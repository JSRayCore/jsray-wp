<?php
/**
 * Plugin Name: JSRay
 * Plugin URI: https://jsray.org
 * Description: JSRay Core rendering for WordPress code blocks.
 * Version: 0.0.1-beta
 * Author: Jie
 * Author URI: https://jsray.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jsray
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package JSRay
 *
 * JSRay for WordPress
 * Copyright (C) 2026 JSRay
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 *
 * Bundled JSRay Core stays MIT — see LICENSE-THIRD-PARTY.
 */

if (! defined('ABSPATH')) {
	exit;
}

define('JSRAY_WP_VERSION', '0.0.1-beta');
define('JSRAY_WP_FILE', __FILE__);
define('JSRAY_WP_DIR', plugin_dir_path(__FILE__));
define('JSRAY_WP_URL', plugin_dir_url(__FILE__));

/*
 * Translations load themselves.
 *
 * `load_plugin_textdomain()` used to be required and has been discouraged since
 * WordPress 4.6: for a plugin on WordPress.org the translations arrive from
 * translate.wordpress.org without it, and since the just-in-time loader landed,
 * a `.mo` sitting in the plugin's own languages/ directory is picked up too. The
 * minimum supported version here is 6.0, so both paths are always available and
 * the call had nothing left to do — it loaded no file, because only a .pot ships
 * today. Plugin Check flags it, and a call that does nothing is not worth a
 * review round.
 *
 * Text Domain and Domain Path stay in the plugin header, which is what both
 * loaders actually read.
 */

/**
 * Return plugin defaults.
 *
 * @return array<string, string>
 */
function jsray_wp_default_options() {
	return array(
		'theme'             => 'dark',
		'palette'           => 'default',
		'custom_palette'    => '',
		'fallback_language' => '',
		'enqueue_assets'    => '1',
		'scan_all_code'     => '1',
	);
}

/**
 * Palettes bundled with the plugin.
 *
 * Each key maps to assets/css/themes/<key>.css, synced from Core. Every
 * palette carries its own dark and light variant, so this is independent of
 * the theme-mode setting.
 *
 * @return array<string, string>
 */
function jsray_wp_palettes() {
	$palettes = array(
		'default' => __('Default — the signature JSRay palette', 'jsray'),
		'aurora'  => __('Aurora — polar night, glacial blue with mint and violet', 'jsray'),
		'ember'   => __('Ember — warm forge, flame keywords with patina mint', 'jsray'),
		'fjord'   => __('Fjord — Nordic low-chroma, calm for long reading', 'jsray'),
	);

	/**
	 * Filter the palettes offered by the JSRay WordPress integration.
	 *
	 * A renderer adapter that ships its own stylesheets can add entries here;
	 * register a style handle named `jsray-theme-<key>` to go with each one.
	 *
	 * @param array<string, string> $palettes Palette key to label map.
	 */
	return apply_filters('jsray_wp_palettes', $palettes);
}

/**
 * Read a bundled JSON data file shipped with the plugin.
 *
 * @param string $relative_path Path relative to plugin root.
 * @return array<string, mixed>
 */
function jsray_wp_read_json($relative_path) {
	$path = JSRAY_WP_DIR . ltrim($relative_path, '/');

	if (! file_exists($path)) {
		return array();
	}

	$decoded = json_decode((string) file_get_contents($path), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return is_array($decoded) ? $decoded : array();
}

/**
 * Verify the bundled JSRay Core snapshot against its official digests.
 *
 * The plugin ships a copy of Core rather than depending on it, so the file that
 * renders a visitor's code lives on disk where a host, a theme, or an attacker
 * can replace it. This hashes each bundled asset and compares it to the digest
 * Core published for that release.
 *
 * Result is cached against the manifest's own modification time, so the hashing
 * happens once per release rather than once per page load.
 *
 * @param bool $force Skip the cache.
 * @return array{status: string, version: string, mismatched: string[], checked: int}
 */
function jsray_wp_core_integrity($force = false) {
	$manifest_path = JSRAY_WP_DIR . 'core-integrity.json';
	$cache_key     = 'jsray_wp_core_integrity';

	if (! $force) {
		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['fingerprint'])
			&& $cached['fingerprint'] === (file_exists($manifest_path) ? (string) filemtime($manifest_path) : '')) {
			return $cached['result'];
		}
	}

	$manifest = jsray_wp_read_json('core-integrity.json');
	$result   = array(
		'status'     => 'unknown',
		'version'    => isset($manifest['version']) ? (string) $manifest['version'] : '',
		'mismatched' => array(),
		'checked'    => 0,
	);

	if (empty($manifest['files']) || ! is_array($manifest['files'])) {
		return $result;
	}

	foreach ($manifest['files'] as $relative => $expected) {
		$path = JSRAY_WP_DIR . ltrim((string) $relative, '/');

		if (! file_exists($path)) {
			$result['mismatched'][] = (string) $relative;
			continue;
		}

		$actual = 'sha256-' . base64_encode((string) hash_file('sha256', $path, true));
		$result['checked']++;

		// hash_equals keeps the comparison constant-time; these are public
		// digests, but there is no reason to leak timing on a security check.
		if (! hash_equals((string) $expected, $actual)) {
			$result['mismatched'][] = (string) $relative;
		}
	}

	$result['status'] = empty($result['mismatched']) ? 'official' : 'modified';

	set_transient(
		$cache_key,
		array(
			'fingerprint' => file_exists($manifest_path) ? (string) filemtime($manifest_path) : '',
			'result'      => $result,
		),
		DAY_IN_SECONDS
	);

	return $result;
}

/**
 * Warn in the admin when the bundled Core no longer matches its official build.
 *
 * @return void
 */
function jsray_wp_core_integrity_notice() {
	if (! current_user_can('manage_options')) {
		return;
	}

	$integrity = jsray_wp_core_integrity();

	if ('modified' !== $integrity['status']) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e('JSRay: the bundled rendering core has been modified.', 'jsray'); ?></strong>
		</p>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated list of file paths. */
					__('These files no longer match the official JSRay Core %1$s build: %2$s', 'jsray'),
					$integrity['version'],
					implode(', ', $integrity['mismatched'])
				)
			);
			?>
		</p>
		<p>
			<?php esc_html_e('Reinstall the plugin to restore the official core. If you replaced the renderer on purpose, use the renderer adapter hooks instead — they let you swap the engine without editing bundled files.', 'jsray'); ?>
		</p>
	</div>
	<?php
}
add_action('admin_notices', 'jsray_wp_core_integrity_notice');

/**
 * The JSRay token vocabulary bundled with this Core snapshot.
 *
 * Maps a palette key (`function.declaration`) to its CSS variable and runtime
 * class suffix (`fn-decl`). Custom palettes are validated against this, so the
 * accepted vocabulary always describes the Core actually shipping here.
 *
 * @return array{tokens: array<string, string>, surfaces: array<string, string>}
 */
function jsray_wp_vocabulary() {
	static $vocabulary = null;

	if (null === $vocabulary) {
		$data       = jsray_wp_read_json('vocabulary.json');
		$vocabulary = array(
			'tokens'   => isset($data['tokens']) && is_array($data['tokens']) ? $data['tokens'] : array(),
			'surfaces' => isset($data['surfaces']) && is_array($data['surfaces']) ? $data['surfaces'] : array(),
		);
	}

	return $vocabulary;
}

/**
 * Resolve the active palette key, falling back to the default when a stored
 * value no longer exists (for example after a filter stops offering it).
 *
 * @return string
 */
function jsray_wp_active_palette() {
	$options  = jsray_wp_get_options();
	$palettes = jsray_wp_palettes();
	$palette  = isset($options['palette']) ? (string) $options['palette'] : 'default';

	return isset($palettes[$palette]) ? $palette : 'default';
}

/**
 * Read merged plugin options.
 *
 * @return array<string, string>
 */
function jsray_wp_get_options() {
	$options = get_option('jsray_wp_options', array());

	if (! is_array($options)) {
		$options = array();
	}

	return wp_parse_args($options, jsray_wp_default_options());
}

/**
 * Sanitize a JSRay language identifier.
 *
 * @param string $language Raw language identifier.
 * @return string
 */
function jsray_wp_sanitize_language($language) {
	$language = strtolower((string) $language);
	$language = preg_replace('/[^a-z0-9_-]/', '', $language);

	return is_string($language) ? $language : '';
}

/**
 * Supported language labels exposed to the block editor.
 *
 * @return array<string, string>
 */
function jsray_wp_supported_languages() {
	$languages = array(
		'js'         => __('JavaScript', 'jsray'),
		'ts'         => __('TypeScript', 'jsray'),
		'jsx'        => __('JSX', 'jsray'),
		'tsx'        => __('TSX', 'jsray'),
		'python'     => __('Python', 'jsray'),
		'php'        => __('PHP', 'jsray'),
		'go'         => __('Go', 'jsray'),
		'swift'      => __('Swift', 'jsray'),
		'kotlin'     => __('Kotlin', 'jsray'),
		'dart'       => __('Dart', 'jsray'),
		'lua'        => __('Lua', 'jsray'),
		'java'       => __('Java', 'jsray'),
		'c'          => __('C', 'jsray'),
		'cpp'        => __('C++', 'jsray'),
		'csharp'     => __('C#', 'jsray'),
		'ruby'       => __('Ruby', 'jsray'),
		'rust'       => __('Rust', 'jsray'),
		'html'       => __('HTML', 'jsray'),
		'xml'        => __('XML', 'jsray'),
		'svg'        => __('SVG', 'jsray'),
		'vue'        => __('Vue', 'jsray'),
		'css'        => __('CSS', 'jsray'),
		'scss'       => __('SCSS', 'jsray'),
		'json'       => __('JSON', 'jsray'),
		'jsonc'      => __('JSONC', 'jsray'),
		'bash'       => __('Bash', 'jsray'),
		'shell'      => __('Shell', 'jsray'),
		'zsh'        => __('Zsh', 'jsray'),
		'markdown'   => __('Markdown', 'jsray'),
		'sql'        => __('SQL', 'jsray'),
		'yaml'       => __('YAML', 'jsray'),
		'scala'      => __('Scala', 'jsray'),
		'objectivec' => __('Objective-C', 'jsray'),
		'r'          => __('R', 'jsray'),
		'perl'       => __('Perl', 'jsray'),
		'powershell' => __('PowerShell', 'jsray'),
		'elixir'     => __('Elixir', 'jsray'),
		'haskell'    => __('Haskell', 'jsray'),
		'graphql'    => __('GraphQL', 'jsray'),
		'toml'       => __('TOML', 'jsray'),
		'ini'        => __('INI', 'jsray'),
		'dockerfile' => __('Dockerfile', 'jsray'),
		'makefile'   => __('Makefile', 'jsray'),
		'diff'       => __('Diff', 'jsray'),
	);

	/**
	 * Filter language labels exposed by the JSRay WordPress integration.
	 *
	 * Renderer adapters can use this to add, remove, or relabel languages
	 * without replacing the block UI.
	 *
	 * @param array<string, string> $languages Language identifier to label map.
	 */
	return apply_filters('jsray_wp_supported_languages', $languages);
}

/**
 * Resolve a human-readable language label.
 *
 * @param string $language JSRay language identifier.
 * @return string
 */
function jsray_wp_language_label($language) {
	$languages = jsray_wp_supported_languages();
	$language  = jsray_wp_sanitize_language($language);

	if ('' === $language) {
		return __('Auto detect', 'jsray');
	}

	return isset($languages[$language]) ? $languages[$language] : strtoupper($language);
}

/**
 * Validate a CSS color value.
 *
 * Deliberately narrow: hex, rgb(), rgba(), hsl(), hsla(), and the CSS-wide
 * keywords. Anything else — url(), var(), expression(), a stray semicolon —
 * is refused, because these values are written straight into a style block.
 *
 * @param mixed $value Raw color.
 * @return string Empty string when the value is not an acceptable color.
 */
function jsray_wp_sanitize_color($value) {
	$value = trim((string) $value);

	if ('' === $value || strlen($value) > 64) {
		return '';
	}

	if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
		return $value;
	}

	if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9a-zA-Z.,%\/\s+-]+\s*\)$/', $value)) {
		return $value;
	}

	if (in_array(strtolower($value), array('transparent', 'currentcolor', 'inherit'), true)) {
		return $value;
	}

	return '';
}

/**
 * Validate a custom palette against the bundled token vocabulary.
 *
 * Accepts the palette shape JSRay Core uses everywhere else — the same JSON the
 * Theme Studio at jsray.org exports — so a palette authored once works on every
 * JSRay surface. Unknown keys and unusable values are dropped rather than
 * rejected wholesale, which keeps a palette written for a newer Core usable on
 * an older one.
 *
 * @param mixed $input Raw palette (JSON string or decoded array).
 * @return array{themes: array<string, array<string, mixed>>, errors: string[]}
 */
function jsray_wp_validate_palette($input) {
	$errors = array();

	if (is_string($input)) {
		$input = trim($input);

		if ('' === $input) {
			return array('themes' => array(), 'errors' => array());
		}

		$decoded = json_decode($input, true);

		if (! is_array($decoded)) {
			return array(
				'themes' => array(),
				'errors' => array(__('The palette is not valid JSON.', 'jsray')),
			);
		}

		$input = $decoded;
	}

	if (! is_array($input)) {
		return array('themes' => array(), 'errors' => array(__('The palette is not valid JSON.', 'jsray')));
	}

	// Accept both a full palette file and a bare { dark: …, light: … } map.
	$themes = isset($input['themes']) && is_array($input['themes']) ? $input['themes'] : $input;

	$vocabulary = jsray_wp_vocabulary();
	$valid      = array();

	foreach (array('dark', 'light') as $mode) {
		if (! isset($themes[$mode]) || ! is_array($themes[$mode])) {
			continue;
		}

		$source = $themes[$mode];
		$output = array('tokens' => array());

		foreach ($vocabulary['surfaces'] as $key => $suffix) {
			if (! isset($source[$key])) {
				continue;
			}

			$color = jsray_wp_sanitize_color($source[$key]);

			if ('' === $color) {
				/* translators: 1: palette key, 2: theme mode. */
				$errors[] = sprintf(__('Ignored an unusable color for "%1$s" in the %2$s theme.', 'jsray'), $key, $mode);
				continue;
			}

			$output[$key] = $color;
			unset($suffix);
		}

		$tokens = isset($source['tokens']) && is_array($source['tokens']) ? $source['tokens'] : array();

		foreach ($tokens as $key => $token) {
			if (! isset($vocabulary['tokens'][$key])) {
				/* translators: %s: palette token key. */
				$errors[] = sprintf(__('Ignored "%s" — not a JSRay token in this Core version.', 'jsray'), (string) $key);
				continue;
			}

			$color = jsray_wp_sanitize_color(is_array($token) ? ($token['color'] ?? '') : $token);

			if ('' === $color) {
				/* translators: 1: palette key, 2: theme mode. */
				$errors[] = sprintf(__('Ignored an unusable color for "%1$s" in the %2$s theme.', 'jsray'), (string) $key, $mode);
				continue;
			}

			$entry = array('color' => $color);

			if (is_array($token) && isset($token['fontStyle'])) {
				$style = strtolower(trim((string) $token['fontStyle']));

				if (in_array($style, array('bold', 'italic', 'bold italic', 'normal'), true)) {
					$entry['fontStyle'] = $style;
				}
			}

			$output['tokens'][$key] = $entry;
		}

		if (! empty($output['tokens']) || count($output) > 1) {
			$valid[$mode] = $output;
		}
	}

	if (empty($valid) && empty($errors)) {
		$errors[] = __('The palette contains no recognizable dark or light theme.', 'jsray');
	}

	return array('themes' => $valid, 'errors' => $errors);
}

/**
 * Build the CSS for a validated custom palette.
 *
 * Emitted as an inline stylesheet after the palette stylesheet, so it overrides
 * only the variables the user actually defined and inherits the rest.
 *
 * @param array<string, array<string, mixed>> $themes Validated themes.
 * @return string
 */
function jsray_wp_palette_css($themes) {
	$vocabulary = jsray_wp_vocabulary();
	$css        = '';

	foreach ($themes as $mode => $theme) {
		$variables = array();
		$rules     = '';

		foreach ($vocabulary['surfaces'] as $key => $suffix) {
			if (isset($theme[$key])) {
				$variables[] = sprintf('--jr-%s:%s', $suffix, $theme[$key]);
			}
		}

		foreach ($theme['tokens'] as $key => $token) {
			$suffix      = $vocabulary['tokens'][$key];
			$variables[] = sprintf('--jr-%s:%s', $suffix, $token['color']);

			// Colors are variable-driven, but weight and slant are declared on
			// the .tk-* classes in the core stylesheet — so overriding them
			// takes a real rule, not a custom property.
			if (isset($token['fontStyle'])) {
				$rules .= sprintf(
					'[data-theme="%1$s"] .tk-%2$s{font-weight:%3$s;font-style:%4$s}',
					$mode,
					$suffix,
					false !== strpos($token['fontStyle'], 'bold') ? '700' : '400',
					false !== strpos($token['fontStyle'], 'italic') ? 'italic' : 'normal'
				);
			}
		}

		if ($variables) {
			$css .= sprintf('[data-theme="%s"]{%s;}', $mode, implode(';', $variables));
		}

		$css .= $rules;
	}

	return $css;
}

/**
 * Sanitize plugin settings.
 *
 * @param mixed $input Raw option payload.
 * @return array<string, string>
 */
function jsray_wp_sanitize_options($input) {
	$defaults = jsray_wp_default_options();
	$output   = $defaults;

	if (! is_array($input)) {
		return $output;
	}

	$theme = isset($input['theme']) ? sanitize_key((string) $input['theme']) : $defaults['theme'];
	if (! in_array($theme, array('dark', 'light', 'inherit'), true)) {
		$theme = $defaults['theme'];
	}

	$palette = isset($input['palette']) ? sanitize_key((string) $input['palette']) : $defaults['palette'];
	if (! array_key_exists($palette, jsray_wp_palettes())) {
		$palette = $defaults['palette'];
	}

	// The custom palette is stored as the user typed it so the textarea can
	// round-trip, but it is validated here and again before any CSS is emitted.
	$custom = isset($input['custom_palette']) ? (string) $input['custom_palette'] : '';
	if ('' !== trim($custom)) {
		$validated = jsray_wp_validate_palette($custom);

		foreach ($validated['errors'] as $message) {
			add_settings_error('jsray_wp_options', 'jsray_wp_palette', $message, 'warning');
		}

		if (empty($validated['themes'])) {
			$custom = '';
		} else {
			add_settings_error(
				'jsray_wp_options',
				'jsray_wp_palette_ok',
				sprintf(
					/* translators: %s: comma-separated theme modes. */
					__('Custom palette accepted for: %s.', 'jsray'),
					implode(', ', array_keys($validated['themes']))
				),
				'success'
			);
		}
	}

	$output['theme']             = $theme;
	$output['palette']           = $palette;
	$output['custom_palette']    = $custom;
	$output['fallback_language'] = isset($input['fallback_language'])
		? jsray_wp_sanitize_language((string) $input['fallback_language'])
		: $defaults['fallback_language'];
	$output['enqueue_assets']    = empty($input['enqueue_assets']) ? '0' : '1';
	$output['scan_all_code']     = empty($input['scan_all_code']) ? '0' : '1';

	return $output;
}

/**
 * Build a cache-busting asset version.
 *
 * @param string $relative_path Path relative to plugin root.
 * @return string
 */
function jsray_wp_asset_version($relative_path) {
	$path = JSRAY_WP_DIR . ltrim($relative_path, '/');

	return file_exists($path) ? (string) filemtime($path) : JSRAY_WP_VERSION;
}

/**
 * Register shared front-end and editor assets.
 *
 * @return void
 */
function jsray_wp_register_assets() {
	// One handle per bundled palette. Exactly one is enqueued at a time; the
	// core stylesheet deliberately does not depend on any of them, so a
	// renderer adapter can supply its own palette instead.
	foreach (array_keys(jsray_wp_palettes()) as $palette) {
		$relative = 'assets/css/themes/' . $palette . '.css';

		if (! file_exists(JSRAY_WP_DIR . $relative)) {
			continue;
		}

		wp_register_style(
			'jsray-theme-' . $palette,
			JSRAY_WP_URL . $relative,
			array(),
			jsray_wp_asset_version($relative)
		);
	}

	wp_register_style(
		'jsray-core',
		JSRAY_WP_URL . 'assets/css/jsray.css',
		array(),
		jsray_wp_asset_version('assets/css/jsray.css')
	);

	wp_register_style(
		'jsray-block-frontend',
		JSRAY_WP_URL . 'assets/css/jsray-block.css',
		array('jsray-core'),
		jsray_wp_asset_version('assets/css/jsray-block.css')
	);

	wp_register_style(
		'jsray-block-editor',
		JSRAY_WP_URL . 'assets/css/jsray-block-editor.css',
		array('jsray-block-frontend'),
		jsray_wp_asset_version('assets/css/jsray-block-editor.css')
	);

	wp_register_script(
		'jsray-core',
		JSRAY_WP_URL . 'assets/js/jsray.js',
		array(),
		jsray_wp_asset_version('assets/js/jsray.js'),
		true
	);

	/**
	 * Filter script dependencies for the front-end loader.
	 *
	 * Renderer adapters that provide their own runtime can replace the default
	 * `jsray-core` dependency with their own registered script handle.
	 *
	 * @param string[] $dependencies Script handles required by the loader.
	 */
	$loader_dependencies = apply_filters('jsray_wp_loader_dependencies', array('jsray-core'));

	wp_register_script(
		'jsray-loader',
		JSRAY_WP_URL . 'assets/js/jsray-loader.js',
		$loader_dependencies,
		jsray_wp_asset_version('assets/js/jsray-loader.js'),
		true
	);

	wp_register_script(
		'jsray-block-editor',
		JSRAY_WP_URL . 'assets/js/jsray-block.js',
		array(
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
			'jsray-core',
		),
		jsray_wp_asset_version('assets/js/jsray-block.js'),
		true
	);

	// block.json is the single source of truth for the block's name, attributes,
	// and supports. The editor script reads it from here instead of repeating it.
	$metadata = array();
	$metadata_path = JSRAY_WP_DIR . 'block.json';
	if (file_exists($metadata_path)) {
		$decoded = json_decode((string) file_get_contents($metadata_path), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if (is_array($decoded)) {
			$metadata = $decoded;
		}
	}

	wp_add_inline_script(
		'jsray-block-editor',
		'window.JSRayBlockSettings = ' . wp_json_encode(
			array(
				'languages' => jsray_wp_supported_languages(),
				'metadata'  => $metadata,
			)
		) . ';',
		'before'
	);

	if (function_exists('wp_set_script_translations')) {
		wp_set_script_translations('jsray-block-editor', 'jsray', JSRAY_WP_DIR . 'languages');
		wp_set_script_translations('jsray-loader', 'jsray', JSRAY_WP_DIR . 'languages');
	}
}
add_action('init', 'jsray_wp_register_assets', 5);

/**
 * Register the JSRay Gutenberg block.
 *
 * @return void
 */
function jsray_wp_register_block() {
	// Attributes, supports, and asset handles all come from block.json, so the
	// PHP and JS sides cannot drift apart.
	register_block_type(
		JSRAY_WP_DIR . 'block.json',
		array(
			'render_callback' => 'jsray_wp_render_code_block',
		)
	);
}
add_action('init', 'jsray_wp_register_block', 20);

/**
 * Register the plugin settings UI.
 *
 * @return void
 */
function jsray_wp_register_settings() {
	register_setting(
		'jsray_wp',
		'jsray_wp_options',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'jsray_wp_sanitize_options',
			'default'           => jsray_wp_default_options(),
		)
	);

	add_settings_section(
		'jsray_wp_rendering',
		__('Rendering', 'jsray'),
		'__return_false',
		'jsray_wp'
	);

	add_settings_field(
		'jsray_wp_palette',
		__('Palette', 'jsray'),
		'jsray_wp_render_palette_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);

	add_settings_field(
		'jsray_wp_custom_palette',
		__('Custom colors', 'jsray'),
		'jsray_wp_render_custom_palette_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);

	add_settings_field(
		'jsray_wp_theme',
		__('Theme mode', 'jsray'),
		'jsray_wp_render_theme_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);

	add_settings_field(
		'jsray_wp_fallback_language',
		__('Fallback language', 'jsray'),
		'jsray_wp_render_fallback_language_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);

	add_settings_field(
		'jsray_wp_enqueue_assets',
		__('Front-end assets', 'jsray'),
		'jsray_wp_render_enqueue_assets_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);

	add_settings_field(
		'jsray_wp_scan_all_code',
		__('Code block coverage', 'jsray'),
		'jsray_wp_render_scan_all_code_field',
		'jsray_wp',
		'jsray_wp_rendering'
	);
}

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function jsray_wp_plugin_action_links($links) {
	$settings = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url(admin_url('options-general.php?page=jsray')),
		esc_html__('Settings', 'jsray')
	);

	array_unshift($links, $settings);

	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(JSRAY_WP_FILE), 'jsray_wp_plugin_action_links');
add_action('admin_init', 'jsray_wp_register_settings');

/**
 * Add the settings page.
 *
 * @return void
 */
function jsray_wp_add_settings_page() {
	add_options_page(
		__('JSRay', 'jsray'),
		__('JSRay', 'jsray'),
		'manage_options',
		'jsray',
		'jsray_wp_render_settings_page'
	);
}
add_action('admin_menu', 'jsray_wp_add_settings_page');

/**
 * Render palette field.
 *
 * @return void
 */
function jsray_wp_render_palette_field() {
	$active   = jsray_wp_active_palette();
	$palettes = jsray_wp_palettes();
	?>
	<select id="jsray-wp-palette" name="jsray_wp_options[palette]">
		<?php foreach ($palettes as $key => $label) : ?>
			<option value="<?php echo esc_attr($key); ?>" <?php selected($active, $key); ?>>
				<?php echo esc_html($label); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<p class="description"><?php esc_html_e('Every palette ships both a dark and a light variant, so this is independent of the theme mode below.', 'jsray'); ?></p>
	<?php
	// A custom palette silently outranks this setting. Without saying so, the
	// palette picker looks broken to anyone who pasted a palette and forgot.
	$overrides = jsray_wp_custom_palette_keys();

	if ($overrides) {
		?>
		<p class="description" style="color:#996800;">
			<strong><?php esc_html_e('Note:', 'jsray'); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of overridden values, 2: comma-separated list. */
					_n(
						'Custom colors below override %1$d value on top of this palette, so it will not change: %2$s',
						'Custom colors below override %1$d values on top of this palette, so they will not change: %2$s',
						count($overrides),
						'jsray'
					),
					count($overrides),
					implode(', ', $overrides)
				)
			);
			?>
		</p>
		<?php
	}
}

/**
 * List the values a stored custom palette overrides.
 *
 * @return string[] Human-readable "mode: key" labels.
 */
function jsray_wp_custom_palette_keys() {
	$options = jsray_wp_get_options();

	if ('' === trim((string) $options['custom_palette'])) {
		return array();
	}

	$validated = jsray_wp_validate_palette($options['custom_palette']);
	$keys      = array();

	foreach ($validated['themes'] as $mode => $theme) {
		foreach (array_keys($theme) as $key) {
			if ('tokens' !== $key) {
				$keys[] = $mode . ': ' . $key;
			}
		}

		foreach (array_keys($theme['tokens']) as $key) {
			$keys[] = $mode . ': ' . $key;
		}
	}

	return $keys;
}

/**
 * Render the custom palette field.
 *
 * @return void
 */
function jsray_wp_render_custom_palette_field() {
	$options = jsray_wp_get_options();
	$count   = count(jsray_wp_vocabulary()['tokens']);
	?>
	<textarea
		id="jsray-wp-custom-palette"
		name="jsray_wp_options[custom_palette]"
		rows="8"
		class="large-text code"
		spellcheck="false"
		placeholder='{"themes":{"dark":{"background":"#101014","tokens":{"keyword":{"color":"#FF6B9D","fontStyle":"bold"}}}}}'
	><?php echo esc_textarea($options['custom_palette']); ?></textarea>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of token classes. */
				__('Optional. Overrides individual colors on top of the palette above — anything you leave out keeps the palette\'s value. Accepts the same JSON the JSRay Theme Studio exports, covering all %d token classes.', 'jsray'),
				$count
			)
		);
		?>
		<br>
		<a href="https://jsray.org/studio.html" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e('Design a palette in the Theme Studio', 'jsray'); ?>
		</a>
	</p>
	<?php
}

/**
 * Render theme mode field.
 *
 * @return void
 */
function jsray_wp_render_theme_field() {
	$options = jsray_wp_get_options();
	$theme   = $options['theme'];
	?>
	<select id="jsray-wp-theme" name="jsray_wp_options[theme]">
		<option value="dark" <?php selected($theme, 'dark'); ?>><?php esc_html_e('Dark', 'jsray'); ?></option>
		<option value="light" <?php selected($theme, 'light'); ?>><?php esc_html_e('Light', 'jsray'); ?></option>
		<option value="inherit" <?php selected($theme, 'inherit'); ?>><?php esc_html_e('Inherit from theme', 'jsray'); ?></option>
	</select>
	<p class="description"><?php esc_html_e('Dark and light set data-theme on the page. Inherit leaves your WordPress theme in control.', 'jsray'); ?></p>
	<?php
}

/**
 * Render fallback language field.
 *
 * @return void
 */
function jsray_wp_render_fallback_language_field() {
	$options = jsray_wp_get_options();
	?>
	<input
		type="text"
		id="jsray-wp-fallback-language"
		name="jsray_wp_options[fallback_language]"
		value="<?php echo esc_attr($options['fallback_language']); ?>"
		placeholder="js"
		class="regular-text"
	/>
	<p class="description"><?php esc_html_e('Optional. Used when a code block has no language class and automatic detection is unsure.', 'jsray'); ?></p>
	<?php
}

/**
 * Render asset toggle field.
 *
 * @return void
 */
function jsray_wp_render_enqueue_assets_field() {
	$options = jsray_wp_get_options();
	?>
	<label for="jsray-wp-enqueue-assets">
		<input
			type="checkbox"
			id="jsray-wp-enqueue-assets"
			name="jsray_wp_options[enqueue_assets]"
			value="1"
			<?php checked($options['enqueue_assets'], '1'); ?>
		/>
		<?php esc_html_e('Load JSRay on public pages', 'jsray'); ?>
	</label>
	<?php
}

/**
 * Render the code-block coverage field.
 *
 * @return void
 */
function jsray_wp_render_scan_all_code_field() {
	$options = jsray_wp_get_options();
	?>
	<label for="jsray-wp-scan-all-code">
		<input
			type="checkbox"
			id="jsray-wp-scan-all-code"
			name="jsray_wp_options[scan_all_code]"
			value="1"
			<?php checked($options['scan_all_code'], '1'); ?>
		/>
		<?php esc_html_e('Also render plain code blocks that carry no language class', 'jsray'); ?>
	</label>
	<p class="description">
		<?php esc_html_e('On by default, so existing posts light up without editing. Turn it off to leave every unmarked block alone — useful when another plugin already renders code on your site.', 'jsray'); ?>
	</p>
	<?php
}

/**
 * Render settings page markup.
 *
 * @return void
 */
function jsray_wp_render_settings_page() {
	if (! current_user_can('manage_options')) {
		return;
	}
	?>
	<?php
	$integrity = jsray_wp_core_integrity();
	$labels    = array(
		'official' => array('#00a32a', __('Official build verified', 'jsray')),
		'modified' => array('#d63638', __('Modified — does not match the official build', 'jsray')),
		'unknown'  => array('#dba617', __('Unverified — no integrity manifest found', 'jsray')),
	);
	$label     = $labels[$integrity['status']];
	?>
	<div class="wrap">
		<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

		<p style="margin:1em 0;padding:.6em .9em;border-left:4px solid <?php echo esc_attr($label[0]); ?>;background:#fff;">
			<strong><?php esc_html_e('Rendering core', 'jsray'); ?>:</strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: Core version, 2: verification status, 3: number of files checked. */
					__('JSRay Core %1$s — %2$s (%3$d files checked)', 'jsray'),
					$integrity['version'] ? $integrity['version'] : __('unknown version', 'jsray'),
					$label[1],
					$integrity['checked']
				)
			);
			?>
		</p>

		<form action="options.php" method="post">
			<?php
			settings_fields('jsray_wp');
			do_settings_sections('jsray_wp');
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Attach the validated custom palette to an already-enqueued stylesheet.
 *
 * Re-validating here rather than trusting the stored string means a palette
 * written directly into the option — by WP-CLI, a migration, or another plugin
 * — still cannot inject arbitrary CSS.
 *
 * @param string $handle Style handle the CSS is appended to.
 * @return void
 */
function jsray_wp_add_custom_palette_css($handle) {
	$options = jsray_wp_get_options();

	if ('' === trim((string) $options['custom_palette'])) {
		return;
	}

	$validated = jsray_wp_validate_palette($options['custom_palette']);

	if (empty($validated['themes'])) {
		return;
	}

	$css = jsray_wp_palette_css($validated['themes']);

	if ('' !== $css) {
		wp_add_inline_style($handle, $css);
	}
}

/**
 * Enqueue front-end JSRay assets.
 *
 * @return void
 */
function jsray_wp_enqueue_assets() {
	$options = jsray_wp_get_options();

	if ('1' !== $options['enqueue_assets']) {
		return;
	}

	/**
	 * Filter whether the bundled JSRay Core assets should be loaded.
	 *
	 * Custom renderer adapters may return false and enqueue their own assets,
	 * while still using JSRay's block markup, copy controls, and settings.
	 *
	 * @param bool $enqueue_core_assets Whether to enqueue bundled Core assets.
	 */
	$enqueue_core_assets = apply_filters('jsray_wp_enqueue_core_assets', true);

	if ($enqueue_core_assets) {
		// The custom palette rides on the palette stylesheet's own handle. It
		// overrides the same custom properties, so it has to be printed after
		// that file — and WordPress only guarantees that ordering for inline
		// CSS attached to the very handle being overridden.
		$palette_handle = 'jsray-theme-' . jsray_wp_active_palette();

		wp_enqueue_style($palette_handle);
		wp_enqueue_style('jsray-core');
		wp_enqueue_script('jsray-core');
		jsray_wp_add_custom_palette_css($palette_handle);
	}

	wp_enqueue_script('jsray-loader');

	$config = array(
		'theme'              => $options['theme'],
		'palette'            => jsray_wp_active_palette(),
		'fallbackLanguage'   => $options['fallback_language'],
		'autoDetectLanguage' => true,
		'scanAllCode'        => '1' === $options['scan_all_code'],
		// The loader runs outside PHP, so its user-facing strings are passed in
		// rather than hardcoded — otherwise the copy button is English-only.
		'i18n'               => array(
			'copy'   => __('Copy', 'jsray'),
			'copied' => __('Copied', 'jsray'),
			'failed' => __('Failed', 'jsray'),
		),
		// Labels for the language the browser detects, so a block header reads
		// the same whether the language was chosen in the editor or resolved here.
		'languages'          => jsray_wp_supported_languages(),
	);

	/**
	 * Filter the front-end runtime configuration passed to jsray-loader.js.
	 *
	 * Custom integrations can add renderer-specific options here and expose a
	 * compatible `window.JSRayWP.renderer.highlightElement(element)` object.
	 *
	 * @param array<string, mixed> $config Front-end runtime config.
	 */
	$config = apply_filters('jsray_wp_frontend_config', $config);

	wp_add_inline_script(
		'jsray-loader',
		'window.JSRayWP = Object.assign({}, window.JSRayWP || {}, ' . wp_json_encode($config) . ');',
		'before'
	);
}
add_action('wp_enqueue_scripts', 'jsray_wp_enqueue_assets');

/**
 * Load the selected palette in the block editor.
 *
 * The editor preview renders through the same stylesheets as the front end, so
 * without this the preview would always show the default palette while the
 * published page shows the chosen one.
 *
 * @return void
 */
function jsray_wp_enqueue_editor_assets() {
	$handle = 'jsray-theme-' . jsray_wp_active_palette();
	wp_enqueue_style($handle);
	jsray_wp_add_custom_palette_css($handle);
}
add_action('enqueue_block_editor_assets', 'jsray_wp_enqueue_editor_assets');

/**
 * Sanitize a whitespace-separated class list.
 *
 * @param string $class_list Raw class list.
 * @return string[]
 */
function jsray_wp_sanitize_class_list($class_list) {
	$classes = preg_split('/\s+/', (string) $class_list);

	if (! is_array($classes)) {
		return array();
	}

	$sanitized = array();
	foreach ($classes as $class_name) {
		$class_name = sanitize_html_class($class_name);
		if ('' !== $class_name) {
			$sanitized[] = $class_name;
		}
	}

	return array_values(array_unique($sanitized));
}

/**
 * Render line-number gutter markup.
 *
 * @param string $code Code content.
 * @return string
 */
function jsray_wp_render_line_numbers($code, $highlighted = array()) {
	$line_count = max(1, substr_count((string) $code, "\n") + 1);
	$items      = '';

	for ($i = 1; $i <= $line_count; $i++) {
		$items .= in_array($i, $highlighted, true)
			? '<li class="is-highlighted"></li>'
			: '<li></li>';
	}

	return '<ol class="jsray-block__gutter" aria-hidden="true">' . $items . '</ol>';
}

/**
 * Parse a line-highlight spec such as `3,7-9,12`.
 *
 * Pointing at the lines being discussed is the thing a code block in a tutorial
 * is usually for, and doing it in prose ("see line 7") makes the reader count.
 *
 * Anything unparseable is dropped rather than failing the render: a typo in an
 * attribute should cost the highlight, not the code block.
 *
 * @param string $spec Comma-separated line numbers and ranges.
 * @param int    $max  Total lines available; anything past it is discarded.
 * @return int[] Sorted, unique line numbers.
 */
function jsray_wp_parse_highlight($spec, $max) {
	$lines = array();

	foreach (explode(',', (string) $spec) as $part) {
		$part = trim($part);

		if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
			$from = (int) $m[1];
			$to   = (int) $m[2];

			// A reversed range is a typo with an obvious reading.
			if ($from > $to) {
				$swap = $from;
				$from = $to;
				$to   = $swap;
			}

			// Bounded before looping: `1-999999` on a ten-line block should
			// cost ten entries, not a million.
			for ($i = max(1, $from); $i <= min($to, $max); $i++) {
				$lines[] = $i;
			}
			continue;
		}

		if (preg_match('/^\d+$/', $part)) {
			$line = (int) $part;
			if ($line >= 1 && $line <= $max) {
				$lines[] = $line;
			}
		}
	}

	$lines = array_values(array_unique($lines));
	sort($lines);

	return $lines;
}

/**
 * Render the dynamic JSRay Gutenberg block.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string
 */
function jsray_wp_render_code_block($attributes) {
	$attributes = is_array($attributes) ? $attributes : array();
	$language   = isset($attributes['language']) ? jsray_wp_sanitize_language((string) $attributes['language']) : '';
	$filename   = isset($attributes['filename']) ? sanitize_text_field((string) $attributes['filename']) : '';
	$code       = isset($attributes['code']) ? (string) $attributes['code'] : '';
	$show_copy  = ! isset($attributes['showCopyButton']) || (bool) $attributes['showCopyButton'];
	$show_lines = ! empty($attributes['showLineNumbers']);
	$highlight  = isset($attributes['highlight']) ? (string) $attributes['highlight'] : '';

	if ('' === trim($code)) {
		return '';
	}

	$wrapper_classes = array('jsray-block');

	if ($language) {
		$wrapper_classes[] = 'language-' . $language;
	}

	if ($show_lines) {
		$wrapper_classes[] = 'has-line-numbers';
	}

	$header = '';
	if ($filename || $show_copy) {
		$title = $filename ? $filename : jsray_wp_language_label($language);
		$copy  = $show_copy
			? sprintf(
				'<button class="jsray-block__copy" type="button" data-jsray-copy aria-label="%1$s">%2$s</button>',
				esc_attr__('Copy code', 'jsray'),
				esc_html__('Copy', 'jsray')
			)
			: '';

		// The language may be resolved in the browser rather than here — PHP
		// cannot read the code and guess. These markers let the front-end loader
		// replace the placeholder once detection has actually run, instead of
		// leaving "Auto detect" on a block that was detected fine.
		$header = sprintf(
			'<div class="jsray-block__header"><span class="jsray-block__title"%4$s>%1$s</span><span class="jsray-block__language" data-jsray-language-label>%2$s</span>%3$s</div>',
			esc_html($title),
			esc_html(jsray_wp_language_label($language)),
			$copy,
			$filename ? '' : ' data-jsray-title-language'
		);
	}

	// Highlighting is drawn from the gutter, which is the only element with one
	// node per line. Asking for a highlight without line numbers would silently
	// do nothing, so the request turns them on.
	$highlighted = $highlight
		? jsray_wp_parse_highlight($highlight, max(1, substr_count($code, "\n") + 1))
		: array();

	if ($highlighted) {
		$show_lines = true;
		if (! in_array('has-line-numbers', $wrapper_classes, true)) {
			$wrapper_classes[] = 'has-line-numbers';
		}
	}

	$line_numbers = $show_lines ? jsray_wp_render_line_numbers($code, $highlighted) : '';
	$pre_classes  = array('jsray');
	$code_classes = array();

	if ($language) {
		$pre_classes[]  = 'language-' . $language;
		$code_classes[] = 'language-' . $language;
	}

	// get_block_wrapper_attributes() is what carries the block's own supports —
	// alignment (wide/full), the HTML anchor, and any custom class the user
	// added — onto the rendered markup. Building the class list by hand would
	// silently drop all of them.
	// get_block_wrapper_attributes() only works while WordPress is rendering a
	// block; called outside that context it emits a PHP warning. Themes and
	// adapters do sometimes invoke a render callback directly, so fall back to
	// a hand-built wrapper rather than warning in their error log.
	$in_block_render = class_exists('WP_Block_Supports')
		&& ! empty(WP_Block_Supports::$block_to_render);

	// Callers outside a block render — the shortcode, a theme template — carry
	// their own classes here, because get_block_wrapper_attributes() is not
	// available to add them.
	if (! empty($attributes['className'])) {
		$wrapper_classes = array_merge(
			$wrapper_classes,
			jsray_wp_sanitize_class_list((string) $attributes['className'])
		);
	}

	$wrapper = ($in_block_render && function_exists('get_block_wrapper_attributes'))
		? get_block_wrapper_attributes(
			array(
				'class'          => implode(' ', $wrapper_classes),
				'data-jsray-block' => '',
				'data-language'  => $language,
			)
		)
		: sprintf(
			'class="%1$s" data-jsray-block data-language="%2$s"',
			esc_attr('wp-block-jsray-code ' . implode(' ', $wrapper_classes)),
			esc_attr($language)
		);

	$html = sprintf(
		'<div %1$s>%2$s<div class="jsray-block__body">%3$s<pre class="%4$s"><code class="%5$s">%6$s</code></pre></div></div>',
		$wrapper,
		$header,
		$line_numbers,
		esc_attr(implode(' ', $pre_classes)),
		esc_attr(implode(' ', $code_classes)),
		esc_html($code)
	);

	/**
	 * Filter the rendered JSRay Gutenberg block HTML.
	 *
	 * Renderer adapters can replace the markup or inject server-rendered
	 * highlighted HTML while preserving the same block attributes.
	 *
	 * @param string               $html       Rendered block HTML.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $code       Raw code content.
	 * @param string               $language   Sanitized language identifier.
	 */
	return apply_filters('jsray_wp_rendered_block_html', $html, $attributes, $code, $language);
}

/**
 * Undo the paragraph markup WordPress injects into shortcode content.
 *
 * `wpautop()` runs on the_content at priority 10 and `do_shortcode()` at 11, so
 * by the time this shortcode sees its own content, every newline has already
 * become a `<br />` and blank lines have become paragraph tags. Escaping that
 * verbatim is how a code block ends up displaying literal `<br />` markers.
 *
 * @param string $content Shortcode content after wpautop.
 * @return string
 */
function jsray_wp_undo_autop($content) {
	// wpautop writes `<br />` followed by a real newline, so replacing the tag
	// alone leaves two. Every line of a shortcode block came out double-spaced.
	$content = preg_replace('#<br\s*/?>\n?#i', "\n", (string) $content);
	$content = preg_replace('#\s*</p>\s*<p>\s*#i', "\n\n", (string) $content);
	$content = preg_replace('#</?p>#i', '', (string) $content);

	return is_string($content) ? $content : '';
}

/**
 * Keep WordPress from applying smart quotes and dashes to code.
 *
 * Without this, `fmt.Println("hi")` is served as `fmt.Println(“hi”)` — code a
 * reader cannot copy and run.
 *
 * @param string[] $shortcodes Shortcode tags exempt from texturizing.
 * @return string[]
 */
function jsray_wp_no_texturize($shortcodes) {
	$shortcodes[] = 'jsray';

	return $shortcodes;
}
add_filter('no_texturize_shortcodes', 'jsray_wp_no_texturize');

/**
 * Render [jsray lang="js"]...[/jsray].
 *
 * @param array<string, string> $atts Shortcode attributes.
 * @param string|null           $content Shortcode content.
 * @return string
 */
function jsray_wp_render_shortcode($atts, $content = null) {
	$atts = shortcode_atts(
		array(
			'lang'         => '',
			'language'     => '',
			'class'        => '',
			'filename'     => '',
			'copy'         => '',
			'line-numbers' => '',
			'highlight'    => '',
		),
		is_array($atts) ? $atts : array(),
		'jsray'
	);

	$options  = jsray_wp_get_options();
	$language = $atts['language'] ? $atts['language'] : $atts['lang'];
	$language = $language ? jsray_wp_sanitize_language($language) : $options['fallback_language'];

	$charset = get_bloginfo('charset');
	$charset = $charset ? $charset : 'UTF-8';
	$code    = html_entity_decode(jsray_wp_undo_autop((string) $content), ENT_QUOTES | ENT_HTML5, $charset);
	$code    = trim($code, "\n\r");

	// The shortcode used to emit a bare <pre><code> while the block produced a
	// header, a copy button and a gutter — the same plugin giving a classic
	// editor or a theme template strictly less than the block editor, for no
	// reason but that nobody had wired it up. It renders through the block now.
	$html = jsray_wp_render_code_block(
		array(
			'code'            => $code,
			'language'        => $language,
			'filename'        => sanitize_text_field($atts['filename']),
			// Unspecified means the block default: copy on, line numbers off.
			'showCopyButton'  => '' === $atts['copy'] ? true : jsray_wp_shortcode_bool($atts['copy']),
			'showLineNumbers' => jsray_wp_shortcode_bool($atts['line-numbers']),
			'highlight'       => $atts['highlight'],
			'className'       => $atts['class'],
		)
	);

	/**
	 * Filter the rendered [jsray] shortcode HTML.
	 *
	 * Renderer adapters can replace the markup or inject server-rendered
	 * highlighted HTML while preserving shortcode attributes.
	 *
	 * @param string                $html     Rendered shortcode HTML.
	 * @param array<string, string> $atts     Shortcode attributes.
	 * @param string                $code     Raw code content.
	 * @param string                $language Sanitized language identifier.
	 */
	// The block's stylesheet is registered through block.json's `style`, which
	// WordPress enqueues only when that block is on the page. This renders the
	// block's markup without being a block, so it has to ask for the styles
	// itself — otherwise the header, the gutter and the highlight band arrive
	// with no CSS at all, which is what a real page showed.
	if (wp_style_is('jsray-block-frontend', 'registered')) {
		wp_enqueue_style('jsray-block-frontend');
	}

	return apply_filters('jsray_wp_rendered_shortcode_html', $html, $atts, $code, $language);
}

/**
 * Read a shortcode attribute as a boolean.
 *
 * Shortcode attributes are always strings, so `copy="false"` arrives as the
 * non-empty string "false" and is true to PHP. Anything that reads as off is
 * off; anything else present is on.
 *
 * @param string $value Raw attribute value.
 * @return bool
 */
function jsray_wp_shortcode_bool($value) {
	$value = strtolower(trim((string) $value));

	if ('' === $value) {
		return false;
	}

	return ! in_array($value, array('false', 'no', 'off', '0'), true);
}

add_shortcode('jsray', 'jsray_wp_render_shortcode');
