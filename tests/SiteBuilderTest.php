<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
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
