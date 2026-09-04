<?php
/**
 * Plugin settings screen.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Controllers;

use DionesRamos\MovieShowcase\Services\ApiKey;
use DionesRamos\MovieShowcase\Views\SettingsView;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page under Settings > Movie Showcase.
 *
 * Uses the WordPress Settings API on purpose, instead of a hand-rolled form.
 * It delivers three security layers for free that would be easy to forget in
 * a home-grown implementation:
 *
 * 1. NONCE: settings_fields() prints the _wpnonce field, and options.php
 *    validates it before storing anything. Protects against CSRF.
 * 2. CAPABILITY: options.php requires the capability associated with the
 *    option group (manage_options, by default) before accepting the POST.
 * 3. SANITIZATION: the sanitize_callback from register_setting always runs,
 *    whether the value comes from the screen, the REST API or WP-CLI.
 */
final class SettingsPage {

	/**
	 * Page and option group slug.
	 */
	private const SLUG = 'movie-showcase';

	/**
	 * Capability required to view and save the settings.
	 *
	 * manage_options = administrator. The API key is a service credential: it
	 * must not be within reach of editors or authors.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Already hooks the admin actions.
	 *
	 * @param ApiKey $api_key Service that stores the credential.
	 */
	public function __construct( private readonly ApiKey $api_key ) {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the submenu page under Settings.
	 *
	 * The fifth argument (capability) makes WordPress hide the menu item and
	 * block direct URL access for anyone without permission.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Movie Showcase', 'wp-movie-showcase' ),
			__( 'Movie Showcase', 'wp-movie-showcase' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Declares the option and the form field.
	 */
	public function register_settings(): void {
		register_setting(
			self::SLUG,
			ApiKey::OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				// Without a sanitize_callback, the raw POST value goes straight
				// to the database. This is the point where the key is
				// validated.
				'sanitize_callback' => array( $this->api_key, 'sanitize' ),
				// Prevents the key from being exposed by the core REST API at
				// /wp/v2/settings, which any logged-in user with settings read
				// permission could query.
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'movie_showcase_credentials',
			__( 'OMDb credentials', 'wp-movie-showcase' ),
			array( $this, 'render_section_intro' ),
			self::SLUG
		);

		add_settings_field(
			ApiKey::OPTION,
			__( 'API key', 'wp-movie-showcase' ),
			array( SettingsView::class, 'render_api_key_field' ),
			self::SLUG,
			'movie_showcase_credentials',
			array(
				'label_for' => ApiKey::OPTION,
				'api_key'   => $this->api_key,
			)
		);
	}

	/**
	 * Section helper text.
	 */
	public function render_section_intro(): void {
		SettingsView::render_section_intro();
	}

	/**
	 * Draws the page.
	 *
	 * The capability check here is redundant with the one in
	 * add_options_page — on purpose. Defense in depth: if this callback is
	 * ever reached through another path (another plugin, a poorly written
	 * do_action), the check still holds.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'wp-movie-showcase' ),
				'',
				array( 'response' => 403 )
			);
		}

		SettingsView::render_page( self::SLUG );
	}
}
