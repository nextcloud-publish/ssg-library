<?php

declare(strict_types=1);

namespace SsgLab;

use RuntimeException;

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
	private string $siteTitle = 'Collectives lab';

	public function __construct(
		private MarkdownConverter $markdown = new MarkdownConverter(),
		private InputSanitizer $sanitizer = new InputSanitizer(),
	) {
	}

	/**
	 * @return int Number of rendered pages
	 */
	public function build(string $pagesDir, string $outDir, string $siteTitle = 'Collectives lab'): int {
		$pagesDir = rtrim($pagesDir, '/');
		if (!is_dir($pagesDir)) {
			throw new RuntimeException("Pages directory not found: {$pagesDir}");
		}

		$this->siteTitle = $siteTitle;
		$this->ensureDir($outDir);
		copy(__DIR__ . '/style.css', $outDir . '/style.css');
		copy(__DIR__ . '/theme.js', $outDir . '/theme.js');

		$pageCount = $this->renderDirectory($pagesDir, $outDir, 0, $siteTitle);
		if ($pageCount === 0) {
			throw new RuntimeException("No .md files found in {$pagesDir}");
		}

		return $pageCount;
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
			$main = '<p>This page has no content yet.</p>';
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

		return '<nav class="subpages"><h2>Pages</h2><ul>' . $items . '</ul></nav>';
	}

	private function writePage(string $outDir, string $title, int $depth, string $main): void {
		$this->ensureDir($outDir);

		$title = $this->e($title);
		$siteTitle = $this->e($this->siteTitle);
		$root = $depth === 0 ? './' : str_repeat('../', $depth);
		$stylesheet = str_repeat('../', $depth) . 'style.css';
		$themeScript = str_repeat('../', $depth) . 'theme.js';
		$timestamp = gmdate('Y-m-d H:i:s');
		// Every page except the root sits one level under its parent index.
		$back = $depth > 0 ? '<a class="back" href="../">← Back</a>' : '';

		$html = <<<HTML
<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="generator" content="ssg-lab">
		<title>{$title}</title>
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
			Generated {$timestamp} UTC
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
