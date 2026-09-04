<?php
/**
 * Uninstall cleanup.
 *
 * Run by WordPress only when the plugin is deleted (not on deactivation).
 * Removes options and transients so nothing is left behind in the database.
 *
 * @package DionesRamos\MovieShowcase
 */

// Strict typing: no scalar coercion on calls made from this file.
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// TODO: list here every option the plugin creates.
$options = array();

foreach ($options as $option) {
	delete_option($option);
	delete_site_option($option);
}
