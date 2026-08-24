<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://jsray.org/assets/brand/jsray-logo-hero-dark.svg">
    <img src="https://jsray.org/assets/brand/jsray-logo-hero-light.svg" alt="JSRay" width="420">
  </picture>
</p>

**English** · [简体中文](README.zh-CN.md)

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.0.1--beta-blue)](CHANGELOG.md)
[![Channel](https://img.shields.io/badge/channel-public%20beta-blue)](CHANGELOG.md)
[![Core](https://img.shields.io/badge/JSRay%20Core-0.0.1--beta.5-success)](https://github.com/JSRayCore/JSRay)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-blue)](readme.txt)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4)](jsray.php)

> JSRay Core rendering for WordPress code blocks · Gutenberg block · shortcode · compatibility mode

<sub>Internal test build · no public beta yet · bundles a JSRay Core snapshot</sub>

---

This repository is the standalone **WordPress plugin** project around [JSRay Core](https://github.com/JSRayCore/JSRay) — an official open-source integration in the JSRay ecosystem, with its own version and release notes.

It **bundles a snapshot** of Core rather than depending on it at runtime, so the plugin keeps working exactly as shipped until a sync deliberately advances it.

The recommended authoring path is the custom **JSRay Code** block. Native WordPress Code blocks remain supported as a compatibility path.

## Core Integrity

The plugin bundles a snapshot of JSRay Core rather than depending on it at
runtime, which means the file that renders your visitors' code sits on disk
where a host, a theme, or an attacker could replace it.

`tools/sync-core.sh` records the digests Core published for that release in
`core-integrity.json`, and the plugin hashes each bundled asset against them.
**Settings > JSRay** reports the result, and an admin notice appears if the
bundled renderer stops matching its official build. Swapping the engine on
purpose is supported — through the adapter hooks below, not by editing files.

## Custom Colors

**Settings > JSRay > Custom colors** accepts the palette JSON the
[JSRay Theme Studio](https://jsray.org/studio.html) exports:

```json
{"themes":{"dark":{"background":"#0B0E14","tokens":{
  "keyword": {"color":"#FF6B9D","fontStyle":"bold italic"},
  "string":  {"color":"#7FE787"}
}}}}
```

Anything you leave out keeps the selected palette's value, so a one-token
override is a two-line palette. Keys are validated against `vocabulary.json` —
the token vocabulary synced from the bundled Core — and values must be real
colors, so a palette cannot inject CSS. Tokens from a newer Core are ignored
rather than rejected, which keeps palettes portable across versions.

## Compatibility

Both ends of the range `readme.txt` declares are tested, not just the newest:

| | WordPress | PHP |
|---|---|---|
| Floor | 6.0 | 7.4 |
| Current | 7.1 | 8.3 |

`npm run test:compat` brings up both environments, runs the PHP assertions and a
real page render in each, and fails on any warning or deprecation notice.

## Renderer Boundary

The plugin uses JSRay Core by default. It is not locked to JSRay Core internally: renderer adapters can extend languages, replace front-end assets, or filter the final block and shortcode HTML.

Available hooks:

```php
jsray_wp_supported_languages
jsray_wp_enqueue_core_assets
jsray_wp_loader_dependencies
jsray_wp_frontend_config
jsray_wp_rendered_block_html
jsray_wp_rendered_shortcode_html
jsray_wp_palettes
```

Front-end adapters can expose:

```js
window.JSRayWP.renderer = {
  highlight(code, language) {},
  highlightElement(element) {}
};
```

## Install

Copy this plugin directory to:

```text
wp-content/plugins/jsray
```

Then activate **JSRay** in the WordPress admin.

## Configure

Open **Settings > JSRay**:

- **Theme mode**: `Dark`, `Light`, or `Inherit from theme`.
- **Fallback language**: optional language used when a code block has no language class and automatic detection is unsure.
- **Front-end assets**: toggle JSRay CSS and JavaScript loading on public pages.

## Use

### JSRay Code Block

In the editor, insert **JSRay Code**. The block provides:

- language selection
- automatic language detection by default
- optional filename
- copy button toggle
- line-number toggle
- live JSRay preview

It renders clean front-end markup around:

```html
<pre><code>const value = 42;</code></pre>
```

### Compatibility Mode

For Gutenberg Code blocks, you can leave the block unmarked and let JSRay detect the language, or add a language class in **Advanced > Additional CSS class(es)**:

```text
language-js
```

Classic editor and custom HTML can use:

```html
<pre><code class="language-js">const value = 42;</code></pre>
```

Shortcode:

```text
[jsray lang="js" filename="app.js" line-numbers="true" highlight="2,4-5"]
const value = 42;
function important(x) {
  return x + value;
}
const done = true;
[/jsray]
```

| Attribute | Meaning |
|---|---|
| `lang` / `language` | Language id; omitted means the fallback language, then auto-detection |
| `filename` | Shown in the header; replaces the language label |
| `copy` | `false` hides the copy button (shown by default) |
| `line-numbers` | `true` shows the gutter |
| `highlight` | Lines to mark, e.g. `3` or `2,4-5`; turns the gutter on by itself |
| `class` | Extra classes on the wrapper |

The shortcode and the block render through the same code, so they produce the
same markup and support the same options.


The plugin also copies `language-*` or `lang-*` classes from `<pre>` to the nested `<code>` element, which makes Gutenberg's wrapper classes work with the JSRay runtime.

When no language class is present, the front-end loader asks JSRay Core to detect the language from the code text. This covers common WordPress Code blocks and plain `<pre><code>` snippets.

## Supported Languages

JavaScript, TypeScript, JSX, TSX, Python, PHP, Go, Swift, Kotlin, Dart, Lua, Java, C, C++, C#, Ruby, Rust, HTML, XML, SVG, Vue, CSS, SCSS, JSON, JSONC, Shell, Bash, Zsh, Markdown, SQL, YAML, Scala, Objective-C, R, Perl, PowerShell, Elixir, Haskell, GraphQL, TOML, INI, Dockerfile, Makefile, and Diff.

## Sync Core Assets

After changing the core JSRay project, rebuild Core `dist/` (run `sh build.sh`
there), then refresh the bundled snapshot with:

```sh
sh tools/sync-core.sh                         # expects Core at ../jsray
JSRAY_CORE_DIR=/path/to/jsray sh tools/sync-core.sh
```

This copies the three Core assets and updates `bundledCore.version` in
`version.json`:

```text
../jsray/dist/jsray.js          -> assets/js/jsray.js
../jsray/dist/jsray.css         -> assets/css/jsray.css
../jsray/dist/themes/default.css -> assets/css/themes/default.css
```

`npm run check:versions` verifies the bundle matches Core whenever the Core
repo is available as a sibling (`../jsray`) or via `JSRAY_CORE_DIR`, so drift is
caught before packaging.

Block-specific files live in:

```text
assets/js/jsray-block.js
assets/js/jsray-loader.js
assets/css/jsray-block.css
assets/css/jsray-block-editor.css
```

## Build Zip

From the repository root:

```sh
npm run build
```

The script reads the plugin version from `jsray.php`,
builds `build/jsray-wp-<version>.zip`, and strips macOS `.DS_Store`
metadata from the packaged archive.
