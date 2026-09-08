<?php

declare(strict_types=1);

namespace SsgLab\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SsgLab\MarkdownConverter;

class MarkdownConverterTest extends TestCase {
	private MarkdownConverter $converter;

	protected function setUp(): void {
		$this->converter = new MarkdownConverter();
	}

	public function testRendersBasicMarkdown(): void {
		$html = $this->converter->toHtml('# Title' . "\n\n" . 'Some **bold** text.');

		self::assertStringContainsString('<h1>Title</h1>', $html);
		self::assertStringContainsString('<strong>bold</strong>', $html);
	}

	public function testHighlightsFencedCodeBlocks(): void {
		$html = $this->converter->toHtml("```php\n<?php\n\$greeting = 'hi';\n```");

		self::assertStringContainsString('<code class="hljs language-php">', $html);
		self::assertStringContainsString('<span class="hljs-string">\'hi\'</span>', $html);
	}

	#[DataProvider('provideFamousLanguages')]
	public function testHighlightsFamousLanguages(string $language, string $code): void {
		$html = $this->converter->toHtml("```$language\n$code\n```");

		self::assertStringContainsString('language-' . $language, $html);
		self::assertStringContainsString('<span class="hljs-', $html);
	}

	public static function provideFamousLanguages(): array {
		return [
			'python' => ['python', "def greet(name):\n    return f'hi {name}'"],
			'javascript' => ['javascript', "const x = 1; // note"],
			'typescript' => ['typescript', 'let x: string = "a";'],
			'java' => ['java', 'public class A { int i = 1; }'],
			'go' => ['go', "package main\nimport \"fmt\""],
			'rust' => ['rust', 'fn main() { let x = 1; }'],
			'ruby' => ['ruby', "def greet\n  puts 'hi'\nend"],
			'cpp' => ['cpp', '#include <vector>'],
			'csharp' => ['csharp', 'var x = "a";'],
			'bash' => ['bash', 'echo "hello" # note'],
			'sql' => ['sql', 'SELECT * FROM cats;'],
			'json' => ['json', '{"a": 1}'],
			'yaml' => ['yaml', 'key: value'],
			'xml' => ['xml', '<a href="#">x</a>'],
			'css' => ['css', 'a { color: red; }'],
			'diff' => ['diff', "+added\n-removed"],
		];
	}

	public function testFallsBackToPlainCodeForUnknownLanguage(): void {
		$html = $this->converter->toHtml("```nosuchlang\na < b\n```");

		self::assertStringContainsString('<code class="hljs language-nosuchlang">', $html);
		self::assertStringContainsString('a &lt; b', $html);
	}

	public function testRendersFenceWithoutLanguageAsPlainCode(): void {
		$html = $this->converter->toHtml("```\na < b\n```");

		self::assertStringContainsString('<code class="hljs">', $html);
		self::assertStringContainsString('a &lt; b', $html);
	}

	public function testUsesOnlyTheFirstInfoWordAsLanguage(): void {
		$html = $this->converter->toHtml("```php title=\"example.php\"\n<?php echo 1;\n```");

		self::assertStringContainsString('<code class="hljs language-php">', $html);
		self::assertStringNotContainsString('title=', $html);
	}

	public function testDoesNotExecuteHtmlInsideCodeBlocks(): void {
		$html = $this->converter->toHtml("```html\n<script>alert(1)</script>\n```");

		self::assertStringNotContainsString('<script', $html);
		self::assertStringContainsString('script', $html);
	}

	public function testStripsScriptTags(): void {
		$html = $this->converter->toHtml('Hello <script>alert(1)</script> world');

		self::assertStringNotContainsString('<script', $html);
	}

	public function testStripsUnsafeEventHandlerAttributes(): void {
		$html = $this->converter->toHtml('<img src="x" onerror="alert(1)">');

		self::assertStringNotContainsString('onerror', $html);
	}

	public function testStripsJavascriptUrls(): void {
		$html = $this->converter->toHtml('[link](javascript:alert(1))');

		self::assertStringNotContainsString('javascript:', $html);
	}

	public function testTurnsNonImageAttachmentEmbedsIntoLinks(): void {
		$html = $this->converter->toHtml('![my-document.pdf](.attachments.13283766/my-document.pdf)');

		self::assertStringNotContainsString('<img', $html);
		self::assertStringContainsString(
			'<a href=".attachments.13283766/my-document.pdf">my-document.pdf</a>',
			$html,
		);
	}

	public function testKeepsAltTextAsLinkTextForNonImageEmbeds(): void {
		$html = $this->converter->toHtml('![The **full** report](.attachments.1/report.docx)');

		self::assertStringContainsString('<a href=".attachments.1/report.docx">The <strong>full</strong> report</a>', $html);
	}

	public function testFallsBackToFileNameWhenNonImageEmbedHasNoAltText(): void {
		$html = $this->converter->toHtml('![](.attachments.1/minutes%202024.pdf)');

		self::assertStringContainsString('>minutes 2024.pdf</a>', $html);
	}

	public function testKeepsTitleWhenRewritingNonImageEmbeds(): void {
		$html = $this->converter->toHtml('![slides](.attachments.1/talk.pptx "Kickoff talk")');

		self::assertStringContainsString('title="Kickoff talk"', $html);
	}

	#[DataProvider('provideImageUrls')]
	public function testKeepsRealImageEmbedsAsImages(string $url): void {
		$html = $this->converter->toHtml("![a picture]($url)");

		self::assertStringContainsString('<img', $html);
		self::assertStringNotContainsString('<a ', $html);
	}

	public static function provideImageUrls(): array {
		return [
			'png' => ['.attachments.1/photo.png'],
			'jpeg' => ['.attachments.1/photo.JPEG'],
			'svg' => ['.attachments.1/example.svg'],
			'webp' => ['.attachments.1/photo.webp'],
			'query string' => ['.attachments.1/photo.png?preview=1'],
			// Nothing extension-shaped to judge by: could well be an image.
			'no extension' => ['https://example.com/avatar'],
			'long suffix' => ['https://example.com/render/x.imagefile'],
		];
	}

	public function testDoesNotRewriteImageSyntaxInsideCodeBlocks(): void {
		$html = $this->converter->toHtml("```\n![report.pdf](.attachments.1/report.pdf)\n```");

		self::assertStringNotContainsString('<a ', $html);
		self::assertStringContainsString('![report.pdf]', $html);
	}
}
