/**
 * Front-end movie search.
 *
 * Talks to the site's own REST API (never to OMDb directly), sending the
 * nonce in the X-WP-Nonce header.
 *
 * SECURITY RULE OF THIS FILE: data coming from OMDb is third-party content.
 * It is NEVER inserted with innerHTML. All text goes in through textContent
 * and every URL passes a protocol check before becoming a src attribute.
 * The PHP already sanitizes on the server side; this is the second layer.
 */
(function () {
	'use strict';

	var settings = window.movieShowcaseSettings;

	// Without the data injected by wp_localize_script there is nothing to do.
	if (!settings || !settings.searchUrl || !settings.nonce) {
		return;
	}

	/**
	 * Calls the site's REST API.
	 *
	 * @param {string} url    Full endpoint.
	 * @param {Object} params Query parameters.
	 * @return {Promise<Object>} Decoded response body.
	 */
	function request(url, params) {
		// URLSearchParams handles the encoding of each parameter.
		var query = new URLSearchParams(params).toString();

		return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + query, {
			method: 'GET',
			headers: {
				// This is the header the RestController check_permission()
				// validates.
				'X-WP-Nonce': settings.nonce,
			},
			// Sends the session cookie so WordPress can associate the nonce
			// with the user when they are logged in.
			credentials: 'same-origin',
		}).then(function (response) {
			// An HTTP error (403 nonce, 429 rate limit, 500...) must not fall
			// into the same bucket as "search with no results". Throws with
			// the status so the catch displays the real reason.
			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}

			return response.json();
		});
	}

	/**
	 * Returns the URL only if the protocol is safe.
	 *
	 * Blocks "javascript:" and "data:", which would become XSS if they landed
	 * in a src.
	 *
	 * @param {string} value Candidate URL.
	 * @return {string} The URL, or an empty string if not http/https.
	 */
	function safeUrl(value) {
		if (typeof value !== 'string' || value === '') {
			return '';
		}

		try {
			var parsed = new URL(value, window.location.origin);

			return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
		} catch (e) {
			return '';
		}
	}

	/**
	 * Creates an element with safe text.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className CSS class.
	 * @param {string} text      Text content.
	 * @return {HTMLElement} Ready element.
	 */
	function node(tag, className, text) {
		var element = document.createElement(tag);

		if (className) {
			element.className = className;
		}

		if (text !== undefined && text !== null && text !== '') {
			// textContent, never innerHTML: the browser treats the value as
			// plain text, so "<script>" shows up written on the screen instead
			// of being executed.
			element.textContent = String(text);
		}

		return element;
	}

	/**
	 * Builds the card of a search result.
	 *
	 * @param {Object} movie     Item returned by the REST API.
	 * @param {Function} onDetails Callback for the details button.
	 * @return {HTMLElement} Card.
	 */
	function buildCard(movie, onDetails) {
		var card = node('li', 'movie-showcase__card');
		var poster = safeUrl(movie.poster);

		if (poster) {
			var img = document.createElement('img');
			img.className = 'movie-showcase__poster';
			img.src = poster;
			// The alt is also third-party data: setAttribute escapes it.
			img.setAttribute('alt', movie.title || '');
			img.loading = 'lazy';
			card.appendChild(img);
		}

		var body = node('div', 'movie-showcase__card-body');
		body.appendChild(node('h3', 'movie-showcase__card-title', movie.title));
		body.appendChild(node('p', 'movie-showcase__card-year', movie.year));

		var button = node('button', 'movie-showcase__details-button', settings.i18n.details);
		button.type = 'button';
		button.addEventListener('click', function () {
			onDetails(movie.imdb_id);
		});

		body.appendChild(button);
		card.appendChild(body);

		return card;
	}

	/**
	 * Builds the details panel of a movie.
	 *
	 * @param {Object} movie Full record returned by the REST API.
	 * @return {HTMLElement} Panel.
	 */
	function buildDetails(movie) {
		var panel = node('div', 'movie-showcase__details');
		var poster = safeUrl(movie.poster);

		if (poster) {
			var img = document.createElement('img');
			img.className = 'movie-showcase__details-poster';
			img.src = poster;
			img.setAttribute('alt', movie.title || '');
			panel.appendChild(img);
		}

		var info = node('div', 'movie-showcase__details-body');
		info.appendChild(node('h3', 'movie-showcase__details-title', movie.title));

		// Each row only appears if OMDb sent the field. The PHP converts the
		// API's "N/A" into an empty string precisely to allow this.
		var rows = [
			['Year', movie.year],
			['Rated', movie.rated],
			['Released', movie.released],
			['Runtime', movie.runtime],
			['Genre', movie.genre],
			['Director', movie.director],
			['Writer', movie.writer],
			['Actors', movie.actors],
			['Language', movie.language],
			['Country', movie.country],
			['Awards', movie.awards],
			['IMDb rating', movie.imdb_rating],
			['Metascore', movie.metascore],
		];

		var list = node('dl', 'movie-showcase__meta');

		rows.forEach(function (row) {
			if (!row[1]) {
				return;
			}

			list.appendChild(node('dt', null, row[0]));
			list.appendChild(node('dd', null, row[1]));
		});

		if (movie.plot) {
			info.appendChild(node('p', 'movie-showcase__plot', movie.plot));
		}

		info.appendChild(list);
		panel.appendChild(info);

		return panel;
	}

	/**
	 * Wires the behavior to one block instance.
	 *
	 * @param {HTMLElement} root Block root element.
	 */
	function setup(root) {
		var form = root.querySelector('.movie-showcase__form');
		var input = root.querySelector('.movie-showcase__input');
		var results = root.querySelector('.movie-showcase__results');

		if (!form || !input || !results) {
			return;
		}

		/**
		 * Clears the results area.
		 *
		 * Removing children one by one instead of results.innerHTML = ''
		 * keeps the rule of never touching innerHTML in this file.
		 */
		function clear() {
			while (results.firstChild) {
				results.removeChild(results.firstChild);
			}
		}

		/**
		 * Last result list displayed. Kept in memory so the details "back"
		 * button can re-render the list without a new API call.
		 *
		 * @type {Array}
		 */
		var lastList = [];

		/**
		 * Renders the results grid.
		 *
		 * @param {Array} list Items returned by the REST API.
		 */
		function renderList(list) {
			clear();

			if (list.length === 0) {
				results.appendChild(node('p', 'movie-showcase__empty', settings.i18n.noResults));

				return;
			}

			var grid = node('ul', 'movie-showcase__grid');

			list.forEach(function (movie) {
				grid.appendChild(buildCard(movie, showDetails));
			});

			results.appendChild(grid);
		}

		/**
		 * Shows an error in the results area.
		 *
		 * @param {Error} err Caught error (network or non-2xx HTTP).
		 */
		function showError(err) {
			clear();
			results.appendChild(
				node('p', 'movie-showcase__error', settings.i18n.error + ' (' + err.message + ')')
			);
		}

		/**
		 * Fetches and shows the details of a movie, with a button to go back
		 * to the list.
		 *
		 * @param {string} imdbId IMDb ID.
		 */
		function showDetails(imdbId) {
			request(settings.movieUrl, { id: imdbId })
				.then(function (data) {
					if (!data || !data.movie || !data.movie.title) {
						return;
					}

					clear();

					var back = node('button', 'movie-showcase__back-button', settings.i18n.back);
					back.type = 'button';
					back.addEventListener('click', function () {
						renderList(lastList);
					});

					results.appendChild(back);
					results.appendChild(buildDetails(data.movie));
				})
				.catch(showError);
		}

		form.addEventListener('submit', function (event) {
			// Prevents the page reload: the search is done via fetch.
			event.preventDefault();

			var term = input.value.trim();

			// Same limit as the PHP validate_callback. Validating here is UX
			// comfort; the server-side validation is the one that counts.
			if (term.length < 2) {
				return;
			}

			// Clears BEFORE the fetch: if the request fails, a stale message
			// (e.g. "No movies found") does not stay frozen on the screen.
			clear();
			results.appendChild(node('p', 'movie-showcase__loading', settings.i18n.loading));

			request(settings.searchUrl, { query: term })
				.then(function (data) {
					// Stores the list for the details "back" button.
					lastList = Array.isArray(data && data.results) ? data.results : [];

					renderList(lastList);
				})
				.catch(showError);
		});
	}

	// There may be more than one block on the same page.
	document.querySelectorAll('.movie-showcase').forEach(setup);
})();
