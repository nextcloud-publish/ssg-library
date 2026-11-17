# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `MarkdownConverter::toHtml()` takes an optional `$attachmentPrefix`, prepended to links into
  `.attachments.*` folders.

### Fixed

- The release notes have one blank line before the CHANGELOG link instead of two.
- On leaf pages, attachment paths are rewritten by HTMLPurifier instead of a regex over its
  output. Text that looked like an attachment attribute, in prose or inline code, was rewritten
  too, and CSS `url()` pointing at an attachment wasn't, so such backgrounds broke.

### Security

- Sub-page links percent-encode the page or folder name. A name such as
  `javascript:alert(1)` linked as a `javascript:` URL, and names containing `?`, `#` or `%`
  linked to the wrong page.
- Attachments are published only for an allowlist of file types (images, PDF, office
  documents, text, audio/video, archives); dotfiles and everything else, including HTML, SVG
  and JavaScript, are left out. They were copied verbatim and could run script on the
  published site.
- Images and CSS `url()` from other hosts or root-relative paths are removed from pages. They
  let a page author track visitors or make them request other paths on the hosting origin.
  External links get `rel="nofollow"`.
- A page file named `..md` or `...md` fails the build with an `InvalidArgumentException`. Its
  page directory was `.` or `..`, so it was written over its folder's page or one directory up,
  from the root outside the output directory.

## [0.3.0] - 2026-11-11

### Added

- PHPStan at level 10 over `src`, `tests` and `bin`.
- PHP-CS-Fixer checks `src`, `tests` and `bin` against PSR-12.
- pre-commit hooks that run the CI lint and test steps.
- CI on pull requests: lint, dependency audit, PHPStan, and tests on PHP 8.4 and 8.5 with the
  lowest and the highest dependencies.
- A pushed version tag creates the GitHub release, with the version's CHANGELOG section as notes.

### Changed

- Code is formatted to PSR-12: four spaces instead of tabs, braces of classes and methods on
  their own line. The generated HTML is indented with spaces.

## [0.2.0] - 2026-11-11

### Changed

- `build()` takes `$renderOptions` as any value decoded from JSON with `assoc: true`, default
  `null`: `null` or an object of supported keys. A non-object, an unknown key or an invalid value
  throws an `InvalidArgumentException`, checked before `$pagesDir` and before anything is
  written; an unknown theme threw a `RuntimeException`. For a `stdClass` the message names
  `assoc: true`.
- The README lists the render options in a table.

## [0.1.0] - 2026-11-04

### Added

- `SsgLab\SiteBuilder::build()` converts a Nextcloud Collectives page export into a static HTML
  site: each folder's `Readme.md` becomes that folder's page, other `.md` files become leaf
  sub-pages rendered one directory level deeper, and `.attachments.<id>/` directories are copied
  verbatim with their inline links rewritten.
- `$renderOptions['theme']` (`muted` default, or `colorful`) picks a color palette from
  `src/themes/`, with matching UI chrome copy, exposed as a CLI flag on `bin/build-site.php`.
- `SsgLab\InputSanitizer` normalizes raw Markdown bytes before parsing (BOM strip, null bytes,
  CRLF/CR to LF, invalid UTF-8 fallback).
- `SsgLab\MarkdownConverter` renders Markdown via league/commonmark (`html_input: allow`,
  `allow_unsafe_links: false`) and pipes the result through HTMLPurifier as the XSS boundary,
  with an allowance for the `<input type=checkbox>` produced by `TaskListExtension`. Supports
  `<details>`/`<summary>` blocks.
- `MultilineTablePreprocessor` converts MultiMarkdown-style multiline tables (as produced by
  Nextcloud Text) into HTML tables before the main CommonMark pass.
- `FencedCodeRenderer` adds server-side syntax highlighting to fenced code blocks via
  scrivo/highlight.php, matching Nextcloud Text's `hljs-*` class names, falling back to escaped
  plain text on any highlighting failure.
- `NonImageEmbedRewriter` rewrites Markdown image embeds whose target isn't actually an image
  (Nextcloud Text embeds every attachment as an image regardless of type) into links, reusing the
  alt text or falling back to the file name.
- `bin/build-site.php` CLI entry point and example pages under `examples/` for local iteration.

[Unreleased]: https://github.com/nextcloud-publish/ssg-library/compare/0.3.0...HEAD
[0.3.0]: https://github.com/nextcloud-publish/ssg-library/releases/tag/0.3.0
[0.2.0]: https://github.com/nextcloud-publish/ssg-library/releases/tag/0.2.0
[0.1.0]: https://github.com/nextcloud-publish/ssg-library/releases/tag/0.1.0
