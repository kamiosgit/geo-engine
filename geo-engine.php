<?php
/**
 * Plugin Name:       GEO Engine
 * Plugin URI:        https://geo.kamibaby.com
 * Description:       让站点自动对 AI 友好：llms.txt / Markdown 端点 / JSON-LD schema，并可用自有 LLM 为每篇内容生成文末 FAQ。
 * Version:           1.0.6
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            GEO Engine
 * Author URI:        https://geo.kamibaby.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       geo-engine
 * Domain Path:       /languages
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

define( 'GEO_ENGINE_VERSION', '1.0.6' );
define( 'GEO_ENGINE_FILE', __FILE__ );
define( 'GEO_ENGINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEO_ENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GEO_ENGINE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Boot the plugin.
 *
 * @return GEO_Plugin
 */
function geo_engine() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new GEO_Plugin();
	}

	return $instance;
}

geo_engine();

register_activation_hook( __FILE__, array( 'GEO_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GEO_Activator', 'deactivate' ) );
