/**
 * Block registration in the editor (Gutenberg).
 *
 * Written in plain JavaScript, using the globals WordPress already exposes
 * (wp.blocks, wp.element, ...), without JSX. This avoids depending on a
 * build step with npm/webpack: the file runs as-is.
 *
 * The editor view is a static preview of the form (disabled fields), with
 * the same classes as the front-end so it reflects the chosen colors. The
 * real search runs for the visitor on the front-end, through view.js.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;

	blocks.registerBlockType('movie-showcase/movie-search', {
		/**
		 * Block screen inside the editor.
		 *
		 * @param {Object} props Properties delivered by Gutenberg.
		 * @return {Object} React element tree.
		 */
		edit: function (props) {
			var blockProps = useBlockProps({ className: 'movie-showcase-editor' });
			var heading = props.attributes.heading || '';

			return el(
				element.Fragment,
				null,

				// Sidebar panel: the text setting. Colors live in the native
				// Gutenberg "Color" panel (supports.color in block.json).
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __('Settings', 'wp-movie-showcase'), initialOpen: true },
						el(components.TextControl, {
							label: __('Heading', 'wp-movie-showcase'),
							help: __('Optional title shown above the search form.', 'wp-movie-showcase'),
							value: heading,
							// The value is only stored in the attribute. The
							// sanitization that matters happens in PHP, at
							// render time (BlockView::render) — never trust
							// the client alone.
							onChange: function (value) {
								props.setAttributes({ heading: value });
							},
						})
					)
				),

				// Static preview of the form, with the same classes as the
				// front-end: the colors picked in the editor (text/background)
				// and the --movie-showcase-accent variable show up here as
				// they will on the page. The fields are disabled — the real
				// search only runs on the published page, via view.js.
				el(
					'div',
					blockProps,
					el(
						'div',
						{ className: 'movie-showcase' },
						heading
							? el('h2', { className: 'movie-showcase__heading' }, heading)
							: null,
						el(
							'label',
							{ className: 'movie-showcase__label' },
							__('Movie title', 'wp-movie-showcase')
						),
						el(
							'div',
							{ className: 'movie-showcase__controls' },
							el('input', {
								type: 'search',
								className: 'movie-showcase__input',
								placeholder: __('e.g. The Godfather', 'wp-movie-showcase'),
								disabled: true,
							}),
							el(
								'button',
								{
									type: 'button',
									className: 'movie-showcase__submit',
									disabled: true,
								},
								__('Search', 'wp-movie-showcase')
							)
						)
					)
				)
			);
		},

		/**
		 * Server-side rendering.
		 *
		 * Returning null makes Gutenberg save no HTML in the post: the markup
		 * comes from the PHP render_callback on every display.
		 *
		 * @return {null} Always null.
		 */
		save: function () {
			return null;
		},
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
