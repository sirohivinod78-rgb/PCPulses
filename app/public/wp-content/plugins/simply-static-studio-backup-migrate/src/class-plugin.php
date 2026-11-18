<?php

namespace Simply_Static\Backup;

use Simply_Static\Backup\Rest\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private const LEGACY_OPTIONS = array(
		'sssbm_backup_migrate'         => 'sss_backup_migrate',
		'sssbm_export_process_running' => 'sss_export_process_running',
		'sssbm_export_complete'        => 'sss_export_complete',
	);

	public function __construct() {
		self::migrate_legacy_options();
		add_action( 'switch_blog', array( self::class, 'migrate_legacy_options' ), 0, 0 );
		$process = Backup::get_process();
		add_action( Backup::STATIC_DOWNLOAD_HOOK, array( Backup::class, 'revoke_static_download' ), 10, 1 );

		if ( ! static::is_managed_runtime() ) {
			Settings::get_instance();
			add_action( 'admin_menu', [ $this, 'admin_menu' ] );
			add_action( 'admin_init', array( $this, 'redirect_legacy_admin_page' ) );
		}

		// Inject Basic Auth headers into background process HTTP requests.
		add_filter( $process->get_identifier() . '_post_args', [ $this, 'add_basic_auth_headers' ] );
	}

	/**
	 * Preserve settings and backup state from releases using the shorter prefix.
	 *
	 * Run before any queue or settings initialization, and again after switching
	 * blogs so multisite requests retain each subsite's own state. Atomic inserts
	 * preserve a newer value if another request has already migrated the option.
	 * Old data is retained for retry when the new option cannot be persisted.
	 *
	 * @return void
	 */
	public static function migrate_legacy_options() {
		$missing = new \stdClass();

		foreach ( self::LEGACY_OPTIONS as $new_key => $legacy_key ) {
			$value = get_option( $legacy_key, $missing );
			if ( $missing === $value ) {
				continue;
			}

			if ( $missing !== get_option( $new_key, $missing ) || add_option( $new_key, $value, '', 'no' ) ) {
				delete_option( $legacy_key );
			}
		}
	}

	/**
	 * Read migrated settings/state without changing behavior after a failed write.
	 *
	 * Only these three renamed plugin options can fall back to legacy data. New
	 * values remain authoritative, including false, and persistence checks still
	 * use the WordPress getter directly.
	 *
	 * @param string $key New option name.
	 * @param mixed $default Value used if neither version exists.
	 * @return mixed
	 */
	public static function get_migrated_option( $key, $default = false ) {
		$missing = new \stdClass();
		$value = get_option( $key, $missing );
		if ( $missing !== $value ) {
			return $value;
		}

		return isset( self::LEGACY_OPTIONS[ $key ] )
			? get_option( self::LEGACY_OPTIONS[ $key ], $default )
			: $default;
	}

	/**
	 * Keep bookmarked backup screens working after prefixing the admin page slug.
	 *
	 * @return void
	 */
	public function redirect_legacy_admin_page() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) || 'studio-backup' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only compatibility redirect to a fixed local admin page.
			return;
		}

		$url = admin_url( 'tools.php?page=sssbm-backup' );
		if ( isset( $_GET['sssbm_browser_worker'] ) && is_string( $_GET['sssbm_browser_worker'] ) && '1' === $_GET['sssbm_browser_worker'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve only the read-only worker mode; mutations remain REST nonce-protected.
			$url = add_query_arg( 'sssbm_browser_worker', '1', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Whether Studio owns this plugin as runtime infrastructure.
	 *
	 * Managed sites keep only the backup engine and WP-CLI surface available for
	 * the platform agent. Their customer-facing API and UI live in Studio, so
	 * the plugin's WordPress REST routes and wp-admin screen are not registered.
	 */
	public static function is_managed_runtime() {
		return defined( 'STATIC_STUDIO_MANAGED_PACKAGES' )
			&& true === STATIC_STUDIO_MANAGED_PACKAGES;
	}

	/**
	 * Revoke static archive capabilities while plugin code is still loaded.
	 *
	 * @param bool $network_wide Whether a multisite network deactivation was requested.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		$current_blog_id = get_current_blog_id();
		$site_ids = $network_wide && is_multisite() && function_exists( 'get_sites' )
			? get_sites( array( 'fields' => 'ids', 'number' => 0 ) )
			: array( $current_blog_id );

		foreach ( $site_ids as $site_id ) {
			if ( ! is_numeric( $site_id ) ) {
				continue;
			}
			$site_id = (int) $site_id;
			$switched = false;
			if ( $site_id !== (int) get_current_blog_id() ) {
				$switched = switch_to_blog( $site_id );
				if ( ! $switched ) {
					continue;
				}
			}

			try {
				Migration_Observation::stop();
				if ( Backup::revoke_static_download() ) {
					wp_clear_scheduled_hook( Backup::STATIC_DOWNLOAD_HOOK );
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Register Admin menu page
	 *
	 * @return void
	 */
	public function admin_menu() {
		if ( static::is_managed_runtime() ) {
			return;
		}

		$default_capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		$capability         = apply_filters( 'sssbm_manage_backup_capability', $default_capability, null );
		if ( ! is_string( $capability ) || '' === $capability ) {
			$capability = $default_capability;
		}

		$menu_hook = add_submenu_page(
			'tools.php',
			__( 'Backup and Migrate', 'simply-static-studio-backup-migrate' ),
			__( 'Backup and Migrate', 'simply-static-studio-backup-migrate' ),
			$capability,
			'sssbm-backup',
			array( $this, 'render_menu_page' ),
			0 // menu position
		);

		// The page-specific load hook fires before WordPress prints admin styles.
		// Enqueuing from admin_print_scripts would load JavaScript, but the style
		// printing phase has already passed by then.
		add_action( "load-{$menu_hook}", array( $this, 'add_settings_scripts' ) );
	}

	public function add_settings_scripts() {
		if ( static::is_managed_runtime() ) {
			return;
		}

		$asset_path = dirname( __DIR__ ) . '/src/Admin/build/index.asset.php';
		$asset      = is_readable( $asset_path ) ? include $asset_path : array();
		$asset      = is_array( $asset ) ? $asset : array();
		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array( 'wp-api-fetch', 'wp-components', 'wp-element' );
		$dependencies = array_values( array_unique( array_filter( $dependencies, 'is_string' ) ) );
		if ( ! in_array( 'wp-i18n', $dependencies, true ) ) {
			$dependencies[] = 'wp-i18n';
		}
		$version = isset( $asset['version'] ) && is_string( $asset['version'] ) && '' !== $asset['version'] ? $asset['version'] : SSSBM_VERSION;

		wp_enqueue_script( 'simply-static-backup-migrate-settings', SSSBM_URL . 'src/Admin/build/index.js', $dependencies, $version, true );
		wp_set_script_translations(
			'simply-static-backup-migrate-settings',
			'simply-static-studio-backup-migrate'
		);

		$localize_data = [
			'blog_id'                => get_current_blog_id(),
			'is_multisite'           => is_multisite(),
			'is_main_site'           => is_main_site(),
			'browser_worker_enabled' => isset( $_GET['sssbm_browser_worker'] ) && is_string( $_GET['sssbm_browser_worker'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['sssbm_browser_worker'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI mode; REST mutations remain nonce-protected.
			'current_php_version'    => PHP_VERSION,
			'studio_php_versions'    => Settings::get_studio_php_versions(),
			'studio_auth_bridge_url' => 'https://static.studio/plugin-auth.html?v=' . rawurlencode( SSSBM_VERSION ),
		];

		wp_localize_script( 'simply-static-backup-migrate-settings', 'sssbm_backup_migrate_options', $localize_data );
		wp_enqueue_style( 'simply-static-backup-migrate-settings-style', SSSBM_URL . 'src/Admin/build/index.css', array( 'wp-components' ), $version );
		wp_style_add_data( 'simply-static-backup-migrate-settings-style', 'rtl', 'replace' );
	}


	/**
	 * Add Basic Auth Authorization header to background process HTTP requests.
	 *
	 * @param array $args Post arguments for wp_remote_post.
	 * @return array
	 */
	public function add_basic_auth_headers( $args ) {
		$settings = self::get_migrated_option( 'sssbm_backup_migrate', [] );
		$authorization = '';

		if (
			is_array( $settings ) &&
			'on' === ( $settings['http_basic_auth_enabled'] ?? '' ) &&
			! empty( $settings['http_basic_auth_username'] ) &&
			is_string( $settings['http_basic_auth_username'] ) &&
			! empty( $settings['http_basic_auth_password'] ) &&
			is_string( $settings['http_basic_auth_password'] )
		) {
			$authorization = 'Basic ' . base64_encode( $settings['http_basic_auth_username'] . ':' . $settings['http_basic_auth_password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires base64 encoding of the credentials for the same-origin loopback request.
		} else {
			// Pantheon and similar hosts protect the whole environment at the
			// web-server layer. Reuse the credential that authenticated this
			// request so the same-origin background worker can pass that layer.
			// This is kept in memory only; it is never saved as plugin settings.
			$authorization = $this->get_request_basic_auth_header();
		}

		if ( '' !== $authorization ) {
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}

			$args['headers']['Authorization'] = $authorization;
		}

		return $args;
	}

	/**
	 * Get a validated Basic Auth header from the current request.
	 *
	 * PHP exposes Basic Auth either as the original Authorization header or as
	 * parsed username/password server variables, depending on the web server.
	 * Only Basic credentials without control bytes are accepted.
	 *
	 * @return string
	 */
	private function get_request_basic_auth_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $server_key ) {
			if ( ! isset( $_SERVER[ $server_key ] ) || ! is_string( $_SERVER[ $server_key ] ) ) {
				continue;
			}

			// Preserve exact credentials; the strict Basic/base64 checks below validate the header.
			$header = trim( wp_unslash( $_SERVER[ $server_key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! preg_match( '/\ABasic[ \t]+([A-Za-z0-9+\/]+={0,2})\z/i', $header, $matches ) ) {
				continue;
			}

			$credentials = base64_decode( $matches[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strictly decode the incoming HTTP Basic authentication header for validation before the same-origin loopback request.
			if (
				false === $credentials ||
				false === strpos( $credentials, ':' ) ||
				0 === strpos( $credentials, ':' ) ||
				preg_match( '/[\x00-\x1F\x7F]/', $credentials )
			) {
				continue;
			}

			return 'Basic ' . base64_encode( $credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Re-encode validated HTTP Basic credentials in their required wire format.
		}

		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_PW'] ) ) {
			// Text sanitizers would change passwords; reject control bytes and invalid usernames below.
			$username = wp_unslash( $_SERVER['PHP_AUTH_USER'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$password = wp_unslash( $_SERVER['PHP_AUTH_PW'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $username && false === strpos( $username, ':' ) && ! preg_match( '/[\x00-\x1F\x7F]/', $username . $password ) ) {
				return 'Basic ' . base64_encode( $username . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode validated HTTP Basic credentials in their required wire format.
			}
		}

		return '';
	}

	/**
	 * Render Menu page.
	 * @return void
	 */
	public function render_menu_page() {
		?>
		<div id="static-studio-backup"></div>
		<?php
	}


}
