<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use PHPUnit\Framework\TestCase;
use SsgLab\InputSanitizer;

class InputSanitizerTest extends TestCase {
	private InputSanitizer $sanitizer;

	protected function setUp(): void {
		$this->sanitizer = new InputSanitizer();
	}

	public function testStripsLeadingUtf8Bom(): void {
		$result = $this->sanitizer->sanitize("\xEF\xBB\xBF# Title\n");

		self::assertSame("# Title\n", $result);
	}

	public function testDropsNullBytes(): void {
		$result = $this->sanitizer->sanitize("before\0after");

		self::assertSame('beforeafter', $result);
	}

	public function testNormalizesCrlfLineEndings(): void {
		$result = $this->sanitizer->sanitize("line1\r\nline2\r\nline3");

		self::assertSame("line1\nline2\nline3", $result);
	}

	public function testNormalizesLoneCrLineEndings(): void {
		$result = $this->sanitizer->sanitize("line1\rline2\rline3");

		self::assertSame("line1\nline2\nline3", $result);
	}

	public function testDiscardsInvalidUtf8Bytes(): void {
		$result = $this->sanitizer->sanitize("valid \xFF\xFE invalid");

		self::assertTrue(mb_check_encoding($result, 'UTF-8'));
	}

	public function testLeavesCleanMarkdownUnchanged(): void {
		$markdown = "# Title\n\nSome **bold** text.\n";

		self::assertSame($markdown, $this->sanitizer->sanitize($markdown));
	}
}
