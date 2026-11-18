<?php
/**
 * Plugin Name: Simply Static Studio Backup and Migrate
 * Plugin URI: https://simplystatic.com/simply-static-studio/
 * Description: Create downloadable ZIP backups of your WordPress database and site files, with optional migration.
 * Version: 1.1.33
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Simply Static
 * Author URI: https://simplystatic.com/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: simply-static-studio-backup-migrate
 *
 * @package Simply_Static_Backup
 */

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSSBM_URL', plugin_dir_url( __FILE__ ) );
define( 'SSSBM_VERSION', '1.1.33' );

spl_autoload_register(
	function ( $class_name ) {
		// Define the namespace prefix we're handling.
		$namespace_prefix = 'Simply_Static\\Backup\\';

		// Check if the class starts with our target namespace.
		if ( strpos( $class_name, $namespace_prefix ) !== 0 ) {
			return; // Not our namespace, let other autoloaders handle it.
		}

		// Remove the namespace prefix from the class name.
		$relative_class = substr( $class_name, strlen( $namespace_prefix ) );
		if ( ! preg_match( '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $relative_class ) ) {
			return;
		}

		// Convert namespace separators to directory separators.
		$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );

		$separator  = DIRECTORY_SEPARATOR;
		$array      = explode( $separator, $relative_path );
		$class_name = end( $array );
		$class_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) );
		reset( $array );
		$array[ count( $array ) - 1 ] = $class_name;
		$relative_path                = implode( $separator, $array );

		// Build the full file path.
		$file_path = plugin_dir_path( __FILE__ ) . 'src' . DIRECTORY_SEPARATOR . $relative_path . '.php';

		// Load the class file if it exists.
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

// Register activation hook.
register_activation_hook( __FILE__, 'Simply_Static\Backup\plugin_activation' );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

/**
 * Set a transient to redirect to the plugin's admin page on activation.
 *
 * @return void
 */
function plugin_activation() {
	// Set transient for redirect.
	set_transient( 'sssbm_activation_redirect', true, 30 );
}

/**
 * Redirect to the plugin's admin page after activation.
 *
 * @return void
 */
function activation_redirect() {
	$required_capability = is_multisite() ? 'manage_network_options' : 'activate_plugins';

	// Check if the transient is set.
	if ( get_transient( 'sssbm_activation_redirect' ) && current_user_can( $required_capability ) ) {
		// Delete the transient.
		delete_transient( 'sssbm_activation_redirect' );

		// Make sure we're on the plugins page.
		if ( is_admin() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only activation context; no request data is stored.
			// Redirect to the plugin's admin page.
			wp_safe_redirect( admin_url( 'tools.php?page=sssbm-backup' ) );
			exit;
		}
	}
}

add_action( 'admin_init', 'Simply_Static\Backup\activation_redirect' );

// Avoid running heavy initialization when WordPress is uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	new Exclude_Plugins();
	new Plugin();

	// Register WP-CLI commands when running via CLI (but not during uninstall).
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\Simply_Static\Backup\CLI\Backup_Command::register();
	}
}
