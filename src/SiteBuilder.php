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
 * Only regular files and real directories of the export are read; symlinks, FIFOs, devices
 * and sockets are ignored, so nothing outside the pages directory is read or published.
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

    /** Accessible name of the `topnav` layout's primary bar — the current page and its siblings. */
    private const TOPNAV_LABEL = 'Pages';

    /** Accessible name of the `topnav` layout's secondary bar — the current page's own children. */
    private const SUBNAV_LABEL = 'Section pages';

    /** `id` the sidebar's heading is referenced by, for its `aria-labelledby`. */
    private const SIDEBAR_HEADING_ID = 'page-tree-heading';

    /** `id` the sidebar is referenced by, for the drawer toggle's `aria-controls`. */
    private const SIDEBAR_ID = 'page-tree';

    private const NO_CONTENT = 'This page has no content yet.';

    /** Label the "Up" link above a page's title uses, next to its arrow. */
    private const UP_LABEL = 'Up';

    /** Labels either side of the prev/next page links below a page's content. */
    private const PREV_LABEL = 'Prev';
    private const NEXT_LABEL = 'Next';

    /** sprintf() format string taking the build timestamp. */
    private const FOOTER = 'Generated %s UTC';

    /**
     * File extensions, lower-case, of the attachments copied to the output; any other file is
     * left out. Everything in the output is served from the published origin, so an attachment
     * a browser renders as a document or runs as script (HTML, SVG, JavaScript…) would run with
     * that origin's privileges. Only types browsers don't execute are listed. SVG is left out
     * until it can be sanitized.
     *
     * @var list<string>
     */
    private const ATTACHMENT_EXTENSIONS = [
        // Images: NonImageEmbedRewriter::IMAGE_EXTENSIONS without svg and svgz.
        'apng', 'avif', 'bmp', 'gif', 'heic', 'heif', 'ico', 'jfif', 'jpe', 'jpeg', 'jpg', 'png',
        'tif', 'tiff', 'webp',
        // Documents
        'pdf', 'txt', 'csv', 'md', 'rtf', 'epub', 'odt', 'ods', 'odp', 'odg', 'doc', 'docx', 'xls',
        'xlsx', 'ppt', 'pptx',
        // Audio and video
        'mp3', 'm4a', 'oga', 'ogg', 'opus', 'wav', 'flac', 'mp4', 'm4v', 'mov', 'ogv', 'webm',
        // Archives
        'zip', 'gz', 'tgz', 'tar', '7z',
    ];

    private string $siteTitle = 'Collectives lab';

    /** @var Theme */
    private string $theme = self::DEFAULT_THEME;

    /** The whole site's page tree, scanned in build() before the first page is written. */
    private PageNode $tree;

    /**
     * The tree flattened into reading order — pre-order, root first then each
     * child in turn, the same order renderDirectory() writes pages in — so a
     * page's prev/next neighbour is just its index's neighbour. Built once in
     * build() alongside $tree, for the same reason: a page's neighbours
     * aren't known from its own folder alone.
     *
     * @var list<array{path: list<string>, title: string}>
     */
    private array $flatSequence = [];

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
        $this->flatSequence = [];
        $this->flattenTree($this->tree, [], $this->flatSequence);

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
            // GLOB_ONLYDIR also matches symlinks to directories.
            if ($this->isPlainDir($attachmentsDir)) {
                $this->copyDir($attachmentsDir, $outDir . '/' . basename($attachmentsDir));
            }
        }

        [$subdirs, $leaves, $readme] = $this->readEntries($dir);

        $pageCount = 0;

        foreach ($leaves as $leaf) {
            $slug = $leaf['slug'];
            // Leaf pages render one level below the `.attachments.*` folders
            // holding their images, so those links need an extra `../`.
            $body = $this->renderMarkdownFile($dir . '/' . $leaf['file'], '../');

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
     * folder's own `Readme.md`; `.attachments.*` directories are copied
     * separately by copyDir().
     *
     * Everything is matched case-insensitively, `Readme.md` included: an
     * export written on a case-insensitive filesystem can spell it `README.md`
     * just as well, and a folder whose Readme went unrecognised used to render
     * as an empty page with its content dropped on the floor.
     *
     * Skipped on the way: dot-prefixed entries — `.attachments.*` directories
     * are copied verbatim elsewhere, and nothing else hidden belongs in a page
     * tree — and anything that is not a regular file or a real directory:
     * a symlink could point at another collective, at a path outside the
     * export entirely, or back at their own parent.
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
            if ($this->isPlainDir($path)) {
                $subdirs[] = $entry;
            } elseif ($this->isPlainFile($path) && strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'md') === 0) {
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

    private function renderMarkdownFile(string $path, string $attachmentPrefix = ''): string
    {
        return $this->markdown->toHtml(
            $this->sanitizer->sanitize((string)file_get_contents($path)),
            $attachmentPrefix,
        );
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
     * The `topnav` layout's navigation, never an empty bar: ordinarily two,
     * a primary one carrying the current page's siblings (pages sharing its
     * own parent), with the current page itself marked `aria-current="page"`,
     * and a secondary one below it carrying the current page's own children.
     * The way back up a level is the "Up" button, left of whichever bar
     * carries the current page's own siblings — primary ordinarily, secondary
     * at the deepest level (see below) — rather than either bar's own links.
     *
     * At the deepest level a page has no children to put in the secondary
     * bar, so the pair shifts up one level instead: the current page joins
     * its siblings in the secondary bar, highlighted there, and the primary
     * bar above becomes its parent's own siblings, the parent highlighted.
     * The root page has no parent and so no siblings — nothing for the
     * primary bar to show — in which case its own children take its place:
     * the one level the landing page has to show reads as the one navbar it
     * gets, nothing in it highlighted, since the root isn't among its own
     * children, and no "Up" button, since it has nowhere to go up to. One
     * level down, where the deepest case would promote the root itself —
     * which has no siblings of its own — there's no parent level to shift up
     * to either, so that page's own siblings stay in the primary bar instead
     * of moving down, the same as any page with children of its own.
     *
     * @param list<string> $path Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function topNav(array $path, string $prefix): string
    {
        $up = $this->upButton($path, $prefix);

        if ($path === []) {
            $ownChildren = $this->navList($this->childrenAt($path), $path, $prefix, null);

            return $ownChildren === '' ? '' : $this->navBar('topnav', self::TOPNAV_LABEL, '', $ownChildren);
        }

        $parentPath = array_slice($path, 0, -1);
        $children = $this->childrenAt($path);
        // The current page's own siblings, it among them — every case below
        // shows this list, the only question being which of the two bars.
        $siblings = $this->navList(
            $this->childrenAt($parentPath),
            $parentPath,
            $prefix,
            $path[array_key_last($path)],
        );

        if ($children !== []) {
            return $this->navBar('topnav', self::TOPNAV_LABEL, $up, $siblings)
                . $this->navBar('subnav', self::SUBNAV_LABEL, '', $this->navList($children, $path, $prefix, null));
        }

        // No children of its own: the pair shifts up a level, the siblings
        // moving down to the secondary bar — unless the level above is the
        // root, which has no siblings to promote in their place.
        if ($parentPath === []) {
            return $this->navBar('topnav', self::TOPNAV_LABEL, $up, $siblings);
        }

        $grandparentPath = array_slice($parentPath, 0, -1);
        $parentSiblings = $this->navList(
            $this->childrenAt($grandparentPath),
            $grandparentPath,
            $prefix,
            $parentPath[array_key_last($parentPath)],
        );

        return $this->navBar('topnav', self::TOPNAV_LABEL, $up, $parentSiblings)
            . $this->navBar('subnav', self::SUBNAV_LABEL, '', $siblings);
    }

    /** Wraps a non-empty `<li>` list $items, with $before next to it, into a `<nav>`, as either bar of the `topnav` layout. */
    private function navBar(string $class, string $label, string $before, string $items): string
    {
        return '<nav class="' . $class . '" aria-label="' . $label . '">' . $before . '<ul>' . $items . '</ul></nav>';
    }

    /**
     * The `topnav` layout's "Up" button, left of the current page's siblings
     * in whichever bar carries them — just an arrow, rather than the "Up"
     * link `wiki` keeps above the page's title, since here it sits right next
     * to the page links it means "up" relative to. Empty on the root page,
     * which has no parent to go up to.
     *
     * @param list<string> $path Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function upButton(array $path, string $prefix): string
    {
        $href = $this->parentHref($path, $prefix);

        return $href === null
            ? ''
            : '<a class="topnav-up" href="' . $href . '" aria-label="' . self::UP_LABEL . '">↑</a>';
    }

    /**
     * The escaped href of $path's parent page, ready for an attribute — or
     * null on the root page, which has no parent, and so gets no way up under
     * either layout.
     *
     * @param list<string> $path Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function parentHref(array $path, string $prefix): ?string
    {
        if ($path === []) {
            return null;
        }

        return $this->e($this->url(array_slice($path, 0, -1), $prefix));
    }

    /**
     * The `<li>`s linking $children, each as a page `$prefix` levels below
     * the site root reaches it: $basePath plus the child's own slug. The
     * child whose slug is $currentSlug is marked `aria-current="page"`;
     * null if none of them is the page being written.
     *
     * @param list<PageNode> $children
     * @param list<string> $basePath Slugs from the site root down to $children's shared parent
     * @param string $prefix `../` per level between the linking page and the site root
     */
    private function navList(array $children, array $basePath, string $prefix, ?string $currentSlug): string
    {
        $items = '';
        foreach ($children as $child) {
            $marker = $child->slug === $currentSlug ? ' aria-current="page"' : '';
            $href = $this->url([...$basePath, $child->slug], $prefix);
            $items .= '<li><a href="' . $this->e($href) . '"' . $marker . '>' . $this->e($child->title) . '</a></li>';
        }

        return $items;
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
     * Flattens $node into $out in reading order: the node itself, then each of
     * its children in turn, recursively — the same order renderDirectory()
     * writes pages in, since both walk $node->children left to right.
     *
     * @param list<string> $path Slugs from the site root down to $node; empty for the root
     * @param list<array{path: list<string>, title: string}> $out
     */
    private function flattenTree(PageNode $node, array $path, array &$out): void
    {
        $out[] = ['path' => $path, 'title' => $node->title];
        foreach ($node->children as $child) {
            $this->flattenTree($child, [...$path, $child->slug], $out);
        }
    }

    /**
     * The `wiki` layout's "Up" link above a page's title: a link to the
     * immediate parent page, with an arrow — see upButton() for `topnav`'s
     * own, folded into its navbar instead. Empty on the root page, which has
     * no parent to go up to.
     *
     * @param list<string> $path Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function upLink(array $path, string $prefix): string
    {
        $href = $this->parentHref($path, $prefix);

        return $href === null
            ? ''
            : '<a class="page-up" href="' . $href . '"><span aria-hidden="true">↑</span> ' . self::UP_LABEL . '</a>';
    }

    /**
     * The prev/next links below a page's content, shared by both layouts, in
     * the reading order $flatSequence was built in. Empty for a page with
     * neither neighbour, which can only be the root of a site with no other
     * pages at all.
     *
     * @param list<string> $path Slugs from the site root down to the page being written
     * @param string $prefix `../` per level between that page and the site root
     */
    private function prevNext(array $path, string $prefix): string
    {
        $index = null;
        foreach ($this->flatSequence as $i => $entry) {
            if ($entry['path'] === $path) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return '';
        }

        $prev = $index > 0 ? $this->flatSequence[$index - 1] : null;
        $next = $this->flatSequence[$index + 1] ?? null;
        if ($prev === null && $next === null) {
            return '';
        }

        $prevHtml = $prev !== null
            ? '<a class="page-nav-link page-nav-prev" href="' . $this->e($this->url($prev['path'], $prefix)) . '">'
                . '<span class="page-nav-label">' . self::PREV_LABEL . '</span>'
                . '<span class="page-nav-title">← ' . $this->e($prev['title']) . '</span></a>'
            : '<span></span>';
        $nextHtml = $next !== null
            ? '<a class="page-nav-link page-nav-next" href="' . $this->e($this->url($next['path'], $prefix)) . '">'
                . '<span class="page-nav-label">' . self::NEXT_LABEL . '</span>'
                . '<span class="page-nav-title">' . $this->e($next['title']) . ' →</span></a>'
            : '<span></span>';

        return '<nav class="page-nav" aria-label="Page navigation">' . $prevHtml . $nextHtml . '</nav>';
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
        $footerText = sprintf(self::FOOTER, gmdate('Y-m-d H:i:s'));
        // The generated-at stamp, the dividers around the content, and the
        // prev/next links below it are the same under both layouts; only how
        // the rest of the site is reached differs — the whole tree in
        // `wiki`'s sidebar, the current page's own children in `topnav`'s
        // bar — and with it the way back up a level: `wiki` keeps its "Up"
        // link above the title, `topnav` folds its own into the bar, beside
        // the page links it sits above. Listed rather than branched on, so a
        // third theme can't quietly inherit whichever layout happens to be
        // the `else`.
        [$nav, $navToggle, $upAboveTitle] = match ($this->theme) {
            'wiki' => [$this->sidebar($path, $prefix), $this->navToggle(), $this->upLink($path, $prefix)],
            'topnav' => [$this->topNav($path, $prefix), '', ''],
        };
        $pageHeaderInner = $upAboveTitle . '<h1 class="page-title">' . $title . '</h1>';
        $afterHeader = '<p class="page-meta">' . $footerText . '</p>'
            . '<hr class="page-divider">'
            . $main
            . '<hr class="page-divider">'
            . $this->prevNext($path, $prefix);

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
                {$pageHeaderInner}
            </div>
            {$afterHeader}
        </main>
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
     * Copies an `.attachments.*` folder, sub-folders included, but only the files
     * isPublishableAttachment() accepts; dot-entries are skipped at every level, files and
     * folders alike, and so is anything that is not a regular file or a real directory —
     * a symlink pointing out of the export would otherwise publish whatever it finds at
     * the other end as a file of the site's own. Skipped files are left out silently, so
     * the build goes on.
     */
    private function copyDir(string $from, string $to): void
    {
        if (is_link($from)) {
            return;
        }

        $this->ensureDir($to);
        foreach (scandir($from) ?: [] as $entry) {
            // Also covers `.` and `..`.
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $source = $from . '/' . $entry;
            $dest = $to . '/' . $entry;
            if ($this->isPlainDir($source)) {
                $this->copyDir($source, $dest);
            } elseif ($this->isPlainFile($source) && $this->isPublishableAttachment($entry)) {
                copy($source, $dest);
            }
        }
    }

    /**
     * A regular file, not a symlink to one: is_link() looks at the entry itself, so once it is
     * false, is_file() does too. Every path below the pages directory is built from scandir()
     * names without `.` and `..`, so with no link followed nothing outside it is ever reached.
     */
    private function isPlainFile(string $path): bool
    {
        return !is_link($path) && is_file($path);
    }

    /** A real directory, not a symlink to one; see isPlainFile(). */
    private function isPlainDir(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }

    /**
     * Whether an attachment file may be published: not a dotfile, and its last extension, in
     * any case, is in self::ATTACHMENT_EXTENSIONS. A file without an extension is not.
     */
    private function isPublishableAttachment(string $name): bool
    {
        return !str_starts_with($name, '.')
            && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ATTACHMENT_EXTENSIONS, true);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
