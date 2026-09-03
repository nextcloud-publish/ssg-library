<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SsgLab;

/**
 * Converts MultiMarkdown-style multiline tables (as used by Nextcloud Text via
 * markdown-it-multimd-table) into HTML before CommonMark parsing.
 *
 * Rows ending with a backslash (\) are merged with the following row.
 */
class MultilineTablePreprocessor {
	/**
	 * @param callable(string): string $renderCellMarkdown
	 */
	public static function process(string $markdown, callable $renderCellMarkdown): string {
		$lines = explode("\n", $markdown);
		$output = [];
		$i = 0;
		$count = count($lines);

		while ($i < $count) {
			if (!self::isTableRow($lines[$i])) {
				$output[] = $lines[$i];
				$i++;
				continue;
			}

			$tableLines = [];
			while ($i < $count && self::isTableRow($lines[$i])) {
				$tableLines[] = $lines[$i];
				$i++;
			}

			$output[] = self::renderTable($tableLines, $renderCellMarkdown);
		}

		return implode("\n", $output);
	}

	private static function isTableRow(string $line): bool {
		return str_starts_with(ltrim($line), '|');
	}

	/**
	 * A table is a header row, a `|---|:---:|` separator row carrying the
	 * column alignments, and body rows. Both the header and the separator are
	 * optional: without a separator there is no header row at all, and a
	 * separator with nothing below it leaves a header-less single-row table.
	 *
	 * @param string[] $lines
	 */
	private static function renderTable(array $lines, callable $renderCellMarkdown): string {
		$rows = self::groupMultilineRows($lines);

		$alignments = [];
		$hasSeparator = false;
		foreach ($rows as $index => $cells) {
			if (self::isSeparatorRow($cells)) {
				$alignments = self::parseAlignments($cells);
				$hasSeparator = true;
				unset($rows[$index]);
			}
		}
		$rows = array_values($rows);

		if ($rows === []) {
			return implode("\n", $lines);
		}

		$header = ($hasSeparator && count($rows) > 1) ? array_shift($rows) : null;

		$html = '<table>';
		if ($header !== null) {
			$html .= '<thead>' . self::renderRow('th', $header, $alignments, $renderCellMarkdown) . '</thead>';
		}
		$html .= '<tbody>';
		foreach ($rows as $row) {
			$html .= self::renderRow('td', $row, $alignments, $renderCellMarkdown);
		}

		return $html . '</tbody></table>';
	}

	/**
	 * @param list<string> $cells
	 * @param list<string|null> $alignments
	 */
	private static function renderRow(string $tag, array $cells, array $alignments, callable $renderCellMarkdown): string {
		$html = '<tr>';
		foreach ($cells as $index => $cell) {
			$html .= self::renderCell($tag, $cell, $alignments[$index] ?? null, $renderCellMarkdown);
		}

		return $html . '</tr>';
	}

	/**
	 * @param string[] $lines
	 *
	 * @return list<list<string>>
	 */
	private static function groupMultilineRows(array $lines): array {
		$groups = [];
		$accumulated = null;

		foreach ($lines as $line) {
			// A trailing backslash means this row continues on the next line.
			$line = preg_replace('/\\\s*$/', '', $line, -1, $continues) ?? $line;
			$cells = self::splitTableRow($line);

			$accumulated = $accumulated === null
				? $cells
				: self::mergeCells($accumulated, $cells);

			if ($continues === 0) {
				$groups[] = $accumulated;
				$accumulated = null;
			}
		}

		if ($accumulated !== null) {
			$groups[] = $accumulated;
		}

		return $groups;
	}

	/**
	 * @param list<string> $previous
	 * @param list<string> $next
	 *
	 * @return list<string>
	 */
	private static function mergeCells(array $previous, array $next): array {
		$columns = max(count($previous), count($next));
		$merged = [];

		for ($i = 0; $i < $columns; $i++) {
			$left = $previous[$i] ?? '';
			$right = $next[$i] ?? '';
			if (trim($right) === '') {
				$merged[$i] = $left;
			} elseif (trim($left) === '') {
				$merged[$i] = $right;
			} else {
				$merged[$i] = rtrim($left) . "\n" . $right;
			}
		}

		return $merged;
	}

	/**
	 * @return list<string>
	 */
	private static function splitTableRow(string $line): array {
		$line = trim($line);
		if (str_starts_with($line, '|')) {
			$line = substr($line, 1);
		}
		if (str_ends_with($line, '|')) {
			$line = substr($line, 0, -1);
		}

		$cells = [];
		$current = '';
		$length = strlen($line);
		for ($i = 0; $i < $length; $i++) {
			$char = $line[$i];
			if ($char === '\\' && ($i + 1) < $length && $line[$i + 1] === '|') {
				$current .= '|';
				$i++;
				continue;
			}
			if ($char === '|') {
				$cells[] = trim($current);
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$cells[] = trim($current);

		return $cells;
	}

	/**
	 * @param list<string> $cells
	 */
	private static function isSeparatorRow(array $cells): bool {
		if ($cells === []) {
			return false;
		}

		foreach ($cells as $cell) {
			if (!preg_match('/^:?-{3,}:?$/', trim($cell))) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<string> $cells
	 *
	 * @return list<string|null>
	 */
	private static function parseAlignments(array $cells): array {
		return array_map(static function (string $cell): ?string {
			$cell = trim($cell);
			$left = str_starts_with($cell, ':');
			$right = str_ends_with($cell, ':');

			return match (true) {
				$left && $right => 'center',
				$right => 'right',
				$left => 'left',
				default => null,
			};
		}, $cells);
	}

	/**
	 * @param callable(string): string $renderCellMarkdown
	 */
	private static function renderCell(string $tag, string $markdown, ?string $align, callable $renderCellMarkdown): string {
		$style = $align !== null ? ' style="text-align:' . $align . ';"' : '';
		$content = trim($markdown) === '' ? '' : $renderCellMarkdown($markdown);

		return '<' . $tag . $style . '>' . $content . '</' . $tag . '>';
	}
}
