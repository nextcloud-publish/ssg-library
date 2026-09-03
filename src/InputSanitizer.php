<?php

declare(strict_types=1);

namespace SsgLab;

/**
 * Lightweight cleanup of raw Markdown source read off disk, applied before
 * it reaches MarkdownConverter/SiteBuilder.
 */
class InputSanitizer {
	/**
	 * Strips a leading UTF-8 BOM, drops null bytes, normalizes line endings
	 * to \n, and discards invalid UTF-8 byte sequences.
	 */
	public function sanitize(string $markdown): string {
		$markdown = str_replace("\xEF\xBB\xBF", '', $markdown);
		$markdown = str_replace("\0", '', $markdown);
		$markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

		if (!mb_check_encoding($markdown, 'UTF-8')) {
			$markdown = mb_convert_encoding($markdown, 'UTF-8', 'UTF-8');
		}

		return $markdown;
	}
}
