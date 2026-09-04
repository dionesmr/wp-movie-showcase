<?php
/**
 * REST endpoints consumed by the block on the front-end.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Controllers;

use DionesRamos\MovieShowcase\Services\OmdbClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Proxy between the visitor's browser and OMDb.
 *
 * Why a proxy instead of the JavaScript calling OMDb directly: the API key
 * would have to live in the front-end, visible to anyone in "view source".
 * With the proxy, the credential never leaves the server.
 */
final class RestController {

	/**
	 * REST API namespace.
	 */
	public const NAMESPACE = 'movie-showcase/v1';

	/**
	 * Maximum requests per IP within the rate limit window.
	 */
	private const RATE_LIMIT_MAX = 30;

	/**
	 * Rate limit window, in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * Already registers the routes hook.
	 *
	 * @param OmdbClient $movies Source of movie data.
	 */
	public function __construct( private readonly OmdbClient $movies ) {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares the two routes.
	 *
	 * The `args` array is the first line of defense: WordPress runs
	 * validate_callback and sanitize_callback BEFORE calling the handler. An
	 * invalid parameter becomes a 400 without ever reaching the business
	 * logic.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'query' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							// Between 2 and 100 characters. The ceiling stops
							// someone from using the endpoint to push a large
							// payload.
							return is_string( $value )
								&& mb_strlen( trim( $value ) ) >= 2
								&& mb_strlen( $value ) <= 100;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/movie',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'movie' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							// IMDb format validated right at the front door.
							return is_string( $value ) && 1 === preg_match( '/^tt\d{5,12}$/', $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Route authorization.
	 *
	 * The block is public: a logged-out visitor must be able to search. So a
	 * capability cannot be required. What CAN be required is the NONCE.
	 *
	 * What the nonce solves here:
	 * - CSRF: another site cannot make your visitor's browser fire these
	 *   calls, because it has no way to guess the token.
	 * - Off-page usage: a script hitting the endpoint without first loading a
	 *   site page gets a 403.
	 *
	 * What the nonce does NOT solve, and why the rate limit below exists:
	 * someone who opens the page, copies the nonce and loops the endpoint can
	 * do it. A nonce is proof of origin, not of identity.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'movie_showcase_invalid_nonce',
				__( 'Invalid or expired security token. Please reload the page.', 'wp-movie-showcase' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'movie_showcase_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'wp-movie-showcase' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * GET /movie-showcase/v1/search?query=...
	 *
	 * @param  WP_REST_Request $request Request already validated and sanitized.
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		// get_param returns the value ALREADY run through the
		// sanitize_callback declared in register_rest_route(). There is no raw
		// $_GET read anywhere.
		$query = (string) $request->get_param( 'query' );

		return new WP_REST_Response(
			array( 'results' => $this->movies->search( $query ) ),
			200
		);
	}

	/**
	 * GET /movie-showcase/v1/movie?id=tt0111161
	 *
	 * @param  WP_REST_Request $request Request already validated and sanitized.
	 * @return WP_REST_Response
	 */
	public function movie( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );

		return new WP_REST_Response(
			array( 'movie' => $this->movies->find( $id ) ),
			200
		);
	}

	/**
	 * Simple per-IP rate limit, stored in a transient.
	 *
	 * Goal: stop the endpoint from being used as a free proxy to OMDb,
	 * burning the site's key quota.
	 *
	 * Assumed limitations: the counter is per IP, so NAT and rotating IPv6
	 * distort the count, and the transient can be evicted by an object cache
	 * under pressure. For high traffic, the right place for this is the edge
	 * (WAF, CDN).
	 */
	private function is_rate_limited(): bool {
		$ip = $this->client_ip();

		if ( '' === $ip ) {
			return false;
		}

		$key   = 'movie_showcase_rl_' . md5( $ip );
		$hits  = get_transient( $key );
		$hits  = is_numeric( $hits ) ? (int) $hits : 0;
		++$hits;

		set_transient( $key, $hits, self::RATE_LIMIT_WINDOW );

		return $hits > self::RATE_LIMIT_MAX;
	}

	/**
	 * Client IP, validated.
	 *
	 * Reads only REMOTE_ADDR. Headers such as X-Forwarded-For are ignored on
	 * purpose: they come from the client and can be forged, which would let
	 * someone defeat the rate limit by changing the header on each call.
	 * Behind a reverse proxy, the correct value must be injected by the
	 * infrastructure, not read from here.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return (string) filter_var( $ip, FILTER_VALIDATE_IP );
	}
}
