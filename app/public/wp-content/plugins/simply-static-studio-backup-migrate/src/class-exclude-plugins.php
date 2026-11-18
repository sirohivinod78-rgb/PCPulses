<?php

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers filters to exclude specific plugins from the export.
 */
class Exclude_Plugins {
	/**
	 * Platform-owned, environment-specific, and access-blocking plugin folders
	 * that must not be included in a Studio migration archive.
	 */
	const MIGRATION_EXCLUDED_PLUGIN_SLUGS = array(
		// Platform-owned migration/export plugins.
		'simply-static',
		'simply-static-pro',
		'simply-static-studio-backup-migrate',
		'simply-static-studio-helper',

		// Caching and source-host optimization.
		'autoptimize',
		'breeze',
		'flying-press',
		'flush-opcache',
		'litespeed-cache',
		'runcache',
		'sg-cachepress',
		'w3-total-cache',
		'wp-cloudflare-page-cache',
		'wp-fastest-cache',
		'wp-optimize',
		'wp-rocket',
		'wp-super-cache',

		// Access, authentication, and security.
		'admin-site-enhancements',
		'admin-site-enhancements-pro',
		'easy-basic-authentication',
		'wp-2fa',
		'two-factor-authentication',
		'two-factor',
		'google-authenticator',
		'miniorange-2-factor-authentication',
		'duo-wordpress',
		'wordfence-login-security',
		'akismet',
		'jetpack',
		'jetpack-boost',
		'jetpack-protect',
		'jetpack-search',
		'wordfence',
		'wordfence-premium',
		'really-simple-ssl',
		'really-simple-ssl-pro',
		'wp-defender',
		'defender-security',
		'all-in-one-wp-security-and-firewall',
		'better-wp-security',
		'ithemes-security-pro',
		'hide-my-wp',
		'malcare-security',
		'sg-security',
		'sucuri-scanner',
		'wps-hide-login',

		// Redirects and server configuration.
		'wp-force-ssl',
		'wordpress-https',
		'easy-https-redirection',
		'ssl-insecure-content-fixer',
		'redirect-redirection',
		'wp-robots-txt',
		'pc-robotstxt',
		'better-robots-txt',
		'wp-htaccess-editor',
		'wp-headers-and-footers',
		'http-headers',

		// Source-host and administrative utilities.
		'white-label-cms',
		'pressable-onepress-login',
		'hardypress',
		'developer-tools-developer-plugins-speedtest-developer-dashboard',
		'developer-tools-developer-plugins-developer-dashboard',
		'developer-tools',
		'developer-tools-developer-plugins',
		'wp-original-media-path',
		'wpide',
		'wp-file-manager',
		'file-manager-advanced',
		'filester',

		// Backup and migration utilities.
		'wpvivid-backuprestore',
		'wpvivid-backup-pro',
		'updraftplus',
		'duplicator',
		'duplicator-pro',
		'all-in-one-wp-migration',
		'prime-mover',

		// Maintenance, coming-soon, offline, and whole-site protection.
		'coming-soon',
		'wp-maintenance-mode',
		'maintenance',
		'cmp-coming-soon-maintenance',
		'under-construction-page',
		'password-protected',
		'disable-site',
		'full-site-login',
		'gozer',
		'igniteup',
		'jonradio-private-site',
		'only-registered-users',
		'pausepage',
		'registered-users-only',
		'restrict-website-access',
		'restricted-site-access',
		'site-is-offline',
		'site-offline',
		'visitor-force-login-page',
		'wp-force-login',

		// Image optimization handled by the platform.
		'ewww-image-optimizer',
		'shortpixel-image-optimiser',
		'webp-converter-for-media',
		'webp-express',
		'imagify',
		'wp-smushit',
		'optimole-wp',
		'robin-image-optimizer',
		'shortpixel-adaptive-images',
		'optimus',
		'image-optimization',
		'plus-webp',
		'tiny-compress-images',
	);

	/**
	 * Narrow directory-name signatures for access-blocking plugin families.
	 */
	const ACCESS_BLOCKING_PLUGIN_SLUG_PATTERNS = array(
		'/(?:coming[-_]?soon|maintenance(?:[-_]?mode)?|under[-_]?construction|site[-_]?(?:is[-_]?)?offline|offline[-_]?site|password[-_]?protected)/i',
	);

	public function __construct() {
		add_filter( 'sssbm_exclude_plugins_from_export', [ $this, 'exclude_core_plugins' ] );
	}

	/**
	 * Ensures core and known-incompatible plugins are excluded from export.
	 *
	 * @param array $exclude_filters Existing exclusion filters.
	 * @return array Modified exclusion filters.
	 */
	public function exclude_core_plugins( $exclude_filters ) {
		return Helper::merge_mandatory_exclusions( $exclude_filters, self::get_core_plugin_exclusions() );
	}

	/**
	 * Return immutable incompatible/security plugin exclusions.
	 *
	 * @return string[] Slugs and absolute plugin directories.
	 */
	public static function get_core_plugin_exclusions() {
		$slugs = self::get_migration_excluded_plugin_slugs();

		$plugins_to_exclude = $slugs;

		// Also add absolute plugin directory paths for robust matching by the iterator
		foreach ( $slugs as $slug ) {
			$plugins_to_exclude[] = Helper::get_plugins_path() . DIRECTORY_SEPARATOR . $slug;
		}

		return $plugins_to_exclude;
	}

	/**
	 * Return the exact policy plus installed plugin folders recognized by an
	 * access-blocking family signature.
	 *
	 * @return string[]
	 */
	public static function get_migration_excluded_plugin_slugs() {
		$slugs = self::MIGRATION_EXCLUDED_PLUGIN_SLUGS;
		$items = is_dir( Helper::get_plugins_path() ) ? @scandir( Helper::get_plugins_path() ) : false;

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( self::is_migration_excluded_plugin_slug( $item ) ) {
					// Preserve on-disk case so absolute iterator exclusions match on
					// case-sensitive source hosts.
					$slugs[] = $item;
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Identify the exact migration policy and maintenance/access-blocking plugin
	 * families by a safe directory slug only. No migrated PHP is loaded.
	 *
	 * @param mixed $slug Plugin directory name.
	 * @return bool
	 */
	public static function is_migration_excluded_plugin_slug( $slug ) {
		if (
			! is_string( $slug )
			|| '' === $slug
			|| $slug !== basename( $slug )
			|| ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $slug )
		) {
			return false;
		}

		$slug = strtolower( $slug );
		if ( in_array( $slug, self::MIGRATION_EXCLUDED_PLUGIN_SLUGS, true ) ) {
			return true;
		}

		foreach ( self::ACCESS_BLOCKING_PLUGIN_SLUG_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $slug ) ) {
				return true;
			}
		}

		return false;
	}
}
