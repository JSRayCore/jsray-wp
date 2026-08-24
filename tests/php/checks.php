<?php
/**
 * Runtime checks executed inside a real WordPress install.
 *
 * The node suites can only read the PHP as text. These run it: sanitizer
 * behavior, XSS escaping on both render paths, filter hooks, and block
 * registration, all against the WordPress that will actually host the plugin.
 *
 * Usage: npm run test:php  (needs the docker-compose site — npm run dev:site)
 *
 * @package JSRay
 */

$pass = 0; $fail = [];
function check(&$pass, &$fail, $label, $condition) {
  if ($condition) { $pass++; } else { $fail[] = $label; }
}

// --- sanitizer rejects junk ---
$s = jsray_wp_sanitize_options(['theme' => 'neon', 'palette' => 'nope', 'fallback_language' => 'J S<script>', 'enqueue_assets' => 'x', 'scan_all_code' => '']);
check($pass, $fail, 'unknown theme falls back to dark', $s['theme'] === 'dark');
check($pass, $fail, 'unknown palette falls back to default', $s['palette'] === 'default');
check($pass, $fail, 'language strips markup and case', $s['fallback_language'] === 'jsscript');
check($pass, $fail, 'truthy enqueue becomes 1', $s['enqueue_assets'] === '1');
check($pass, $fail, 'empty scan_all_code becomes 0', $s['scan_all_code'] === '0');

$s2 = jsray_wp_sanitize_options('not an array');
check($pass, $fail, 'non-array input returns defaults', $s2 === jsray_wp_default_options());

foreach (['aurora', 'ember', 'fjord', 'default'] as $p) {
  check($pass, $fail, "palette $p survives sanitizing", jsray_wp_sanitize_options(['palette' => $p])['palette'] === $p);
}

// --- XSS: the code payload must come back escaped ---
$evil = '<script>alert(1)</script>';
$html = jsray_wp_render_code_block(['code' => $evil, 'language' => 'js']);
check($pass, $fail, 'block escapes script tags', strpos($html, '<script>') === false);
check($pass, $fail, 'block emits escaped entity', strpos($html, '&lt;script&gt;') !== false);

$attr = jsray_wp_render_code_block(['code' => 'x', 'language' => 'js" onload="alert(1)']);
check($pass, $fail, 'language attribute cannot break out', strpos($attr, 'data-language="jsonloadalert1"') !== false && strpos($attr, ' onload=') === false);

$sc = jsray_wp_render_shortcode(['lang' => 'js'], $evil);
check($pass, $fail, 'shortcode escapes script tags', strpos($sc, '<script>') === false);

$empty = jsray_wp_render_code_block(['code' => "   \n  ", 'language' => 'js']);
check($pass, $fail, 'blank block renders nothing', $empty === '');

// --- supports actually land on the wrapper ---
check($pass, $fail, 'wrapper carries the block class', strpos($html, 'wp-block-jsray-code') !== false);

// --- line numbers match the line count ---
$lines = jsray_wp_render_code_block(['code' => "a\nb\nc", 'language' => 'js', 'showLineNumbers' => true]);
check($pass, $fail, 'gutter has one li per line', substr_count($lines, '<li>') === 3);

// --- filters are live ---
add_filter('jsray_wp_rendered_block_html', function () { return 'REPLACED'; });
check($pass, $fail, 'render filter can replace the markup', jsray_wp_render_code_block(['code' => 'x', 'language' => 'js']) === 'REPLACED');
remove_all_filters('jsray_wp_rendered_block_html');

add_filter('jsray_wp_palettes', function ($p) { unset($p['ember']); return $p; });
check($pass, $fail, 'removing a palette forces the default', jsray_wp_sanitize_options(['palette' => 'ember'])['palette'] === 'default');
remove_all_filters('jsray_wp_palettes');

// --- registration ---
check($pass, $fail, 'block is registered from block.json', WP_Block_Type_Registry::get_instance()->is_registered('jsray/code'));
$type = WP_Block_Type_Registry::get_instance()->get_registered('jsray/code');
check($pass, $fail, 'block declares align support', !empty($type->supports['align']));
check($pass, $fail, 'block api version is 3', $type->api_version === 3);
check($pass, $fail, 'shortcode is registered', shortcode_exists('jsray'));
check($pass, $fail, 'all four palettes are offered', count(jsray_wp_palettes()) === 4);

// ===== custom palette: accepted, and hostile input refused =====

$good = '{"themes":{"dark":{"background":"#101014","tokens":{"keyword":{"color":"#FF6B9D","fontStyle":"bold italic"},"string":{"color":"rgb(160, 220, 120)"}}},"light":{"tokens":{"keyword":{"color":"#B0004E"}}}}}';
$v = jsray_wp_validate_palette($good);
check($pass, $fail, 'valid palette accepted', empty($v['errors']));
check($pass, $fail, 'both modes parsed', array_keys($v['themes']) === ['dark','light']);
$css = jsray_wp_palette_css($v['themes']);
check($pass, $fail, 'emits the keyword variable', strpos($css, '--jr-keyword:#FF6B9D') !== false);
check($pass, $fail, 'emits background surface var', strpos($css, '--jr-bg:#101014') !== false);
check($pass, $fail, 'rgb() color survives', strpos($css, '--jr-string:rgb(160, 220, 120)') !== false);
check($pass, $fail, 'fontStyle becomes a real rule', strpos($css, '[data-theme="dark"] .tk-keyword{font-weight:700;font-style:italic}') !== false);
check($pass, $fail, 'light mode scoped separately', strpos($css, '[data-theme="light"]{--jr-keyword:#B0004E') !== false);

// --- hostile input is refused ---
$evil = '{"themes":{"dark":{"tokens":{"keyword":{"color":"red;} body{display:none} .x{color:red"}}}}}';
$ev = jsray_wp_validate_palette($evil);
check($pass, $fail, 'CSS injection via color is dropped', empty($ev['themes']['dark']['tokens']));
check($pass, $fail, 'injection is reported', !empty($ev['errors']));

foreach ([
  'url(javascript:alert(1))', 'expression(alert(1))', 'var(--x)', '#fff;}*{color:red',
  "red\n}", '"><script>alert(1)</script>',
] as $payload) {
  check($pass, $fail, "color payload refused: $payload", jsray_wp_sanitize_color($payload) === '');
}
foreach (['#fff', '#FFAA00', '#ffaa0080', 'rgb(1,2,3)', 'rgba(1,2,3,.5)', 'hsl(210 40% 50%)', 'transparent'] as $ok) {
  check($pass, $fail, "legit color kept: $ok", jsray_wp_sanitize_color($ok) === $ok);
}

// --- unknown tokens are ignored, not fatal (forward compatibility) ---
$future = '{"themes":{"dark":{"tokens":{"keyword":{"color":"#fff"},"lifetime.annotation":{"color":"#f00"}}}}}';
$fv = jsray_wp_validate_palette($future);
check($pass, $fail, 'unknown token ignored', !isset($fv['themes']['dark']['tokens']['lifetime.annotation']));
check($pass, $fail, 'known token still applied', isset($fv['themes']['dark']['tokens']['keyword']));
check($pass, $fail, 'unknown token reported', count($fv['errors']) === 1);

// --- garbage in ---
check($pass, $fail, 'invalid JSON reported', jsray_wp_validate_palette('not json')['errors'] !== []);
check($pass, $fail, 'empty string is a no-op', jsray_wp_validate_palette('')['errors'] === []);
check($pass, $fail, 'bare mode map accepted', isset(jsray_wp_validate_palette('{"dark":{"tokens":{"keyword":{"color":"#fff"}}}}')['themes']['dark']));

// --- the sanitizer stores only validated palettes ---
$stored = jsray_wp_sanitize_options(['custom_palette' => 'not json']);
check($pass, $fail, 'unusable palette is not stored', $stored['custom_palette'] === '');
$stored2 = jsray_wp_sanitize_options(['custom_palette' => $good]);
check($pass, $fail, 'usable palette is stored verbatim', $stored2['custom_palette'] === $good);

// --- vocabulary is the shared source ---
$vocab = jsray_wp_vocabulary();
check($pass, $fail, '23 tokens in the vocabulary', count($vocab['tokens']) === 23);
check($pass, $fail, 'vocabulary maps the three-name lock-step', $vocab['tokens']['function.declaration'] === 'fn-decl');


// ===== shortcode: WordPress must not corrupt the code =====
$autop = jsray_wp_undo_autop("a<br />b</p>\n<p>c");
check($pass, $fail, 'autop br becomes a newline', strpos($autop, "a\nb") === 0);
check($pass, $fail, 'autop paragraph break becomes a blank line', strpos($autop, "b\n\nc") !== false);
check($pass, $fail, 'no paragraph tags survive', strpos($autop, '<p') === false);
check($pass, $fail, 'jsray is exempt from texturizing', in_array('jsray', apply_filters('no_texturize_shortcodes', array()), true));

$rendered = do_shortcode('[jsray lang="go"]' . "\n" . 'fmt.Println("hi")' . "\n" . '[/jsray]');
check($pass, $fail, 'shortcode keeps straight quotes', strpos($rendered, '&quot;hi&quot;') !== false);
check($pass, $fail, 'shortcode emits no literal br', strpos($rendered, '&lt;br') === false);

// ===== shortcode reaches the same features as the block =====
// The shortcode used to emit a bare <pre><code> while the block produced a
// header, a copy button and a gutter — the same plugin handing a classic
// editor strictly less than the block editor. It renders through the block now,
// and only running it proves that.
$full = do_shortcode(
	'[jsray lang="js" filename="app.js" line-numbers="true"]' . "\n"
	. 'const a = 1;' . "\n" . 'const b = 2;' . "\n" . '[/jsray]'
);
check($pass, $fail, 'shortcode renders the block wrapper', strpos($full, 'jsray-block') !== false);
check($pass, $fail, 'shortcode shows the filename', strpos($full, 'app.js') !== false);
check($pass, $fail, 'shortcode renders the copy button', strpos($full, 'jsray-block__copy') !== false);
check($pass, $fail, 'shortcode renders the gutter', strpos($full, 'jsray-block__gutter') !== false);
check($pass, $fail, 'gutter has one node per line', substr_count($full, '<li') === 2);

// Shortcode attributes are strings, so `copy="false"` is the truthy string
// "false" to PHP unless something reads it.
$no_copy = do_shortcode('[jsray lang="js" copy="false"]' . "\n" . 'x' . "\n" . '[/jsray]');
check($pass, $fail, 'copy="false" hides the button', strpos($no_copy, 'jsray-block__copy') === false);
foreach (array('false', 'FALSE', 'no', 'off', '0') as $off) {
	check($pass, $fail, "copy=\"$off\" reads as off",
		jsray_wp_shortcode_bool($off) === false);
}
check($pass, $fail, 'copy="true" reads as on', jsray_wp_shortcode_bool('true') === true);
check($pass, $fail, 'an unset attribute reads as off', jsray_wp_shortcode_bool('') === false);

$with_class = do_shortcode('[jsray lang="js" class="my-thing"]' . "\n" . 'x' . "\n" . '[/jsray]');
check($pass, $fail, 'shortcode class reaches the wrapper', strpos($with_class, 'my-thing') !== false);

// Two bugs a rendered page found and static checks could not: wpautop writes
// `<br />` followed by a real newline, so replacing the tag alone left two and
// every line came out double-spaced; and the block's stylesheet is registered
// through block.json's `style`, which WordPress enqueues only when that block
// is on the page — a shortcode rendering block markup got no CSS at all.
$spaced = do_shortcode('[jsray lang="js"]' . "\n" . 'a' . "\n" . 'b' . "\n" . 'c' . "\n" . '[/jsray]');
preg_match('#<code[^>]*>(.*?)</code>#s', $spaced, $m);
$inner = isset($m[1]) ? $m[1] : '';
check($pass, $fail, 'shortcode lines are not double-spaced', strpos($inner, "\n\n") === false);
check($pass, $fail, 'shortcode keeps every line', substr_count($inner, "\n") === 2);

$autop_br = jsray_wp_undo_autop("a<br />\nb");
check($pass, $fail, 'a br and its newline collapse to one', $autop_br === "a\nb");

do_shortcode('[jsray lang="js"]' . "\n" . 'x' . "\n" . '[/jsray]');
check($pass, $fail, 'the shortcode enqueues the block stylesheet',
	wp_style_is('jsray-block-frontend', 'enqueued'));

// ===== line highlighting =====
check($pass, $fail, 'a single line parses', jsray_wp_parse_highlight('3', 10) === array(3));
check($pass, $fail, 'a range parses', jsray_wp_parse_highlight('2-4', 10) === array(2, 3, 4));
check($pass, $fail, 'a mixed spec parses', jsray_wp_parse_highlight('1,3-4', 10) === array(1, 3, 4));
check($pass, $fail, 'a reversed range is read the obvious way', jsray_wp_parse_highlight('4-2', 10) === array(2, 3, 4));
check($pass, $fail, 'overlapping entries do not repeat', jsray_wp_parse_highlight('2,2-3', 10) === array(2, 3));
check($pass, $fail, 'lines past the end are dropped', jsray_wp_parse_highlight('9,20', 10) === array(9));
// Bounded before the loop: 1-999999 on a ten-line block costs ten entries.
check($pass, $fail, 'a huge range is clamped to the block', count(jsray_wp_parse_highlight('1-999999', 10)) === 10);
check($pass, $fail, 'junk is dropped, not fatal', jsray_wp_parse_highlight('abc,,-,3', 10) === array(3));
check($pass, $fail, 'line 0 is not a line', jsray_wp_parse_highlight('0-2', 10) === array(1, 2));

$hl = do_shortcode('[jsray lang="js" highlight="2"]' . "\n" . 'a' . "\n" . 'b' . "\n" . 'c' . "\n" . '[/jsray]');
check($pass, $fail, 'highlight marks the line', substr_count($hl, 'is-highlighted') === 1);
// Highlighting is drawn from the gutter, so asking for it has to turn it on.
check($pass, $fail, 'highlight turns the gutter on by itself', strpos($hl, 'jsray-block__gutter') !== false);
check($pass, $fail, 'highlight adds the wrapper class', strpos($hl, 'has-line-numbers') !== false);

// ===== bundled core verification =====
$integrity = jsray_wp_core_integrity(true);
check($pass, $fail, 'bundled core verifies as official', $integrity['status'] === 'official');
check($pass, $fail, 'every bundled asset is covered', $integrity['checked'] === 6);
check($pass, $fail, 'integrity reports the core version', $integrity['version'] === jsray_wp_read_json('core-integrity.json')['version']);

// ===== a custom palette must not silently hide the palette picker =====
update_option('jsray_wp_options', array_merge(jsray_wp_default_options(), array(
  'palette'        => 'ember',
  'custom_palette' => '{"themes":{"dark":{"background":"#0B0E14","tokens":{"keyword":{"color":"#FF6B9D"}}}}}',
)));
$keys = jsray_wp_custom_palette_keys();
check($pass, $fail, 'overridden values are enumerated for the UI', count($keys) === 2);
check($pass, $fail, 'surface overrides are listed', in_array('dark: background', $keys, true));
check($pass, $fail, 'token overrides are listed', in_array('dark: keyword', $keys, true));

update_option('jsray_wp_options', array_merge(jsray_wp_default_options(), array('palette' => 'ember')));
check($pass, $fail, 'no custom palette means nothing to warn about', jsray_wp_custom_palette_keys() === array());
check($pass, $fail, 'the selected palette is what resolves', jsray_wp_active_palette() === 'ember');
delete_option('jsray_wp_options');

// ===== the header must be marked up so the browser can finish it =====
$auto = jsray_wp_render_code_block(array('code' => 'x = 1', 'language' => ''));
check($pass, $fail, 'a language-less block marks its placeholder title', strpos($auto, 'data-jsray-title-language') !== false);
check($pass, $fail, 'the language label is addressable', strpos($auto, 'data-jsray-language-label') !== false);

$named = jsray_wp_render_code_block(array('code' => 'x = 1', 'language' => 'js', 'filename' => 'app.js'));
check($pass, $fail, 'a filename is not marked as a placeholder', strpos($named, 'data-jsray-title-language') === false);
check($pass, $fail, 'the filename is what renders', strpos($named, '>app.js<') !== false);

echo "PHP checks: {$pass} passed, " . count($fail) . " failed\n";
foreach ($fail as $f) { echo "  FAIL: {$f}\n"; }
exit(count($fail) === 0 ? 0 : 1);
