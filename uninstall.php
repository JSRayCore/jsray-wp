<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user deletes the plugin from the Plugins screen, not on
 * deactivation. Everything JSRay stores lives in a single option, so removing
 * it leaves the database exactly as it was before the plugin was installed.
 *
 * @package JSRay
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Delete the option everywhere it can exist.
 *
 * The work is inside a function because a variable written at file scope in
 * uninstall.php is a global, and an unprefixed global from a plugin can collide
 * with anything else in the request. Plugin Check flags it, and it is the kind
 * of thing that costs a review round for no benefit — nothing here needs to
 * outlive the call.
 *
 * @return void
 */
function jsray_wp_uninstall_cleanup() {
	delete_option('jsray_wp_options');

	// The option is per-site, so on a network it has to be cleared on each one.
	if (! is_multisite()) {
		return;
	}

	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ($site_ids as $site_id) {
		switch_to_blog($site_id);
		delete_option('jsray_wp_options');
		restore_current_blog();
	}
}

jsray_wp_uninstall_cleanup();
