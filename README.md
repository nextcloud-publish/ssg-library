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
bin/build-site.php <pages-dir> <out-dir> [site-title] [theme]
```

`[theme]` is `wiki` (default) or `topnav` — see [Render options](#render-options).

Try it against the sample export in `examples/pages`:

```sh
bin/build-site.php examples/pages examples/output "Sample Site" topnav
```

Then serve it with PHP's built-in server rather than opening the file
directly, since relative links (`../style.css`, sub-pages, attachments) expect
to be resolved by a webserver:

```sh
php -S 0.0.0.0:8090 -t examples/output
```

and open `http://localhost:8090/` in a browser. `examples/output/` is
gitignored, so re-running the command is always safe.

### As a library

```php
use SsgLab\SiteBuilder;

$builder = new SiteBuilder();
$pageCount = $builder->build($pagesDir, $outDir, siteTitle: 'My Collective', renderOptions: [
    'theme' => 'topnav',
]);
```

- `$pagesDir` is the root of a Collectives export: each folder is a page whose
  content is its `Readme.md`, plain `.md` files sitting next to it are leaf
  sub-pages, and `.attachments.<id>` directories hold that folder's inline
  images/files. File extensions and the `Readme.md` name are matched
  case-insensitively. Dot-prefixed entries are skipped, and so are symlinks —
  an export is read as the self-contained tree it claims to be, never through a
  link that could lead out of it.
- `$outDir` is where the static site is written. Each folder becomes
  `<name>/index.html`; each leaf `.md` file becomes `<name>/index.html` one
  level below its folder. Where a leaf and a folder of one parent would claim
  the same name, the folder keeps it and the leaf is numbered off it
  (`Cats.md` beside `Cats/` renders as `Cats-2/`), so neither overwrites the
  other.
- `$renderOptions` is optional and defaults to `null`; see
  [Render options](#render-options).
- `build()` returns the number of rendered pages. It throws an
  `InvalidArgumentException` for invalid render options — checked first, before
  `$pagesDir` is looked at and before anything is written — and a
  `RuntimeException` if `$pagesDir` doesn't exist or contains no `.md` files.

### Render options

`$renderOptions` takes the value as decoded from JSON, unchecked by the caller:
`null`, or a JSON object of the keys below, decoded with `assoc: true` so it
arrives as an associative array (an empty object decodes to `[]`, so `[]` is
accepted as well):

| Key     | Type   | Default | Values             |
| ------- | ------ | ------- | ------------------ |
| `theme` | string | `wiki`  | `wiki` or `topnav` |

`theme` picks how a page navigates to the rest of the site. Its stylesheet
(`src/themes/*.css`) is copied to the output as `layout.css`, and its script, if
it has one (`src/themes/*.js`), as `layout.js`; everything else — colors,
typography, prose and the light/dark toggle — is the same under both.

- **`wiki`** puts the site's whole page tree in a sidebar on the left, the same
  on every page, with the page being shown marked `aria-current="page"`. Below
  60rem there is no room for it beside the page, so it becomes a drawer that
  slides in from the left, opened from a button in the site header and closed
  by that button, by the dimmed page behind it, or by <kbd>Esc</kbd>. With
  JavaScript unavailable the drawer can't be opened, so the tree falls back to
  a band above the page and the button is never shown.
- **`topnav`** puts the current page's own sub-pages in a bar below the site
  header — one level deep only, no ancestors and no nesting — with the back
  link to the parent page at its end.

Under neither layout does the page body repeat its sub-pages as a list: the
sidebar and the bar already link them.

Worth knowing before pointing `wiki` at a large export: every page carries a
copy of the whole tree, so the output grows with the square of the page count —
roughly 180 KB for 50 pages, 2 MB for 200, 16 MB for 600. Build time stays well
under a second throughout. `topnav` carries one level, so its output grows
linearly.

Anything else — a value that is not an object, an unknown key, or a value
outside the list — throws an `InvalidArgumentException` whose message names the
problem.

Markdown is converted with league/commonmark (raw HTML allowed, unsafe links
disallowed), run through HTMLPurifier to strip anything unsafe, and includes:

- Server-side syntax highlighting for fenced code blocks (scrivo/highlight.php).
- MultiMarkdown-style multiline tables (as used by Nextcloud Text).
- GitHub-style task lists (`- [ ]` / `- [x]`).

Generated pages ship with a light/dark theme toggle (`style.css`, `theme.js`),
copied into the output directory on every build, alongside the selected
layout's `layout.css` and — under `wiki` — its `layout.js`.

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

Static analysis, PHPStan at level 10:

```sh
vendor/bin/phpstan analyse
```

Code style, PSR-12, checked and fixed with PHP-CS-Fixer:

```sh
vendor/bin/php-cs-fixer check --diff
vendor/bin/php-cs-fixer fix
```

[pre-commit](https://pre-commit.com) runs the tests, PHPStan and the style
check, plus `composer validate`, `composer audit`, `php -l` and gitleaks, on
every commit. Set it up once:

```sh
pre-commit install
```

CI runs the same checks on every pull request, and the tests on PHP 8.4 and
8.5, each with the lowest and the highest dependency versions `composer.json`
allows.

## Releases

A release is a tag like `1.2.3`, without a leading `v`, on `main`:

1. In the release PR, turn `## [Unreleased]` in `CHANGELOG.md` into a version
   section and add its link at the bottom.
2. After the merge, tag `main` and push the tag:

   ```sh
   git tag -a 1.2.3
   git push origin 1.2.3
   ```

CI then creates the GitHub release with that CHANGELOG section as its notes.
It fails, and creates nothing, if the tag is not on `main` or `CHANGELOG.md`
has no section or link for it. Composer reads the version from the tag.

## License

AGPL-3.0-or-later
