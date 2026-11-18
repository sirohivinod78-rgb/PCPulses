<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\filter\Recursive_Exclude_Filter;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Directory_Iterator;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Iterator_Iterator;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Enumerate_Content {

	/**
	 * These paths are supplied by Static Studio as immutable bind mounts. They
	 * are deployment artifacts, not customer content, and must never be copied
	 * into a portable backup.
	 */
	const MANAGED_MU_PLUGIN_ENTRIES = array(
		'00-static-studio-simply-static.php',
		'01-static-studio-backup-migrate.php',
		'load.php',
		'simply-static-studio-backup-migrate',
		'simply-static-studio-helper',
	);

	/**
	 * Get default content filters
	 *
	 * @param array $filters List of files and directories
	 *
	 * @return array
	 */
	public static function content_filters( $filters = array() ) {
		$dropins = array(
			'advanced-cache.php',
			'db.php',
			'db-error.php',
			'install.php',
			'maintenance.php',
			'object-cache.php',
			'php-error.php',
			'fatal-error-handler.php',
			'sunrise.php',
			'blog-deleted.php',
			'blog-inactive.php',
			'blog-suspended.php',
		);
		$dropin_filters = array_map(
			static function ( $dropin ) {
				return untrailingslashit( Helper::get_content_path() ) . DIRECTORY_SEPARATOR . $dropin;
			},
			$dropins
		);

		return array_merge(
			$filters,
			array(
				untrailingslashit( Backup::get_storage_path() ),
				Backup::get_backup_path(),
				'config.json',
				'database.sql',
				'db.sql',

				// These are historical backup locations, not assumptions about the
				// active uploads root. Keep stale archives and working files out of
				// content backups after an uploads relocation or media-offload change.
				Helper::get_content_path() . '/uploads/simply-static',
				Helper::get_content_path() . '/uploads/simply-static-downloads',
				Helper::get_content_path() . '/.simply-static/backup-migrate',

				// Host/runtime artifacts are neither portable site content nor stable
				// ZIP sources. WP Engine can manage and rotate these paths while an
				// admin-ajax backup request is still reading wp-content.
				untrailingslashit( Helper::get_content_path() ) . DIRECTORY_SEPARATOR . '.logs',
				untrailingslashit( Helper::get_content_path() ) . DIRECTORY_SEPARATOR . '_wpeprivate',
				untrailingslashit( Helper::get_content_path() ) . DIRECTORY_SEPARATOR . 'upgrade',

				// Exclude third-party backup plugin directories to avoid
				// backing up potentially multi-GB archive files from other
				// plugins, which bloat the ZIP and slow the entire migration.
				'ai1wm-backups',       // All-in-One WP Migration
				'updraft',             // UpdraftPlus
				'backups-dup-pro',     // Duplicator Pro
				'backups-dup-lite',    // Duplicator Lite
				'backupbuddy_backups', // BackupBuddy
				'backup-guard',        // BackupGuard
			),
			// WordPress drop-ins are provider/cache/database integration code.
			// Their owning plugins are deliberately excluded from portable
			// archives, so carrying the drop-ins alone can fatal or redirect the
			// destination before WordPress is able to repair itself.
			$dropin_filters
		);
	}

	public static function execute( $params = [] ) {

		$plugins_dir    = untrailingslashit( Helper::get_plugins_path() );
		$mu_plugins_dir = Helper::get_mu_plugins_path();

		$theme_dirs = array();
		$theme_directories = search_theme_directories();
		foreach ( is_array( $theme_directories ) ? $theme_directories : array() as $theme_name => $theme_info ) {
			if ( isset( $theme_info['theme_root'] ) ) {
				if ( ! in_array( $theme_info['theme_root'], $theme_dirs, true ) ) {
					$theme_dirs[] = untrailingslashit( $theme_info['theme_root'] );
				}
			}
		}

		$exclude_filters = array_merge(
			array(
				// Older multisite installations may retain legacy media after
				// switching their active uploads location.
				untrailingslashit( Helper::get_content_path() ) . DIRECTORY_SEPARATOR . 'blogs.dir',
				$plugins_dir,
			),
			Helper::get_uploads_paths(),
			$theme_dirs,
			self::managed_mu_plugin_filters( $mu_plugins_dir ),
			self::maintenance_quarantine_filters()
		);

		// Customer MU plugins are portable site content. Honour the explicit
		// opt-out, but otherwise archive them while the five platform-managed
		// mount paths above remain mandatory exclusions.
		if ( ! empty( $params['options']['no_muplugins'] ) ) {
			$exclude_filters[] = $mu_plugins_dir;
		}

		// Get total content files count
		if ( isset( $params['total_content_files_count'] ) ) {
			$total_content_files_count = (int) $params['total_content_files_count'];
		} else {
			$total_content_files_count = 0;
		}

		// Get total content files size
		if ( isset( $params['total_content_files_size'] ) ) {
			$total_content_files_size = (int) $params['total_content_files_size'];
		} else {
			$total_content_files_size = 0;
		}

		// Set progress
		Status::info( __( 'Gathering content files...', 'simply-static-studio-backup-migrate' ), 'enumerate_content' );

		// Exclude cache
		$exclude_filters[] = 'cache';

		// Exclude selected files
		if ( isset( $params['options']['exclude_files'], $params['excluded_files'] ) ) {
			if ( ( $excluded_files = explode( ',', $params['excluded_files'] ) ) ) {
				foreach ( $excluded_files as $excluded_path ) {
					$exclude_filters[] = Helper::get_content_path() . DIRECTORY_SEPARATOR . untrailingslashit( $excluded_path );
				}
			}
		}

		// Create content list file
		$content_list = @fopen( Backup::get_content_list_path(), "w" );
		if ( ! is_resource( $content_list ) ) {
			throw new \RuntimeException( __( 'Could not create the content file list.', 'simply-static-studio-backup-migrate' ) );
		}

		$excluded_runtime_artifacts = 0;

		// Enumerate over content directory
		if ( isset( $params['options']['no_themes'], $params['options']['no_muplugins'], $params['options']['no_plugins'] ) === false ) {

			// Iterate over content directory
			$iterator = new Recursive_Directory_Iterator( Helper::get_content_path() );

			// Filters may add exclusions, but cannot erase the upload/plugin/theme,
			// credential, cache, or active-archive safety boundaries above.
			$mandatory_filters = self::content_filters( $exclude_filters );
			$filtered_filters  = apply_filters( 'sssbm_exclude_content_from_export', $mandatory_filters );
			$effective_filters = Helper::merge_mandatory_exclusions( $filtered_filters, $mandatory_filters );
			$iterator          = new Recursive_Exclude_Filter( $iterator, $effective_filters );

			// Recursively iterate over content directory
			$iterator = new Recursive_Iterator_Iterator( $iterator, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );

			// Write path line
			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					if ( self::is_runtime_artifact( $iterator->getSubPathname() ) ) {
						$excluded_runtime_artifacts++;
						continue;
					}

					if ( Helper::putcsv( $content_list, array(
						$iterator->getPathname(),
						$iterator->getSubPathname(),
						$iterator->getSize(),
						$iterator->getMTime()
					) ) ) {
						$total_content_files_count ++;

						// Add current file size
						$total_content_files_size += $iterator->getSize();
					}
				}
			}
		}

		if ( $excluded_runtime_artifacts > 0 ) {
			Logger::log( sprintf( 'Excluded %d non-portable runtime log or private host artifact(s) from the content backup.', $excluded_runtime_artifacts ) );
		}

		// Set progress
		Status::success( __( 'Content files gathered.', 'simply-static-studio-backup-migrate' ), 'enumerate_content' );

		// Set total content files count
		$params['total_content_files_count'] = $total_content_files_count;

		// Set total content files size
		$params['total_content_files_size'] = $total_content_files_size;

		$params['completed'] = true;

		// Close the content list file
		@fclose( $content_list );

		return $params;
	}

	/**
	 * Identify volatile host artifacts that must not be copied into a migration.
	 *
	 * Runtime logs can be large and can be rotated between ZipArchive::addFile()
	 * and its deferred read at close(). Host-private and upgrade directories are
	 * likewise deployment state, not customer content. Match path components so
	 * the rule remains effective when a host nests one of these directories.
	 *
	 * @param mixed $relative_path Path relative to wp-content.
	 * @return bool
	 */
	public static function is_runtime_artifact( $relative_path ) {
		if ( ! is_string( $relative_path ) || '' === $relative_path || false !== strpos( $relative_path, "\0" ) ) {
			return false;
		}

		$normalized = trim( str_replace( '\\', '/', $relative_path ), '/' );
		$segments   = explode( '/', strtolower( $normalized ) );
		$basename   = end( $segments );

		if ( in_array( '.logs', $segments, true ) || in_array( '_wpeprivate', $segments, true ) ) {
			return true;
		}

		if ( isset( $segments[0] ) && 'upgrade' === $segments[0] ) {
			return true;
		}

		return 'log.txt' === $basename || 'log' === strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Return the exact platform-managed MU paths that must not enter an archive.
	 *
	 * @param string $mu_plugins_dir MU plugin root.
	 * @return array<int,string>
	 */
	public static function managed_mu_plugin_filters( $mu_plugins_dir ) {
		$mu_plugins_dir = untrailingslashit( (string) $mu_plugins_dir );
		if ( '' === $mu_plugins_dir ) {
			return array();
		}

		return array_map(
			static function ( $entry ) use ( $mu_plugins_dir ) {
				return $mu_plugins_dir . DIRECTORY_SEPARATOR . $entry;
			},
			self::MANAGED_MU_PLUGIN_ENTRIES
		);
	}

	/**
	 * Exclude restore-only drop-in quarantine directories if an interrupted
	 * maintenance operation left one behind. Only canonical request-bound names
	 * are recognized, so similarly named customer content remains portable.
	 *
	 * @return array<int,string>
	 */
	public static function maintenance_quarantine_filters() {
		$content_dir = untrailingslashit( Helper::get_content_path() );
		$entries     = @scandir( $content_dir );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$filters = array();
		foreach ( $entries as $entry ) {
			if (
				is_string( $entry )
				&& preg_match(
					'/^\.static-studio-maintenance-dropins-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
					$entry
				)
			) {
				$filters[] = $content_dir . DIRECTORY_SEPARATOR . $entry;
			}
		}

		return $filters;
	}
}
