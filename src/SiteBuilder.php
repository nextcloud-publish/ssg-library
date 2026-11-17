<?php

declare(strict_types=1);

namespace SsgLab;

use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Reads a Collectives-style page export from disk and renders it into a
 * static HTML site. Standalone port of the layout/rendering logic from the
 * Collectives SSG microservice, without the HTTP/payload plumbing —
 * everything is read straight off disk so it can be iterated on locally.
 *
 * Collectives exports are a directory tree: each folder is a page whose
 * content is its `Readme.md`, plain `.md` files sitting next to it are leaf
 * sub-pages, and `.attachments.<id>` directories hold that folder's inline
 * images/files (referenced by Markdown as e.g. `.attachments.123/foo.png`,
 * relative to the folder the `.md` file lives in — not to the rendered
 * page's own output directory).
 *
 * Output mirrors that tree: a folder becomes `<name>/index.html`, a leaf
 * `.md` file becomes `<name>/index.html` one level below its folder. The
 * `$path` threaded through the recursion is the slugs from the site root down
 * to the page being written, which gives both its depth (for relative links)
 * and its place in the page tree the navigation is drawn from.
 *
 * The `theme` render option is one of self::THEMES, named here so the layout
 * it selects can be matched exhaustively rather than branched on:
 *
 * @phpstan-type Theme 'wiki'|'topnav'
 */
class SiteBuilder
{
    /** @var list<string> The render option keys build() accepts. */
    private const RENDER_OPTION_KEYS = ['theme'];

    /** @var list<Theme> Valid `theme` render option values; file names under src/themes/. */
    private const THEMES = ['wiki', 'topnav'];

    /**
     * @var list<Theme> Themes that also have a `src/themes/<theme>.js`, copied to the
     *   output as `layout.js` and linked from every page. Listed rather than probed for,
     *   so a stray file next to a stylesheet can't put itself into a build.
     */
    private const THEME_SCRIPTS = ['wiki'];

    /** Theme used when the `theme` render option isn't given. */
    private const DEFAULT_THEME = 'wiki';

    /** Heading above the `wiki` sidebar's page tree. */
    private const SIDEBAR_HEADING = 'Contents';

    /** Accessible name of the `topnav` bar, which carries no visible heading. */
    private const TOPNAV_LABEL = 'Pages';

    /** `id` the sidebar's heading is referenced by, for its `aria-labelledby`. */
    private const SIDEBAR_HEADING_ID = 'page-tree-heading';

    /** `id` the sidebar is referenced by, for the drawer toggle's `aria-controls`. */
    private const SIDEBAR_ID = 'page-tree';

    private const BACK = '← Back';
    private const NO_CONTENT = 'This page has no content yet.';

    /** sprintf() format string taking the build timestamp. */
    private const FOOTER = 'Generated %s UTC';

    private string $siteTitle = 'Collectives lab';

    /** @var Theme */
    private string $theme = self::DEFAULT_THEME;

    /** The whole site's page tree, scanned in build() before the first page is written. */
    private PageNode $tree;

    public function __construct(
        private MarkdownConverter $markdown = new MarkdownConverter(),
        private InputSanitizer $sanitizer = new InputSanitizer(),
    ) {
    }

    /**
     * @param mixed $renderOptions Per-build rendering options as decoded from
     *   JSON: null, or an object of the keys listed under "Render options" in
     *   the README. Checked before anything is written to $outDir.
     * @return int Number of rendered pages
     * @throws InvalidArgumentException if $renderOptions is not null or an object of supported keys and values
     * @throws RuntimeException if $pagesDir is missing or holds no .md file
     */
    public function build(string $pagesDir, string $outDir, string $siteTitle = 'Collectives lab', mixed $renderOptions = null): int
    {
        // Arguments first: they're the caller's own mistake and cheap to check.
        $theme = $this->parseRenderOptions($renderOptions)['theme'] ?? self::DEFAULT_THEME;

        $pagesDir = rtrim($pagesDir, '/');
        if (!is_dir($pagesDir)) {
            throw new RuntimeException("Pages directory not found: {$pagesDir}");
        }

        $this->siteTitle = $siteTitle;
        $this->theme = $theme;
        // Navigation shows pages other than the one being written, so the
        // whole tree has to be known before the first page is rendered.
        $this->tree = $this->scanTree($pagesDir, '', $siteTitle);

        $this->ensureDir($outDir);
        copy(__DIR__ . '/style.css', $outDir . '/style.css');
        copy(__DIR__ . '/theme.js', $outDir . '/theme.js');
        copy(__DIR__ . "/themes/{$theme}.css", $outDir . '/layout.css');
        if (in_array($theme, self::THEME_SCRIPTS, true)) {
            copy(__DIR__ . "/themes/{$theme}.js", $outDir . '/layout.js');
        }

        $pageCount = $this->renderDirectory($pagesDir, $outDir, [], $siteTitle);
        if ($pageCount === 0) {
            throw new RuntimeException("No .md files found in {$pagesDir}");
        }

        return $pageCount;
    }

    /**
     * Returns the options given in $value, checked; an option that is not given is not in the result.
     * A JSON object is expected as an associative array, i.e. decoded with `assoc: true` — where an
     * empty object is indistinguishable from `[]`, so `[]` counts as an object too.
     *
     * @return array{theme?: Theme}
     * @throws InvalidArgumentException if $value is not null or an object of supported keys and values
     */
    private function parseRenderOptions(mixed $value): array
    {
        $value ??= [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                // stdClass means json_decode() ran without `assoc: true` — a caller-side slip
                // worth naming, since every other non-array is a genuinely wrong value.
                $value instanceof stdClass
                    ? 'render_options must be decoded with assoc: true, got stdClass'
                    : 'render_options must be a JSON object, got ' . get_debug_type($value),
            );
        }
        // A JSON array decodes to a list — and so does the (pathological) object with
        // keys "0", "1", …, which lands here rather than on the unknown-key error below.
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException('render_options must be a JSON object, got a list');
        }

        // Numeric-string keys arrive as ints, hence strval() before comparing names.
        $unknown = array_diff(array_map('strval', array_keys($value)), self::RENDER_OPTION_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown render option: ' . implode(', ', $unknown) . '. Supported: ' . implode(', ', self::RENDER_OPTION_KEYS),
            );
        }

        $options = [];

        if (array_key_exists('theme', $value)) {
            $theme = $value['theme'];
            if (!is_string($theme) || !in_array($theme, self::THEMES, true)) {
                throw new InvalidArgumentException(
                    'Invalid theme: ' . (is_string($theme) ? $theme : get_debug_type($theme)) . '. Valid themes: ' . implode(', ', self::THEMES),
                );
            }
            $options['theme'] = $theme;
        }

        return $options;
    }

    /**
     * Scans one Collectives folder into the page tree the navigation is drawn
     * from, in the order renderDirectory() renders its pages: leaf `.md`
     * sub-pages first, then sub-folders.
     */
    private function scanTree(string $dir, string $slug, string $title): PageNode
    {
        [$subdirs, $leaves] = $this->readEntries($dir);

        $children = [];
        foreach ($leaves as $leaf) {
            // A leaf page has no title of its own beyond the name it renders under.
            $children[] = new PageNode($leaf['slug'], $leaf['slug']);
        }
        foreach ($subdirs as $subdir) {
            $children[] = $this->scanTree($dir . '/' . $subdir, $subdir, $subdir);
        }

        return new PageNode($slug, $title, $children);
    }

    /**
     * Renders one Collectives folder (its own Readme.md, if any, plus its
     * leaf .md siblings and sub-folders) into $outDir, recursing into
     * sub-folders along the way.
     *
     * @param list<string> $path Slugs from the site root down to this folder; empty for the root.
     * @return int Number of pages rendered, including sub-folders
     */
    private function renderDirectory(string $dir, string $outDir, array $path, string $title): int
    {
        $this->ensureDir($outDir);

        foreach (glob($dir . '/.attachments.*', GLOB_ONLYDIR) ?: [] as $attachmentsDir) {
            $this->copyDir($attachmentsDir, $outDir . '/' . basename($attachmentsDir));
        }

        [$subdirs, $leaves, $readme] = $this->readEntries($dir);

        $pageCount = 0;

        foreach ($leaves as $leaf) {
            $slug = $leaf['slug'];
            // Leaf pages render one level below the `.attachments.*` folders
            // holding their images, so those links need an extra `../`.
            $body = $this->rewriteAttachmentLinks($this->renderMarkdownFile($dir . '/' . $leaf['file']));

            $this->writePage($outDir . '/' . $slug, $slug, [...$path, $slug], $this->article($body));
            $pageCount++;
        }

        foreach ($subdirs as $subdir) {
            $pageCount += $this->renderDirectory($dir . '/' . $subdir, $outDir . '/' . $subdir, [...$path, $subdir], $subdir);
        }

        $main = '';
        if ($readme !== null) {
            $main .= $this->article($this->renderMarkdownFile($dir . '/' . $readme));
            $pageCount++;
        }
        if ($main === '') {
            // Sub-pages aren't listed here under either layout — the sidebar
            // and the top bar already link them.
            $main = '<p>' . self::NO_CONTENT . '</p>';
        }

        $this->writePage($outDir, $title, $path, $main);

        return $pageCount;
    }

    /**
     * Splits a folder's entries into sub-folders, leaf `.md` pages and the
     * folder's own `Readme.md`.
     *
     * Everything is matched case-insensitively, `Readme.md` included: an
     * export written on a case-insensitive filesystem can spell it `README.md`
     * just as well, and a folder whose Readme went unrecognised used to render
     * as an empty page with its content dropped on the floor.
     *
     * Skipped on the way: dot-prefixed entries — `.attachments.*` directories
     * are copied verbatim elsewhere, and nothing else hidden belongs in a page
     * tree — and symlinks, which could point at another collective, at a path
     * outside the export entirely, or back at their own parent.
     *
     * @return array{list<string>, list<array{file: string, slug: string}>, ?string}
     *   sub-folder names, leaf pages, and the folder's own Readme file name if it has one
     */
    private function readEntries(string $dir): array
    {
        $entries = scandir($dir) ?: [];
        sort($entries);

        $subdirs = [];
        $leafFiles = [];
        $readme = null;
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $subdirs[] = $entry;
            } elseif (strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'md') === 0) {
                if (strcasecmp($entry, 'Readme.md') === 0) {
                    // Both spellings at once is a broken export either way; the
                    // sort above at least makes which one wins the same everywhere.
                    $readme ??= $entry;
                } else {
                    $leafFiles[] = $entry;
                }
            }
        }

        // Sub-folders claim their slug first: a folder is a page with sub-pages
        // below it, so moving it would move everything underneath it too.
        $taken = array_map('strtolower', $subdirs);
        $leaves = [];
        foreach ($leafFiles as $file) {
            $leaves[] = ['file' => $file, 'slug' => $this->freeSlug(pathinfo($file, PATHINFO_FILENAME), $taken)];
        }

        return [$subdirs, $leaves, $readme];
    }

    /**
     * `$name`, or `$name-2`, `$name-3`, … if it is already spoken for — two
     * pages of one folder would otherwise render into a single output
     * directory, where the second silently overwrites the first.
     *
     * Compared case-insensitively, so a build on a case-insensitive filesystem
     * (where `Cats/` and `cats/` are one directory) lays out the same as it
     * does anywhere else. The slug handed out is added to `$taken`.
     *
     * @param list<string> $taken Lowercased slugs already used in this folder
     */
    private function freeSlug(string $name, array &$taken): string
    {
        $slug = $name;
        for ($n = 2; in_array(strtolower($slug), $taken, true); $n++) {
            $slug = $name . '-' . $n;
        }
        $taken[] = strtolower($slug);

        return $slug;
    }

    private function renderMarkdownFile(string $path): string
    {
        return $this->markdown->toHtml(
            $this->sanitizer->sanitize((string)file_get_contents($path)),
        );
    }

    private function rewriteAttachmentLinks(string $html): string
    {
        return preg_replace(
            '/(href|src)="(\.attachments\.[^"]*)"/',
            '$1="../$2"',
            $html,
        ) ?? $html;
    }

    private function article(string $html): string
    {
        return '<article class="page"><div class="content">' . $html . '</div></article>';
    }

    /**
     * The `wiki` layout's navigation: the whole page tree, nested under the
     * site root, with the page being written marked as the current one.
     *
     * The scrim in front of it is the drawer's: on a narrow screen the
     * sidebar slides over the page, and the scrim is both what dims the page
     * underneath and what closes the drawer when it is clicked. It is inert
     * under the wide layout, where `layout.css` leaves it `display: none`.
     *
     * @param list<string> $current Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function sidebar(array $current, string $prefix): string
    {
        // The heading names the navigation already — pointing at it beats
        // repeating the same word in an aria-label for screen readers.
        return '<div class="nav-scrim"></div>'
            . '<aside class="sidebar" id="' . self::SIDEBAR_ID . '">'
            . '<nav class="page-tree" aria-labelledby="' . self::SIDEBAR_HEADING_ID . '">'
            . '<h2 id="' . self::SIDEBAR_HEADING_ID . '">' . self::SIDEBAR_HEADING . '</h2>'
            . '<ul>' . $this->treeItem($this->tree, [], $current, $prefix) . '</ul>'
            . '</nav></aside>';
    }

    /**
     * The `wiki` drawer's toggle, for the site header — the one part of the
     * page a drawer sliding over it doesn't cover, so the button that opened
     * it can also close it.
     *
     * Ships `hidden`, like the light/dark toggle: `layout.js` is the only
     * thing that can make it do anything, and a page without it shows the
     * tree outright rather than behind a button that wouldn't work.
     *
     * The name stays "Contents" whether the drawer is open or shut — it names
     * what the button controls, and `aria-expanded` says which way it stands.
     */
    private function navToggle(): string
    {
        $label = self::SIDEBAR_HEADING;
        $controls = self::SIDEBAR_ID;

        return <<<HTML
<button type="button" class="nav-toggle" hidden aria-expanded="false" aria-controls="{$controls}" aria-label="{$label}">
                <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                    <line x1="3.5" y1="6" x2="16.5" y2="6"></line>
                    <line x1="3.5" y1="10" x2="16.5" y2="10"></line>
                    <line x1="3.5" y1="14" x2="16.5" y2="14"></line>
                </svg>
            </button>
HTML;
    }

    /**
     * One page tree item, with its sub-pages nested below it.
     *
     * @param list<string> $path Slugs from the site root down to $node; empty for the root
     * @param list<string> $current Slugs down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function treeItem(PageNode $node, array $path, array $current, string $prefix): string
    {
        $marker = $path === $current ? ' aria-current="page"' : '';

        $html = '<li><a href="' . $this->e($this->url($path, $prefix)) . '"' . $marker . '>' . $this->e($node->title) . '</a>';
        if ($node->children !== []) {
            $html .= '<ul>';
            foreach ($node->children as $child) {
                $html .= $this->treeItem($child, [...$path, $child->slug], $current, $prefix);
            }
            $html .= '</ul>';
        }

        return $html . '</li>';
    }

    /**
     * The `topnav` layout's navigation: one level only — the current page's
     * own sub-pages, which sit directly below it in the output — plus the back
     * link up to its parent.
     *
     * @param list<string> $current Slugs from the site root down to the page being written
     * @param string $back The back link, empty on the root page
     */
    private function topNav(array $current, string $back): string
    {
        $items = '';
        foreach ($this->childrenAt($current) as $child) {
            // A child sits directly below the current page, so its URL is
            // relative to that page rather than to the site root.
            $href = $this->url([$child->slug], '');
            $items .= '<li><a href="' . $this->e($href) . '">' . $this->e($child->title) . '</a></li>';
        }

        if ($items === '' && $back === '') {
            return '';
        }

        return '<nav class="topnav" aria-label="' . self::TOPNAV_LABEL . '">'
            . ($items === '' ? '' : '<ul>' . $items . '</ul>')
            . $back
            . '</nav>';
    }

    /**
     * The URL of the page `$path` leads to, as linked from a page `$prefix`
     * levels below the site root.
     *
     * Slugs are directory names straight off disk, so they are percent-encoded
     * on the way in: a `#` or `?` in a page name would otherwise cut the URL
     * short at a fragment or a query string, and a page named `javascript:…`
     * would turn a relative link into a scheme of its own.
     *
     * @param list<string> $path Slugs from the site root down; empty for the root itself
     * @param string $prefix `../` per level between the linking page and the site root
     */
    private function url(array $path, string $prefix): string
    {
        // At the site root both parts are empty, where an href would come out
        // empty too — pointing at the page doing the linking.
        if ($path === []) {
            return $prefix === '' ? './' : $prefix;
        }

        return $prefix . implode('/', array_map('rawurlencode', $path)) . '/';
    }

    /**
     * The sub-pages of the page $path leads to. Empty if it has none — or if
     * the path leads nowhere, which can't happen for a page the recursion is
     * writing, since the tree was scanned off the same directories.
     *
     * @param list<string> $path Slugs from the site root down
     * @return list<PageNode>
     */
    private function childrenAt(array $path): array
    {
        $children = $this->tree->children;
        foreach ($path as $slug) {
            foreach ($children as $child) {
                if ($child->slug === $slug) {
                    // Found this level's page: carry on down from its own children.
                    $children = $child->children;
                    continue 2;
                }
            }

            return [];
        }

        return $children;
    }

    /**
     * @param list<string> $path Slugs from the site root down to this page; empty for the root
     */
    private function writePage(string $outDir, string $title, array $path, string $main): void
    {
        $this->ensureDir($outDir);

        $depth = count($path);
        $title = $this->e($title);
        $siteTitle = $this->e($this->siteTitle);
        $prefix = str_repeat('../', $depth);
        $root = $this->url([], $prefix);
        $layoutStyles = $prefix . 'layout.css';
        $stylesheet = $prefix . 'style.css';
        $themeScript = $prefix . 'theme.js';
        $layoutScript = in_array($this->theme, self::THEME_SCRIPTS, true)
            ? '<script src="' . $prefix . 'layout.js"></script>'
            : '';
        $footer = sprintf(self::FOOTER, gmdate('Y-m-d H:i:s'));
        // Every page except the root sits one level under its parent index.
        $back = $depth > 0 ? '<a class="back" href="../">' . self::BACK . '</a>' : '';
        // Each layout carries its navigation differently, and the back link
        // with it: `topnav` puts both in the bar below the site header, `wiki`
        // keeps the link next to the page title, the tree in the sidebar and a
        // toggle for it in the header. Listed rather than branched on, so a
        // third theme can't quietly inherit whichever layout happens to be the
        // `else`.
        [$nav, $navToggle, $titleBack] = match ($this->theme) {
            'wiki' => [$this->sidebar($path, $prefix), $this->navToggle(), $back],
            'topnav' => [$this->topNav($path, $back), '', ''],
        };

        $html = <<<HTML
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="generator" content="ssg-lab">
        <title>{$title}</title>
        <link rel="stylesheet" href="{$stylesheet}">
        <link rel="stylesheet" href="{$layoutStyles}">
        <!-- Blocking on purpose: the state the stylesheets key off is set before the first paint. -->
        <script src="{$themeScript}"></script>
        {$layoutScript}
    </head>
    <body>
        <header class="site-header">
            {$navToggle}
            <a href="{$root}">{$siteTitle}</a>
            <button type="button" class="theme-toggle" hidden aria-label="Toggle light/dark theme">
                <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true">
                    <circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"></circle>
                    <path class="disc-fill" d="M10 2a8 8 0 0 1 0 16z" fill="currentColor"></path>
                </svg>
            </button>
        </header>
        {$nav}
        <main>
            <div class="page-header">
                <h1 class="page-title">{$title}</h1>
                {$titleBack}
            </div>
            {$main}
        </main>
        <footer>
            {$footer}
        </footer>
    </body>
</html>
HTML;

        file_put_contents($outDir . '/index.html', $html);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create output directory: {$dir}");
        }
    }

    /**
     * Copies an attachments folder into the output verbatim, symlinks aside:
     * copy() reads through them, so a link pointing out of the export would
     * publish whatever it finds at the other end as a file of the site's own.
     */
    private function copyDir(string $from, string $to): void
    {
        if (is_link($from)) {
            return;
        }

        $this->ensureDir($to);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from . '/' . $entry;
            $dest = $to . '/' . $entry;
            if (is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                $this->copyDir($source, $dest);
            } else {
                copy($source, $dest);
            }
        }
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
