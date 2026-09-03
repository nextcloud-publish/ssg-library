<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

use Highlight\Highlighter;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Renders fenced code blocks with server-side syntax highlighting.
 *
 * Replaces CommonMark's own FencedCode renderer. The info string's first word
 * is taken as the language name and handed to highlight.php (a PHP port of
 * highlight.js), which emits `hljs-*` spans — the same class names the
 * highlight.js themes use, and the same ones Nextcloud Text highlights with
 * client-side. Colors for them live in `style.css`.
 *
 * Highlighting is best-effort: an unknown or missing language falls back to
 * a plain escaped code block, so nothing is ever lost to a failed lookup.
 */
class FencedCodeRenderer implements NodeRendererInterface {
	private Highlighter $highlighter;

	public function __construct() {
		$this->highlighter = new Highlighter();
	}

	public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement {
		if (!$node instanceof FencedCode) {
			throw new \InvalidArgumentException('Expected a FencedCode node');
		}

		$code = $node->getLiteral();
		$language = $this->languageOf($node);

		$classes = ['hljs'];
		if ($language !== null) {
			// The class keeps the name as written in the fence, not the one
			// the highlighter resolves aliases to (`csharp`, not `cs`), so it
			// stays the `language-` class CommonMark would have emitted.
			$classes[] = 'language-' . $language;
		}

		try {
			$contents = $this->highlighter->highlight($language ?? '', $code)->value;
		} catch (\Throwable) {
			// Unknown language, or the highlighter choked on the snippet.
			$contents = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}

		return new HtmlElement(
			'pre',
			[],
			new HtmlElement('code', ['class' => implode(' ', $classes)], $contents),
		);
	}

	/**
	 * The info string may carry more than the language (```php title="x"),
	 * so only its first word counts — that is what CommonMark itself uses
	 * for the `language-` class.
	 */
	private function languageOf(FencedCode $node): ?string {
		$words = $node->getInfoWords();
		if ($words === [] || $words[0] === '') {
			return null;
		}

		return strtolower($words[0]);
	}
}
