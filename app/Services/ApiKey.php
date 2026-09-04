<?php
/**
 * Stores and validates the OMDb API key.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Services;

defined('ABSPATH') || exit;

/**
 * Encapsulates access to the API key.
 *
 * Every read and write of the key goes through here. Centralizing this in a
 * single class prevents the key from being read loose throughout the code
 * and guarantees that the rule of "never return the real value to the
 * screen" holds everywhere.
 */
final class ApiKey {

	/**
	 * Option name in the database.
	 *
	 * Prefixed so it does not collide with options from other plugins.
	 */
	public const OPTION = 'movie_showcase_omdb_api_key';

	/**
	 * Value sent by the form when the user does NOT want to change the key.
	 *
	 * The admin field shows this mask instead of the real key. If the POST
	 * arrives with it, we understand nothing should change.
	 */
	public const MASK = '••••••••';

	/**
	 * Returns the key in plain text.
	 *
	 * Restricted to the HTTP client. NEVER send the return of this to the
	 * browser.
	 */
	public function get(): string {
		// get_option may return false/array if the option gets corrupted by
		// other code; the cast + is_string guarantee a string always comes out.
		$value = get_option( self::OPTION, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Tells whether a key is configured.
	 */
	public function has(): bool {
		return (bool) $this->get();
	}

	/**
	 * Safe value to display in the admin form.
	 *
	 * Returns only the mask — the real key is never printed in the HTML. That
	 * is the central point: an XSS, a browser extension or a screenshot of
	 * the settings screen gives no access to the credential.
	 */
	public function masked(): string {
		return $this->has() ? self::MASK : '';
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Runs on every save of the options screen. Three paths:
	 *
	 * 1. Field came with the mask (or empty) -> keeps the current key.
	 *    Without this, saving the page without touching the field would wipe
	 *    the key.
	 * 2. Field came with an invalid format -> rejects it, keeps the current
	 *    key and registers an error that the Settings API displays on screen.
	 * 3. Field came with a valid new key -> stores it.
	 *
	 * @param  mixed $value Raw value from $_POST (the Settings API already applied wp_unslash).
	 * @return string Value to be stored in the option.
	 */
	public function sanitize( $value ): string {
		$current = $this->get();

		// Wrong type (array, null) never becomes a key.
		if ( ! is_string( $value ) ) {
			return $current;
		}

		// MIND THE ORDER: validate first, sanitize after.
		//
		// The only treatment before validation is trim, for whitespace pasted
		// along with the key. Passing sanitize_text_field() here would be a
		// subtle security bug: it would strip the tags from "key<script>" and
		// "key" would remain, which passes the regex and would be stored as
		// the new key. Invalid input must be REJECTED, not silently fixed.
		$raw = trim( $value );

		// Path 1: there was no intention to change the key.
		if ( '' === $raw || self::MASK === $raw ) {
			return $current;
		}

		// Path 2: invalid format. OMDb keys are alphanumeric (usually 8
		// hexadecimal characters). The validation is deliberately tolerant on
		// length so it does not reject a valid key of another format, but it
		// closes the door to any character outside [A-Za-z0-9].
		if ( ! preg_match( '/^[A-Za-z0-9]{4,64}$/', $raw ) ) {
			add_settings_error(
				self::OPTION,
				'movie_showcase_invalid_api_key',
				__( 'The OMDb API key must contain only letters and numbers.', 'wp-movie-showcase' ),
				'error'
			);

			return $current;
		}

		// Path 3: new key accepted. The regex above already proved there are
		// only [A-Za-z0-9], so sanitize_text_field is redundant — it stays as
		// defense in depth, in case the regex is loosened in the future.
		$value = sanitize_text_field( $raw );

		// Flushes the cache so the next searches use the new credential
		// instead of serving responses stored with the old key.
		if ( $value !== $current ) {
			OmdbClient::flush_cache();
		}

		return $value;
	}
}
