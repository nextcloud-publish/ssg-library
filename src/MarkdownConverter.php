<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter as LeagueMarkdownConverter;

/**
 * Converts Markdown to HTML (matching the Collectives editor output).
 *
 * `html_input: allow` lets raw HTML in the Markdown source pass straight
 * through CommonMark, so the resulting HTML is run through HTMLPurifier
 * before being returned to strip anything unsafe (script tags, event
 * handler attributes, javascript: URLs, etc.).
 */
class MarkdownConverter {
	private LeagueMarkdownConverter $converter;
	private HTMLPurifier $purifier;

	public function __construct() {
		$environment = new Environment([
			'html_input' => 'allow',
			'allow_unsafe_links' => false,
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new TaskListExtension());
		// Renderers don't replace each other: CommonMarkCoreExtension already
		// registered one for FencedCode, so both stay registered and the
		// higher priority decides who is asked first. The core ones all sit at
		// 0, so anything above that wins.
		$environment->addRenderer(FencedCode::class, new FencedCodeRenderer(), 10);
		$environment->addEventListener(DocumentParsedEvent::class, new NonImageEmbedRewriter());

		$this->converter = new LeagueMarkdownConverter($environment);
		$this->purifier = self::createPurifier();
	}

	private static function createPurifier(): HTMLPurifier {
		$config = HTMLPurifier_Config::createDefault();
		$config->set('Cache.DefinitionImpl', null);

		// HTMLPurifier strips <input> by default since it belongs to the
		// Forms module. We don't want to allow whole forms, just the disabled
		// checkbox that CommonMark's TaskListExtension renders for
		// `- [ ]` / `- [x]` items, so it's allow-listed as its own element
		// instead of turning on HTML.Forms.
		$config->set('HTML.DefinitionID', 'ssg-lab-task-list');
		$config->set('HTML.DefinitionRev', 1);
		$config->maybeGetRawHTMLDefinition()?->addElement(
			'input', 'Inline', 'Empty', 'Common',
			[
				'type' => 'Enum#checkbox',
				'checked' => 'Bool#checked',
				'disabled' => 'Bool#disabled',
			],
		);

		return new HTMLPurifier($config);
	}

	public function toHtml(string $content): string {
		$content = MultilineTablePreprocessor::process(
			$content,
			fn (string $cell): string => $this->converter->convert($cell)->getContent(),
		);

		return $this->purifier->purify($this->converter->convert($content)->getContent());
	}
}
