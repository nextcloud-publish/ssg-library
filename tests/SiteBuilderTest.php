<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SsgLab\SiteBuilder;

class SiteBuilderTest extends TestCase {
	private string $pagesDir;
	private string $outDir;

	protected function setUp(): void {
		$this->pagesDir = sys_get_temp_dir() . '/ssg-lab-test-pages-' . uniqid();
		mkdir($this->pagesDir);
		file_put_contents($this->pagesDir . '/Readme.md', "# Hello\n");

		$this->outDir = sys_get_temp_dir() . '/ssg-lab-test-out-' . uniqid();
	}

	protected function tearDown(): void {
		$this->removeDir($this->pagesDir);
		$this->removeDir($this->outDir);
	}

	public function testDefaultsToMutedTheme(): void {
		(new SiteBuilder())->build($this->pagesDir, $this->outDir);

		self::assertFileEquals(__DIR__ . '/../src/themes/muted.css', $this->outDir . '/palette.css');
		self::assertStringContainsString(
			'<link rel="stylesheet" href="palette.css">',
			(string)file_get_contents($this->outDir . '/index.html'),
		);
	}

	public function testColorfulThemeOptionSelectsColorfulPalette(): void {
		(new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: ['theme' => 'colorful']);

		self::assertFileEquals(__DIR__ . '/../src/themes/colorful.css', $this->outDir . '/palette.css');
	}

	public function testRejectsUnknownTheme(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown theme option: neon');

		(new SiteBuilder())->build($this->pagesDir, $this->outDir, renderOptions: ['theme' => 'neon']);
	}

	private function removeDir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->removeDir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
