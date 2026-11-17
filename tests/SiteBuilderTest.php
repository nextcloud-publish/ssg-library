<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SsgLab\SiteBuilder;

class SiteBuilderTest extends TestCase
{
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
     * The bar holds the current page's own sub-pages and nothing else: no
     * ancestors, no grandchildren — those are a back link and a click away.
     */
    public function testTopnavThemeLinksOnlyTheCurrentPagesChildren(): void
    {
        $this->writePages();

        (new SiteBuilder())->build($this->pagesDir, $this->outDir, 'Sample', ['theme' => 'topnav']);

        $cats = $this->navOf((string)file_get_contents($this->outDir . '/Cats/index.html'));
        self::assertStringContainsString('<a href="Paws/">Paws</a>', $cats);
        self::assertStringContainsString('<a class="back" href="../">', $cats);
        self::assertStringNotContainsString('>about</a>', $cats);
        self::assertStringNotContainsString('<aside class="sidebar"', $cats);

        // The root page has sub-pages to link, but no parent to go back to.
        $root = $this->navOf((string)file_get_contents($this->outDir . '/index.html'));
        self::assertStringContainsString('<a href="Cats/">Cats</a>', $root);
        self::assertStringNotContainsString('class="back"', $root);

        // A leaf page has neither, so it gets the back link alone.
        $leaf = $this->navOf((string)file_get_contents($this->outDir . '/Cats/Paws/index.html'));
        self::assertStringStartsWith('<nav class="topnav"', $leaf);
        self::assertStringNotContainsString('<ul>', $leaf);
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
