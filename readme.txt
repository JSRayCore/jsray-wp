=== JSRay ===
Contributors: liuyingjierun
Tags: code rendering, code blocks, gutenberg, syntax highlighting, developer tools
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.1-beta
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

JSRay Core rendering for WordPress code blocks.

== Description ==

Public beta. Renders code blocks with JSRay Core: 35 language families, 23
token classes, four palettes in dark and light, and no third-party requests.
The major version tracks the bundled Core's, so this line stays on 0.x until
Core reaches 1.0.

Official site: https://jsray.org

This is an official open-source integration in the JSRay ecosystem.

JSRay adds a dedicated JSRay Code block to the WordPress editor and loads a bundled snapshot of the zero-dependency JSRay Core JavaScript runtime on public pages.

It supports:

* A dedicated JSRay Code Gutenberg block with automatic language detection, language override, filename, copy button, and line-number controls.
* Gutenberg Code blocks with an Additional CSS class like language-js.
* Classic editor markup such as <pre><code class="language-js">...</code></pre>.
* A [jsray lang="js"]...[/jsray] shortcode.
* Four palettes — Default, Aurora, Ember, and Fjord — each with a dark and a light variant.
* Dark, light, or inherited theme mode.
* Automatic language detection for code blocks without language classes.
* Optional fallback language when detection is unsure.
* An opt-out for unmarked code blocks, so JSRay can coexist with another code plugin.
* Custom colors: paste a palette from the JSRay Theme Studio to override any of the 23 token classes.
* Core integrity verification: the plugin checks the bundled renderer against the digests JSRay Core published, and warns in the admin if it no longer matches.
* Renderer adapter hooks for projects that need to integrate another rendering engine.

== Installation ==

1. Copy this plugin directory to wp-content/plugins/jsray.
2. Activate JSRay in Plugins.
3. Open Settings > JSRay and choose the palette and theme mode.

== Usage ==

JSRay Code block:

1. Insert a JSRay Code block.
2. Keep Auto detect or choose a language.
3. Paste your code.
4. Optionally set a filename, copy button, and line numbers.

The block renders clean front-end markup:

<pre><code>const value = 42;</code></pre>

Compatibility mode:

Gutenberg:

1. Insert a Code block.
2. Open Advanced.
3. Add a class such as language-js or language-python.

Classic editor or HTML:

<pre><code class="language-js">const value = 42;</code></pre>

Shortcode:

[jsray lang="js" filename="app.js" line-numbers="true" highlight="2,4-5"]
const value = 42;
function important(x) {
  return x + value;
}
const done = true;
[/jsray]

Attributes: lang / language, filename, copy, line-numbers, highlight, class.

`highlight="2,4-5"` marks those lines and turns the gutter on by itself.
The shortcode and the block render through the same code, so they produce the
same markup and support the same options.


== Supported Languages ==

JavaScript, TypeScript, JSX, TSX, Python, PHP, Go, Swift, Kotlin, Dart, Lua, Java, C, C++, C#, Ruby, Rust, HTML, XML, SVG, Vue, CSS, SCSS, JSON, JSONC, Shell, Bash, Zsh, Markdown, SQL, YAML, Scala, Objective-C, R, Perl, PowerShell, Elixir, Haskell, GraphQL, TOML, INI, Dockerfile, Makefile, and Diff.

== Frequently Asked Questions ==

= Does JSRay send anything to a server? =

No. Rendering happens entirely in the browser with the bundled JSRay Core
snapshot. The plugin makes no external requests and collects no data.

= Will it conflict with another syntax highlighting plugin? =

Turn off "Code block coverage" in Settings > JSRay. JSRay then only renders
blocks that explicitly declare a language, and leaves everything else alone.

= How do I customize the colors? =

Settings > JSRay > Custom colors accepts the JSON the JSRay Theme Studio at
https://jsray.org/studio.html exports. Anything you leave out keeps the value
from the palette you selected, so you can override a single token or the whole
scheme. Values are validated against the token vocabulary of the bundled Core,
and only real color values are accepted.

= What is the "Rendering core" line on the settings page? =

The plugin bundles a snapshot of JSRay Core rather than loading it from a CDN.
That snapshot is hashed and compared against the digests published for that
Core release, so you can tell at a glance whether the engine rendering your
code is the official build or has been altered on disk.

= Can I use a different renderer? =

Yes. Six PHP filters and a `window.JSRayWP.renderer` slot let you extend or
replace the rendering engine while keeping the block UI, settings, and markup.

== Screenshots ==

1. A rendered code block: filename tab, language label, copy button, line numbers, and line highlighting. `highlight="4,8-10"` marks the lines under discussion; hovering a line lights it the same way.
2. The settings screen. The notice at the top is the integrity check: the bundled renderer is hashed against the digests JSRay Core published, so a modified file is reported rather than silently used.

== Changelog ==

= 0.0.1-beta =
* Shortcodes now render through the same path as the block, so they get the block stylesheet — previously the markup carried JSRay classes with no CSS behind it.
* Shortcodes gained what blocks already had: filename, copy button, line numbers, a highlight="3,7-9" line spec, and a custom class.
* Added line highlighting, and hovering a line now lights it. Both need line numbers turned on.
* Fixed every line rendering double-spaced, caused by wpautop inserting a break where the renderer had already put a newline.
* Fixed the highlight band covering only the line number instead of the whole row.
* Fixed line numbers drifting out of step with the code on themes that wrap long lines. Where line numbers are shown, code now scrolls sideways instead of wrapping.
* Removed load_plugin_textdomain(), discouraged since WordPress 4.6 and loading nothing here.
* Verified on WordPress 7.1 with PHP 8.3 and on 6.0 with PHP 7.4; the official Plugin Check reports no errors or warnings.
* Bundled JSRay Core 0.0.1-beta.5.
* First public beta.
* Added custom colors: override any of the 23 token classes with a palette from the JSRay Theme Studio, validated against the bundled Core's token vocabulary.
* Added core integrity verification with an admin warning when the bundled renderer no longer matches its official build.
* The Palette setting now warns when custom colors are overriding it, instead of appearing to have no effect.
* Fixed shortcode content being mangled by WordPress: literal <br /> markers and curly quotes no longer appear in code.
* Added a palette setting with all four JSRay palettes (Default, Aurora, Ember, Fjord); the block editor preview follows the selected palette.
* Added a "Code block coverage" setting to stop JSRay from taking over unmarked code blocks.
* Fixed block alignment (wide/full), HTML anchors, and custom classes, which were declared as supported but never reached the front end.
* Fixed a leftover `data-cx-lang` attribute in the front-end markup, now `data-jsray-lang`.
* Translations now actually load, including the block editor and the copy button labels.
* Added block.json as the single source of truth for the block definition.
* Added uninstall cleanup, a Settings link on the Plugins screen, and the LICENSE file to the packaged zip.
* Bundled JSRay Core 0.0.1-beta.

= 0.0.1-internal.2 =
* Bundled JSRay Core 0.0.1-internal.2 with 13 new language families (Scala, Objective-C, R, Perl, PowerShell, Elixir, Haskell, GraphQL, TOML, INI, Dockerfile, Makefile, Diff).
* Added the new languages to the block editor language picker.
* Smarter automatic detection: shebang lines resolve the interpreter directly, and all new languages have detection signals.

= 0.0.1-internal.1 =
* Internal test build.
* Added the dedicated JSRay Code Gutenberg block.
* Added filename, copy button, and line-number controls.
* Added renderer adapter hooks for open renderer integration.
* Added automatic language detection for plain WordPress code blocks.
* Added front-end block styling and copy-button behavior.
* Initial WordPress plugin wrapper for JSRay.

== Upgrade Notice ==

= 0.0.1-beta =
First public beta. Bundles JSRay Core 0.0.1-beta.5, which corrects eight literal forms that were previously mis-coloured — Rust lifetimes, Go and C++ raw strings, Java text blocks, numeric type suffixes and Python f-strings — and adds twenty keywords from recent language versions.
