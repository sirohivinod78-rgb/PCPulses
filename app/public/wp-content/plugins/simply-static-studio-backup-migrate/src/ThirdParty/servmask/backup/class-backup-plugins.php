<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Plugin;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Runtime_Limits;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\Zip_Archive_Wrapper;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Plugins {

	protected $plugins_info = null;

	/**
	 * Calculate sum of third column values from CSV file
	 *
	 * @return array Total sum of third column values and total count.
	 */
	public function getPluginsInfo() {

		if ( null !== $this->plugins_info ) {
			return $this->plugins_info;
		}

		$sum       = 0;
		$count     = 0;
		$file_path = Backup::get_plugins_list_path();

		if ( ( $handle = @fopen( $file_path, "r" ) ) !== false ) {
			while ( ( $data = Helper::getcsv( $handle ) ) !== false ) {
				if ( isset( $data[2] ) ) {
					$sum += floatval( $data[2] );
					$count ++;
				}
			}
			fclose( $handle );
		}

		$this->plugins_info = [ $sum, $count ];


		return [ $sum, $count ];
	}


	public function execute( $params = [], $task_id = 'plugins' ) {

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = Helper::getArchiveBytes();
		}

		// Set file bytes offset
		if ( isset( $params['file_bytes_offset'] ) ) {
			$file_bytes_offset = (int) $params['file_bytes_offset'];
		} else {
			$file_bytes_offset = 0;
		}

		// Set plugins bytes offset
		if ( isset( $params['plugins_bytes_offset'] ) ) {
			$plugins_bytes_offset = (int) $params['plugins_bytes_offset'];
		} else {
			$plugins_bytes_offset = 0;
		}

		// Get processed files size
		if ( isset( $params['processed_files_size'] ) ) {
			$processed_files_size = (int) $params['processed_files_size'];
		} else {
			$processed_files_size = 0;
		}

		// Get total plugins files size
		if ( isset( $params['total_plugins_files_size'] ) ) {
			$total_plugins_files_size = max( 1, (int) $params['total_plugins_files_size'] );
		} else {
			$plugins_info             = $this->getPluginsInfo();
			$total_plugins_files_size = max( 1, (int) $plugins_info[0] );
		}

		// Get total plugins files count
		if ( isset( $params['total_plugins_files_count'] ) ) {
			$total_plugins_files_count = (int) $params['total_plugins_files_count'];
		} else {
			$plugins_info              = $this->getPluginsInfo();
			$total_plugins_files_count = $plugins_info[1];
		}

  // Set batch size for processing from settings (default 250 files per request)
		$settings   = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$batch_size = Runtime_Limits::bounded_int( isset( $settings['batch_size'] ) ? $settings['batch_size'] : 250, 250, 1, 1000 );
		$batch_size = Runtime_Limits::bounded_int( apply_filters( 'sssbm_plugins_batch_size', $batch_size ), $batch_size, 1, 1000 );
		$timeout    = Runtime_Limits::completed_timeout();

		// Get processed files count in current session
		if ( isset( $params['processed_files_count'] ) ) {
			$processed_files_count = (int) $params['processed_files_count'];
		} else {
			$processed_files_count = 0;
		}

		// Get memory limit and calculate thresholds
		$memory_limit     = self::get_memory_limit();
		$memory_threshold = $memory_limit * 0.9; // 90% threshold

		// What percent of files have we processed?
		$progress = (int) min( ( $processed_files_size / $total_plugins_files_size ) * 100, 100 );

		// Set progress
		/* translators: 1: Number of files, 2: Progress. */
		Status::info( sprintf( __( 'Backing up %1$d plugin files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_plugins_files_count, $progress ), 'ssse-plugins' );
		/* translators: 1: Number of files, 2: Progress. */
		Logger::log( sprintf( __( 'Backing up %1$d plugin files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_plugins_files_count, $progress ) );
		// Flag to hold if all files have been processed (end of CSV reached)
		$completed = true;

		// Counter for files processed in current batch
		$current_batch_count = 0;

		// Track files that could not be added to the archive
		$skipped_files = 0;
		$rebased_files = 0;

		// Track entries before this batch for verification
		$entries_before      = 0;
		$entries_added       = 0;
		$entries_reused      = 0;
		$preexisting_entries = array();
		$start               = microtime( true );

		// Get plugins list file
		$plugins_list = @fopen( Backup::get_plugins_list_path(), 'r' );
		if ( ! is_resource( $plugins_list ) ) {
			throw new \RuntimeException( __( 'Could not open the plugin file list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set the file pointer at the current index
		if ( fseek( $plugins_list, $plugins_bytes_offset ) !== - 1 ) {

			// Acquire ZIP file lock to prevent concurrent access
			$zip_lock = Helper::waitForZipLock( 30 );
			if ( ! $zip_lock ) {
				Logger::log( 'Failed to acquire ZIP lock for plugins export' );
				@fclose( $plugins_list );
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			// Open the archive file for writing using wrapper (supports ZipArchive and PclZip fallback)
			$archive     = new Zip_Archive_Wrapper();
			$backup_path = Backup::get_backup_path();
			if ( 'PclZip' === $archive->get_library_name() ) {
				$pclzip_batch_size = Runtime_Limits::bounded_int(
					apply_filters( 'sssbm_pclzip_plugins_batch_size', 50 ),
					50,
					1,
					50
				);
				$batch_size = min( $batch_size, $pclzip_batch_size );
				Logger::log( 'PclZip plugin batch size capped at ' . $batch_size . ' files.' );
			}

			// Open the archive (creates if not exists)
			if ( ! $archive->open( $backup_path ) ) {
				Logger::log( 'Failed to open ZIP archive for plugins export: ' . $archive->getError() );
				Helper::releaseZipLock( $zip_lock );
				@fclose( $plugins_list );
				$params['completed'] = false;

				return $params;
			}

			$entries_before = $archive->getEntryCount();
			$flush_count     = $archive->getFlushCount();

			// Loop over files with batch processing
			while ( false !== ( $plugins_row = Helper::getcsv( $plugins_list ) ) ) {
				list( $file_abspath, $file_relpath, $file_size, $file_mtime ) = $plugins_row;
				$listed_abspath = $file_abspath;
				$file_abspath   = Helper::resolve_listed_path_within( $listed_abspath, $file_relpath, Helper::get_plugins_path() );
				if (
					false !== $file_abspath &&
					( ! is_string( $listed_abspath ) || wp_normalize_path( $file_abspath ) !== wp_normalize_path( $listed_abspath ) )
				) {
					$rebased_files++;
				}

				// Check if we've reached the batch limit
				if ( $current_batch_count >= $batch_size ) {
					Logger::log( 'Batch limit reached. Processed ' . $current_batch_count . ' files in this batch.' );
					$completed = false;
					break;
				}

				// Check memory usage before processing each file (safety check)
				$current_memory = memory_get_usage( true );
				if ( $current_memory >= $memory_threshold ) {
					Logger::log( 'Memory threshold reached. Current: ' . self::format_bytes( $current_memory ) . ', Threshold: ' . self::format_bytes( $memory_threshold ) );
					$completed = false;
					break;
				}

				// Revalidate resumable CSV paths to prevent symlink/list tampering.
				if ( false === $file_abspath ) {
					Logger::log( 'Warning: Plugin file is outside the plugin directory, skipping.' );
					$skipped_files++;
				} else {
					$zip_path = self::get_formatted_path( 'plugins' . DIRECTORY_SEPARATOR . $file_relpath );
					if ( ! array_key_exists( $zip_path, $preexisting_entries ) ) {
						$preexisting_entries[ $zip_path ] = $archive->hasEntry( $zip_path );
					}
					if ( $preexisting_entries[ $zip_path ] ) {
						$entries_reused++;
					} elseif ( ! file_exists( $file_abspath ) ) {
						Logger::log( 'Warning: Plugin file does not exist, skipping: ' . $file_abspath );
						$skipped_files++;
					} elseif ( $archive->addFile( $file_abspath, $zip_path ) ) {
						$entries_added++;
					} else {
						Logger::log( 'Warning: Failed to add plugin file to archive: ' . $file_abspath );
						$skipped_files++;
					}
				}

				// Always advance the CSV offset so we never get stuck
				// retrying a permanently failed file.
				$plugins_bytes_offset = ftell( $plugins_list );

				// Increment processed files size and count
				$processed_files_size += $file_size;
				$processed_files_count ++;
				$current_batch_count ++;

				// PclZip commits buffered files during addFile(). Checkpoint the
				// matching CSV offset immediately so a later expensive rewrite
				// cannot roll the visible task progress back to an older batch.
				$current_flush_count = $archive->getFlushCount();
				if ( $current_flush_count > $flush_count ) {
					Backup::save_export_param( $task_id, self::get_progress_params(
						$archive_bytes_offset,
						$file_bytes_offset,
						$plugins_bytes_offset,
						$processed_files_size,
						$processed_files_count,
						$total_plugins_files_size,
						$total_plugins_files_count,
						false
					) );
					update_option( 'sssbm_last_progress_at', time(), false );
					$flush_count = $current_flush_count;
				}

				// What percent of files have we processed?
				$progress = (int) min( ( $processed_files_size / $total_plugins_files_size ) * 100, 100 );
				// Avoid a database write and log write for every file on large sites.
				if ( 0 === $current_batch_count % 25 ) {
					Logger::log( 'Processed File size: ' . $processed_files_size . '. Total size: ' . $total_plugins_files_size . '. Progress: ' . $progress . '%.' );
					/* translators: 1: Number of files, 2: Progress. */
					Status::info( sprintf( __( 'Backing up %1$d plugin files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_plugins_files_count, $progress ), 'ssse-plugins' );
				}

				if ( $timeout > 0 && ( microtime( true ) - $start ) > $timeout ) {
					$completed = false;
					break;
				}
			}

		// Flush deferred writes to disk before saving progress.
			if ( ! $archive->flush() ) {
				$zip_error     = $archive->getError();
				$storage_error = $archive->is_storage_exhausted() ? $archive->get_storage_exhausted_error() : '';
				Logger::log( 'Archive flush failed for plugins batch. ZIP error: ' . $zip_error );
				$archive->close();
				Helper::releaseZipLock( $zip_lock );
				@fclose( $plugins_list );
				if ( '' !== $storage_error ) {
					throw new \RuntimeException( $storage_error );
				}
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			// Persist progress to DB before closing the archive.
			if ( $current_batch_count > 0 ) {
				$progress_params = self::get_progress_params(
					$archive_bytes_offset,
					$file_bytes_offset,
					$plugins_bytes_offset,
					$processed_files_size,
					$processed_files_count,
					$total_plugins_files_size,
					$total_plugins_files_count,
					$completed
				);
				Backup::save_export_param( $task_id, $progress_params );
				update_option( 'sssbm_last_progress_at', time(), false );
			}

			// Verify archive integrity after closing
			$entries_after = $archive->getEntryCount();

			// Close the archive file
			$archive->close();

			if ( $entries_before >= 0 && $entries_after >= 0 ) {
				$expected_new = $entries_added;
				$actual_new   = $entries_after - $entries_before;
				if ( $actual_new !== $expected_new ) {
					$message = sprintf(
						/* translators: 1: Expected entries, 2: Actual entries. */
						__( 'Plugin archive verification failed: expected %1$d new entries, but found %2$d. Push to Studio has been disabled for this backup.', 'simply-static-studio-backup-migrate' ),
						$expected_new,
						$actual_new
					);
					Logger::log( sprintf(
						'Warning: Archive integrity check — expected %d new entries but %d were written (before: %d, after: %d)',
						$expected_new, $actual_new, $entries_before, $entries_after
					) );
					Backup::add_backup_integrity_issue( 'plugin_archive_entry_count_mismatch', $message, true );
					Status::error( $message, 'sssbm-plugins-entry-count-mismatch' );
				}
			}

			if ( $entries_reused > 0 ) {
				Logger::log( sprintf( 'Recognized %d pre-existing plugin archive entry/entries from a retried batch.', $entries_reused ) );
			}

			if ( $skipped_files > 0 ) {
				$message = sprintf(
					/* translators: %d: Number of skipped files in the latest batch. */
					__( 'Some plugin files could not be included in the backup (%d in the latest batch). Push to Studio has been disabled for this backup.', 'simply-static-studio-backup-migrate' ),
					$skipped_files
				);
				Logger::log( sprintf( 'Warning: %d plugin file(s) were skipped in this batch.', $skipped_files ) );
				Backup::add_backup_integrity_issue( 'plugin_files_skipped', $message, true );
				Status::error( $message, 'sssbm-plugins-files-skipped' );
			}

			if ( $rebased_files > 0 ) {
				Logger::log( sprintf( 'Rebased %d plugin file path(s) against the current plugin directory.', $rebased_files ) );
			}

			// Release ZIP file lock
			Helper::releaseZipLock( $zip_lock );
		} else {
			@fclose( $plugins_list );
			throw new \RuntimeException( __( 'Could not resume from the plugin file list offset.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set archive bytes offset
		$params['archive_bytes_offset'] = $archive_bytes_offset;

		// Set file bytes offset
		$params['file_bytes_offset'] = $file_bytes_offset;

		// Set plugins bytes offset
		$params['plugins_bytes_offset'] = $plugins_bytes_offset;

		// Set processed files size
		$params['processed_files_size'] = $processed_files_size;

		// Set processed files count
		$params['processed_files_count'] = $processed_files_count;

		// Set total plugins files size
		$params['total_plugins_files_size'] = $total_plugins_files_size;

		// Set total plugins files count
		$params['total_plugins_files_count'] = $total_plugins_files_count;

		// Set completed flag
		$params['completed'] = $completed;

		if ( $completed ) {
			Status::success( __( 'Backed up all plugin files...<br />100% complete', 'simply-static-studio-backup-migrate' ), 'ssse-plugins' );
		}

		// Get file stats before closing
		$file_stats = fstat( $plugins_list );
		if ( $file_stats ) {
			Logger::log( 'Final plugins list buffer size: ' . self::format_bytes( $file_stats['size'] ) . ' (' . $file_stats['size'] . ')' );
		}

		// Close the plugins list file 
		@fclose( $plugins_list );

		return $params;
	}

	/**
	 * Build a complete, resumable plugins checkpoint.
	 *
	 * @return array
	 */
	private static function get_progress_params(
		$archive_bytes_offset,
		$file_bytes_offset,
		$plugins_bytes_offset,
		$processed_files_size,
		$processed_files_count,
		$total_plugins_files_size,
		$total_plugins_files_count,
		$completed
	) {
		return [
			'archive_bytes_offset'      => (int) $archive_bytes_offset,
			'file_bytes_offset'         => (int) $file_bytes_offset,
			'plugins_bytes_offset'      => (int) $plugins_bytes_offset,
			'processed_files_size'      => (int) $processed_files_size,
			'processed_files_count'     => (int) $processed_files_count,
			'total_plugins_files_size'  => (int) $total_plugins_files_size,
			'total_plugins_files_count' => (int) $total_plugins_files_count,
			'completed'                 => (bool) $completed,
		];
	}

	/**
	 * Get the memory limit in bytes
	 *
	 * @return int Memory limit in bytes
	 */
	private static function get_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );

		if ( false === $memory_limit || '' === trim( $memory_limit ) || '-1' === trim( $memory_limit ) ) {
			// No memory limit
			return PHP_INT_MAX;
		}

		// Convert memory limit to bytes
		$memory_limit = trim( $memory_limit );
		$last_char    = strtolower( $memory_limit[ strlen( $memory_limit ) - 1 ] );
		$memory_limit = (int) $memory_limit;

		switch ( $last_char ) {
			case 'g':
				$memory_limit *= 1024;
			case 'm':
				$memory_limit *= 1024;
			case 'k':
				$memory_limit *= 1024;
		}

		return $memory_limit > 0 ? $memory_limit : PHP_INT_MAX;
	}

	/**
	 * Format bytes into human readable format
	 *
	 * @param int $bytes Number of bytes
	 *
	 * @return string Formatted string
	 */
	private static function format_bytes( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i ++ ) {
			$bytes /= 1024;
		}

		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	public static function get_formatted_path( $path ) {

		$path_array = explode( DIRECTORY_SEPARATOR, $path );

		if ( ! in_array( 'plugins', $path_array, true ) ) {
			$path_array = array_merge( [ 'plugins' ], $path_array );
		}

		if ( ! in_array( 'wp-content', $path_array, true ) ) {
			$path_array = array_merge( [ 'wp-content' ], $path_array );
		}

		return implode( '/', $path_array );
	}
}
