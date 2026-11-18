<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Exclude_Plugins;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\database\Database_Utility;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Config {

	public static function execute( $params = [] ) {
		global $wp_version;
		$params         = is_array( $params ) ? $params : array();
		$export_options = isset( $params['options'] ) && is_array( $params['options'] ) ? $params['options'] : array();
		$export_options = self::add_omitted_component_flags( $export_options );

		// Set progress
		Status::info( __( 'Preparing configuration...', 'simply-static-studio-backup-migrate' ), 'ssm-config' );

		// Get options
		$all_options = wp_load_alloptions();
		$all_options = is_array( $all_options ) ? $all_options : array();

		// Get database client
		$db_client = Database_Utility::create_client();

		$config = array();

		// Set site URL
		$config['SiteURL'] = site_url();

		// Set home URL
		$config['HomeURL'] = home_url();

		// Set internal site URL
		if ( isset( $all_options['siteurl'] ) && self::is_safe_string( $all_options['siteurl'] ) ) {
			$config['InternalSiteURL'] = $all_options['siteurl'];
		}

		// Set internal home URL
		if ( isset( $all_options['home'] ) && self::is_safe_string( $all_options['home'] ) ) {
			$config['InternalHomeURL'] = $all_options['home'];
		}

		// Set replace old and new values
		self::add_replacements( $config, $export_options );

		// Set no spam comments
		if ( isset( $export_options['no_spam_comments'] ) ) {
			$config['NoSpamComments'] = true;
		}

		// Set no post revisions
		if ( isset( $export_options['no_post_revisions'] ) ) {
			$config['NoPostRevisions'] = true;
		}

		// Set no media
		if ( isset( $export_options['no_media'] ) ) {
			$config['NoMedia'] = true;
		}

		// Set no themes
		if ( isset( $export_options['no_themes'] ) ) {
			$config['NoThemes'] = true;
		}

		// Set no inactive themes
		$config['NoInactiveThemes'] = true;

		// Must-use plugins are always excluded from the content archive.
		$config['NoMustUsePlugins'] = true;

		// Set no plugins
		if ( isset( $export_options['no_plugins'] ) ) {
			$config['NoPlugins'] = true;
		}

		// The plugin enumerator intentionally exports active plugins only.
		$config['NoInactivePlugins'] = true;

		// The content enumerator always excludes the top-level cache directory.
		$config['NoCache'] = true;

		// Set no database
		if ( isset( $export_options['no_database'] ) ) {
			$config['NoDatabase'] = true;
		}

		// Set no email replace
		if ( isset( $export_options['no_email_replace'] ) ) {
			$config['NoEmailReplace'] = true;
		}

		// Set plugin version
		$config['Plugin'] = array( 'Version' => SSSBM_VERSION );

		// Set WordPress version and content
		$config['WordPress'] = array( 'Version'    => $wp_version,
		                              'Absolute'   => Helper::get_wordpress_path(),
		                              'Content'    => Helper::get_content_path(),
		                              'Plugins'    => ai1wm_get_plugins_dir(),
		                              'Themes'     => ai1wm_get_themes_dirs(),
		                              'Uploads'    => ai1wm_get_uploads_dir(),
		                              'UploadsURL' => ai1wm_get_uploads_url()
		);

		// Set database version
		$table_prefix = Helper::get_table_prefix();
		if ( ! is_string( $table_prefix ) || '' === $table_prefix || preg_match( '/[^a-z0-9_]/i', $table_prefix ) ) {
			throw new \RuntimeException( __( 'The current WordPress database table prefix is invalid.', 'simply-static-studio-backup-migrate' ) );
		}

		$config['Database'] = array(
			'Version' => $db_client->server_info(),
			'Charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'undefined',
			'Collate' => defined( 'DB_COLLATE' ) ? DB_COLLATE : 'undefined',
			'Prefix'  => $table_prefix,
		);

		// Exclude selected db tables
		if ( isset( $export_options['exclude_db_tables'], $params['excluded_db_tables'] ) && is_string( $params['excluded_db_tables'] ) ) {
			if ( ( $excluded_db_tables = self::parse_csv_values( $params['excluded_db_tables'] ) ) ) {
				$config['Database']['ExcludedTables'] = $excluded_db_tables;
			}
		}

		// Include selected db tables
		if ( isset( $export_options['include_db_tables'], $params['included_db_tables'] ) && is_string( $params['included_db_tables'] ) ) {
			if ( ( $included_db_tables = self::parse_csv_values( $params['included_db_tables'] ) ) ) {
				$config['Database']['IncludedTables'] = $included_db_tables;
			}
		}

		// Set PHP version
		$config['PHP'] = array( 'Version' => PHP_VERSION, 'System' => PHP_OS, 'Integer' => PHP_INT_SIZE );

		// Set active plugins
		$config['Plugins'] = isset( $export_options['no_plugins'] ) ? array() : self::get_active_plugins();

		// Set a coherent active theme pair only when theme files are included.
		$active_theme         = isset( $export_options['no_themes'] ) ? array( '', '' ) : self::get_active_theme();
		$config['Template']   = $active_theme[0];
		$config['Stylesheet'] = $active_theme[1];

		// Set upload path
		$upload_path       = get_option( 'upload_path', '' );
		$config['Uploads'] = self::is_safe_string( $upload_path ) ? $upload_path : '';

		// Set upload URL path
		$upload_url_path       = get_option( 'upload_url_path', '' );
		$config['UploadsURL']  = self::is_safe_string( $upload_url_path ) ? $upload_url_path : '';

		// Save file
		$config_json = wp_json_encode( $config );
		if ( ! is_string( $config_json ) ) {
			throw new \RuntimeException( __( 'Could not encode the backup configuration.', 'simply-static-studio-backup-migrate' ) );
		}

		$handle = @fopen( Backup::get_config_file_path(), 'wb' );
		if ( ! is_resource( $handle ) ) {
			throw new \RuntimeException( __( 'Could not create the backup configuration file.', 'simply-static-studio-backup-migrate' ) );
		}
		@chmod( Backup::get_config_file_path(), 0600 );

		try {
			Helper::write( $handle, $config_json );
		} finally {
			fclose( $handle );
		}

		// Set progress
		Status::success( __( 'Configuration prepared.', 'simply-static-studio-backup-migrate' ), 'ssm-config' );

		return $params;
	}

	/**
	 * Mark optional components omitted by the frozen queue for this run.
	 *
	 * @param array $options Legacy export options.
	 * @return array
	 */
	private static function add_omitted_component_flags( $options ) {
		$tasks = Backup::get_tasks();
		$map   = array(
			'no_media'    => 'media',
			'no_plugins'  => 'plugins',
			'no_themes'   => 'themes',
			'no_database' => 'database_file',
		);

		foreach ( $map as $flag => $task ) {
			if ( ! in_array( $task, $tasks, true ) ) {
				$options[ $flag ] = true;
			}
		}

		return $options;
	}

	/**
	 * Add only complete, string replacement pairs to the manifest.
	 *
	 * @param array $config Configuration payload, passed by reference.
	 * @param array $options Export options.
	 * @return void
	 */
	private static function add_replacements( &$config, $options ) {
		if ( ! isset( $options['replace'] ) || ! is_array( $options['replace'] ) ) {
			return;
		}

		$old_values = isset( $options['replace']['old_value'] ) && is_array( $options['replace']['old_value'] ) ? $options['replace']['old_value'] : array();
		$new_values = isset( $options['replace']['new_value'] ) && is_array( $options['replace']['new_value'] ) ? $options['replace']['new_value'] : array();

		foreach ( $old_values as $key => $old_value ) {
			if (
				! array_key_exists( $key, $new_values ) ||
				! self::is_safe_string( $old_value ) ||
				! self::is_safe_string( $new_values[ $key ] ) ||
				'' === $old_value ||
				'' === $new_values[ $key ]
			) {
				continue;
			}

			$config['Replace']['OldValues'][] = $old_value;
			$config['Replace']['NewValues'][] = $new_values[ $key ];
		}
	}

	/**
	 * Return active per-site and network plugins that are actually exported.
	 *
	 * @return string[]
	 */
	private static function get_active_plugins() {
		$plugins      = get_option( 'active_plugins', array() );
		$plugins      = is_array( $plugins ) ? array_values( $plugins ) : array();
		$active       = array();
		$network_wide = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();

		if ( is_array( $network_wide ) ) {
			foreach ( array_keys( $network_wide ) as $plugin ) {
				if ( is_string( $plugin ) ) {
					$plugins[] = $plugin;
				}
			}
		}

		$mandatory_exclusions = Exclude_Plugins::get_core_plugin_exclusions();
		$filtered_exclusions  = apply_filters( 'sssbm_exclude_plugins_from_export', $mandatory_exclusions );
		$excluded             = Helper::merge_mandatory_exclusions( $filtered_exclusions, $mandatory_exclusions );

		foreach ( $plugins as $plugin ) {
			$plugin = self::normalize_relative_path( $plugin );
			if (
				'' === $plugin ||
				self::is_plugin_excluded( $plugin, $excluded ) ||
				! self::is_plugin_exportable( $plugin )
			) {
				continue;
			}

			$active[ $plugin ] = $plugin;
		}

		return array_values( $active );
	}

	/**
	 * Return a safe, non-excluded template/stylesheet pair.
	 *
	 * @return string[]
	 */
	private static function get_active_theme() {
		$template   = self::normalize_relative_path( get_option( 'template', '' ) );
		$stylesheet = self::normalize_relative_path( get_option( 'stylesheet', '' ) );

		if ( '' === $template || '' === $stylesheet ) {
			return array( '', '' );
		}

		$excluded = Helper::merge_mandatory_exclusions(
			apply_filters( 'sssbm_exclude_themes_from_export', array() ),
			array()
		);
		$roots    = Helper::get_themes_dirs();

		if (
			self::is_component_excluded( $template, $template, $roots, $excluded ) ||
			self::is_component_excluded( $stylesheet, $stylesheet, $roots, $excluded ) ||
			! self::is_theme_exportable( $template, $roots ) ||
			! self::is_theme_exportable( $stylesheet, $roots )
		) {
			return array( '', '' );
		}

		return array( $template, $stylesheet );
	}

	/**
	 * Check whether the plugin main file or its top-level directory is excluded.
	 *
	 * @param string $plugin Plugin basename.
	 * @param array  $excluded Exclusion filter paths.
	 * @return bool
	 */
	private static function is_plugin_excluded( $plugin, $excluded ) {
		$directory = dirname( $plugin );
		$directory = '.' === $directory ? $plugin : $directory;

		return self::is_component_excluded( $plugin, $directory, array( Helper::get_plugins_path() ), $excluded );
	}

	/**
	 * Confirm that a plugin main file can be reached by the plugin iterator.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function is_plugin_exportable( $plugin ) {
		$path = untrailingslashit( Helper::get_plugins_path() ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugin );

		return is_file( $path )
			&& is_readable( $path )
			&& self::path_allows_symbolic_links( Helper::get_plugins_path(), $plugin );
	}

	/**
	 * Confirm that at least one registered root contains an exportable theme.
	 *
	 * @param string $theme Theme stylesheet/template basename.
	 * @param array  $roots Registered theme roots.
	 * @return bool
	 */
	private static function is_theme_exportable( $theme, $roots ) {
		foreach ( $roots as $root ) {
			$root = self::normalize_filter_path( $root );
			if ( '' === $root ) {
				continue;
			}

			$path = $root . '/' . $theme;
			if ( is_dir( $path ) && is_readable( $path ) && self::path_allows_symbolic_links( $root, $theme ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match the recursive iterator's default refusal to follow symbolic links.
	 *
	 * @param string $root Absolute iterator root.
	 * @param string $relative Relative component path.
	 * @return bool
	 */
	private static function path_allows_symbolic_links( $root, $relative ) {
		$path = untrailingslashit( $root );
		foreach ( explode( '/', $relative ) as $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if (
				is_link( $path ) &&
				! apply_filters( 'sssbm_follow_symbolic_links', false, $path )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Match the same relative and absolute component paths used by the iterators.
	 *
	 * @param string $relative Relative main path.
	 * @param string $component Relative component root.
	 * @param array  $roots Absolute component roots.
	 * @param array  $excluded Exclusion filter paths.
	 * @return bool
	 */
	private static function is_component_excluded( $relative, $component, $roots, $excluded ) {
		$candidates = array( $relative, $component );

		foreach ( $roots as $root ) {
			$root = self::normalize_filter_path( $root );
			if ( '' === $root ) {
				continue;
			}

			$candidates[] = $root;
			$candidates[] = $root . '/' . $relative;
			$candidates[] = $root . '/' . $component;
		}

		$candidates = array_unique( array_map( array( __CLASS__, 'normalize_filter_path' ), $candidates ) );
		foreach ( $excluded as $path ) {
			$path = self::normalize_filter_path( $path );
			if ( '' !== $path && in_array( $path, $candidates, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a WordPress plugin/theme basename and reject path traversal.
	 *
	 * @param mixed $path Candidate relative path.
	 * @return string
	 */
	private static function normalize_relative_path( $path ) {
		if ( ! self::is_safe_string( $path, 1024 ) ) {
			return '';
		}

		$path = str_replace( '\\', '/', $path );
		if ( '' === $path || '/' === $path[0] || preg_match( '/^[a-z]:\//i', $path ) ) {
			return '';
		}

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}
		}

		return $path;
	}

	/**
	 * Normalize an exclusion filter path for stable comparisons.
	 *
	 * @param mixed $path Candidate path.
	 * @return string
	 */
	private static function normalize_filter_path( $path ) {
		if ( ! self::is_safe_string( $path, 4096 ) ) {
			return '';
		}

		$path = str_replace( '\\', '/', $path );

		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Parse a comma-separated metadata list without admitting malformed values.
	 *
	 * @param string $value Comma-separated values.
	 * @return string[]
	 */
	private static function parse_csv_values( $value ) {
		$values = array();
		foreach ( explode( ',', $value ) as $item ) {
			$item = trim( $item );
			if ( '' !== $item && self::is_safe_string( $item ) ) {
				$values[] = $item;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Validate strings before they are persisted in a JSON integration payload.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $maximum_length Maximum byte length.
	 * @return bool
	 */
	private static function is_safe_string( $value, $maximum_length = 4096 ) {
		return is_string( $value )
			&& strlen( $value ) <= $maximum_length
			&& 1 === preg_match( '//u', $value )
			&& ! preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
