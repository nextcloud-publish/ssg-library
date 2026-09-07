#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SsgLab\SiteBuilder;

/**
 * CLI entry point for local development: builds a static site from a
 * Collectives-style Markdown export.
 *
 *   bin/build-site.php <pages-dir> <out-dir> [site-title]
 */

[, $pagesDir, $outDir] = [...$argv, null, null, null];
$siteTitle = $argv[3] ?? 'Collectives lab';

if ($pagesDir === null || $outDir === null) {
	fwrite(STDERR, "Usage: bin/build-site.php <pages-dir> <out-dir> [site-title]\n");
	fwrite(STDERR, "Example: bin/build-site.php examples/pages examples/output \"Sample Site\"\n");
	exit(1);
}

try {
	$count = (new SiteBuilder())->build($pagesDir, $outDir, $siteTitle);
	echo "Rendered {$count} pages to {$outDir}\n";
} catch (\Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
	exit(1);
}
