<?php
/**
 * Plugin Name:       WP Movie Showcase
 * Plugin URI:        https://github.com/dionesmr/wp-movie-showcase
 * Description:       Movie catalogue and showcase for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Diones Menqui Ramos
 * Author URI:        https://www.linkedin.com/in/diones-ramos-6a0616128/
 * Text Domain:       wp-movie-showcase
 * Domain Path:       /languages
 *
 * @package DionesRamos\MovieShowcase
 */

namespace DionesRamos\MovieShowcase;

defined('ABSPATH') || exit;

const VERSION = '0.1.0';
const MIN_PHP = '8.1';
const MIN_WP  = '6.0';

define(__NAMESPACE__ . '\FILE', __FILE__);
define(__NAMESPACE__ . '\PATH', plugin_dir_path(__FILE__));
define(__NAMESPACE__ . '\URL', plugin_dir_url(__FILE__));
define(__NAMESPACE__ . '\BASENAME', plugin_basename(__FILE__));

/**
 * Environment requirement that stops the plugin from running.
 *
 * Runs before any autoloading so that an unsupported PHP or WordPress version
 * surfaces as an admin notice instead of a fatal error.
 *
 * @return string Problem code ('php' or 'wp'), or an empty string when fine.
 */
function environment_error(): string {
	if (version_compare(PHP_VERSION, MIN_PHP, '<')) {
		return 'php';
	}

	if (version_compare(get_bloginfo('version'), MIN_WP, '<')) {
		return 'wp';
	}

	return '';
}

/**
 * Composer dependencies.
 *
 * Deliberately kept apart from environment_error(): a missing autoloader is
 * recoverable with `composer install`, so it only warns and never blocks
 * activation.
 *
 * @return string Problem code ('composer'), or an empty string when fine.
 */
function dependency_error(): string {
	return file_exists(PATH . 'vendor/autoload.php') ? '' : 'composer';
}

/**
 * Turns a problem code into the message shown to the user.
 *
 * @param  string $code Code returned by environment_error() or dependency_error().
 * @return string Translated message; may contain HTML allowed by wp_kses_post().
 */
function error_message(string $code): string {
	switch ($code) {
		case 'php':
			return sprintf(
				/* translators: 1: minimum PHP version, 2: current PHP version */
				__('Movie Showcase requires PHP %1$s or newer. This site runs PHP %2$s.', 'wp-movie-showcase'),
				MIN_PHP,
				PHP_VERSION
			);

		case 'wp':
			return sprintf(
				/* translators: 1: minimum WordPress version, 2: current WordPress version */
				__('Movie Showcase requires WordPress %1$s or newer. This site runs version %2$s.', 'wp-movie-showcase'),
				MIN_WP,
				get_bloginfo('version')
			);

		case 'composer':
			return __('Movie Showcase could not find its Composer dependencies. Run <code>composer install</code> in the plugin folder.', 'wp-movie-showcase');

		default:
			return '';
	}
}

/**
 * Queues an error notice in the admin.
 *
 * @param string $code Problem code.
 */
function render_notice(string $code): void {
	add_action('admin_notices', static function () use ($code): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post(error_message($code))
		);
	});
}

/**
 * Plugin entry point.
 *
 * Loads the autoloader and hands control to the main class. Any unmet
 * requirement becomes an admin notice
 */
function bootstrap(): void {
	foreach (array(environment_error(), dependency_error()) as $code) {
		if ($code !== '') {
			render_notice($code);

			return;
		}
	}

	require_once PATH . 'vendor/autoload.php';

	// TODO: implement app/Plugin.php with a static boot() method.

	if (class_exists(Plugin::class)) {
		Plugin::boot();
	}
}

add_action('plugins_loaded', __NAMESPACE__ . '\bootstrap');

/**
 * Loads translations.
 */
add_action('init', static function (): void {
	load_plugin_textdomain(
		'wp-movie-showcase',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
});

register_activation_hook(__FILE__, static function (): void {
	$code = environment_error();

	if ($code !== '') {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(wp_kses_post(error_message($code)));
	}

	// TODO: register the CPT before flushing so permalinks come out right.
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
	flush_rewrite_rules();
});
