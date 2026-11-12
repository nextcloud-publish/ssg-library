<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

/**
 * Converts Nextcloud Text's callout container syntax into HTML before
 * CommonMark parsing:
 *
 *   ::: info
 *   Some **markdown**.
 *   :::
 *
 * becomes a `<div data-callout="info" class="callout callout-info">`,
 * matching the markup Nextcloud Text's own editor produces
 * (src/markdownit/callouts.ts in nextcloud/text, built on
 * markdown-it-container).
 */
class CalloutPreprocessor
{
    private const TYPES = ['info', 'warn', 'error', 'success', 'question'];

    /**
     * @param callable(string): string $renderBlockMarkdown
     */
    public static function process(string $markdown, callable $renderBlockMarkdown): string
    {
        $lines = explode("\n", $markdown);
        $output = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $type = self::matchOpeningFence($lines[$i]);
            if ($type === null) {
                $output[] = $lines[$i];
                $i++;
                continue;
            }

            $start = $i;
            $i++;
            $contentLines = [];
            while ($i < $count && !self::isClosingFence($lines[$i])) {
                $contentLines[] = $lines[$i];
                $i++;
            }

            if ($i >= $count) {
                // No closing `:::` found: not a callout, emit the lines as-is.
                $output = [...$output, ...array_slice($lines, $start)];
                break;
            }

            $i++; // consume the closing `:::`
            $html = $renderBlockMarkdown(implode("\n", $contentLines));
            $output[] = sprintf('<div data-callout="%1$s" class="callout callout-%1$s">%2$s</div>', $type, $html);
        }

        return implode("\n", $output);
    }

    private static function matchOpeningFence(string $line): ?string
    {
        $types = implode('|', self::TYPES);
        if (preg_match('/^:::\s*(' . $types . ')(?:\s+.*)?$/', trim($line), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function isClosingFence(string $line): bool
    {
        return trim($line) === ':::';
    }
}
