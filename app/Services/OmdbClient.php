<?php
/**
 * HTTP client for the OMDb API.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to https://www.omdbapi.com/ and returns normalized, cleaned data.
 *
 * Responsibilities:
 * - build the URL without ever concatenating strings by hand (add_query_arg + rawurlencode);
 * - cache in transients, so we neither blow the API quota nor make the
 *   visitor wait for an external call on every search;
 * - treat EVERY piece of data coming back from OMDb as untrusted and
 *   sanitize it field by field.
 */
final class OmdbClient {

	/**
	 * API endpoint. HTTPS is mandatory: the key travels in the querystring.
	 */
	private const ENDPOINT = 'https://www.omdbapi.com/';

	/**
	 * Cache transient prefix.
	 */
	private const CACHE_PREFIX = 'movie_showcase_omdb_';

	/**
	 * Option that holds the cache version.
	 *
	 * Transients have no "delete everything matching a prefix". Instead of
	 * sweeping the options table by hand, the version goes into the transient
	 * key: bumping the version makes every old key unreachable, and they
	 * disappear on their own when they expire.
	 */
	private const CACHE_VERSION_OPTION = 'movie_showcase_cache_version';

	/**
	 * Search cache lifetime (result lists).
	 */
	private const SEARCH_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Details cache lifetime.
	 *
	 * Longer than the search one: a movie record hardly ever changes.
	 */
	private const DETAIL_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Negative cache lifetime (failed call).
	 *
	 * Short: if the API comes back, we want to serve good data soon. But
	 * without it, a down API would make every visitor trigger a new blocking
	 * call.
	 */
	private const ERROR_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Request timeout, in seconds.
	 *
	 * Short on purpose: the visitor is waiting. If OMDb is slow, it is better
	 * to return empty than to hold the PHP-FPM connection.
	 */
	private const TIMEOUT = 8;

	/**
	 * @param ApiKey $api_key Service that stores the credential.
	 */
	public function __construct( private readonly ApiKey $api_key ) {}

	/**
	 * Searches movies by title (OMDb `s` parameter).
	 *
	 * @param  string $title Term already sanitized by the REST layer.
	 * @return array<int, array<string, string>>
	 */
	public function search( string $title ): array {
		$title = trim( $title );

		// A too-short term yields useless results and wastes API quota.
		if ( mb_strlen( $title ) < 2 ) {
			return array();
		}

		$data = $this->request(
			array(
				's'    => $title,
				'type' => 'movie',
			),
			self::SEARCH_TTL
		);

		// OMDb returns the list inside "Search".
		if ( empty( $data['Search'] ) || ! is_array( $data['Search'] ) ) {
			return array();
		}

		$results = array();

		foreach ( $data['Search'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$imdb_id = $this->clean_imdb_id( $item['imdbID'] ?? '' );

			// Without an ID the details cannot be requested later; the row is
			// discarded.
			if ( '' === $imdb_id ) {
				continue;
			}

			$results[] = array(
				'imdb_id' => $imdb_id,
				'title'   => $this->clean_text( $item['Title'] ?? '' ),
				'year'    => $this->clean_text( $item['Year'] ?? '' ),
				'type'    => $this->clean_text( $item['Type'] ?? '' ),
				'poster'  => $this->clean_url( $item['Poster'] ?? '' ),
			);
		}

		return $results;
	}

	/**
	 * Fetches the full record of a movie (OMDb `i` parameter).
	 *
	 * @param  string $imdb_id ID already validated by the REST layer.
	 * @return array<string, string>
	 */
	public function find( string $imdb_id ): array {
		$imdb_id = $this->clean_imdb_id( $imdb_id );

		if ( '' === $imdb_id ) {
			return array();
		}

		$data = $this->request(
			array(
				'i'    => $imdb_id,
				'plot' => 'full',
			),
			self::DETAIL_TTL
		);

		if ( empty( $data ) ) {
			return array();
		}

		// Explicit field allowlist: whatever OMDb sends outside this list is
		// ignored, instead of forwarding the whole response downstream.
		return array(
			'imdb_id'     => $this->clean_imdb_id( $data['imdbID'] ?? '' ),
			'title'       => $this->clean_text( $data['Title'] ?? '' ),
			'year'        => $this->clean_text( $data['Year'] ?? '' ),
			'rated'       => $this->clean_text( $data['Rated'] ?? '' ),
			'released'    => $this->clean_text( $data['Released'] ?? '' ),
			'runtime'     => $this->clean_text( $data['Runtime'] ?? '' ),
			'genre'       => $this->clean_text( $data['Genre'] ?? '' ),
			'director'    => $this->clean_text( $data['Director'] ?? '' ),
			'writer'      => $this->clean_text( $data['Writer'] ?? '' ),
			'actors'      => $this->clean_text( $data['Actors'] ?? '' ),
			'plot'        => $this->clean_text( $data['Plot'] ?? '' ),
			'language'    => $this->clean_text( $data['Language'] ?? '' ),
			'country'     => $this->clean_text( $data['Country'] ?? '' ),
			'awards'      => $this->clean_text( $data['Awards'] ?? '' ),
			'imdb_rating' => $this->clean_text( $data['imdbRating'] ?? '' ),
			'imdb_votes'  => $this->clean_text( $data['imdbVotes'] ?? '' ),
			'metascore'   => $this->clean_text( $data['Metascore'] ?? '' ),
			'poster'      => $this->clean_url( $data['Poster'] ?? '' ),
		);
	}

	/**
	 * Invalidates the whole cache.
	 *
	 * Called when the API key changes. See CACHE_VERSION_OPTION.
	 */
	public static function flush_cache(): void {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );

		// autoload = false: the version is only read during a search, not on
		// every WordPress request.
		update_option( self::CACHE_VERSION_OPTION, $version + 1, false );
	}

	/**
	 * Performs the HTTP call, with cache.
	 *
	 * @param  array<string, string> $args Query parameters (without the API key).
	 * @param  int                   $ttl  Cache lifetime, in seconds.
	 * @return array<string, mixed> Decoded response, or empty array on any failure.
	 */
	private function request( array $args, int $ttl ): array {
		if ( ! $this->api_key->has() ) {
			return array();
		}

		$cache_key = $this->cache_key( $args );
		$cached    = get_transient( $cache_key );

		// !== false because a legitimately cached empty array is also a hit.
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		// Builds the URL in two steps, without array_merge or array_map:
		// 1. add_query_arg already handles the encoding of the search params;
		// 2. the API key is appended last, only on this line — it never enters
		//    $args, so it never appears in the cache key or in logs.
		$url = add_query_arg( $args, self::ENDPOINT );
		$url = add_query_arg( 'apikey', rawurlencode( $this->api_key->get() ), $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				// sslverify stays at the default (true): turning it off would
				// open the door to man-in-the-middle attacks precisely on the
				// request that carries the key.
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'Movie Showcase WordPress plugin',
			)
		);

		// Network, DNS or timeout failure.
		if ( is_wp_error( $response ) ) {
			return $this->fail( $cache_key, 'HTTP request failed: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return $this->fail( $cache_key, 'Unexpected HTTP status: ' . $status );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return $this->fail( $cache_key, 'Response was not valid JSON.' );
		}

		// OMDb answers 200 even on error (invalid key, movie not found); the
		// field that tells the truth is "Response".
		if ( isset( $body['Response'] ) && 'True' !== $body['Response'] ) {
			$error = is_string( $body['Error'] ?? null ) ? $body['Error'] : 'unknown error';

			// The OMDb message goes only to the server log, never to the
			// screen: returning "Invalid API key!" to the visitor would leak
			// information about the site configuration.
			return $this->fail( $cache_key, 'API error: ' . $error );
		}

		set_transient( $cache_key, $body, $ttl );

		return $body;
	}

	/**
	 * Handles a failure: logs it, stores a negative cache entry and returns empty.
	 *
	 * @param  string $cache_key Transient key.
	 * @param  string $message   Message for the log (never shown to the visitor).
	 * @return array<string, mixed> Always empty.
	 */
	private function fail( string $cache_key, string $message ): array {
		$this->log( $message );
		set_transient( $cache_key, array(), self::ERROR_TTL );

		return array();
	}

	/**
	 * Builds the transient key.
	 *
	 * The API key does NOT go into the hash: nothing derived from the
	 * credential is stored in the database. Invalidation on key change comes
	 * from the cache version.
	 *
	 * @param  array<string, string> $args Query parameters.
	 */
	private function cache_key( array $args ): string {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );

		ksort( $args );

		// Transient names have a length limit; the md5 guarantees it fits.
		return self::CACHE_PREFIX . $version . '_' . md5( (string) wp_json_encode( $args ) );
	}

	/**
	 * Normalizes a text field coming from OMDb.
	 *
	 * The API returns "N/A" for a missing field. It becomes an empty string,
	 * so the display layer can simply omit the row.
	 *
	 * @param mixed $value Raw value from the response.
	 */
	private function clean_text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		return 'N/A' === $value ? '' : $value;
	}

	/**
	 * Validates and normalizes the poster URL.
	 *
	 * esc_url_raw already removes dangerous schemes, but the explicit
	 * http/https allowlist closes the door to "javascript:" and "data:" —
	 * which would end up as a src attribute in the visitor's browser.
	 *
	 * @param mixed $value Raw value from the response.
	 */
	private function clean_url( mixed $value ): string {
		if ( ! is_string( $value ) || 'N/A' === $value ) {
			return '';
		}

		$url    = esc_url_raw( $value, array( 'http', 'https' ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * Validates the IMDb ID format.
	 *
	 * Always "tt" followed by digits. Anything else is discarded before it
	 * becomes a request parameter.
	 *
	 * @param mixed $value Raw value from the response or the request.
	 */
	private function clean_imdb_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return 1 === preg_match( '/^tt\d{5,12}$/', $value ) ? $value : '';
	}

	/**
	 * Logs a failure to the PHP error log.
	 *
	 * Only writes when WP_DEBUG is on. The API key never appears in the
	 * message.
	 *
	 * @param string $message Log text.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MovieShowcase/OmdbClient: ' . $message );
		}
	}
}
