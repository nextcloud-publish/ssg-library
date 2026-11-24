<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use Dom\HTMLDocument;
use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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

    /** Content of the files outside the export that symlink fixtures point at. */
    private const OUTSIDE_SECRET = 'OUTSIDE-SECRET';

    private string $pagesDir;
    private string $outDir;
    /** Files outside the export, as symlink targets. */
    private string $outsideDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/ssg-lab-test-pages-' . uniqid();
        mkdir($this->pagesDir);
        file_put_contents($this->pagesDir . '/Readme.md', "# Hello\n");

        $this->outDir = sys_get_temp_dir() . '/ssg-lab-test-out-' . uniqid();

        $this->outsideDir = sys_get_temp_dir() . '/ssg-lab-test-outside-' . uniqid();
        mkdir($this->outsideDir);
        foreach (['secret.md', 'secret.txt', 'Readme.md'] as $file) {
            file_put_contents($this->outsideDir . '/' . $file, self::OUTSIDE_SECRET . "\n");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->pagesDir);
        $this->removeDir($this->outDir);
        $this->removeDir($this->outsideDir);
    }

    public function testDefaultsToWikiTheme(): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileEquals(__DIR__ . '/../src/themes/wiki.css', $this->outDir . '/layout.css');
        self::assertStringContainsString(
            '<link rel="stylesheet" href="layout.css">',
            (string)file_get_contents($this->outDir . '/index.html'),
        );
    }

    public function testTopnavThemeOptionSelectsTopnavLayout(): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: ['theme' => 'topnav']);

        self::assertFileEquals(__DIR__ . '/../src/themes/topnav.css', $this->outDir . '/layout.css');
    }

    /**
     * The sidebar links every page of the site, whole tree, from every page —
     * the leaf page two levels down included.
     */
    public function testWikiThemeRendersThePageTreeInTheSidebar(): void
    {
        $this->writePages();

        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

        $leaf = (string)file_get_contents($this->outDir . '/Cats/Paws/index.html');
        self::assertStringContainsString('<aside class="sidebar"', $leaf);
        self::assertStringContainsString('<a href="../../">Sample</a>', $leaf);
        self::assertStringContainsString('<a href="../../Cats/">Cats</a>', $leaf);
        self::assertStringContainsString('<a href="../../Cats/Paws/" aria-current="page">Paws</a>', $leaf);
        self::assertStringContainsString('<a href="../../about/">about</a>', $leaf);
        // The root's own link is a relative path of its own, not an empty one.
        self::assertStringContainsString('<a href="./">Sample</a>', (string)file_get_contents($this->outDir . '/index.html'));
    }

    /**
     * On a narrow screen the `wiki` sidebar is a drawer, which needs a script
     * and a button in the header to open it. Both are the layout's own: under
     * `topnav` there is no tree to toggle, so neither is written.
     */
    public function testWikiThemeShipsTheDrawerToggleAndScript(): void
    {
        $this->writePages();

        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

        self::assertFileEquals(__DIR__ . '/../src/themes/wiki.js', $this->outDir . '/layout.js');

        $leaf = (string)file_get_contents($this->outDir . '/Cats/Paws/index.html');
        self::assertStringContainsString('<script src="../../layout.js"></script>', $leaf);
        // The button says what it controls, and the sidebar answers to that id.
        self::assertStringContainsString('aria-controls="page-tree"', $leaf);
        self::assertStringContainsString('<aside class="sidebar" id="page-tree">', $leaf);
        // Shut, and hidden until the script that can open it has run.
        self::assertStringContainsString('class="nav-toggle" hidden aria-expanded="false"', $leaf);
    }

    public function testTopnavThemeShipsNeitherDrawerNorItsScript(): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample', ['theme' => 'topnav']);

        $root = (string)file_get_contents($this->outDir . '/index.html');
        self::assertFileDoesNotExist($this->outDir . '/layout.js');
        self::assertStringNotContainsString('layout.js', $root);
        self::assertStringNotContainsString('nav-toggle', $root);
        self::assertStringNotContainsString('nav-scrim', $root);
    }

    /**
     * Ordinarily the primary bar holds the current page's siblings, itself
     * marked `aria-current="page"`, and the secondary bar below it the
     * current page's own children. At the deepest level, where there are no
     * children to put in the secondary bar, the pair shifts up one level
     * instead: the current page joins its siblings in the secondary bar, and
     * the primary bar above becomes its parent's own siblings, the parent
     * highlighted there. Ancestors further up and grandchildren are neither
     * bar — those are the "Up" link above the title and a click away.
     */
    public function testTopnavThemeShowsSiblingsAboveChildren(): void
    {
        $this->writePages();

        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample', ['theme' => 'topnav']);

        $cats = (string)file_get_contents($this->outDir . '/Cats/index.html');
        $primary = $this->topnavOf($cats);
        self::assertStringContainsString('<a href="../about/">about</a>', $primary);
        self::assertStringContainsString('<a href="../Cats/" aria-current="page">Cats</a>', $primary);
        self::assertStringNotContainsString('>Paws</a>', $primary);

        self::assertStringContainsString('<a href="../Cats/Paws/">Paws</a>', $this->subnavOf($cats));

        // The root page has no parent and so no siblings: with only one
        // level to show, its own children take the primary bar's place
        // rather than sit alone in a secondary bar under an empty one.
        $root = (string)file_get_contents($this->outDir . '/index.html');
        self::assertSame('', $this->subnavOf($root));
        self::assertStringContainsString('<a href="about/">about</a>', $this->topnavOf($root));
        self::assertStringContainsString('<a href="Cats/">Cats</a>', $this->topnavOf($root));

        // "about" is a leaf one level down: with no children of its own to
        // pair with a secondary bar, and its parent being the root — which
        // has no siblings of its own to shift up to — its own siblings stay
        // in the primary bar, itself highlighted there, same as any page
        // with children of its own.
        $aboutSingle = (string)file_get_contents($this->outDir . '/about/index.html');
        self::assertSame('', $this->subnavOf($aboutSingle));
        self::assertStringContainsString('<a href="../about/" aria-current="page">about</a>', $this->topnavOf($aboutSingle));

        // "Paws" is a leaf two levels down: its own siblings (itself alone,
        // having none) move to the secondary bar, and the primary bar above
        // becomes its parent's siblings, "Cats" highlighted there instead.
        $leaf = (string)file_get_contents($this->outDir . '/Cats/Paws/index.html');
        $leafPrimary = $this->topnavOf($leaf);
        self::assertStringContainsString('<a href="../../about/">about</a>', $leafPrimary);
        self::assertStringContainsString('<a href="../../Cats/" aria-current="page">Cats</a>', $leafPrimary);
        self::assertStringContainsString('aria-current="page">Paws</a>', $this->subnavOf($leaf));
    }

    /**
     * Slugs are directory names off disk, so a page name can hold anything a
     * filesystem allows. Percent-encoded, `#` and `?` stay part of the path
     * instead of starting a fragment or a query, and a page named
     * `javascript:…` stays a relative path instead of becoming a scheme.
     */
    public function testPageNamesArePercentEncodedInNavigationLinks(): void
    {
        foreach (['Notes #1', 'javascript:alert(1)'] as $name) {
            mkdir($this->pagesDir . '/' . $name);
            file_put_contents($this->pagesDir . '/' . $name . '/Readme.md', "# {$name}\n");
        }

        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

        // The root page is where the tree's hrefs carry no `../` to defuse them.
        $root = $this->navOf((string)file_get_contents($this->outDir . '/index.html'));
        self::assertStringContainsString('<a href="Notes%20%231/">Notes #1</a>', $root);
        self::assertStringContainsString('<a href="javascript%3Aalert%281%29/">', $root);
        self::assertStringNotContainsString('href="javascript:', $root);

        // The directory on disk keeps the name as it was; only the href is encoded.
        $leaf = $this->navOf((string)file_get_contents($this->outDir . '/Notes #1/index.html'));
        self::assertStringContainsString('<a href="../Notes%20%231/" aria-current="page">', $leaf);
    }

    /**
     * Two pages of one folder can't share an output directory: whichever came
     * second would overwrite the first, and both would be linked to the same
     * place. The folder keeps the name — moving it would move its sub-pages
     * too — and the leaf is numbered off it.
     */
    public function testALeafColludingWithAFolderGetsANumberedSlug(): void
    {
        file_put_contents($this->pagesDir . '/Cats.md', "# Leaf cats\n");
        mkdir($this->pagesDir . '/Cats');
        file_put_contents($this->pagesDir . '/Cats/Readme.md', "# Folder cats\n");

        $pageCount = (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

        self::assertSame(3, $pageCount, 'root, the folder page and the leaf');
        self::assertStringContainsString('Folder cats', (string)file_get_contents($this->outDir . '/Cats/index.html'));
        self::assertStringContainsString('Leaf cats', (string)file_get_contents($this->outDir . '/Cats-2/index.html'));

        $root = $this->navOf((string)file_get_contents($this->outDir . '/index.html'));
        self::assertStringContainsString('<a href="Cats/">Cats</a>', $root);
        self::assertStringContainsString('<a href="Cats-2/">Cats-2</a>', $root);
    }

    /**
     * A folder's own page is its Readme whatever it is spelled like — an
     * export written on a case-insensitive filesystem may well shout it.
     */
    public function testAnUppercaseReadmeIsStillTheFoldersOwnPage(): void
    {
        mkdir($this->pagesDir . '/Docs');
        file_put_contents($this->pagesDir . '/Docs/README.md', "# Docs content\n");

        $pageCount = (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

        $docs = (string)file_get_contents($this->outDir . '/Docs/index.html');
        self::assertSame(2, $pageCount);
        self::assertStringContainsString('Docs content', $docs);
        self::assertStringNotContainsString('no content yet', $docs);
        // Read as the folder's page, it is not also a leaf page beside it.
        self::assertDirectoryDoesNotExist($this->outDir . '/Docs/README');
    }

    /**
     * Symlinks are left where they are rather than read through: in an export
     * from somewhere else, one could point at another collective, at a path
     * outside the export entirely, or back at its own parent.
     */
    public function testSymlinksInTheExportAreNotFollowed(): void
    {
        $outside = $this->pagesDir . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/Readme.md', "# Secret page\n");
        file_put_contents($outside . '/secret.txt', "secret\n");

        mkdir($this->pagesDir . '/.attachments.1');
        file_put_contents($this->pagesDir . '/.attachments.1/fine.txt', "fine\n");
        symlink($outside, $this->pagesDir . '/Elsewhere');
        symlink($outside . '/secret.txt', $this->pagesDir . '/.attachments.1/notes.txt');
        symlink($outside . '/Readme.md', $this->pagesDir . '/leaf.md');

        try {
            $pageCount = (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample');

            self::assertSame(1, $pageCount, 'the root page alone');
            self::assertDirectoryDoesNotExist($this->outDir . '/Elsewhere');
            self::assertDirectoryDoesNotExist($this->outDir . '/leaf');
            self::assertFileDoesNotExist($this->outDir . '/.attachments.1/notes.txt');
            // The folder around the symlink is still copied as it was.
            self::assertFileExists($this->outDir . '/.attachments.1/fine.txt');
            self::assertStringNotContainsString(
                'Secret page',
                $this->navOf((string)file_get_contents($this->outDir . '/index.html')),
            );
        } finally {
            $this->removeDir($outside);
        }
    }

    /**
     * A sub-page tree two levels deep: a root `Readme.md` (written in setUp),
     * an `about.md` leaf next to it, and a `Cats` folder with a `Paws.md` leaf.
     */
    private function writePages(): void
    {
        file_put_contents($this->pagesDir . '/about.md', "# About\n");
        mkdir($this->pagesDir . '/Cats');
        file_put_contents($this->pagesDir . '/Cats/Readme.md', "# Cats\n");
        file_put_contents($this->pagesDir . '/Cats/Paws.md', "# Paws\n");
    }

    /**
     * The page's navigation alone — the `wiki` sidebar or the `topnav` bar.
     * What the navigation links is asserted against this rather than against
     * the whole document, where the page's own Markdown contributes lists and
     * links of its own that would answer for it.
     */
    private function navOf(string $html): string
    {
        preg_match('#<aside class="sidebar".*?</aside>|<nav class="topnav".*?</nav>#s', $html, $match);

        return $match[0] ?? '';
    }

    /** The `topnav` layout's primary bar alone — the current page's siblings. */
    private function topnavOf(string $html): string
    {
        preg_match('#<nav class="topnav".*?</nav>#s', $html, $match);

        return $match[0] ?? '';
    }

    /** The `topnav` layout's secondary bar alone — the current page's own children. */
    private function subnavOf(string $html): string
    {
        preg_match('#<nav class="subnav".*?</nav>#s', $html, $match);

        return $match[0] ?? '';
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
    public function testEmptyRenderOptionsDefaultToWikiTheme(mixed $renderOptions): void
    {
        (new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: $renderOptions);

        self::assertFileEquals(__DIR__ . '/../src/themes/wiki.css', $this->outDir . '/layout.css');
    }

    /**
     * @return array<string, array{mixed, non-empty-string}>
     */
    public static function invalidRenderOptions(): array
    {
        return [
            'string' => ['topnav', 'render_options must be a JSON object, got string'],
            'list' => [['topnav'], 'render_options must be a JSON object, got a list'],
            'unknown key' => [['them' => 'topnav'], 'Unknown render option: them. Supported: theme'],
            'several unknown keys' => [
                ['them' => 'topnav', 'layout' => 'wiki'],
                'Unknown render option: them, layout. Supported: theme',
            ],
            'stdClass' => [
                json_decode('{"theme": "topnav"}'),
                'render_options must be decoded with assoc: true, got stdClass',
            ],
            'unknown theme' => [['theme' => 'neon'], 'Invalid theme: neon. Valid themes: wiki, topnav'],
            'non-string theme' => [['theme' => ['topnav']], 'Invalid theme: array'],
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
     * Page files whose name starts with a dot, including ones whose name minus `.md` is nothing
     * but dots: `..md` is `.`, `...md` is `..`.
     *
     * @return array<string, array{string}>
     */
    public static function dotSegmentPageFiles(): array
    {
        return [
            '. at the root' => ['..md'],
            '.. at the root' => ['...md'],
            '.. in a folder' => ['sub/...md'],
            'four dots' => ['....md'],
        ];
    }

    /**
     * Skipped like any other dot-prefixed entry. Read as a leaf page, `..md` and `...md` would
     * have named the page's directory `.` or `..`, writing it over its folder's own page or one
     * directory up, from the root outside the output directory.
     */
    #[DataProvider('dotSegmentPageFiles')]
    public function testSkipsPageFilesThatAreDotSegments(string $file): void
    {
        $this->writePageFile($file);
        // One level below $this->outDir, so a write one directory up would land in the test's own dir.
        $outDir = $this->outDir . '/site';

        (new SiteBuilder())->build($this->pagesDir, $outDir);

        self::assertFileDoesNotExist($this->outDir . '/index.html');
        self::assertFileExists($outDir . '/index.html');
    }

    /**
     * Symlinks a hostile archive could plant: the link's path below the pages directory, its
     * target (relative to the outside dir, or absolute), and the output path the link would
     * produce if followed (null if it would only add content to a page that exists anyway).
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function linkedExportEntries(): array
    {
        return [
            'leaf page' => ['linked.md', 'secret.md', 'linked'],
            'folder' => ['linked', '', 'linked'],
            'attachments folder' => ['.attachments.2', '', '.attachments.2'],
            'file in an attachments folder' => ['.attachments.1/env.txt', 'secret.txt', '.attachments.1/env.txt'],
            'Readme of a folder' => ['sub/Readme.md', 'secret.md', null],
            'device' => ['zero.md', '/dev/zero', 'zero'],
        ];
    }

    #[DataProvider('linkedExportEntries')]
    public function testSkipsSymlinksInTheExport(string $link, string $target, ?string $output): void
    {
        $path = $this->pagesDir . '/' . $link;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        symlink(str_starts_with($target, '/') ? $target : rtrim($this->outsideDir . '/' . $target, '/'), $path);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        if ($output !== null) {
            self::assertFileDoesNotExist($this->outDir . '/' . $output);
        }
        self::assertSame([], self::filesContaining($this->outDir, self::OUTSIDE_SECRET));
    }

    public function testSkipsSymlinkLoops(): void
    {
        // Two links to their own folder: followed, every level doubles until the kernel's limit.
        symlink('.', $this->pagesDir . '/a');
        symlink('.', $this->pagesDir . '/b');

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileDoesNotExist($this->outDir . '/a');
        self::assertFileDoesNotExist($this->outDir . '/b');
    }

    public function testSkipsFifos(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is needed to create a FIFO');
        }
        // Reading or copying a FIFO blocks until something writes to it, so the build would hang.
        posix_mkfifo($this->pagesDir . '/pipe.md', 0o644);
        mkdir($this->pagesDir . '/.attachments.1');
        posix_mkfifo($this->pagesDir . '/.attachments.1/pipe.txt', 0o644);

        (new SiteBuilder())->build($this->pagesDir, $this->outDir);

        self::assertFileDoesNotExist($this->outDir . '/pipe');
        self::assertFileDoesNotExist($this->outDir . '/.attachments.1/pipe.txt');
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
        $html = $this->navOf((string)file_get_contents($this->outDir . '/index.html'));
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        $links = [];
        foreach ((new DOMXPath($document))->query('//a') ?: [] as $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            $href = $link->getAttribute('href');
            // The root's own link, not a sub-page.
            if ($href === './') {
                continue;
            }
            $links[$href] = $link->textContent;
        }

        return $links;
    }

    /**
     * Paths below $dir of the files whose content contains $needle. Symlinks are not followed.
     *
     * @return list<string>
     */
    private static function filesContaining(string $dir, string $needle): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if (!$file->isLink() && $file->isFile() && str_contains((string)file_get_contents($file->getPathname()), $needle)) {
                $found[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }

        return $found;
    }

    /** Removes $dir and everything in it; a symlink is removed itself, never what it points at. */
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
            // A symlink is unlinked, never recursed into: some of the tests
            // below plant one, and following it would delete its target.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                $this->removeDir($path);
            }
        }
        rmdir($dir);
    }
}
