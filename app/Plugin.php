<?php
/**
 * Main plugin class.
 *
 * @package DionesRamos\MovieShowcase
 */

declare(strict_types=1);

namespace DionesRamos\MovieShowcase;

use DionesRamos\MovieShowcase\Controllers\BlockController;
use DionesRamos\MovieShowcase\Controllers\RestController;
use DionesRamos\MovieShowcase\Controllers\SettingsPage;
use DionesRamos\MovieShowcase\Services\ApiKey;
use DionesRamos\MovieShowcase\Services\OmdbClient;

// Blocks direct file access: without WordPress loaded, ABSPATH does not exist.
defined('ABSPATH') || exit;

/**
 * Single composition root of the plugin.
 *
 * Dependencies are created here and passed by constructor, making it
 * explicit who depends on whom instead of each class fetching its own
 * through singletons.
 *
 * Each controller registers its own hooks in the constructor. The side
 * effect in the constructor is intentional: it is the most direct and most
 * common approach in WordPress plugins. The trade-off is that instantiating
 * the class in a test also registers the hooks — if that ever becomes a
 * problem, a register() method can be brought back.
 */
final class Plugin {

	/**
	 * Assembles the plugin components.
	 *
	 * Called from the `plugins_loaded` hook. No heavy work happens here:
	 * each component only registers its hooks, which WordPress fires later,
	 * at the right time.
	 */
	public static function boot(): void {
		$api_key = new ApiKey();

		new SettingsPage( $api_key );
		new RestController( new OmdbClient( $api_key ) );
		new BlockController();
	}
}
