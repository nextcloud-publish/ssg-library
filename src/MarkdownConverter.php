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
class MarkdownConverter
{
    private LeagueMarkdownConverter $converter;
    private HTMLPurifier $purifier;
    private AttachmentPathFilter $attachmentPaths;

    public function __construct()
    {
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
        $this->attachmentPaths = new AttachmentPathFilter();
        $this->purifier = self::createPurifier($this->attachmentPaths);
    }

    private static function createPurifier(AttachmentPathFilter $attachmentPaths): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);

        // Embedded resources only from the site itself. An external <img> or CSS url() would
        // let any page author log every visitor's IP and user agent, and a root-relative one
        // (RootRelativeResourceFilter, added below) makes visitors request other paths on the
        // hosting origin. Links are not affected.
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DefinitionID', 'ssg-lab');
        $config->set('URI.DefinitionRev', 1);
        // Search engines shouldn't credit the published site for links its authors add.
        $config->set('HTML.Nofollow', true);

        // HTMLPurifier strips <input> by default since it belongs to the
        // Forms module. We don't want to allow whole forms, just the disabled
        // checkbox that CommonMark's TaskListExtension renders for
        // `- [ ]` / `- [x]` items, so it's allow-listed as its own element
        // instead of turning on HTML.Forms.
        $config->set('HTML.DefinitionID', 'ssg-lab');
        $config->set('HTML.DefinitionRev', 3);
        $def = $config->maybeGetRawHTMLDefinition();
        $def?->addElement(
            'input',
            'Inline',
            'Empty',
            'Common',
            [
                'type' => 'Enum#checkbox',
                'checked' => 'Bool#checked',
                'disabled' => 'Bool#disabled',
            ],
        );

        // <details>/<summary> aren't in HTMLPurifier's default (XHTML 1.0)
        // doctype either, but Nextcloud Text emits them for collapsible
        // blocks and smart-picker context references, so allow-list them too.
        $def?->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool']);
        $def?->addElement('summary', 'Inline', 'Inline', 'Common');

        // Nextcloud Text's callout boxes (`::: info` ... `:::`) render as a
        // `<div class="callout callout-<type>" data-callout="<type>">`; the
        // data-* attribute isn't part of HTMLPurifier's default definition,
        // so it needs allow-listing too, same as above.
        $def?->addAttribute('div', 'data-callout', 'Enum#info,warn,error,success,question');

        $uriDefinition = $config->maybeGetRawURIDefinition();
        $uriDefinition?->addFilter(new RootRelativeResourceFilter(), $config);
        $uriDefinition?->addFilter($attachmentPaths, $config);

        return new HTMLPurifier($config);
    }

    /**
     * @param string $attachmentPrefix Prepended to links into `.attachments.*` folders: `../`
     *   for a leaf page, which renders one directory below the folder its attachments are in.
     */
    public function toHtml(string $content, string $attachmentPrefix = ''): string
    {
        $renderBlock = fn (string $block): string => $this->converter->convert($block)->getContent();

        $content = CalloutPreprocessor::process($content, $renderBlock);
        $content = MultilineTablePreprocessor::process($content, $renderBlock);
        $html = $this->converter->convert($content)->getContent();

        $this->attachmentPaths->prefix = $attachmentPrefix;
        try {
            return $this->purifier->purify($html);
        } finally {
            $this->attachmentPaths->prefix = '';
        }
    }
}
