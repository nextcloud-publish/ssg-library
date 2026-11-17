# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Below 60rem the `wiki` sidebar is a drawer that slides in from the left, opened from a button
  in the site header and closed by that button, by the dimmed page behind it, or by Escape. It
  used to be a band above the page, which it still is where JavaScript is unavailable and the
  drawer could not be opened. A layout may now ship a script (`src/themes/<theme>.js`) next to
  its stylesheet, copied to the output as `layout.js`; `wiki` is the only one that has one.

### Changed

- **BREAKING:** `$renderOptions['theme']` selects a layout instead of a color palette: `wiki`
  (default) shows the site's whole page tree in a left sidebar, `topnav` the current page's
  sub-pages as a bar below the site header, one level deep, with the back link at its end.
  `'muted'` and `'colorful'` are no longer accepted and now raise an `InvalidArgumentException`;
  their shared colors and typography live in `style.css`.
- **BREAKING:** the layout stylesheet is copied to the output as `layout.css`, not `palette.css`.
  Anything referencing the old file name has to be pointed at the new one.
- Pages no longer repeat their sub-pages as a list in the body — both layouts link them in their
  navigation. A page without a `Readme.md` shows the empty-page message.
- The UI chrome text (back link, empty-page message, footer) is the same under both layouts; it
  used to be worded per theme.
- Entries whose name starts with a dot are skipped wholesale, rather than only `.attachments.*`.

### Fixed

- Page names are percent-encoded in navigation links. A `#` or `?` in a name used to cut the
  link short at a fragment or a query string, and a page named `javascript:…` produced a link
  with a scheme of its own — on every page under `topnav`, on the site root under `wiki`.
- Symlinks inside an export are skipped instead of read through. A link pointing out of the
  export used to have its target published as a file of the site's own, and one pointing at its
  own parent expanded into as many nested copies of the tree as the OS would resolve.
- A leaf `.md` file and a folder of the same name no longer render into one output directory,
  where the folder silently overwrote the leaf. The folder keeps the name, the leaf is numbered
  off it (`Cats-2`), and the returned page count matches what was written.
- A folder's own page is recognised whatever its `Readme.md` is spelled like. `README.md` used
  to be excluded as a leaf page and missed as a Readme, so the folder rendered as an empty page
  and its content went unwritten.
- A leaf file's slug drops its extension whatever its case: `Notes.MD` renders as `Notes/`, not
  `Notes.MD/`.
- The release notes have one blank line before the CHANGELOG link instead of two.

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
