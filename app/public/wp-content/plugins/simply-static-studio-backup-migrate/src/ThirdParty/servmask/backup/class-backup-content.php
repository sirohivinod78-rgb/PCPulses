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

class Backup_Content {

	public static function execute( $params = [], $task_id = 'content' ) {

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = \Simply_Static\Backup\Helper::getArchiveBytes( $params );
		}

		// Set file bytes offset
		if ( isset( $params['file_bytes_offset'] ) ) {
			$file_bytes_offset = (int) $params['file_bytes_offset'];
		} else {
			$file_bytes_offset = 0;
		}

		// Set content bytes offset
		if ( isset( $params['content_bytes_offset'] ) ) {
			$content_bytes_offset = (int) $params['content_bytes_offset'];
		} else {
			$content_bytes_offset = 0;
		}

		// Get processed files size
		if ( isset( $params['content_processed_files_size'] ) ) {
			$processed_files_size = (int) $params['content_processed_files_size'];
		} else {
			$processed_files_size = 0;
		}

		// Get total content files size
		if ( isset( $params['total_content_files_size'] ) ) {
			$total_content_files_size = max( 1, (int) $params['total_content_files_size'] );
		} else {
			$total_content_files_size = 1;
		}

		// Get total content files count
		if ( isset( $params['total_content_files_count'] ) ) {
			$total_content_files_count = (int) $params['total_content_files_count'];
		} else {
			$total_content_files_count = 0;
		}

  // Set batch size for processing from settings (default 250 files per request)
		$settings   = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$batch_size = Runtime_Limits::bounded_int( isset( $settings['batch_size'] ) ? $settings['batch_size'] : 250, 250, 1, 1000 );
		$batch_size = Runtime_Limits::bounded_int( apply_filters( 'sssbm_content_batch_size', $batch_size ), $batch_size, 1, 1000 );
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

		// Counter for files processed in current batch
		$current_batch_count = 0;

		// What percent of files have we processed?
		$progress = (int) min( ( $processed_files_size / $total_content_files_size ) * 100, 100 );
		Logger::log( 'Processed File size: ' . $processed_files_size . '. Total size: ' . $total_content_files_size . '. Progress: ' . $progress . '%.' );

		// Set progress
		/* translators: 1: Number of files, 2: Progress. */
		Status::info( sprintf( __( 'Backing up %1$d content files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_content_files_count, $progress ), 'ssse-content' );

		// Flag to hold if all files have been processed (end of CSV reached)
		$completed = true;

		// Start time
		$start = microtime( true );

		// Track files that could not be added to the archive
		$skipped_files = 0;
		$rebased_files = 0;

		// Track entries before this batch for verification
		$entries_before      = 0;
		$entries_added       = 0;
		$entries_reused      = 0;
		$preexisting_entries = array();

		// Get content list file
		$content_list = @fopen( Backup::get_content_list_path(), 'r' );
		if ( ! is_resource( $content_list ) ) {
			throw new \RuntimeException( __( 'Could not open the content file list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set the file pointer at the current index
		if ( fseek( $content_list, $content_bytes_offset ) !== - 1 ) {

			// Acquire ZIP file lock to prevent concurrent access
			$zip_lock = Helper::waitForZipLock( 30 );
			if ( ! $zip_lock ) {
				Logger::log( 'Failed to acquire ZIP lock for content export' );
				@fclose( $content_list );
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			// Open the archive file for writing using wrapper (supports ZipArchive and PclZip fallback)
			$archive     = new Zip_Archive_Wrapper();
			$backup_path = Backup::get_backup_path();

			// Open the archive (creates if not exists)
			if ( ! $archive->open( $backup_path ) ) {
				Logger::log( 'Failed to open ZIP archive for content export: ' . $archive->getError() );
				Helper::releaseZipLock( $zip_lock );
				@fclose( $content_list );
				$params['completed'] = false;

				return $params;
			}

			$entries_before = $archive->getEntryCount();

			// Loop over files
			while ( false !== ( $content_row = Helper::getcsv( $content_list ) ) ) {
				list( $file_abspath, $file_relpath, $file_size, $file_mtime ) = $content_row;
				$listed_abspath = $file_abspath;
				$file_abspath   = Helper::resolve_listed_path_within( $listed_abspath, $file_relpath, Helper::get_content_path() );
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

				// Revalidate paths read from the resumable CSV. This blocks a
				// tampered list or a swapped symlink from escaping wp-content.
				if ( false === $file_abspath ) {
					Logger::log( 'Warning: Content file is outside wp-content, skipping.' );
					$skipped_files++;
				} else {
					$zip_path = self::get_formatted_path( $file_relpath );
					if ( ! array_key_exists( $zip_path, $preexisting_entries ) ) {
						$preexisting_entries[ $zip_path ] = $archive->hasEntry( $zip_path );
					}
					if ( $preexisting_entries[ $zip_path ] ) {
						$entries_reused++;
					} elseif ( ! file_exists( $file_abspath ) ) {
						Logger::log( 'Warning: Content file does not exist, skipping: ' . $file_abspath );
						$skipped_files++;
					} elseif ( $archive->addFile( $file_abspath, $zip_path ) ) {
						$entries_added++;
					} else {
						Logger::log( 'Warning: Failed to add content file to archive: ' . $file_abspath );
						$skipped_files++;
					}
				}

				// Always advance the CSV offset so we never get stuck
				// retrying a permanently failed file.
				$content_bytes_offset = ftell( $content_list );

				// Increment processed files size and count
				$processed_files_size += $file_size;
				$processed_files_count ++;
				$current_batch_count ++;

				// What percent of files have we processed?
				$progress = (int) min( ( $processed_files_size / $total_content_files_size ) * 100, 100 );
				if ( 0 === $current_batch_count % 25 ) {
					Logger::log( 'Processed File size: ' . $processed_files_size . '. Total size: ' . $total_content_files_size . '. Progress: ' . $progress . '%.' );
					/* translators: 1: Number of files, 2: Progress. */
					Status::info( sprintf( __( 'Backing up %1$d content files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_content_files_count, $progress ), 'ssse-content' );
				}

				// More than 10 seconds have passed, break and do another request
				if ( $timeout > 0 && ( microtime( true ) - $start ) > $timeout ) {
					$completed = false;
					break;
				}

			}

 		// Flush deferred writes to disk before saving progress.
			// ZipArchive::addFile() defers writes until close/flush, so
			// we must flush here to ensure the archive on disk matches
			// the progress we are about to persist to the database.
			if ( ! $archive->flush() ) {
				$zip_error     = $archive->getError();
				$storage_error = $archive->is_storage_exhausted() ? $archive->get_storage_exhausted_error() : '';
				Logger::log( 'Archive flush failed for content batch. ZIP error: ' . $zip_error );
				$archive->close();
				Helper::releaseZipLock( $zip_lock );
				@fclose( $content_list );
				if ( '' !== $storage_error ) {
					throw new \RuntimeException( $storage_error );
				}
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			// Persist progress to DB before closing the archive.
			// If archive->close() or the PHP process is killed after this point,
			// the next run will resume from where we left off instead of starting over.
			if ( $current_batch_count > 0 ) {
				$progress_params = [
					'archive_bytes_offset'          => $archive_bytes_offset,
					'file_bytes_offset'             => $file_bytes_offset,
					'content_bytes_offset'          => $content_bytes_offset,
					'content_processed_files_size'  => $processed_files_size,
					'processed_files_count'         => $processed_files_count,
					'total_content_files_size'      => $total_content_files_size,
					'total_content_files_count'     => $total_content_files_count,
					'completed'                     => $completed,
				];
				Backup::save_export_param( $task_id, $progress_params );
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
						__( 'Content archive verification found an entry-count mismatch: expected %1$d new entries, but found %2$d. Review the activity log before restoring this backup.', 'simply-static-studio-backup-migrate' ),
						$expected_new,
						$actual_new
					);
					Logger::log( sprintf(
						'Warning: Archive integrity check — expected %d new entries but %d were written (before: %d, after: %d)',
						$expected_new, $actual_new, $entries_before, $entries_after
					) );
					Backup::add_backup_integrity_issue( 'content_archive_entry_count_mismatch', $message, false );
					Status::warning( $message, 'sssbm-content-entry-count-mismatch' );
				}
			}

			if ( $entries_reused > 0 ) {
				Logger::log( sprintf( 'Recognized %d pre-existing content archive entry/entries from a retried batch.', $entries_reused ) );
			}

			if ( $skipped_files > 0 ) {
				$message = sprintf(
					/* translators: %d: Number of skipped files in the latest batch. */
					__( 'Some content files could not be included in the backup (%d in the latest batch). Review the activity log before restoring this backup.', 'simply-static-studio-backup-migrate' ),
					$skipped_files
				);
				Logger::log( sprintf( 'Warning: %d content file(s) were skipped in this batch.', $skipped_files ) );
				Backup::add_backup_integrity_issue( 'content_files_skipped', $message, false );
				Status::warning( $message, 'sssbm-content-files-skipped' );
			}

			if ( $rebased_files > 0 ) {
				Logger::log( sprintf( 'Rebased %d content file path(s) against the current wp-content directory.', $rebased_files ) );
			}

			// Release ZIP file lock
			Helper::releaseZipLock( $zip_lock );
		} else {
			@fclose( $content_list );
			throw new \RuntimeException( __( 'Could not resume from the content file list offset.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set archive bytes offset
		$params['archive_bytes_offset'] = $archive_bytes_offset;

		// Set file bytes offset
		$params['file_bytes_offset'] = $file_bytes_offset;

		// Set content bytes offset
		$params['content_bytes_offset'] = $content_bytes_offset;

		// Set processed files size
		$params['content_processed_files_size'] = $processed_files_size;

		// Set processed files count
		$params['processed_files_count'] = $processed_files_count;

		// Set total content files size
		$params['total_content_files_size'] = $total_content_files_size;

		// Set total content files count
		$params['total_content_files_count'] = $total_content_files_count;

		$params['completed'] = $completed;

		if ( $completed ) {
			Status::success( __( 'Backed up all content files...<br />100% complete', 'simply-static-studio-backup-migrate' ), 'ssse-content' );
		}

		// Close the content list file
		@fclose( $content_list );

		return $params;
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

	public static function get_formatted_path( $path ) {

		$path_array = explode( DIRECTORY_SEPARATOR, $path );

		if ( ! in_array( 'wp-content', $path_array, true ) ) {
			$path_array = array_merge( [ 'wp-content' ], $path_array );
		}

		return implode( '/', $path_array );
	}

	/**
	 * Format bytes into a human-readable string.
	 *
	 * @param int $bytes Number of bytes.
	 *
	 * @return string Formatted string (e.g. "128.00 MB").
	 */
	private static function format_bytes( $bytes ) {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		}

		return $bytes . ' bytes';
	}
}
