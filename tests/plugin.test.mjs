// Static checks over the PHP plugin and its metadata.
//
// There is no PHP runtime in CI, so this cannot execute the plugin. What it
// can do is hold the contracts that broke before: block.json as the single
// source of truth, escaping on every echoed value, palettes that actually have
// stylesheets, and the version agreeing across all four files that state it.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
const json = (p) => JSON.parse(read(p));

const php = read('jsray.php');
const block = json('block.json');
const release = json('version.json');

test('block.json is the single source of truth for the block', () => {
  assert.equal(block.name, 'jsray/code');
  assert.equal(block.apiVersion, 3);
  assert.equal(block.textdomain, 'jsray');

  // PHP registers from the file, not an inline array.
  assert.match(php, /register_block_type\(\s*JSRAY_WP_DIR \. 'block\.json'/);
  assert.doesNotMatch(php, /'api_version'\s*=>/);

  // The editor script must not redeclare what block.json owns.
  const editor = read('assets/js/jsray-block.js');
  assert.doesNotMatch(editor, /^\s*attributes:\s*\{/m);
  assert.doesNotMatch(editor, /^\s*supports:\s*\{/m);
  assert.match(editor, /registerBlockType\(metadata,/);
});

test('every attribute the render callback reads is declared in block.json', () => {
  for (const attribute of ['code', 'language', 'filename', 'showCopyButton', 'showLineNumbers']) {
    assert.ok(block.attributes[attribute], `block.json is missing the ${attribute} attribute`);
    assert.match(php, new RegExp(`attributes\\['${attribute}'\\]`), `jsray.php never reads ${attribute}`);
  }
});

test('declared supports actually reach the front end', () => {
  // align/anchor/className only work on a dynamic block if the render callback
  // emits get_block_wrapper_attributes(). Hand-built class strings drop them.
  assert.ok(block.supports.align, 'block.json should declare align support');
  assert.match(php, /get_block_wrapper_attributes\(/);
});

test('every bundled palette is offered, and every offered palette is bundled', () => {
  const files = readdirSync(resolve(ROOT, 'assets/css/themes'))
    .filter((f) => f.endsWith('.css'))
    .map((f) => f.replace(/\.css$/, ''))
    .sort();

  assert.deepEqual(files, ['aurora', 'default', 'ember', 'fjord']);

  const offered = [...php.matchAll(/^\t\t'(\w+)'\s+=> __\('(?:Default|Aurora|Ember|Fjord)/gm)].map((m) => m[1]);
  assert.deepEqual(offered.sort(), files);
});

test('user-facing output is escaped', () => {
  // Every `echo` of a dynamic value must run through an esc_* function. A bare
  // `echo $var` in an admin field is how settings screens grow XSS.
  const bareEcho = php.match(/echo \$[a-z_]+/g) || [];
  assert.deepEqual(bareEcho, [], `unescaped echo: ${bareEcho.join(', ')}`);

  // The code payload itself is the highest-risk value on the page.
  assert.match(php, /esc_html\(\$code\)/);
});

test('the settings screen is capability-gated and its input sanitized', () => {
  assert.match(php, /current_user_can\('manage_options'\)/);
  assert.match(php, /'sanitize_callback'\s*=>\s*'jsray_wp_sanitize_options'/);
  assert.match(php, /add_options_page\(/);

  // Every stored option needs a branch in the sanitizer.
  const defaults = [...php.matchAll(/^\t\t'(\w+)'\s+=> /gm)].map((m) => m[1]);
  for (const key of ['theme', 'palette', 'fallback_language', 'enqueue_assets', 'scan_all_code']) {
    assert.ok(defaults.includes(key), `${key} missing from jsray_wp_default_options()`);
    assert.match(php, new RegExp(`output\\['${key}'\\]`), `${key} is never sanitized`);
  }
});

test('translations are wired end to end', () => {
  assert.match(php, /Domain Path: \/languages/);
  assert.match(php, /load_plugin_textdomain\('jsray'/);
  assert.match(php, /wp_set_script_translations\('jsray-block-editor', 'jsray'/);
  assert.ok(existsSync(resolve(ROOT, 'languages/jsray.pot')), 'languages/jsray.pot missing — run npm run build:pot');

  // Front-end JS gets its strings from PHP; nothing user-facing is hardcoded.
  const loader = read('assets/js/jsray-loader.js');
  assert.doesNotMatch(loader, /setCopyState\(button, '(Copied|Failed)'\)/);
  assert.match(php, /'copied' => __\('Copied', 'jsray'\)/);
});

test('uninstall removes exactly what the plugin stored', () => {
  const uninstall = read('uninstall.php');
  assert.match(uninstall, /WP_UNINSTALL_PLUGIN/);
  assert.match(uninstall, /delete_option\('jsray_wp_options'\)/);
  assert.match(uninstall, /is_multisite\(\)/);
});

test('the version agrees across every file that states it', () => {
  const version = release.version;
  assert.equal(json('package.json').version, version);
  assert.match(php, new RegExp(`\\* Version: ${version.replace(/\./g, '\\.')}`));
  assert.match(php, new RegExp(`JSRAY_WP_VERSION', '${version.replace(/\./g, '\\.')}'`));
  assert.match(read('readme.txt'), new RegExp(`Stable tag: ${version.replace(/\./g, '\\.')}`));
});

test('the packaged zip carries everything a WordPress install needs', () => {
  const build = read('tools/build-plugin.sh');
  for (const file of ['jsray.php', 'uninstall.php', 'block.json', 'readme.txt', 'LICENSE']) {
    assert.match(build, new RegExp(file.replace('.', '\\.')), `${file} is not copied into the zip`);
  }
  assert.match(build, /cp -R assets/);
  assert.match(build, /languages/);
});


const CORE = createRequire(import.meta.url)(resolve(ROOT, 'assets/js/jsray.js'));
const VOCABULARY = JSON.parse(readFileSync(resolve(ROOT, 'vocabulary.json'), 'utf8'));
const DOC_FILES = ['README.md', 'README.zh-CN.md', 'readme.txt'];

test('documented counts match the bundled Core, not a remembered one', () => {
  // Every number here was copied from Core's README at some point, and Core's
  // numbers move: the identifier-family count read "six" against a table with
  // nine rows for as long as nobody checked. The bundled snapshot is the only
  // authority this repository has, so it is the one to check against.
  const grammars = new Set(Object.values(CORE.languages)).size;
  const tokens = Object.keys(VOCABULARY.tokens).length;

  for (const file of DOC_FILES) {
    const path = resolve(ROOT, file);
    if (!existsSync(path)) continue;
    const text = readFileSync(path, 'utf8');

    for (const [claim, count, kind] of [...text.matchAll(/(\d+)\s*(language famil|token class|个 token|种语言)/gi)]
        .map((m) => [m[0], Number(m[1]), m[2].toLowerCase()])) {
      const expected = /language|种语言/.test(kind) ? grammars : tokens;
      assert.equal(count, expected, `${file} claims "${claim}" but the bundled Core has ${expected}`);
    }

    // The identifier families are a Core concept the docs restate in prose.
    assert.doesNotMatch(text, /\b(six|Six)[- ]family\b/, `${file} still says six-family`);
    assert.doesNotMatch(text, /六[- ]?族/, `${file} still says 六族`);
  }
});

test('the shortcode reaches the same features as the block', () => {
  // The shortcode emitted a bare <pre><code> while the block produced a header,
  // a copy button and a gutter — the same plugin handing a classic editor or a
  // theme template strictly less than the block editor, for no reason but that
  // nobody had wired it up.
  const php = readFileSync(resolve(ROOT, 'jsray.php'), 'utf8');
  const shortcode = php.slice(
    php.indexOf('function jsray_wp_render_shortcode'),
    php.indexOf('function jsray_wp_shortcode_bool')
  );

  assert.match(shortcode, /jsray_wp_render_code_block\(/,
    'the shortcode still builds its own markup instead of rendering through the block');

  for (const attribute of ['filename', 'copy', 'line-numbers', 'class']) {
    assert.match(shortcode, new RegExp(`'${attribute}'\\s*=>`),
      `the shortcode does not accept ${attribute}`);
  }

  // Every block attribute the shortcode can reach has to be passed on.
  for (const mapped of ['showCopyButton', 'showLineNumbers', 'filename', 'className']) {
    assert.match(shortcode, new RegExp(`'${mapped}'\\s*=>`), `${mapped} is not forwarded`);
  }
});

test('shortcode booleans read as booleans, not as non-empty strings', () => {
  // Shortcode attributes arrive as strings, so `copy="false"` is the truthy
  // string "false" unless something reads it.
  const php = readFileSync(resolve(ROOT, 'jsray.php'), 'utf8');
  const fn = php.slice(php.indexOf('function jsray_wp_shortcode_bool'));

  for (const off of ['false', 'no', 'off', "'0'"]) {
    assert.ok(fn.includes(off.replace(/'/g, "'")), `"${off}" is not treated as off`);
  }
  assert.match(fn, /strtolower/, 'the comparison is case sensitive');
});

test('line highlighting marks the lines it is given and nothing else', () => {
  const php = readFileSync(resolve(ROOT, 'jsray.php'), 'utf8');
  const parser = php.slice(php.indexOf('function jsray_wp_parse_highlight'));

  // Bounded before the loop runs: `1-999999` on a ten-line block should cost
  // ten entries, not a million.
  assert.match(parser, /min\(\$to, \$max\)/, 'a range is not clamped to the block length');
  assert.match(parser, /max\(1, \$from\)/, 'a range is not clamped at line 1');
  assert.match(parser, /\$from > \$to/, 'a reversed range is not handled');
  assert.match(parser, /array_unique/, 'overlapping ranges would repeat lines');

  // Highlighting is drawn from the gutter, the only element with one node per
  // line, so asking for it without line numbers would silently do nothing.
  const block = php.slice(php.indexOf('function jsray_wp_render_code_block'));
  assert.match(block, /if \(\$highlighted\) \{\s*\$show_lines = true;/,
    'a highlight request does not turn the gutter on');
});

test('the highlight band spans the block, not the gutter', () => {
  // `right: -100%` resolves against the containing block — the <li> — so it
  // bought about thirty pixels and the band stopped inside the gutter. Caught
  // by measuring a rendered page, not by reading the rule.
  const css = readFileSync(resolve(ROOT, 'assets/css/jsray-block.css'), 'utf8');
  // Comments explain the mistake by name, so they have to come out before
  // checking that the mistake itself is gone.
  const declarations = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const band = declarations.slice(declarations.indexOf('.jsray-block__gutter li.is-highlighted::after'));
  const rule = band.slice(0, band.indexOf('}'));

  assert.match(rule, /width:\s*100vw/, 'the band is not wide enough to cross the code column');
  assert.doesNotMatch(rule, /right:\s*-100%/, 'the percentage form is back');
  assert.match(declarations, /\.jsray-block \{[^}]*overflow:\s*hidden/s, 'nothing clips the overflowing band');
});

test('line numbers cannot be desynced by a theme that wraps code', () => {
  // The gutter holds one <li> per logical line, so it stays aligned only while
  // one logical line occupies one visual row. `pre { white-space: pre-wrap }`
  // is the standard theme fix for code overflowing on phones, and it silently
  // adds a visual row the gutter has no item for — every number below the wrap
  // then labels the wrong line, and the last line gets none. Found on a real
  // site, not here: six logical lines rendered seven line boxes beside six
  // numbers.
  const css = read('assets/css/jsray-block.css');
  const rule = css
    .split('}')
    .find((block) => block.includes('.jsray-block.has-line-numbers pre.jsray'));

  assert.ok(rule, 'no rule pins wrapping for blocks that show line numbers');
  assert.match(
    rule,
    /white-space:\s*pre\s*!important/,
    'the gutter needs one visual row per logical line, so white-space: pre has ' +
      'to win against a theme that set pre-wrap with !important'
  );

  // Scoped to line-numbered blocks: nothing about a plain block depends on the
  // row count, so wrapping there stays the theme's call.
  assert.ok(
    !/^\s*\.jsray-block pre\.jsray\s*,?\s*$/m.test(rule.split('{')[0]),
    'the override must not apply to blocks without line numbers'
  );

  // The code has to go somewhere once it stops wrapping.
  assert.match(read('assets/css/jsray.css'), /overflow-x:\s*auto/);
});
