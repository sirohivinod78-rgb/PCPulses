<?php

/**
 * Local backup I/O requires bounded streams, exclusive locks, atomic same-volume
 * renames, verified deletion results, and owner-only permissions. WP_Filesystem
 * remote transports cannot preserve these guarantees for sensitive backup files.
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 *
 * Exceptions carry plain-text diagnostics to CLI, logs, or JSON; HTML consumers
 * escape at their rendering boundary, never while constructing these messages.
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 */

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Helper {

	/**
	 * Last machine-readable database dump redaction failure.
	 *
	 * @var string
	 */
	private static $database_dump_redaction_error = '';

	/**
	 * Resolve local installation paths through the WordPress Filesystem API.
	 *
	 * A separate direct adapter is intentional: the global filesystem may use
	 * FTP paths, which cannot be passed to the local backup stream/iterator APIs.
	 * WordPress resolves relocated roots without inferring paths from public URLs
	 * or from this plugin's own (potentially symlinked) directory.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	private static function get_local_wordpress_filesystem() {
		static $filesystem;

		if ( ! $filesystem ) {
			if ( ! class_exists( '\WP_Filesystem_Base' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			}
			if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$filesystem = new \WP_Filesystem_Direct( null );
		}

		return $filesystem;
	}

	/**
	 * Normalize a local root returned by WordPress, failing closed on invalid paths.
	 *
	 * @param mixed $path WordPress filesystem path.
	 * @return string
	 */
	private static function normalize_wordpress_directory( $path ) {
		if ( ! is_string( $path ) || '' === $path || preg_match( '/[\x00-\x1F\x7F]/', $path ) || self::is_stream_wrapped_path( $path ) ) {
			throw new \RuntimeException( __( 'A local WordPress directory could not be resolved.', 'simply-static-studio-backup-migrate' ) );
		}

		$path = wp_normalize_path( $path );
		if ( '/' !== $path[0] && ! preg_match( '~^[A-Za-z]:/~', $path ) ) {
			throw new \RuntimeException( __( 'A local WordPress directory could not be resolved.', 'simply-static-studio-backup-migrate' ) );
		}

		return untrailingslashit( $path );
	}

	/** @return string Local WordPress core directory with a trailing slash. */
	public static function get_wordpress_path() {
		return trailingslashit( self::normalize_wordpress_directory( self::get_local_wordpress_filesystem()->abspath() ) );
	}

	/** @return string Local content directory without a trailing slash. */
	public static function get_content_path() {
		return self::normalize_wordpress_directory( self::get_local_wordpress_filesystem()->wp_content_dir() );
	}

	/** @return string Local plugins directory without a trailing slash. */
	public static function get_plugins_path() {
		return self::normalize_wordpress_directory( self::get_local_wordpress_filesystem()->wp_plugins_dir() );
	}

	/** @return string Local must-use plugins directory without a trailing slash. */
	public static function get_mu_plugins_path() {
		// WordPress has no MU-directory accessor. Its configured root is required
		// here because MU plugins may live outside both content and regular plugins.
		return self::normalize_wordpress_directory( self::get_local_wordpress_filesystem()->find_folder( WPMU_PLUGIN_DIR ) );
	}

	/**
	 * Return every site's configured upload root for content exclusions.
	 *
	 * Each site can relocate its uploads independently. Resolve every site through
	 * WordPress instead of assuming that the main site's root contains them all.
	 *
	 * @return string[]
	 */
	public static function get_uploads_paths() {
		$uploads = wp_upload_dir( null, false );
		$paths   = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) && '' !== $uploads['basedir']
			? array( untrailingslashit( $uploads['basedir'] ) )
			: array();

		if ( is_multisite() ) {
			$current_blog = get_current_blog_id();
			$offset = 0;
			do {
				$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
				foreach ( $site_ids as $site_id ) {
					if ( $current_blog === (int) $site_id ) {
						continue;
					}
					switch_to_blog( (int) $site_id );
					try {
						$uploads = wp_upload_dir( null, false );
						if ( isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) && '' !== $uploads['basedir'] ) {
							$paths[] = untrailingslashit( $uploads['basedir'] );
						}
					} finally {
						restore_current_blog();
					}
				}
				$offset += count( $site_ids );
			} while ( count( $site_ids ) === 100 );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Merge integration exclusions without allowing filters to erase safety rules.
	 *
	 * @param mixed $filtered Filter return value.
	 * @param array $mandatory Immutable exclusions assembled by the caller.
	 * @return string[]
	 */
	public static function merge_mandatory_exclusions( $filtered, $mandatory ) {
		$filtered  = is_array( $filtered ) ? $filtered : array();
		$mandatory = is_array( $mandatory ) ? $mandatory : array();
		$merged    = array();

		foreach ( array_merge( $filtered, $mandatory ) as $path ) {
			if (
				! is_string( $path ) ||
				'' === $path ||
				strlen( $path ) > 4096 ||
				preg_match( '/[\x00-\x1F\x7F]/', $path )
			) {
				continue;
			}

			$merged[ $path ] = $path;
		}

		return array_values( $merged );
	}

	/**
	 * Returns the global $wp_filesystem with credentials set.
	 * Returns null in case of any errors.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function getFileSystem() {
		global $wp_filesystem;

		$success = true;

		// Initialize the file system if it has not been done yet.
		if ( ! $wp_filesystem ) {
			require_once self::get_wordpress_path() . 'wp-admin/includes/file.php';

			$constants = array(
				'hostname'    => 'FTP_HOST',
				'username'    => 'FTP_USER',
				'password'    => 'FTP_PASS',
				'public_key'  => 'FTP_PUBKEY',
				'private_key' => 'FTP_PRIKEY',
			);

			$credentials = array();

			// We provide credentials based on wp-config.php constants.
			// Reference https://developer.wordpress.org/apis/wp-config-php/#wordpress-upgrade-constants
			foreach ( $constants as $key => $constant ) {
				if ( defined( $constant ) ) {
					$credentials[ $key ] = constant( $constant );
				}
			}

			$success = WP_Filesystem( $credentials );
		}

		if ( ! $success || ! $wp_filesystem ) {
			return null;
		}

		if ( isset( $wp_filesystem->errors ) && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
			return null;
		}

		return $wp_filesystem;
	}

	/**
	 * Cleans a WordPress options SQL INSERT query by removing rows that contain a specific keyword
	 * in the option_name field.
	 *
	 * @todo Test thoroughly before using. Used to remove rows of insert SQL that is not needed such as wc_session from option_value.
	 *
	 * @param string $sql_query The SQL INSERT query to clean
	 * @param string|array $keywords Single keyword or array of keywords to filter out
	 * @return string The cleaned SQL query
	 */
	public static function cleanInsertQuery($sql_query, $keywords) {
		// Convert single keyword to array for consistent processing
		if (!is_array($keywords)) {
			$keywords = [$keywords];
		}

		// Check if this is an INSERT query for wp_options table
		if (strpos($sql_query, 'INSERT INTO') === false || strpos($sql_query, 'wp_options') === false) {
			return $sql_query; // Return original if not a relevant query
		}

		// Extract the header part (everything before VALUES)
		$parts = explode('VALUES', $sql_query, 2);
		if (count($parts) !== 2) {
			return $sql_query; // Return original if format doesn't match expected
		}

		$header = $parts[0];
		$values_section = $parts[1];

		// Find the start and end of the values section
		$values_start = strpos($values_section, '(');
		$values_end = strrpos($values_section, ';');

		if ($values_start === false) {
			return $sql_query; // Return original if no opening parenthesis found
		}

		// Extract just the values part
		$values_content = $values_end !== false
			? substr($values_section, $values_start, $values_end - $values_start)
			: substr($values_section, $values_start);

		// Split into individual rows
		$rows = [];
		$current_row = '';
		$in_quotes = false;
		$escaped = false;
		$paren_level = 0;

		for ($i = 0; $i < strlen($values_content); $i++) {
			$char = $values_content[$i];

			if ( $char === ',' && ! $current_row ) {
				continue;
			}

			if ($escaped) {
				$current_row .= $char;
				$escaped = false;
				continue;
			}

			if ($char === '\\') {
				$current_row .= $char;
				$escaped = true;
				continue;
			}

			if ($char === "'" && !$in_quotes) {
				$in_quotes = true;
				$current_row .= $char;
			} elseif ($char === "'" && $in_quotes) {
				$in_quotes = false;
				$current_row .= $char;
			} elseif ($char === '(' && !$in_quotes) {
				$paren_level++;
				$current_row .= $char;
			} elseif ($char === ')' && !$in_quotes) {
				$paren_level--;
				$current_row .= $char;

				if ($paren_level === 0) {
					// End of a row
					$rows[] = trim($current_row);
					$current_row = '';
				}
			} else {
				$current_row .= $char;
			}
		}

		// Filter out rows containing any of the keywords in the option_name field
		$filtered_rows = [];
		foreach ($rows as $row) {
			$row_values = str_getcsv(trim($row, '()'), ',', "'", '\\');

			// Assuming option_name is the second field (index 1)
			if (isset($row_values[1])) {
				$option_name = trim($row_values[1], "'");
				$contains_keyword = false;

				foreach ($keywords as $keyword) {
					if (strpos($option_name, $keyword) !== false) {
						$contains_keyword = true;
						break;
					}
				}

				if (!$contains_keyword) {
					$filtered_rows[] = $row;
				}
			} else {
				// If we can't parse the row properly, include it
				$filtered_rows[] = $row;
			}
		}

		// Reassemble the SQL query
		$cleaned_sql = $header . 'VALUES' . PHP_EOL;
		if (!empty($filtered_rows)) {
			$cleaned_sql .= ' ' . implode(',' . PHP_EOL . ' ', $filtered_rows) . ';';
		} else {
			// If all rows were filtered out, return an empty query or placeholder
			$cleaned_sql = '-- All rows were filtered out from the query';
		}

		return $cleaned_sql;
	}

	/**
	 * Get archive size in bytes
	 *
	 * @return integer
	 */
	public static function getArchiveBytes() {
		$archive_path = Backup::get_existing_backup_path();

		if ( ! $archive_path ) {
			return 0;
		}

		$size = filesize( $archive_path );

		return false === $size ? 0 : $size;
	}

	/**
	 * Check whether a path resolves inside a trusted root directory.
	 *
	 * The comparison is boundary-aware (so `/uploads/foo` does not match
	 * `/uploads/foobar`) and resolves symlinks for existing paths. A direct
	 * child that does not exist yet is supported by resolving its parent.
	 *
	 * @param string $path Candidate path.
	 * @param string $root Trusted root directory.
	 * @return bool
	 */
	public static function is_path_within( $path, $root ) {
		static $resolved_roots = array();

		if ( ! is_string( $path ) || ! is_string( $root ) || '' === $path || '' === $root || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$root_cache_key = wp_normalize_path( $root );
		if ( isset( $resolved_roots[ $root_cache_key ] ) ) {
			$resolved_root = $resolved_roots[ $root_cache_key ];
		} else {
			$resolved_root = realpath( $root );
			if ( false === $resolved_root ) {
				return false;
			}

			$resolved_roots[ $root_cache_key ] = $resolved_root;
		}

		$resolved_path = realpath( $path );
		if ( false === $resolved_path ) {
			$resolved_parent = realpath( dirname( $path ) );
			if ( false === $resolved_parent ) {
				return false;
			}

			$resolved_path = $resolved_parent . DIRECTORY_SEPARATOR . basename( $path );
		}

		$resolved_root = untrailingslashit( wp_normalize_path( $resolved_root ) );
		$resolved_path = untrailingslashit( wp_normalize_path( $resolved_path ) );

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$resolved_root = strtolower( $resolved_root );
			$resolved_path = strtolower( $resolved_path );
		}

		return $resolved_path === $resolved_root || 0 === strpos( $resolved_path, trailingslashit( $resolved_root ) );
	}

	/**
	 * Resolve a persisted root-relative file path against the current root.
	 *
	 * Resumable file lists also contain the absolute path observed while they
	 * were enumerated. Some managed hosts can expose a different absolute mount
	 * to a later worker, so the relative path is the durable part of the row.
	 * Rebuilding it from the current trusted root keeps those resumes portable
	 * while the canonical containment check still rejects traversals and links
	 * that escape the root.
	 *
	 * @param string $relative_path Root-relative path from a backup list.
	 * @param string $root Current trusted root directory.
	 * @return string|false
	 */
	public static function resolve_path_within( $relative_path, $root ) {
		if (
			! is_string( $relative_path ) ||
			! is_string( $root ) ||
			'' === $relative_path ||
			'' === $root ||
			false !== strpos( $relative_path, "\0" ) ||
			false !== strpos( $root, "\0" )
		) {
			return false;
		}

		$relative_path = wp_normalize_path( $relative_path );
		if ( '/' === $relative_path[0] || preg_match( '/^[a-z]:\//i', $relative_path ) ) {
			return false;
		}

		$parts = explode( '/', $relative_path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}

		if ( self::is_stream_wrapped_path( $root ) ) {
			// Stream-wrapper paths (for example s3://bucket/uploads) cannot be
			// canonicalized with realpath(). Rebuild them only from the trusted
			// current root and already-validated relative path instead of trusting
			// the absolute path persisted by an earlier worker.
			return rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' . implode( '/', $parts );
		}

		$candidate = trailingslashit( $root ) . implode( DIRECTORY_SEPARATOR, $parts );

		return self::is_path_within( $candidate, $root ) ? $candidate : false;
	}

	/**
	 * Whether a path is handled by a PHP stream wrapper.
	 *
	 * @param mixed $path Candidate path.
	 * @return bool
	 */
	public static function is_stream_wrapped_path( $path ) {
		return is_string( $path )
			&& 1 === preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*://#', $path );
	}

	/**
	 * Resolve one persisted backup-list row inside the current trusted root.
	 *
	 * Keep the enumerated absolute path when it still validates. Otherwise use
	 * the safe current-root path rebuilt from the row's relative path.
	 *
	 * @param mixed  $listed_path Absolute path captured during enumeration.
	 * @param string $relative_path Root-relative path from the same row.
	 * @param string $root Current trusted root directory.
	 * @return string|false
	 */
	public static function resolve_listed_path_within( $listed_path, $relative_path, $root ) {
		$current_path = self::resolve_path_within( $relative_path, $root );
		if ( false === $current_path ) {
			return false;
		}
		if ( is_string( $listed_path ) && wp_normalize_path( $listed_path ) === wp_normalize_path( $current_path ) ) {
			return $current_path;
		}

		return is_string( $listed_path ) && self::is_path_within( $listed_path, $root )
			? $listed_path
			: $current_path;
	}

	/**
	 * Open a stable local lock without following links or locking another inode.
	 *
	 * WordPress filesystem transports cannot return native handles for flock().
	 * Verify the opened descriptor against the private directory entry before
	 * changing its permissions or allowing a caller to acquire the lock.
	 *
	 * @param string $path Lock file path.
	 * @param string $root Protected local directory.
	 * @return resource|false Verified handle, or false for an unsafe path.
	 */
	public static function open_lock_file( $path, $root ) {
		if ( ! self::is_path_within( $path, $root ) || is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return false;
		}
		$handle = @fopen( $path, 'c+' );
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		clearstatcache( true, $path );
		$opened = @fstat( $handle );
		$entry = @lstat( $path );
		if (
			! is_array( $opened ) || ! is_array( $entry ) ||
			0100000 !== ( (int) $opened['mode'] & 0170000 ) ||
			0100000 !== ( (int) $entry['mode'] & 0170000 ) ||
			1 !== (int) $opened['nlink'] || 1 !== (int) $entry['nlink'] ||
			(string) $opened['dev'] !== (string) $entry['dev'] ||
			(string) $opened['ino'] !== (string) $entry['ino'] ||
			! @chmod( $path, 0600 )
		) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	/**
	 * Acquire a lock for ZIP file operations
	 *
	 * @return resource|false Lock file handle or false on failure
	 */
	public static function acquireZipLock() {
		$storage = Backup::get_storage_path();
		$lock_handle = self::open_lock_file( $storage . 'backup.lock', $storage );

		if ( $lock_handle && flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
			return $lock_handle;
		}

		if ( $lock_handle ) {
			fclose( $lock_handle );
		}

		return false;
	}

	/**
	 * Release ZIP file lock
	 *
	 * @param resource $lock_handle Lock file handle
	 */
	public static function releaseZipLock( $lock_handle ) {
		if ( is_resource( $lock_handle ) ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle );
		}
	}

	/**
	 * Wait for ZIP lock with timeout
	 *
	 * @param int $timeout_seconds Maximum time to wait for lock
	 * @return resource|false Lock file handle or false on timeout
	 */
	public static function waitForZipLock( $timeout_seconds = 30 ) {
		$start_time = time();

		while ( ( time() - $start_time ) < $timeout_seconds ) {
			$lock_handle = self::acquireZipLock();
			if ( $lock_handle ) {
				return $lock_handle;
			}

			// Wait 100ms before trying again
			usleep( 100000 );
		}

		return false;
	}

	/**
	 * Write fields to a file
	 *
	 * @param resource  $handle File handle to write to
	 * @param array     $fields Fields to write to the file
	 * @param string    $separator
	 * @param string    $enclosure
	 * @param string    $escape
	 *
	 * @return integer
	 * @throws \Exception
	 */
	public static function putcsv( $handle, $fields, $separator = ',', $enclosure = '"', $escape = '\\' ) {
		if ( PHP_MAJOR_VERSION >= 7 ) {
			$write_result = @fputcsv( $handle, $fields, $separator, $enclosure, $escape );
		} else {
			$write_result = @fputcsv( $handle, $fields, $separator, $enclosure );
		}

		if ( false === $write_result ) {
			if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
				throw new \Exception(
					/* translators: %s: Backup file path or underlying request failure. */
					/* translators: %s: Backup file path or underlying request failure. */
					sprintf( __( 'Could not write to: %s. The process cannot continue.', 'simply-static-studio-backup-migrate' ), $meta['uri'] )
				);
			}
		}

		return $write_result;
	}

	/**
	 * Read fields from a file
	 *
	 * @param resource  $handle File handle to read from
	 * @param int       $length
	 * @param string    $separator
	 * @param string    $enclosure
	 * @param string    $escape
	 *
	 * @return array|false|null
	 */
	public static function getcsv( $handle, $length = null, $separator = ',', $enclosure = '"', $escape = '\\' ) {
		return fgetcsv( $handle, $length, $separator, $enclosure, $escape );
	}

	/**
	 * Get WordPress theme directories
	 *
	 * @return array
	 */
	public static function get_themes_dirs() {
		$theme_dirs = array();
		$directories = search_theme_directories();

		if ( ! is_array( $directories ) ) {
			return $theme_dirs;
		}

		foreach ( $directories as $theme_info ) {
			if ( isset( $theme_info['theme_root'] ) ) {
				$theme_root = untrailingslashit( wp_normalize_path( $theme_info['theme_root'] ) );
				if ( ! in_array( $theme_root, $theme_dirs, true ) ) {
					$theme_dirs[] = $theme_root;
				}
			}
		}

		return $theme_dirs;
	}

	/**
	 * Get WordPress table prefix by blog ID
	 *
	 * @param  integer $blog_id Blog ID
	 * @return string
	 */
	public static function get_table_prefix( $blog_id = null ) {
		global $wpdb;

		if ( null === $blog_id ) {
			return $wpdb->prefix;
		}

		if ( method_exists( $wpdb, 'get_blog_prefix' ) ) {
			return $wpdb->get_blog_prefix( (int) $blog_id );
		}

		return self::is_mainsite( $blog_id ) ? $wpdb->base_prefix : $wpdb->base_prefix . (int) $blog_id . '_';
	}

	/**
	 * Check whether blog ID is main site
	 *
	 * @param  integer $blog_id Blog ID
	 * @return boolean
	 */
	public static function is_mainsite( $blog_id = null ) {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( null === $blog_id || 0 === (int) $blog_id ) {
			$blog_id = get_current_blog_id();
		}

		return function_exists( 'get_main_site_id' )
			? (int) $blog_id === (int) get_main_site_id()
			: (int) $blog_id === 1;
	}

	/**
	 * Get immutable and integration-provided option prefixes that must not migrate.
	 *
	 * @return string[]
	 */
	public static function get_sensitive_option_prefixes() {
		$default_sensitive_prefixes = array(
			'sss_backup_migrate',
			'sss_export_',
			'sssbm_',
			'simply_static_backup_process_',
			'simply_static_migration_retry_count_',
			'_transient_sssbm_',
			'_transient_timeout_sssbm_',
		);
		if ( is_multisite() ) {
			$default_sensitive_prefixes[] = 'simply_static_' . get_current_blog_id() . '_backup_process_';
		}

		$filtered_prefixes = apply_filters(
			'sssbm_sensitive_option_prefixes',
			$default_sensitive_prefixes
		);
		$additional_prefixes = is_array( $filtered_prefixes ) ? array_filter(
			$filtered_prefixes,
			static function ( $prefix ) {
				return is_string( $prefix ) && '' !== $prefix && strlen( $prefix ) <= 255 && ! preg_match( '/[\x00-\x1F\x7F]/', $prefix );
			}
		) : array();

		// Mandatory plugin credential/state prefixes are immutable. Integrations may
		// only add valid prefixes; an empty or malformed filter result cannot weaken
		// the redaction boundary.
		return array_values( array_unique( array_merge( $default_sensitive_prefixes, $additional_prefixes ) ) );
	}

	/**
	 * Remove this plugin's credentials and ephemeral process state from a SQL dump.
	 *
	 * Database exports contain one INSERT statement per line. Rewriting the dump
	 * line-by-line keeps memory usage bounded for large sites and ensures Studio
	 * access tokens and HTTP Basic Auth credentials are not migrated or shared.
	 *
	 * @param string $database_path Absolute path to the SQL dump.
	 * @return bool True when the dump was safely rewritten.
	 */
	public static function redact_database_dump( $database_path ) {
		self::$database_dump_redaction_error = '';

		if ( ! is_string( $database_path ) || ! self::is_path_within( $database_path, Backup::get_storage_path() ) ) {
			return self::fail_database_dump_redaction( 'invalid_path' );
		}

		$lock_handle = self::acquire_database_dump_redaction_lock( $database_path );
		if ( ! $lock_handle ) {
			return self::fail_database_dump_redaction( 'lock_unavailable' );
		}

		try {
			return self::redact_database_dump_locked( $database_path );
		} finally {
			self::release_database_dump_redaction_lock( $lock_handle );
		}
	}

	/**
	 * Rewrite a dump while its stable per-dump lock is held.
	 *
	 * @param string $database_path Absolute database dump path.
	 * @return bool Whether the dump was safely rewritten.
	 */
	private static function redact_database_dump_locked( $database_path ) {
		$rollback_path = $database_path . '.redacting-original';
		if ( ! self::recover_database_dump_rollback( $database_path, $rollback_path ) || ! is_file( $database_path ) || ! is_readable( $database_path ) ) {
			return self::fail_database_dump_redaction( 'source_unavailable' );
		}

		$source = @fopen( $database_path, 'rb' );
		if ( ! is_resource( $source ) ) {
			return self::fail_database_dump_redaction( 'source_open_failed' );
		}

		$table_name             = preg_quote( self::get_table_prefix() . 'options', '/' );
		$options_insert_pattern = '/^\s*INSERT\s+INTO\s+`?' . $table_name . '`?\s*(?:\([^)]*\)\s*)?VALUES\b/i';
		$option_pattern         = '/^\s*INSERT\s+INTO\s+`?' . $table_name . '`?\s*(?:\([^)]*\)\s*)?VALUES\s*\(\s*[^,]*,\s*\'((?:\\\\.|[^\'])*)\'\s*,/i';
		$sensitive_prefixes     = self::get_sensitive_option_prefixes();
		$contains_sensitive_row = false;

		// New exports omit sensitive rows at query time. Verify that invariant with
		// a read-only pass so a safe dump does not need a second full-size copy.
		while ( false !== ( $line = fgets( $source ) ) ) {
			if ( self::database_dump_line_contains_sensitive_option( $line, $options_insert_pattern, $option_pattern, $sensitive_prefixes ) ) {
				$contains_sensitive_row = true;
				break;
			}
		}

		if ( ! $contains_sensitive_row ) {
			$scan_completed = feof( $source );
			fclose( $source );

			return $scan_completed ? true : self::fail_database_dump_redaction( 'verification_read_failed' );
		}

		if ( ! rewind( $source ) ) {
			fclose( $source );

			return self::fail_database_dump_redaction( 'source_rewind_failed' );
		}

		$temp_path = $database_path . '.redacting';
		if ( ( file_exists( $temp_path ) || is_link( $temp_path ) ) && ! @unlink( $temp_path ) ) {
			fclose( $source );

			return self::fail_database_dump_redaction( 'temporary_cleanup_failed' );
		}
		$target = @fopen( $temp_path, 'xb' );
		@chmod( $temp_path, 0600 );

		if ( ! is_resource( $target ) ) {
			fclose( $source );
			@unlink( $temp_path );

			return self::fail_database_dump_redaction( 'temporary_open_failed' );
		}

		$success = true;
		while ( false !== ( $line = fgets( $source ) ) ) {
			if ( ! self::database_dump_line_contains_sensitive_option( $line, $options_insert_pattern, $option_pattern, $sensitive_prefixes ) ) {
				try {
					self::write( $target, $line );
				} catch ( \Throwable $exception ) {
					$success = false;
					break;
				}
			}
		}

		if ( ! feof( $source ) ) {
			$success = false;
		}

		$success = $success && fflush( $target );
		fclose( $source );
		fclose( $target );

		if ( ! $success ) {
			@unlink( $temp_path );

			return self::fail_database_dump_redaction( 'rewrite_failed' );
		}

		if ( ! self::promote_redacted_database_dump( $temp_path, $database_path, $rollback_path ) ) {
			@unlink( $temp_path );

			return self::fail_database_dump_redaction( 'promotion_failed' );
		}

		@chmod( $database_path, 0600 );

		return true;
	}

	/**
	 * Get the last machine-readable database redaction failure.
	 *
	 * @return string
	 */
	public static function get_database_dump_redaction_error() {
		return self::$database_dump_redaction_error;
	}

	/**
	 * Record a bounded diagnostic code and return false for concise fail paths.
	 *
	 * @param string $code Failure code.
	 * @return false
	 */
	private static function fail_database_dump_redaction( $code ) {
		if ( '' === self::$database_dump_redaction_error ) {
			self::$database_dump_redaction_error = substr( sanitize_key( (string) $code ), 0, 64 );
		}

		return false;
	}

	/**
	 * Determine whether one SQL dump line contains a sensitive options row.
	 *
	 * @param string   $line SQL dump line.
	 * @param string   $options_insert_pattern Options INSERT detection pattern.
	 * @param string   $option_pattern Single-row option-name extraction pattern.
	 * @param string[] $sensitive_prefixes Option-name prefixes to redact.
	 * @return bool
	 */
	private static function database_dump_line_contains_sensitive_option( $line, $options_insert_pattern, $option_pattern, $sensitive_prefixes ) {
		$redact            = false;
		$is_options_insert = (bool) preg_match( $options_insert_pattern, $line );
		if ( preg_match( $option_pattern, $line, $matches ) ) {
			$option_name = stripcslashes( $matches[1] );
			foreach ( $sensitive_prefixes as $prefix ) {
				if ( is_string( $prefix ) && '' !== $prefix && 0 === strpos( $option_name, $prefix ) ) {
					$redact = true;
					break;
				}
			}
		}

		// Future dump grammars may include a column list, different spacing, or
		// multiple value tuples. Do not retain a known sensitive option merely
		// because the exact row parser no longer recognizes the statement.
		if ( $is_options_insert && ! $redact ) {
			$unescaped_line = stripcslashes( $line );
			foreach ( $sensitive_prefixes as $prefix ) {
				if ( is_string( $prefix ) && '' !== $prefix && false !== strpos( $unescaped_line, "'" . $prefix ) ) {
					$redact = true;
					break;
				}
			}
		}

		return $redact;
	}

	/**
	 * Restore the raw dump after an interrupted fallback promotion.
	 *
	 * This is called before the database-file task decides whether the dump is
	 * missing, so a recoverable rollback is not overwritten by regeneration.
	 *
	 * @param string $database_path Absolute database dump path.
	 * @return bool Whether the normal dump path is safe to continue using.
	 */
	public static function recover_database_dump_redaction( $database_path ) {
		self::$database_dump_redaction_error = '';

		if ( ! is_string( $database_path ) || ! self::is_path_within( $database_path, Backup::get_storage_path() ) ) {
			return self::fail_database_dump_redaction( 'invalid_path' );
		}

		$lock_handle = self::acquire_database_dump_redaction_lock( $database_path );
		if ( ! $lock_handle ) {
			return self::fail_database_dump_redaction( 'lock_unavailable' );
		}

		try {
			return self::recover_database_dump_rollback( $database_path, $database_path . '.redacting-original' );
		} finally {
			self::release_database_dump_redaction_lock( $lock_handle );
		}
	}

	/**
	 * Acquire the stable per-dump redaction lock without waiting.
	 *
	 * The lock file is intentionally retained to avoid unlink/recreate inode races.
	 *
	 * @param string $database_path Absolute database dump path.
	 * @return resource|false Lock handle, or false when another worker owns it.
	 */
	private static function acquire_database_dump_redaction_lock( $database_path ) {
		$lock_handle = self::open_lock_file( $database_path . '.redacting.lock', Backup::get_storage_path() );
		if ( ! is_resource( $lock_handle ) ) {
			return false;
		}

		if ( ! flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock_handle );

			return false;
		}

		return $lock_handle;
	}

	/**
	 * Release a database dump redaction lock.
	 *
	 * @param resource $lock_handle Lock handle.
	 * @return void
	 */
	private static function release_database_dump_redaction_lock( $lock_handle ) {
		if ( is_resource( $lock_handle ) ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle );
		}
	}

	/**
	 * Recover an original dump left behind by an interrupted fallback promotion.
	 *
	 * @param string $database_path Absolute database dump path.
	 * @param string $rollback_path Absolute rollback path.
	 * @return bool Whether the normal database path is ready for redaction.
	 */
	private static function recover_database_dump_rollback( $database_path, $rollback_path ) {
		if ( ! file_exists( $rollback_path ) && ! is_link( $rollback_path ) ) {
			return true;
		}
		if ( is_link( $rollback_path ) || ! is_file( $rollback_path ) || ! self::is_path_within( $rollback_path, Backup::get_storage_path() ) ) {
			return self::fail_database_dump_redaction( 'rollback_invalid' );
		}

		@chmod( $rollback_path, 0600 );

		// The rollback is always the source of truth. A coexisting destination can
		// be a partial failed copy, while a fully promoted redacted destination can
		// simply be regenerated from the original on this retry.
		if ( ( file_exists( $database_path ) || is_link( $database_path ) ) && ! @unlink( $database_path ) ) {
			return self::fail_database_dump_redaction( 'rollback_destination_cleanup_failed' );
		}

		if ( @rename( $rollback_path, $database_path ) ) {
			@chmod( $database_path, 0600 );

			return true;
		}

		$rollback_hash = @hash_file( 'sha256', $rollback_path );
		if ( ! is_string( $rollback_hash ) || ! @copy( $rollback_path, $database_path ) || ! self::file_matches_sha256( $database_path, $rollback_hash ) ) {
			return self::fail_database_dump_redaction( 'rollback_restore_failed' );
		}

		@chmod( $database_path, 0600 );
		if ( ! @unlink( $rollback_path ) && file_exists( $rollback_path ) ) {
			return self::fail_database_dump_redaction( 'rollback_cleanup_failed' );
		}

		return true;
	}

	/**
	 * Promote a complete redacted dump without a partial-copy window.
	 *
	 * POSIX can atomically replace the destination with rename(). Platforms that
	 * cannot rename over an existing file use an O(1) rollback rename instead.
	 * If promotion then fails, the original is restored; copy is only a final
	 * rollback mechanism and is hash-verified before its source is removed.
	 *
	 * @param string $temp_path Absolute completed redaction path.
	 * @param string $database_path Absolute database dump path.
	 * @param string $rollback_path Absolute rollback path.
	 * @return bool Whether the redacted dump was promoted successfully.
	 */
	private static function promote_redacted_database_dump( $temp_path, $database_path, $rollback_path ) {
		$redacted_hash = @hash_file( 'sha256', $temp_path );
		if ( ! is_string( $redacted_hash ) ) {
			return false;
		}

		if ( @rename( $temp_path, $database_path ) ) {
			return self::file_matches_sha256( $database_path, $redacted_hash );
		}

		$original_hash = @hash_file( 'sha256', $database_path );
		if ( ! is_string( $original_hash ) || ( ( file_exists( $rollback_path ) || is_link( $rollback_path ) ) && ! @unlink( $rollback_path ) ) || ! @rename( $database_path, $rollback_path ) ) {
			return false;
		}

		@chmod( $rollback_path, 0600 );

		if ( @rename( $temp_path, $database_path ) && self::file_matches_sha256( $database_path, $redacted_hash ) ) {
			return @unlink( $rollback_path ) || ! file_exists( $rollback_path );
		}

		// Remove only the failed redacted candidate. The original remains at the
		// rollback path until a verified restoration has succeeded.
		if ( file_exists( $database_path ) ) {
			@unlink( $database_path );
		}

		$restored = @rename( $rollback_path, $database_path );
		if ( ! $restored ) {
			$restored = @copy( $rollback_path, $database_path ) && self::file_matches_sha256( $database_path, $original_hash );
			if ( $restored ) {
				@unlink( $rollback_path );
			}
		}

		if ( $restored ) {
			@chmod( $database_path, 0600 );
		}

		return false;
	}

	/**
	 * Compare a file with a previously computed SHA-256 digest.
	 *
	 * @param string $path Absolute file path.
	 * @param string $expected_hash Expected hexadecimal SHA-256 digest.
	 * @return bool Whether the file matches the digest.
	 */
	private static function file_matches_sha256( $path, $expected_hash ) {
		$actual_hash = @hash_file( 'sha256', $path );

		return is_string( $actual_hash ) && hash_equals( $expected_hash, $actual_hash );
	}

	/**
	 * Write contents to a file
	 *
	 * @param  resource $handle  File handle to write to
	 * @param  string   $content Contents to write to the file
	 * @return integer
	 * @throws \Exception
	 */
	public static function write( $handle, $content ) {
		$write_result = @fwrite( $handle, $content );
		if ( false === $write_result ) {
			if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
				throw new \Exception(
					/* translators: %s: Backup file path or underlying request failure. */
					/* translators: %s: Backup file path or underlying request failure. */
					sprintf( __( 'Could not write to: %s. The process cannot continue.', 'simply-static-studio-backup-migrate' ), $meta['uri'] )
				);
			}
		} elseif ( null === $write_result ) {
			return strlen( $content );
		} elseif ( strlen( $content ) !== $write_result ) {
			if ( ( $meta = stream_get_meta_data( $handle ) ) ) {
				throw new \Exception(
					/* translators: %s: Backup file path or underlying request failure. */
					sprintf( __( 'Out of disk space. Could not write to: %s. The process cannot continue.', 'simply-static-studio-backup-migrate' ), $meta['uri'] )
				);
			}
		}

		return $write_result;
	}
}
