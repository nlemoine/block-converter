<?php

declare(strict_types=1);

namespace n5s\BlockConverter;

use n5s\BlockConverter\PostProcessors\EmptyParagraphRemover;
use n5s\BlockConverter\PreProcessors\AutoEmbedProcessor;
use n5s\BlockConverter\PreProcessors\DeprecatedTagReplacer;
use n5s\BlockConverter\PreProcessors\HtmlSanitizer;
use n5s\BlockConverter\PreProcessors\InlineStyleNormalizer;
use n5s\BlockConverter\PreProcessors\MoreTagProcessor;
use n5s\BlockConverter\PreProcessors\ProtocolLessUrlFixer;
use n5s\BlockConverter\PreProcessors\ShortcodeProcessor;
use n5s\BlockConverter\PreProcessors\WpAutop;
use n5s\BlockConverter\ShortcodeConverters\AudioConverter;
use n5s\BlockConverter\ShortcodeConverters\CaptionConverter;
use n5s\BlockConverter\ShortcodeConverters\EmbedConverter;
use n5s\BlockConverter\ShortcodeConverters\GalleryConverter;
use n5s\BlockConverter\ShortcodeConverters\VideoConverter;
use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use n5s\BlockConverter\Support\HtmlUtils;
use n5s\BlockConverter\TagConverters\AudioConverter as AudioTagConverter;
use n5s\BlockConverter\TagConverters\BlockquoteConverter;
use n5s\BlockConverter\TagConverters\FigureConverter;
use n5s\BlockConverter\TagConverters\HeadingConverter;
use n5s\BlockConverter\TagConverters\IframeConverter;
use n5s\BlockConverter\TagConverters\ImageConverter;
use n5s\BlockConverter\TagConverters\LinkConverter;
use n5s\BlockConverter\TagConverters\ListConverter;
use n5s\BlockConverter\TagConverters\ListItemConverter;
use n5s\BlockConverter\TagConverters\ObjectConverter;
use n5s\BlockConverter\TagConverters\ParagraphConverter;
use n5s\BlockConverter\TagConverters\PreConverter;
use n5s\BlockConverter\TagConverters\SeparatorConverter;
use n5s\BlockConverter\TagConverters\SkippedTagConverter;
use n5s\BlockConverter\TagConverters\TableConverter;
use n5s\BlockConverter\TagConverters\TagConverterInterface;
use n5s\BlockConverter\TagConverters\VideoConverter as VideoTagConverter;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use voku\helper\SimpleHtmlDomInterface;
use voku\helper\SimpleHtmlDomNodeInterface;
use WP_Post;

class BlockConverter
{
    use LoggerAwareTrait;

    /** Tags that are legitimate inline (phrasing) content inside a <p>. */
    private const array INLINE_TAGS = [
        '#text', 'a', 'strong', 'b', 'em', 'i', 'span', 'br',
        'code', 'abbr', 'sub', 'sup', 'small', 'mark',
        'del', 'ins', 'u', 's', 'cite', 'q', 'time',
    ];

    /**
     * Check if an element contains any non-inline descendant tags.
     */
    private function hasBlockContent(SimpleHtmlDomInterface $element): bool
    {
        $children = $element->childNodes();

        // @codeCoverageIgnoreStart
        // The parser always hands back a node list; the check exists to satisfy
        // the nullable union in its signature, and no input can trigger it.
        if ($children === null || !$children instanceof SimpleHtmlDomNodeInterface) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        foreach ($children as $child) {
            $tag = $child->getTag();

            if ($tag !== '' && !\in_array($tag, self::INLINE_TAGS, true)) {
                return true;
            }

            // Recurse into inline wrappers (e.g. <a> containing <img>)
            if ($tag !== '' && $tag !== '#text' && $this->hasBlockContent($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unwrap a paragraph that contains block-level content.
     *
     * Walks direct children, groups consecutive inline nodes into paragraph
     * blocks, and dispatches non-inline elements through convertNode().
     *
     * @return Block[]
     */
    private function unwrapParagraph(SimpleHtmlDomInterface $element, ?WP_Post $post): array
    {
        $blocks = [];
        $inlineBuffer = '';

        $children = $element->childNodes();

        // @codeCoverageIgnoreStart
        // The parser always hands back a node list; the check exists to satisfy
        // the nullable union in its signature, and no input can trigger it.
        if ($children === null || !$children instanceof SimpleHtmlDomNodeInterface) {
            return $blocks;
        }
        // @codeCoverageIgnoreEnd

        foreach ($children as $child) {
            $childTag = $child->getTag();
            $isInline = ($childTag === '' || \in_array($childTag, self::INLINE_TAGS, true))
                && !$this->hasBlockContent($child);

            if ($isInline) {
                $inlineBuffer .= (string) $child;
                continue;
            }

            // Flush buffered inline content as a paragraph
            $this->flushInlineBuffer($inlineBuffer, $blocks);

            // Dispatch block-level element through the normal pipeline
            $result = $this->convertNode($child, $post);

            if ($result !== null) {
                $items = \is_array($result) ? $result : [$result];
                \array_push($blocks, ...$items);
            }
        }

        // Flush any trailing inline content
        $this->flushInlineBuffer($inlineBuffer, $blocks);

        return $blocks;
    }

    /**
     * If the inline buffer has non-empty content, create a paragraph block and reset.
     *
     * @param Block[] $blocks
     */
    private function flushInlineBuffer(string &$buffer, array &$blocks): void
    {
        $trimmed = HtmlUtils::trim($buffer);

        if ($trimmed !== '') {
            $blocks[] = new Block('paragraph', innerContent: ['<p>' . $trimmed . '</p>']);
        }

        $buffer = '';
    }

    public function __construct(
        private readonly ConverterRegistry $registry,
        ?LoggerInterface $logger = null,
    ) {

        $this->logger = $logger;
    }

    public function getRegistry(): ConverterRegistry
    {
        return $this->registry;
    }

    /**
     * Convert HTML string to Gutenberg block markup.
     *
     * Uses parse_blocks() to handle mixed content (existing blocks + raw HTML).
     * Only raw HTML segments (blockName === null) are converted.
     */
    public function convert(string $html, ?WP_Post $post = null): string
    {
        $blocks = $this->convertToBlocks($html, $post);

        if ($blocks === []) {
            return '';
        }

        return \implode("\n\n", \array_map(static fn (Block $b): string => $b->render(), $blocks));
    }

    /**
     * Convert HTML string to a Block tree.
     *
     * @return Block[]
     */
    public function convertToBlocks(string $html, ?WP_Post $post = null): array
    {
        if (\trim($html) === '') {
            return [];
        }

        // parse_blocks() comes first so nothing — not even a pre-processor —
        // ever rewrites markup that is already a block. Running the early
        // pre-processors over the whole post used to re-wrap their own output
        // on a second pass, nesting <!-- wp:more --> one level deeper each time.
        $blocks = $this->splitBlocks($html, fn (string $raw): array => $this->convertRawHtml($raw, $post));

        // Run post-processors on the full block tree
        foreach ($this->registry->getPostProcessors() as $postProcessor) {
            $blocks = $postProcessor->process($blocks, $post);
        }

        return $blocks;
    }

    /**
     * Convert a raw HTML string through pre-processors and DOM conversion.
     *
     * When a pre-processor injects Gutenberg block markup (e.g. AutoEmbed
     * converting a bare URL to <!-- wp:embed -->), parse_blocks() is used
     * to separate those new blocks from the remaining raw HTML.
     *
     * @return Block[]
     */
    private function convertRawHtml(string $html, ?WP_Post $post): array
    {
        // Early pre-processors turn shortcodes and bare URLs into block markup,
        // so their output has to be split out before the late ones run: the
        // sanitizer would otherwise strip the block delimiters it just made.
        foreach ($this->registry->getEarlyPreProcessors() as $preProcessor) {
            $html = $preProcessor->process($html, $post);
        }

        if (\trim($html) === '') {
            return [];
        }

        return $this->splitBlocks($html, fn (string $raw): array => $this->convertLateHtml($raw, $post));
    }

    /**
     * Late pre-processors, then DOM conversion of whatever is still raw.
     *
     * @return Block[]
     */
    private function convertLateHtml(string $html, ?WP_Post $post): array
    {
        foreach ($this->registry->getPreProcessors() as $preProcessor) {
            $html = $preProcessor->process($html, $post);
        }

        if (\trim($html) === '') {
            return [];
        }

        // A late pre-processor may have injected block markup too, e.g.
        // AutoEmbedProcessor turning a bare URL into an embed block.
        return $this->splitBlocks($html, fn (string $raw): array => $this->domConvert($raw, $post));
    }

    /**
     * Split block markup from raw HTML, keeping recognised blocks untouched.
     *
     * Called unconditionally rather than behind a str_contains() check for
     * `<!-- wp:`: that string is not the block grammar, which allows any
     * whitespace after the comment opener, and a delimiter the check missed
     * went to the DOM parser, which drops comments — a vanished block.
     * parse_blocks() on plain HTML is one freeform entry, so the check
     * bought nothing.
     *
     * @param  callable(string): Block[] $convertRaw
     * @return list<Block>
     */
    private function splitBlocks(string $html, callable $convertRaw): array
    {
        $blocks = [];

        foreach (\parse_blocks($this->dropUnmatchedDelimiters($html)) as $parsed) {
            if ($parsed['blockName'] !== null) {
                // Already a block: hand it back exactly as it came in.
                $blocks[] = Block::fromParsed($parsed); // @phpstan-ignore argument.type (WP parse_blocks() stubs are loose)

                continue;
            }

            $rawHtml = \trim($parsed['innerHTML']);

            if ($rawHtml === '') {
                continue;
            }

            \array_push($blocks, ...$convertRaw($rawHtml));
        }

        return $blocks;
    }

    /**
     * Remove block delimiters that open a block nothing closes, or close one
     * nothing opened.
     *
     * parse_blocks() runs first here, on legacy HTML that was never a block
     * document. Its parser handles an unmatched delimiter by giving up: an
     * opener with no closer absorbs everything to the end of the document as
     * that block's content, and a closer with no opener turns the rest of
     * the document into raw HTML. Either way a single stray comment silently
     * no-ops the conversion of everything after it. The tokens are found with
     * the parser's own tokenizer, so what counts as a delimiter is exactly
     * what parse_blocks() would have matched.
     */
    private function dropUnmatchedDelimiters(string $html): string
    {
        $parser = new \WP_Block_Parser();
        $parser->document = $html;
        $parser->offset = 0;

        /** @var list<array{0: string, 1: int, 2: int}> $open name, offset, length */
        $open = [];
        /** @var list<array{0: string, 1: int, 2: int}> $unmatched */
        $unmatched = [];

        while (true) {
            /** @var array{0: string, 1: string|null, 2: array<string, mixed>|null, 3: int|null, 4: int|null} $token */
            $token = $parser->next_token();
            [$type, $name, , $start, $length] = $token;

            if ($type === 'no-more-tokens') {
                break;
            }

            $parser->offset = $start + $length;

            if ($type === 'block-opener') {
                $open[] = [(string) $name, (int) $start, (int) $length];
            } elseif ($type === 'block-closer') {
                // Like the parser, a closer closes whatever is open, whatever
                // it is named.
                if (\array_pop($open) === null) {
                    $unmatched[] = [(string) $name, (int) $start, (int) $length];
                }
            }
        }

        \array_push($unmatched, ...$open);

        // Later offsets first, so removing one leaves the others valid.
        \usort($unmatched, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        foreach ($unmatched as [$name, $start, $length]) {
            $this->logger?->warning('Unmatched block delimiter dropped', [
                'block' => $name,
                'delimiter' => \substr($html, $start, $length),
            ]);

            $html = \substr_replace($html, '', $start, $length);
        }

        return $html;
    }

    /**
     * Parse HTML into a DOM tree and convert elements to blocks.
     *
     * @return Block[]
     */
    private function domConvert(string $html, ?WP_Post $post): array
    {
        $dom = HtmlUtils::parse('<body>' . $html . '</body>');
        $childNodes = $dom->findMultiOrFalse('//body/*');

        if ($childNodes === false) {
            return [];
        }

        return $this->buildBlocks($childNodes, $post);
    }

    /**
     * Convert a single element through the registry.
     *
     * @return Block|Block[]|null
     */
    public function convertNode(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
    {
        $tag = $element->getTag();

        if ($tag === '#text' || $tag === '') {
            return null;
        }

        $tagConverter = $this->registry->getTagConverter($tag);

        // A paragraph holding block-level content has to be unwrapped, since a
        // core/paragraph cannot carry an image block. An inline wrapper is
        // unwrapped for the same reason — unless a converter claims the tag,
        // which knows what to do with its own children. LinkConverter keeps the
        // <a> around an image; unwrapping would hand ImageConverter the bare
        // <img> and throw the link away.
        $unwrap = $tag === 'p'
            || (\in_array($tag, self::INLINE_TAGS, true) && !$tagConverter instanceof TagConverterInterface);

        if ($unwrap && $this->hasBlockContent($element)) {
            return $this->unwrapParagraph($element, $post);
        }

        if (!$tagConverter instanceof TagConverterInterface) {
            // Fallback: wrap in wp:html block
            // html() is the outer markup, so it is never blank for a real
            // element; kept as a belt-and-braces guard on the fallback path.
            // @codeCoverageIgnoreStart
            $content = $element->html();
            if ($content === '') {
                return null;
            }
            // @codeCoverageIgnoreEnd

            $this->logger?->warning('No converter for tag, using HTML fallback', ['tag' => $tag]);

            return new Block('html', innerContent: [$content]);
        }

        $result = $tagConverter->convert($element, $post);

        // Container blocks: recurse into children to populate innerBlocks
        if ($result instanceof Block && $result->container) {
            $this->populateInnerBlocks($result, $element, $post);
        }

        return $result;
    }

    /**
     * Walk a container's children and populate its innerBlocks.
     */
    private function populateInnerBlocks(Block $block, SimpleHtmlDomInterface $element, ?WP_Post $post): void
    {
        $children = $element->childNodes();

        // @codeCoverageIgnoreStart
        // The parser always hands back a node list; the check exists to satisfy
        // the nullable union in its signature, and no input can trigger it.
        if ($children === null || !$children instanceof SimpleHtmlDomNodeInterface) {
            return;
        }
        // @codeCoverageIgnoreEnd

        foreach ($children as $child) {
            $childTag = $child->getTag();

            $hasConverter = $childTag !== ''
                && $childTag !== '#text'
                && $this->registry->getTagConverter($childTag) instanceof TagConverterInterface;

            if ($hasConverter) {
                $childResult = $this->convertNode($child, $post);

                if ($childResult !== null) {
                    $items = \is_array($childResult) ? $childResult : [$childResult];

                    foreach ($items as $i => $b) {
                        if ($i > 0) {
                            $block->appendContent("\n\n");
                        }
                        $block->appendInnerBlock($b);
                    }

                    continue;
                }
            }

            $block->appendContent((string) $child);
        }

        // Normalize whitespace: replace empty strings between null placeholders
        // with "\n\n" to match parse_blocks() output.
        for ($i = 1, $len = \count($block->innerContent) - 1; $i < $len; $i++) {
            if (
                \is_string($block->innerContent[$i])
                && \trim($block->innerContent[$i]) === ''
                && ($block->innerContent[$i - 1] ?? null) === null
                && ($block->innerContent[$i + 1] ?? null) === null
            ) {
                $block->innerContent[$i] = "\n\n";
            }
        }

        $block->appendContent('</' . $element->getTag() . '>');
    }

    /**
     * Build a flat array of Block objects from child nodes.
     *
     * @return Block[]
     */
    private function buildBlocks(SimpleHtmlDomNodeInterface $childNodes, ?WP_Post $post): array
    {
        $blocks = [];

        foreach ($childNodes as $childNode) {
            $result = $this->convertNode($childNode, $post);

            if ($result === null) {
                continue;
            }

            $items = \is_array($result) ? $result : [$result];
            \array_push($blocks, ...$items);
        }

        return $blocks;
    }

    /**
     * A converter with every built-in converter and processor registered.
     *
     * Both services are shared for the whole conversion rather than rebuilt
     * per converter, because both cache an expensive lookup:
     * attachment_url_to_postid() scans an unindexed meta_value column, and
     * every oEmbed hit is an HTTP request. Six converters resolve attachments
     * and four resolve embeds; unshared, each would pay separately for the
     * same URL. Both caches only grow, and a migration is a long-lived
     * process: pass your own instances to keep a handle on them — to call
     * forget() between batches, or to turn oEmbed discovery on.
     */
    public static function createDefault(
        ?AttachmentResolver $attachmentResolver = null,
        ?EmbedBlockFactory $embedFactory = null,
    ): self {

        $registry = new ConverterRegistry();

        $attachmentResolver ??= new AttachmentResolver();
        $embedFactory ??= new EmbedBlockFactory();
        $imageConverter = new ImageConverter($attachmentResolver);

        // One sanitizer for both the raw HTML fragments and the blocks the
        // shortcode converters build, so a single policy covers everything
        // that ends up in post_content.
        $htmlSanitizer = new HtmlSanitizer();

        // Early pre-processors: run on each raw segment before its block
        // markup is split out, so what they emit is parsed as blocks. Their
        // priorities sit below 30.
        $registry->registerPreProcessor(new ProtocolLessUrlFixer());
        $registry->registerPreProcessor(new DeprecatedTagReplacer());
        $registry->registerPreProcessor(new MoreTagProcessor());
        $registry->registerPreProcessor(new InlineStyleNormalizer());
        $registry->registerPreProcessor(new AutoEmbedProcessor($embedFactory));
        $registry->registerPreProcessor(new ShortcodeProcessor($registry, $htmlSanitizer));

        // Late pre-processors: run on what is still raw HTML once the early
        // output is split, right before DOM conversion. Priorities from 30 up,
        // so the numbers read in the order the processors actually run.
        $registry->registerPreProcessor($htmlSanitizer);
        $registry->registerPreProcessor(new WpAutop());

        // Shortcode converters
        $registry->registerShortcodeConverter(new CaptionConverter($attachmentResolver));
        $registry->registerShortcodeConverter(new GalleryConverter());
        $registry->registerShortcodeConverter(new AudioConverter($attachmentResolver));
        $registry->registerShortcodeConverter(new VideoConverter($attachmentResolver));
        $registry->registerShortcodeConverter(new EmbedConverter($embedFactory));

        // Tag converters
        $registry->registerTagConverter(new ParagraphConverter());
        $registry->registerTagConverter(new HeadingConverter());
        $registry->registerTagConverter($imageConverter);
        $registry->registerTagConverter(new ListConverter());
        $registry->registerTagConverter(new ListItemConverter());
        $registry->registerTagConverter(new BlockquoteConverter());
        $registry->registerTagConverter(new SeparatorConverter());
        $registry->registerTagConverter(new SkippedTagConverter());
        $registry->registerTagConverter(new TableConverter());
        $registry->registerTagConverter(new PreConverter());
        $registry->registerTagConverter(new ObjectConverter($embedFactory));
        $registry->registerTagConverter(new IframeConverter($embedFactory));
        $registry->registerTagConverter(new LinkConverter($imageConverter));
        $registry->registerTagConverter(new AudioTagConverter($attachmentResolver));
        $registry->registerTagConverter(new VideoTagConverter($attachmentResolver));
        $registry->registerTagConverter(new FigureConverter($imageConverter));

        // Post-processors
        $registry->registerPostProcessor(new EmptyParagraphRemover());

        return new BlockConverter($registry);
    }
}
