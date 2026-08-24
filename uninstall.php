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

delete_option('jsray_wp_options');

// Multisite: the option is per-site, so clear it on every site in the network.
if (is_multisite()) {
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
