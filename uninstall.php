<?php
/**
 * Cleanup on uninstall.
 *
 * Executed by WordPress only when the plugin is deleted (not on
 * deactivation). Removes options and transients to leave no garbage in the
 * database — and, more importantly here, to not leave the API key stored on
 * a site where the plugin no longer exists.
 *
 * @package DionesRamos\MovieShowcase
 */

// Strict typing: no scalar coercion in calls made from this file.
declare(strict_types=1);

// Constant defined by WordPress itself when running the uninstall. Without
// it, the file was accessed directly by URL and must not execute anything.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Options created by the plugin.
 *
 * The first stores the OMDb credential; the second, the cache version.
 */
$movie_showcase_options = array(
	'movie_showcase_omdb_api_key',
	'movie_showcase_cache_version',
);

foreach ( $movie_showcase_options as $movie_showcase_option ) {
	delete_option( $movie_showcase_option );
	// Covers multisite installs, where the option may live in the network.
	delete_site_option( $movie_showcase_option );
}

/**
 * Removes the OMDb response cache and rate limit transients.
 *
 * There is no WordPress API to delete transients by prefix, so the direct
 * query is unavoidable. Care taken:
 *
 * - $wpdb->options and $wpdb->prefix come from WordPress itself, not from
 *   user input;
 * - esc_like() escapes the "%" and "_" wildcards of LIKE, so the prefix is
 *   treated as literal text;
 * - prepare() binds the values — no string is concatenated into the query.
 */
global $wpdb;

$movie_showcase_prefixes = array( 'movie_showcase_omdb_', 'movie_showcase_rl_' );

foreach ( $movie_showcase_prefixes as $movie_showcase_prefix ) {
	$movie_showcase_like = $wpdb->esc_like( $movie_showcase_prefix ) . '%';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- there is no API to delete transients by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $movie_showcase_like,
			'_transient_timeout_' . $movie_showcase_like
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

// An external object cache (Redis, Memcached) may still hold the values in
// memory after the DELETE above.
wp_cache_flush();
