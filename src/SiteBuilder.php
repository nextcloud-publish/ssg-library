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
 * `.md` file becomes `<name>/index.html` one level below its folder. `$depth`
 * threaded through the recursion is how many directories deep the page being
 * written sits, which is all that's needed to build its relative links.
 */
class SiteBuilder {
	/** @var list<string> The render option keys build() accepts. */
	private const RENDER_OPTION_KEYS = ['theme'];

	/** @var list<string> Valid `theme` render option values; file names under src/themes/. */
	private const THEMES = ['muted', 'colorful'];

	/** Theme used when the `theme` render option isn't given. */
	private const DEFAULT_THEME = 'muted';

	/**
	 * Static UI chrome text, varied a bit per theme — `colorful` reads more
	 * playful, `muted` stays plain. Keyed by self::THEMES value, then by copy
	 * key; `footer` is an sprintf() format string taking the timestamp.
	 *
	 * @var array<string, array{back: string, noContent: string, subpagesHeading: string, footer: string}>
	 */
	private const COPY = [
		'muted' => [
			'back' => '← Back',
			'noContent' => 'This page has no content yet.',
			'subpagesHeading' => 'Pages',
			'footer' => 'Generated %s UTC',
		],
		'colorful' => [
			'back' => '← Back for more',
			'noContent' => "Nothing here yet — this page is still blank. Go write something!",
			'subpagesHeading' => 'Explore',
			'footer' => 'Freshly generated %s UTC',
		],
	];

	private string $siteTitle = 'Collectives lab';
	private string $theme = self::DEFAULT_THEME;

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
	public function build(string $pagesDir, string $outDir, string $siteTitle = 'Collectives lab', mixed $renderOptions = null): int {
		// Arguments first: they're the caller's own mistake and cheap to check.
		$theme = $this->parseRenderOptions($renderOptions)['theme'] ?? self::DEFAULT_THEME;

		$pagesDir = rtrim($pagesDir, '/');
		if (!is_dir($pagesDir)) {
			throw new RuntimeException("Pages directory not found: {$pagesDir}");
		}

		$this->siteTitle = $siteTitle;
		$this->theme = $theme;
		$this->ensureDir($outDir);
		copy(__DIR__ . '/style.css', $outDir . '/style.css');
		copy(__DIR__ . '/theme.js', $outDir . '/theme.js');
		copy(__DIR__ . "/themes/{$theme}.css", $outDir . '/palette.css');

		$pageCount = $this->renderDirectory($pagesDir, $outDir, 0, $siteTitle);
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
	 * @return array{theme?: string}
	 * @throws InvalidArgumentException if $value is not null or an object of supported keys and values
	 */
	private function parseRenderOptions(mixed $value): array {
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
	 * Renders one Collectives folder (its own Readme.md, if any, plus its
	 * leaf .md siblings and sub-folders) into $outDir, recursing into
	 * sub-folders along the way.
	 *
	 * @return int Number of pages rendered, including sub-folders
	 */
	private function renderDirectory(string $dir, string $outDir, int $depth, string $title): int {
		$this->ensureDir($outDir);

		foreach (glob($dir . '/.attachments.*', GLOB_ONLYDIR) ?: [] as $attachmentsDir) {
			$this->copyDir($attachmentsDir, $outDir . '/' . basename($attachmentsDir));
		}

		[$subdirs, $leafFiles] = $this->readEntries($dir);

		$pageCount = 0;
		$children = [];

		foreach ($leafFiles as $file) {
			$slug = basename($file, '.md');
			// Leaf pages render one level below the `.attachments.*` folders
			// holding their images, so those links need an extra `../`.
			$body = $this->rewriteAttachmentLinks($this->renderMarkdownFile($dir . '/' . $file));

			$this->writePage($outDir . '/' . $slug, $slug, $depth + 1, $this->article($body));
			$pageCount++;
			$children[] = $slug;
		}

		foreach ($subdirs as $subdir) {
			$pageCount += $this->renderDirectory($dir . '/' . $subdir, $outDir . '/' . $subdir, $depth + 1, $subdir);
			$children[] = $subdir;
		}

		$main = '';
		$readmePath = $dir . '/Readme.md';
		if (is_file($readmePath)) {
			$main .= $this->article($this->renderMarkdownFile($readmePath));
			$pageCount++;
		}
		$main .= $this->subPageList($children);
		if ($main === '') {
			$main = '<p>' . $this->copy('noContent') . '</p>';
		}

		$this->writePage($outDir, $title, $depth, $main);

		return $pageCount;
	}

	/**
	 * Splits a folder's entries into sub-folders and leaf `.md` files,
	 * skipping `Readme.md` (rendered as the folder's own page) and
	 * `.attachments.*` directories (copied verbatim).
	 *
	 * @return array{list<string>, list<string>} sub-folder names, leaf file names
	 */
	private function readEntries(string $dir): array {
		$entries = scandir($dir) ?: [];
		sort($entries);

		$subdirs = [];
		$leafFiles = [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.attachments.')) {
				continue;
			}
			if (is_dir($dir . '/' . $entry)) {
				$subdirs[] = $entry;
			} elseif (strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'md') === 0 && strcasecmp($entry, 'Readme.md') !== 0) {
				$leafFiles[] = $entry;
			}
		}

		return [$subdirs, $leafFiles];
	}

	private function renderMarkdownFile(string $path): string {
		return $this->markdown->toHtml(
			$this->sanitizer->sanitize((string)file_get_contents($path)),
		);
	}

	private function rewriteAttachmentLinks(string $html): string {
		return preg_replace(
			'/(href|src)="(\.attachments\.[^"]*)"/',
			'$1="../$2"',
			$html,
		) ?? $html;
	}

	private function article(string $html): string {
		return '<article class="page"><div class="content">' . $html . '</div></article>';
	}

	/**
	 * @param list<string> $children Sub-page names, linked as `<name>/`
	 */
	private function subPageList(array $children): string {
		if ($children === []) {
			return '';
		}

		$items = '';
		foreach ($children as $child) {
			$name = $this->e($child);
			$items .= '<li><a href="' . $name . '/">' . $name . '</a></li>';
		}

		return '<nav class="subpages"><h2>' . $this->copy('subpagesHeading') . '</h2><ul>' . $items . '</ul></nav>';
	}

	private function copy(string $key): string {
		return self::COPY[$this->theme][$key];
	}

	private function writePage(string $outDir, string $title, int $depth, string $main): void {
		$this->ensureDir($outDir);

		$title = $this->e($title);
		$siteTitle = $this->e($this->siteTitle);
		$root = $depth === 0 ? './' : str_repeat('../', $depth);
		$palette = str_repeat('../', $depth) . 'palette.css';
		$stylesheet = str_repeat('../', $depth) . 'style.css';
		$themeScript = str_repeat('../', $depth) . 'theme.js';
		$footer = sprintf($this->copy('footer'), gmdate('Y-m-d H:i:s'));
		// Every page except the root sits one level under its parent index.
		$back = $depth > 0 ? '<a class="back" href="../">' . $this->copy('back') . '</a>' : '';

		$html = <<<HTML
<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="generator" content="ssg-lab">
		<title>{$title}</title>
		<link rel="stylesheet" href="{$palette}">
		<link rel="stylesheet" href="{$stylesheet}">
		<!-- Blocking on purpose: sets the theme before the first paint. -->
		<script src="{$themeScript}"></script>
	</head>
	<body>
		<header class="site-header">
			<a href="{$root}">{$siteTitle}</a>
			<button type="button" class="theme-toggle" hidden aria-label="Toggle light/dark theme">
				<svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true">
					<circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"></circle>
					<path class="disc-fill" d="M10 2a8 8 0 0 1 0 16z" fill="currentColor"></path>
				</svg>
			</button>
		</header>
		<main>
			<div class="page-header">
				<h1 class="page-title">{$title}</h1>
				{$back}
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

	private function ensureDir(string $dir): void {
		if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
			throw new RuntimeException("Could not create output directory: {$dir}");
		}
	}

	private function copyDir(string $from, string $to): void {
		$this->ensureDir($to);
		foreach (scandir($from) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$source = $from . '/' . $entry;
			$dest = $to . '/' . $entry;
			if (is_dir($source)) {
				$this->copyDir($source, $dest);
			} else {
				copy($source, $dest);
			}
		}
	}

	private function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
