<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use Dom\HTMLDocument;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SsgLab\SiteBuilder;

class SiteBuilderTest extends TestCase
{
    /** Elements that run script, load from elsewhere, submit data or redirect; none belongs in page content. */
    private const ACTIVE_ELEMENTS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'base', 'meta',
        'style', 'link', 'noscript', 'svg', 'math',
    ];

    /** Attributes a browser follows or loads as a URL. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href'];

    private string $pagesDir;
    private string $outDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/ssg-lab-test-pages-' . uniqid();
        mkdir($this->pagesDir);
        file_put_contents($this->pagesDir . '/Readme.md', "# Hello\n");

        $this->outDir = sys_get_temp_dir() . '/ssg-lab-test-out-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->pagesDir);
        $this->removeDir($this->outDir);
    }

    public function testDefaultsToMutedTheme(): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileEquals(__DIR__ . '/../src/themes/muted.css', $this->outDir . '/palette.css');
        self::assertStringContainsString(
            '<link rel="stylesheet" href="palette.css">',
            (string)file_get_contents($this->outDir . '/index.html'),
        );
    }

    public function testColorfulThemeOptionSelectsColorfulPalette(): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: ['theme' => 'colorful']);

        self::assertFileEquals(__DIR__ . '/../src/themes/colorful.css', $this->outDir . '/palette.css');
    }

    /**
     * Both shapes that mean "no option given": an explicit null, and an empty JSON object, which
     * decodes to [].
     *
     * @return array<string, array{mixed}>
     */
    public static function emptyRenderOptions(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('emptyRenderOptions')]
    public function testEmptyRenderOptionsDefaultToMutedTheme(mixed $renderOptions): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: $renderOptions);

        self::assertFileEquals(__DIR__ . '/../src/themes/muted.css', $this->outDir . '/palette.css');
    }

    /**
     * @return array<string, array{mixed, non-empty-string}>
     */
    public static function invalidRenderOptions(): array
    {
        return [
            'string' => ['colorful', 'render_options must be a JSON object, got string'],
            'list' => [['colorful'], 'render_options must be a JSON object, got a list'],
            'unknown key' => [['them' => 'colorful'], 'Unknown render option: them. Supported: theme'],
            'several unknown keys' => [
                ['them' => 'colorful', 'palette' => 'muted'],
                'Unknown render option: them, palette. Supported: theme',
            ],
            'stdClass' => [
                json_decode('{"theme": "colorful"}'),
                'render_options must be decoded with assoc: true, got stdClass',
            ],
            'unknown theme' => [['theme' => 'neon'], 'Invalid theme: neon. Valid themes: muted, colorful'],
            'non-string theme' => [['theme' => ['colorful']], 'Invalid theme: array'],
            'null theme' => [['theme' => null], 'Invalid theme: null'],
        ];
    }

    /**
     * @param non-empty-string $message The start of the expected exception message.
     */
    #[DataProvider('invalidRenderOptions')]
    public function testRejectsInvalidRenderOptionsBeforeWritingOutput(mixed $renderOptions, string $message): void
    {
        try {
            (new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: $renderOptions);
            self::fail('Expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->outDir);
    }

    public function testRejectsInvalidRenderOptionsBeforeCheckingPagesDir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid theme: neon');

        (new SiteBuilder())->build($this->pagesDir . '-missing', $this->outDir, renderOptions: ['theme' => 'neon']);
    }

    /**
     * A sub-page named like a URL scheme, once as a folder and once as a leaf page.
     *
     * @return array<string, array{non-empty-string, non-empty-string}>
     */
    public static function schemeLikeSubPages(): array
    {
        return [
            'folder' => ['javascript:alert(1)%2F%2F/Readme.md', 'javascript:alert(1)%2F%2F'],
            'leaf page' => ['javascript:alert(1)%2F%2F.md', 'javascript:alert(1)%2F%2F'],
        ];
    }

    #[DataProvider('schemeLikeSubPages')]
    public function testSubPageLinkCannotCarryAUrlScheme(string $file, string $name): void
    {
        $this->writePageFile($file);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        $links = $this->subPageLinks();
        foreach (array_keys($links) as $href) {
            self::assertDoesNotMatchRegularExpression('/^[a-z][a-z0-9+.-]*:/i', $href);
        }
        self::assertSame(['javascript%3Aalert%281%29%252F%252F/' => $name], $links);
    }

    /**
     * Folder names with characters that mean something in a URL or in HTML.
     *
     * @return array<string, array{non-empty-string}>
     */
    public static function subPageNamesNeedingEncoding(): array
    {
        return [
            'query' => ['a?b'],
            'fragment' => ['a#b'],
            'percent' => ['100%'],
            'encoded slash' => ['a%2Fb'],
            'double quotes' => ['say "hi"'],
            'angle bracket' => ['a<b'],
            'space' => ['two words'],
            'non-ASCII' => ['Über uns'],
        ];
    }

    #[DataProvider('subPageNamesNeedingEncoding')]
    public function testSubPageLinkResolvesToTheRenderedPage(string $name): void
    {
        $this->writePageFile($name . '/Readme.md');

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        $links = $this->subPageLinks();
        self::assertCount(1, $links);
        $href = (string)array_key_first($links);
        self::assertSame(rawurlencode($name) . '/', $href);
        self::assertFileExists($this->outDir . '/' . rawurldecode(rtrim($href, '/')) . '/index.html');
        self::assertSame($name, $links[$href]);
    }

    /**
     * Paths relative to `.attachments.1/`.
     *
     * @return array<string, array{non-empty-string}>
     */
    public static function allowlistedAttachments(): array
    {
        return [
            'image' => ['photo.png'],
            'upper-case extension' => ['PHOTO.JPG'],
            'PDF' => ['report.pdf'],
            'CSV' => ['notes.csv'],
            'video' => ['clip.mp4'],
            'only the last extension counts' => ['x.html.png'],
            'nested' => ['sub/photo.png'],
        ];
    }

    #[DataProvider('allowlistedAttachments')]
    public function testPublishesAllowlistedAttachments(string $path): void
    {
        $this->writeAttachment($path);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileEquals(
            $this->pagesDir . '/.attachments.1/' . $path,
            $this->outDir . '/.attachments.1/' . $path,
        );
    }

    /**
     * Paths relative to `.attachments.1/`: types a browser renders as a document or runs as
     * script, server config dotfiles, files without an extension, and anything in a dot-folder.
     *
     * @return array<string, array{non-empty-string}>
     */
    public static function scriptCapableAttachments(): array
    {
        return [
            'HTML' => ['index.html'],
            'HTM' => ['x.htm'],
            'XHTML' => ['x.xhtml'],
            'XML' => ['x.xml'],
            'SVG' => ['x.svg'],
            'upper-case SVG' => ['x.SVG'],
            'SVGZ' => ['x.svgz'],
            'JavaScript' => ['x.js'],
            'JavaScript module' => ['x.mjs'],
            'PHP' => ['shell.php'],
            '.htaccess' => ['.htaccess'],
            '.user.ini' => ['.user.ini'],
            'no extension' => ['noextension'],
            'nested HTML' => ['sub/index.html'],
            'image in a dot-folder' => ['.hidden/photo.png'],
        ];
    }

    #[DataProvider('scriptCapableAttachments')]
    public function testSkipsAttachmentsThatCouldRunScript(string $path): void
    {
        $this->writeAttachment($path);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileDoesNotExist($this->outDir . '/.attachments.1/' . $path);
        self::assertFileExists($this->outDir . '/index.html');
    }

    /**
     * Page files whose name minus `.md` is a dot segment: `..md` is `.`, `...md` is `..`.
     *
     * @return array<string, array{string}>
     */
    public static function dotSegmentPageFiles(): array
    {
        return [
            '. at the root' => ['..md'],
            '.. at the root' => ['...md'],
            '.. in a folder' => ['sub/...md'],
        ];
    }

    /**
     * A dot segment as the page's directory would write over the folder's own page or one
     * directory up, from the root outside the output directory.
     */
    #[DataProvider('dotSegmentPageFiles')]
    public function testRejectsPageNamesThatAreDotSegments(string $file): void
    {
        $this->writePageFile($file);
        // One level below $this->outDir, so a write one directory up stays in the test's own dir.
        $outDir = $this->outDir . '/site';

        try {
            (new SiteBuilder())->build($this->pagesDir, $outDir);
            self::fail("{$file} was built");
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(basename($file), $e->getMessage());
            self::assertStringNotContainsString($this->pagesDir, $e->getMessage());
        }
        self::assertFileDoesNotExist($this->outDir . '/index.html');
    }

    public function testBuildsAPageNamedThreeDots(): void
    {
        $this->writePageFile('....md');

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileExists($this->outDir . '/.../index.html');
    }

    public function testLeafPagesReachAttachmentsOneDirectoryUp(): void
    {
        $markdown = "![photo](.attachments.1/a.png)\n\n"
            . "[report](.attachments.1/r.pdf)\n\n"
            . "![doc](.attachments.1/d.pdf)\n\n"
            . "<p style=\"background-image:url(.attachments.1/bg.png)\">bg</p>\n\n"
            . "Write src=\".attachments.1/a.png\" in prose and `href=\".attachments.1/r.pdf\"` in code.\n";
        file_put_contents($this->pagesDir . '/Readme.md', $markdown);
        file_put_contents($this->pagesDir . '/Leaf.md', $markdown);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        $leaf = (string)file_get_contents($this->outDir . '/Leaf/index.html');
        self::assertStringContainsString('<img src="../.attachments.1/a.png" alt="photo" />', $leaf);
        self::assertStringContainsString('<a href="../.attachments.1/r.pdf">report</a>', $leaf);
        // An embed NonImageEmbedRewriter turned into a link.
        self::assertStringContainsString('<a href="../.attachments.1/d.pdf">doc</a>', $leaf);
        self::assertStringContainsString('url(&quot;../.attachments.1/bg.png&quot;)', $leaf);
        // Text that only looks like an attribute stays as written.
        self::assertStringContainsString('Write src=".attachments.1/a.png" in prose', $leaf);
        self::assertStringContainsString('<code>href=".attachments.1/r.pdf"</code>', $leaf);

        $folder = (string)file_get_contents($this->outDir . '/index.html');
        self::assertStringContainsString('<img src=".attachments.1/a.png" alt="photo" />', $folder);
        self::assertStringContainsString('url(&quot;.attachments.1/bg.png&quot;)', $folder);
        self::assertStringNotContainsString('../.attachments.', $folder);
    }

    /**
     * Markdown that tries to get active content into a page, through every stage of the pipeline
     * that touches the HTML: the multiline table preprocessor, CommonMark and its extensions, and
     * HTMLPurifier with its URI filters, which rewrite attachment paths on leaf pages.
     *
     * @return array<string, array{string}>
     */
    public static function xssPayloads(): array
    {
        return [
            // MultilineTablePreprocessor renders cells itself and splices the HTML in.
            'script in a table cell' => ["| a |\n|---|\n| <script>alert(1)</script> |"],
            'event handler in a table cell' => ["| a |\n|---|\n| <img src=x onerror=alert(1)> |"],
            'event handler in a header cell' => ["| <svg onload=alert(1)> |\n|---|\n| a |"],
            'event handler in a multi-line cell' => ["| a |\n|---|\n| first \\\n| <img src=x onerror=alert(1)> |"],
            'javascript link in a table cell' => ["| a |\n|---|\n| [x](javascript:alert(1)) |"],
            // FencedCodeRenderer builds the class attribute from the info string.
            'fence info string closing the tag' => ["```\"><script>alert(1)</script>\ncode\n```"],
            'fence info string adding an attribute' => ["```php onmouseover=alert(1)\ncode\n```"],
            'image alt text closing the attribute' => ['![" onerror="alert(1)](.attachments.1/a.png)'],
            'image title closing the attribute' => ['![a](.attachments.1/a.png "\" onload=\"alert(1)")'],
            'data URL image' => ['![x](data:image/svg+xml,<svg onload=alert(1)>)'],
            'javascript link' => ['[x](javascript:alert(1))'],
            'javascript link in mixed case' => ['[x](JaVaScRiPt:alert(1))'],
            'javascript link, entity-encoded' => ['[x](&#106;avascript:alert(1))'],
            'javascript link split by a tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'data URL link' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            'iframe' => ['<iframe src="https://evil.example/"></iframe>'],
            'object' => ['<object data="x.swf"></object>'],
            'embed' => ['<embed src="x.swf">'],
            'form' => ['<form action="https://evil.example/"><input type="password" name="p"></form>'],
            'style element' => ['<style>body { background: url(https://evil.example/) }</style>'],
            'base element' => ['<base href="https://evil.example/">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example/">'],
            'external stylesheet' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'script inside svg' => ['<svg><script>alert(1)</script></svg>'],
            'noscript mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>'],
            // Elements MarkdownConverter allow-lists on top of HTMLPurifier's defaults.
            'event handlers on details and summary' => ['<details ontoggle="alert(1)" open><summary onclick="alert(1)">s</summary>x</details>'],
            'event handler on a text input' => ['<input type="text" onfocus="alert(1)" autofocus>'],
            'event handler on a checkbox' => ['<input type="checkbox" onclick="alert(1)">'],
            'javascript URL in a style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            // Aimed at the attachment path rewrite on leaf pages, once a regex run after purification.
            'attachment path closing the attribute' => ["<img src='.attachments.1/a.png\" onerror=\"alert(1)'>"],
        ];
    }

    /**
     * Checks the pages as written, so the stages after HTMLPurifier are covered too. Each payload
     * is rendered as a folder page and as a leaf page, which has its attachment links rewritten.
     */
    #[DataProvider('xssPayloads')]
    public function testRenderedPagesCarryNoActiveContent(string $markdown): void
    {
        file_put_contents($this->pagesDir . '/Readme.md', $markdown);
        file_put_contents($this->pagesDir . '/Leaf.md', $markdown);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        foreach (['index.html', 'Leaf/index.html'] as $page) {
            $findings = self::activeContent((string)file_get_contents($this->outDir . '/' . $page));
            self::assertSame([], $findings, "{$page}: " . implode(', ', $findings));
        }
    }

    public function testActiveContentDetectorFlagsActiveContent(): void
    {
        $findings = self::activeContent(
            '<main><script>alert(1)</script><img src="x.png" onerror="alert(1)">'
            . '<a href=" JaVa&#9;script:x">a</a><img src="data:image/png;base64,AA">'
            . '<p style="background:url(javascript:x)">p</p></main>',
        );

        self::assertSame([
            '<script>',
            '<img onerror>',
            '<a href=JaVascript:x>',
            '<img src=data:image/png;base64,AA>',
            '<p style=background:url(javascript:x)>',
        ], $findings);
        self::assertSame([], self::activeContent('<main><p><a href="sub/">x</a> <img src=".attachments.1/a.png"></p></main>'));
    }

    /**
     * Active content inside `<main>`, parsed the way a browser parses it: elements from
     * self::ACTIVE_ELEMENTS, event handler attributes, `javascript:`/`vbscript:`/`data:` URLs (with
     * the whitespace and control characters browsers ignore removed) and script in `style`.
     * Everything outside `<main>`, such as the template's own theme.js, is the page template's.
     *
     * @return list<string> One entry per finding, e.g. `<img onerror>`
     */
    private static function activeContent(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $findings = [];
        foreach ($document->querySelectorAll('main *') as $element) {
            $tag = $element->localName;
            if (in_array($tag, self::ACTIVE_ELEMENTS, true)) {
                $findings[] = "<{$tag}>";
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $url = (string)preg_replace('/[\x00-\x20]+/', '', $attribute->value);
                if (str_starts_with($name, 'on')) {
                    $findings[] = "<{$tag} {$name}>";
                } elseif (in_array($name, self::URL_ATTRIBUTES, true) && preg_match('/^(javascript|vbscript|data):/i', $url) === 1) {
                    $findings[] = "<{$tag} {$name}={$url}>";
                } elseif ($name === 'style' && preg_match('/javascript:|expression\(/i', $attribute->value) === 1) {
                    $findings[] = "<{$tag} style={$attribute->value}>";
                }
            }
        }

        return $findings;
    }

    private function writeAttachment(string $relativePath): void
    {
        $path = $this->pagesDir . '/.attachments.1/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, "content of {$relativePath}\n");
    }

    private function writePageFile(string $relativePath): void
    {
        $path = $this->pagesDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, "# Page\n");
    }

    /**
     * @return array<string, string> The root page's sub-page links, href => link text
     */
    private function subPageLinks(): array
    {
        $document = new DOMDocument();
        // libxml reads HTML as ISO-8859-1 unless told otherwise.
        $html = (string)file_get_contents($this->outDir . '/index.html');
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        $links = [];
        foreach ((new DOMXPath($document))->query('//nav[@class="subpages"]//a') ?: [] as $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            $links[$link->getAttribute('href')] = $link->textContent;
        }

        return $links;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
