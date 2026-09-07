# ssg-library

CommonMark-based static site generator for Nextcloud Collectives page exports.

A standalone PHP library that turns a Collectives export — a directory tree of
Markdown files — into a static HTML site. It's a port of the layout/rendering
logic from the Collectives SSG microservice, with the HTTP/payload plumbing
stripped out: everything is read straight off disk, so it can be iterated on
locally.

## Requirements

- PHP >= 8.4
- ext-json, ext-mbstring

## Installation

```sh
composer install
```

## Usage

### Command line

For quick local iteration, `bin/build-site.php` builds a site straight from
the terminal:

```sh
bin/build-site.php <pages-dir> <out-dir> [site-title]
```

Try it against the sample export in `examples/pages`:

```sh
bin/build-site.php examples/pages examples/output "Sample Site"
```

Then open `examples/output/index.html` in a browser. `examples/output/` is
gitignored, so re-running the command is always safe.

### As a library

```php
use SsgLab\SiteBuilder;

$builder = new SiteBuilder();
$pageCount = $builder->build($pagesDir, $outDir, siteTitle: 'My Collective');
```

- `$pagesDir` is the root of a Collectives export: each folder is a page whose
  content is its `Readme.md`, plain `.md` files sitting next to it are leaf
  sub-pages, and `.attachments.<id>` directories hold that folder's inline
  images/files.
- `$outDir` is where the static site is written. Each folder becomes
  `<name>/index.html`; each leaf `.md` file becomes `<name>/index.html` one
  level below its folder.
- `build()` returns the number of rendered pages, and throws a
  `RuntimeException` if `$pagesDir` doesn't exist or contains no `.md` files.

Markdown is converted with league/commonmark (raw HTML allowed, unsafe links
disallowed), run through HTMLPurifier to strip anything unsafe, and includes:

- Server-side syntax highlighting for fenced code blocks (scrivo/highlight.php).
- MultiMarkdown-style multiline tables (as used by Nextcloud Text).
- GitHub-style task lists (`- [ ]` / `- [x]`).

Generated pages ship with a light/dark theme toggle (`style.css`, `theme.js`),
copied into the output directory on every build.

### Why sanitization is split into two places

`InputSanitizer` and HTMLPurifier both deal with "unsafe" input, but at
different pipeline stages:

- **`InputSanitizer`** runs *before* Markdown parsing, on the raw bytes read
  off disk. It handles encoding/byte-level hygiene — stripping a BOM, null
  bytes, normalizing CRLF/CR to LF, discarding invalid UTF-8 — problems that
  have nothing to do with HTML or security, just making sure the string is
  well-formed text before CommonMark sees it.
- **HTMLPurifier** (inside `MarkdownConverter`) runs *after* Markdown→HTML
  conversion, on the generated HTML. It's the actual XSS/security boundary:
  stripping `<script>` tags, event handler attributes, `javascript:` URLs,
  etc. — necessary specifically because `MarkdownConverter` sets
  `html_input: allow`, letting raw HTML in the Markdown source pass straight
  through CommonMark untouched.

The split follows the pipeline stage, not convenience: `InputSanitizer` is a
small, dependency-free text-hygiene step reusable anywhere raw file content is
read, while HTMLPurifier is tightly coupled to CommonMark's HTML output (and
its own config, like the task-list `<input>` allowlist) and only makes sense
living inside `MarkdownConverter`.

## Testing

```sh
vendor/bin/phpunit
```

Run a single test:

```sh
vendor/bin/phpunit --filter testMethodName tests/MarkdownConverterTest.php
```

## License

AGPL-3.0-or-later
