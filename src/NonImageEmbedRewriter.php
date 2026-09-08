<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;

/**
 * Turns image embeds that don't point at an image into plain links.
 *
 * Nextcloud Text writes every attachment into the Markdown as an image
 * embed regardless of its type, so a Collectives export contains things
 * like `![my-document.pdf](.attachments.13283766/my-document.pdf)`.
 * As an `<img>` that can only ever render as a broken image, so it becomes
 * `<a href="…">my-document.pdf</a>` — the download the author meant.
 *
 * Runs on the parsed document rather than the Markdown source so image
 * syntax inside code spans and fenced blocks is left alone.
 */
class NonImageEmbedRewriter {
	private const IMAGE_EXTENSIONS = [
		'apng', 'avif', 'bmp', 'gif', 'heic', 'heif', 'ico', 'jfif', 'jpe',
		'jpeg', 'jpg', 'png', 'svg', 'svgz', 'tif', 'tiff', 'webp',
	];

	public function __invoke(DocumentParsedEvent $event): void {
		// Collected up front: replacing nodes rewires the sibling links the
		// iterator walks.
		$embeds = [];
		foreach ($event->getDocument()->iterator() as $node) {
			if ($node instanceof Image && !self::pointsAtImage($node->getUrl())) {
				$embeds[] = $node;
			}
		}

		foreach ($embeds as $embed) {
			self::replaceWithLink($embed);
		}
	}

	private static function replaceWithLink(Image $image): void {
		$link = new Link($image->getUrl(), null, $image->getTitle());

		// An image's children are its alt text, which reads the same as link
		// text. Empty alt (`![](…/report.pdf)`) would leave an unclickable
		// link, so fall back to the file name.
		$label = $image->children();
		if ($label === []) {
			$label = [new Text(self::fileName($image->getUrl()))];
		}
		$link->replaceChildren($label);

		$image->replaceWith($link);
	}

	private static function pointsAtImage(string $url): bool {
		// Inline images carry their type in the URI itself, and their payload
		// can look like anything — never judge those by extension.
		if (str_starts_with(strtolower($url), 'data:')) {
			return true;
		}

		$extension = strtolower(pathinfo(self::path($url), PATHINFO_EXTENSION));

		// Nothing that looks like a file extension to go on — an
		// image-serving endpoint, say — so leave it as an image rather than
		// guessing.
		if (preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1) {
			return true;
		}

		return in_array($extension, self::IMAGE_EXTENSIONS, true);
	}

	/**
	 * Only ever called for a URL that ended in a file extension, so the file
	 * name is never empty.
	 */
	private static function fileName(string $url): string {
		return rawurldecode(basename(self::path($url)));
	}

	/**
	 * The path part of a URL, i.e. without any query string or fragment.
	 */
	private static function path(string $url): string {
		return preg_split('/[?#]/', $url, 2)[0];
	}
}
