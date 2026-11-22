<?php
/**
 * Plugin bootstrap: loads every module and wires hooks.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin container.
 */
final class GEO_Plugin {

	/**
	 * Module instances.
	 *
	 * @var array<string,object>
	 */
	private $modules = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_files();
		$this->init_modules();
	}

	/**
	 * Load every class file.
	 *
	 * Deliberately explicit instead of an autoloader: the file list is part of
	 * the public distribution contract and stays inspectable for reviewers.
	 *
	 * @return void
	 */
	private function load_files() {
		$files = array(
			'class-key-vault.php',
			'class-logger.php',
			'class-activator.php',
			'class-settings.php',
			'class-settings-llm.php',
			'class-llm-driver-interface.php',
			'class-driver-base.php',
			'class-driver-openai-chat.php',
			'class-driver-anthropic.php',
			'class-llm-client.php',
			'class-faq-generator.php',
			'class-shortcode.php',
			'class-writeback.php',
			'class-automation.php',
			'class-schema.php',
			'class-llms-feed.php',
			'class-robots.php',
			'class-bulk.php',
		);

		foreach ( $files as $file ) {
			$path = GEO_ENGINE_PLUGIN_DIR . 'includes/' . $file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Instantiate modules. Each module registers its own hooks.
	 *
	 * @return void
	 */
	private function init_modules() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( 'GEO_Activator', 'maybe_upgrade' ) );

		$this->modules['logger']     = new GEO_Logger();
		$this->modules['shortcode']  = new GEO_Shortcode();
		$this->modules['schema']     = new GEO_Schema();
		$this->modules['llms']       = new GEO_LLMS_Feed();
		$this->modules['robots']     = new GEO_Robots();
		$this->modules['automation'] = new GEO_Automation();
		$this->modules['bulk']       = new GEO_Bulk();

		if ( is_admin() ) {
			$this->modules['settings'] = new GEO_Settings();
		}
	}

	/**
	 * Drop every open output buffer before emitting a JSON response.
	 *
	 * A single file saved with a UTF-8 BOM that is loaded on every request
	 * (wp-config.php, a drop-in, a mu-plugin) echoes the BOM ahead of
	 * WordPress' output. Browsers then receive "\xEF\xBB\xBF{...}", which
	 * JSON.parse() rejects — the admin UI shows a misleading network error
	 * even though the server answered 200. Discarding the buffers removes the
	 * stray bytes without touching the file that caused them.
	 *
	 * @return void
	 */
	public static function clean_output() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'geo-engine', false, dirname( GEO_ENGINE_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Access a module instance.
	 *
	 * @param string $name Module key.
	 * @return object|null
	 */
	public function module( $name ) {
		return isset( $this->modules[ $name ] ) ? $this->modules[ $name ] : null;
	}
}
