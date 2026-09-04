<?php
/**
 * Front-end block markup.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase\Views;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the search form the visitor sees.
 *
 * The results are inserted by view.js after the REST response. What comes
 * out of here is only the skeleton: form + empty container.
 */
final class BlockView {

	/**
	 * Builds the block HTML.
	 *
	 * @param  array<string, mixed> $attributes Attributes saved in the post.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		// The attribute comes from the database and was written by the
		// editor. Even with the block.json schema constraining the type, we
		// treat it as untrusted input.
		$heading = isset( $attributes['heading'] ) && is_string( $attributes['heading'] )
			? sanitize_text_field( $attributes['heading'] )
			: '';

		// Unique ID per instance: allows more than one block on the same page
		// without label/for and aria-controls pointing to the wrong element.
		$input_id   = wp_unique_id( 'movie-showcase-input-' );
		$results_id = wp_unique_id( 'movie-showcase-results-' );

		// get_block_wrapper_attributes() already returns escaped attributes
		// and applies the alignment/spacing classes defined in supports.
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'movie-showcase' ) );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped by core. ?>>
			<?php if ( '' !== $heading ) : ?>
				<h2 class="movie-showcase__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>

			<form class="movie-showcase__form" role="search" novalidate>
				<label class="movie-showcase__label" for="<?php echo esc_attr( $input_id ); ?>">
					<?php esc_html_e( 'Movie title', 'wp-movie-showcase' ); ?>
				</label>

				<div class="movie-showcase__controls">
					<input
						type="search"
						id="<?php echo esc_attr( $input_id ); ?>"
						class="movie-showcase__input"
						name="movie-title"
						autocomplete="off"
						maxlength="100"
						placeholder="<?php esc_attr_e( 'e.g. The Godfather', 'wp-movie-showcase' ); ?>"
						aria-controls="<?php echo esc_attr( $results_id ); ?>"
						required
					>
					<button type="submit" class="movie-showcase__submit">
						<?php esc_html_e( 'Search', 'wp-movie-showcase' ); ?>
					</button>
				</div>
			</form>

			<?php
			// aria-live makes the screen reader announce the results arriving
			// via JavaScript, without a page reload.
			?>
			<div
				id="<?php echo esc_attr( $results_id ); ?>"
				class="movie-showcase__results"
				aria-live="polite"
			></div>

			<noscript>
				<p class="movie-showcase__noscript">
					<?php esc_html_e( 'JavaScript is required to search for movies.', 'wp-movie-showcase' ); ?>
				</p>
			</noscript>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
