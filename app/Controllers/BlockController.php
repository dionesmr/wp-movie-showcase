<?php
/**
 * Gutenberg block registration.
 *
 * @package DionesRamos\MovieShowcase;
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Controllers;

use DionesRamos\MovieShowcase\Views\BlockView;
use WP_Block;

use const DionesRamos\MovieShowcase\PATH;
use const DionesRamos\MovieShowcase\URL;
use const DionesRamos\MovieShowcase\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Movie Search" block and its assets.
 *
 * The block is rendered on the server (render_callback) instead of saving
 * HTML into the post. Two reasons:
 *
 * 1. Security: the markup is generated on every render by PHP code that
 *    escapes everything. There is no HTML stored in the database that could
 *    have been tampered with.
 * 2. Maintenance: changing the block layout does not invalidate already
 *    published posts ("block validation error" in the editor).
 */
final class BlockController {

	/**
	 * Editor script handle.
	 */
	private const EDITOR_HANDLE = 'movie-showcase-editor';

	/**
	 * Front-end script handle.
	 */
	private const VIEW_HANDLE = 'movie-showcase-view';

	/**
	 * CSS handle.
	 */
	private const STYLE_HANDLE = 'movie-showcase-style';

	/**
	 * Already hooks the block registration.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers assets and the block.
	 *
	 * The handles are registered by hand (instead of letting block.json use
	 * "file:./index.js") because we need the known handle to inject the REST
	 * URL and the nonce with wp_localize_script().
	 */
	public function register_block(): void {
		$dir = PATH . 'blocks/movie-search/';

		wp_register_script(
			self::EDITOR_HANDLE,
			URL . 'blocks/movie-search/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			VERSION,
			true
		);

		wp_register_script(
			self::VIEW_HANDLE,
			URL . 'blocks/movie-search/view.js',
			array(),
			VERSION,
			true
		);

		wp_register_style(
			self::STYLE_HANDLE,
			URL . 'blocks/movie-search/style.css',
			array(),
			VERSION
		);

		// Allows translating the JS strings through the /languages files.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_HANDLE, 'wp-movie-showcase', PATH . 'languages' );
		}

		if ( file_exists( $dir . 'block.json' ) ) {
			register_block_type(
				$dir,
				array( 'render_callback' => array( $this, 'render' ) )
			);
		}
	}

	/**
	 * Renders the block on the front-end.
	 *
	 * This is where the front-end script receives the data it needs. The
	 * nonce is generated at this moment, which means it is always fresh for
	 * whoever loads the page.
	 *
	 * WARNING about full page caching (Varnish, WP Rocket, Cloudflare): the
	 * HTML with the embedded nonce can be served after the nonce has expired
	 * (default of 12h to 24h), and the search then returns 403. In production
	 * with aggressive caching, the right approach is to fetch the nonce
	 * through a separate, non-cached call.
	 *
	 * @param  array<string, mixed> $attributes Attributes saved in the post.
	 * @param  string               $content    Inner content (unused).
	 * @param  WP_Block             $block      Block instance.
	 * @return string Block HTML.
	 */
	public function render( array $attributes, string $content = '', ?WP_Block $block = null ): string {
		wp_enqueue_script( self::VIEW_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		wp_localize_script(
			self::VIEW_HANDLE,
			'movieShowcaseSettings',
			array(
				// esc_url_raw because the value goes to JSON/JS, not to HTML.
				'searchUrl' => esc_url_raw( rest_url( RestController::NAMESPACE . '/search' ) ),
				'movieUrl'  => esc_url_raw( rest_url( RestController::NAMESPACE . '/movie' ) ),
				// Core REST nonce. The JS sends it in the X-WP-Nonce header
				// and the RestController check_permission() validates it.
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'noResults' => __( 'No movies found.', 'wp-movie-showcase' ),
					'details'   => __( 'View details', 'wp-movie-showcase' ),
					'loading'   => __( 'Searching…', 'wp-movie-showcase' ),
					'error'     => __( 'The search failed. Please reload the page and try again.', 'wp-movie-showcase' ),
					'back'      => __( '← Back to results', 'wp-movie-showcase' ),
				),
			)
		);

		return BlockView::render( $attributes );
	}
}
