<?php
/**
 * Custom Autoloader for the RSS Feed Manager plugin.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Autoloader
 *
 * Registers the spl_autoload_register logic to autoload plugin classes.
 */
class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	/**
	 * Autoload handler.
	 *
	 * Maps namespace RSSFeedManager\ClassName to includes/class-rss-classname.php
	 * or RSSFeedManager\Admin\ClassName to admin/class-rss-classname.php.
	 *
	 * @param string $class Class name to load.
	 * @return void
	 */
	public static function autoload( $class ) {
		// Only autoload classes belonging to our namespace.
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		// Get relative class path.
		$relative_class = substr( $class, strlen( $prefix ) );

		// Separate parts.
		$parts      = explode( '\\', $relative_class );
		$class_name = array_pop( $parts );

		// Format class name into WordPress naming standard: class-rss-<hyphenated-name>.php
		$hyphenated = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $class_name ) );
		$filename   = 'class-rss-' . $hyphenated . '.php';

		// Identify target directory.
		$base_dir = plugin_dir_path( dirname( __FILE__ ) );
		$subpath  = '';

		if ( ! empty( $parts ) ) {
			// Convert namespaces to directory path.
			$subdirs = array_map( 'strtolower', $parts );
			$subpath = implode( DIRECTORY_SEPARATOR, $subdirs ) . DIRECTORY_SEPARATOR;
		} else {
			// Default folder is includes.
			$subpath = 'includes' . DIRECTORY_SEPARATOR;
		}

		$file_path = $base_dir . $subpath . $filename;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
