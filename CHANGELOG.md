# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

> This repository tracks **JSRay WordPress Plugin** versions only. [JSRay Core](https://github.com/JSRayCore/JSRay)
> keeps its own version and changelog; the `bundledCore` field in `version.json`
> records which Core snapshot each release ships.

## [Unreleased]

## [0.0.1-beta.1] — 2026-08-01

First public beta. The plugin renders through a bundled JSRay Core snapshot
rather than depending on Core at runtime, because a WordPress plugin is a zip
dropped onto a host with no package manager.

### Added
- **Custom colors.** A palette exported by the JSRay Theme Studio overrides any of the 23 token classes on top of the selected palette. Keys are validated against the token vocabulary synced from the bundled Core, values must be real colors — `url()`, `var()` and a stray semicolon are refused, because these are written straight into a style block — and unknown keys are dropped rather than rejecting the whole palette, so one written for a newer Core still works here.
- **Core integrity verification.** `core-integrity.json` pins the digests Core published for the bundled snapshot. The plugin hashes each asset against them, reports the result on the settings screen, and raises an admin notice when the bundled renderer no longer matches its official build. The notice is conditional and limited to `manage_options`: it appears when something is wrong, not on every page load.
- `npm run test:compat` runs the PHP suite and a rendered page against WordPress 6.0 on PHP 7.4 as well as the current release, so `Requires at least` and `Requires PHP` describe something that was exercised rather than assumed.

### Fixed

- **Line numbers no longer drift when a theme wraps code.** The gutter holds one `<li>` per logical line, so it stays aligned only while one logical line occupies one visual row. `pre { white-space: pre-wrap }` is the standard theme fix for code overflowing on phones — many ship it with `!important` — and it silently adds a visual row the gutter has no item for. Every number below the wrap then labelled the wrong line, and the last line got none at all. Found on a live site, not in these tests: a 17-line Python snippet showed `if list1 is None:` as line 9 when it was line 8. Where line numbers are on, wrapping is now pinned off and the code column scrolls horizontally instead — the trade every code host makes, and better than numbers that lie. Blocks without line numbers keep whatever wrapping the theme chose.

- A stored custom palette silently outranked the palette picker with nothing in the UI saying so, which made switching palettes look broken. The Palette field now names every value the custom colors override.
- Shortcode content passed through `wpautop` and `wptexturize` before the shortcode ran, so code arrived carrying literal `<br />` markers and curly quotes — code a reader could not copy and run.
- `align` and `anchor` were accepted by the block editor and dropped by the render callback, which built its own class string instead of calling `get_block_wrapper_attributes()`.

### Changed
- Bundled Core is **0.0.1-beta.4**. That snapshot fixes a denial of service present in every earlier one: an unterminated interpolating string sent four grammars into exponential backtracking, and rendering is synchronous, so a post containing a half-finished code block could hold a reader's tab. Measured here, the same input went from over two seconds to three milliseconds.
- CI fails when the bundled Core is behind the published Core. The previous drift check compared against a sibling checkout and skipped silently when Core was absent — every CI run — so a stale engine passed a green build. A scheduled workflow now opens a sync pull request when Core moves.
- Repository documentation matches the ecosystem baseline: LICENSE, CHANGELOG, CONTRIBUTING, SECURITY and Code of Conduct, with the shared brand header and a Simplified Chinese README.

## [0.0.1-internal.2] — 2026-07-02

### Status
- Internal test build; not a public beta.

### Added
- Core snapshot 0.0.1-internal.2 with 13 additional language families.
- Loader test harness and CI workflow (Node 18 / 20 / 22).

### Changed
- Project id renamed `jsray-wordpress` → `jsray-wp`.
- Core drift check is advisory day-to-day and strict at the packaging gate (`JSRAY_STRICT_DRIFT=1`).
- Official project emails adopted; CI `GITHUB_TOKEN` pinned to read-only.

### Fixed
- Core snapshot carrying the `applyTheme` root fix, so runtime theme changes are no longer shadowed by the theme stylesheet.

## [0.0.1-internal.1] — 2026-07-01

### Status
- Internal test build; not a public beta. Split out of the Core repository into its own project.

### Added
- WordPress plugin around JSRay Core: the **JSRay Code** block, a shortcode, and a compatibility path for native Code blocks.
- Settings screen (theme mode, fallback language, front-end asset toggles).
- Renderer adapter hooks — six PHP filters plus `window.JSRayWP.renderer` — so a host site can extend or replace the renderer.
- `tools/sync-core.sh` and `tools/check-versions.mjs` to bundle Core assets and catch snapshot drift.
