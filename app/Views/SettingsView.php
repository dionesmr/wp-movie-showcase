<?php
/**
 * Settings screen markup.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Views;

use DionesRamos\MovieShowcase\Services\ApiKey;

defined( 'ABSPATH' ) || exit;

/**
 * Options page rendering.
 *
 * View separated from the controller: only HTML and escaping live here, no
 * business logic. This makes security audits easy — just check that every
 * printed variable goes through an esc_* function.
 */
final class SettingsView {

	/**
	 * Draws the whole page.
	 *
	 * @param string $slug Option group and page slug.
	 */
	public static function render_page( string $slug ): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			// Shows the errors registered by the sanitize_callback (invalid
			// key) and the WordPress success message.
			settings_errors( ApiKey::OPTION );
			?>

			<form method="post" action="options.php">
				<?php
				// settings_fields() prints three hidden fields: option_page,
				// action and _wpnonce. It is what protects the POST against
				// CSRF — options.php refuses the write if the nonce does not
				// match.
				settings_fields( $slug );

				do_settings_sections( $slug );
				submit_button( __( 'Save settings', 'wp-movie-showcase' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Section introductory text.
	 */
	public static function render_section_intro(): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: link to the OMDb API key request page */
				esc_html__( 'Get a free key at %s and paste it below.', 'wp-movie-showcase' ),
				'<a href="https://www.omdbapi.com/apikey.aspx" target="_blank" rel="noopener noreferrer">omdbapi.com</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Draws the API key field.
	 *
	 * The most sensitive spot on the screen. The stored key is NEVER printed
	 * in the HTML: the field shows only a mask. If the real key were echoed
	 * here, an XSS, a browser extension or a screenshot would be enough to
	 * leak the credential.
	 *
	 * The type="password" and autocomplete="new-password" prevent the browser
	 * (or a password manager) from storing and later autofilling the value.
	 *
	 * @param array<string, mixed> $args Arguments coming from add_settings_field().
	 */
	public static function render_api_key_field( array $args ): void {
		$api_key = $args['api_key'] ?? null;

		if ( ! $api_key instanceof ApiKey ) {
			return;
		}

		$has_key = $api_key->has();
		?>
		<input
			type="password"
			id="<?php echo esc_attr( ApiKey::OPTION ); ?>"
			name="<?php echo esc_attr( ApiKey::OPTION ); ?>"
			value="<?php echo esc_attr( $api_key->masked() ); ?>"
			class="regular-text"
			autocomplete="new-password"
			spellcheck="false"
			placeholder="<?php esc_attr_e( 'Paste your OMDb API key', 'wp-movie-showcase' ); ?>"
		>

		<p class="description">
			<?php if ( $has_key ) : ?>
				<?php esc_html_e( 'A key is saved. Leave the field untouched to keep it, or type a new key to replace it.', 'wp-movie-showcase' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'No key saved yet. The block will return no results until a key is configured.', 'wp-movie-showcase' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}
}
