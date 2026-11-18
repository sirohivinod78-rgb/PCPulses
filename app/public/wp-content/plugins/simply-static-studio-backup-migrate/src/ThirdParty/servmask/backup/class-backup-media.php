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

class Backup_Media {

	public static function execute( $params = [], $task_id = 'media' ) {

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

		// Set media bytes offset (prefer persisted option to avoid getting stuck)
		$persisted_media_offset = (int) get_option( 'sssbm_media_bytes_offset', 0 );
		if ( isset( $params['media_bytes_offset'] ) ) {
			$media_bytes_offset = max( (int) $params['media_bytes_offset'], $persisted_media_offset );
		} else {
			$media_bytes_offset = $persisted_media_offset;
		}

		// Get processed files size
		if ( isset( $params['processed_files_size'] ) ) {
			$processed_files_size = (int) $params['processed_files_size'];
		} else {
			$processed_files_size = 0;
		}

		// Get total media files size
		if ( isset( $params['total_media_files_size'] ) ) {
			$total_media_files_size = max( 1, (int) $params['total_media_files_size'] );
		} else {
			$total_media_files_size = 1;
		}

		// Get total media files count
		if ( isset( $params['total_media_files_count'] ) ) {
			$total_media_files_count = (int) $params['total_media_files_count'];
		} else {
			$total_media_files_count = 1;
		}

  // Set batch size for processing from settings (default 250 files per request)
		$settings      = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$batch_size    = Runtime_Limits::bounded_int( isset( $settings['batch_size'] ) ? $settings['batch_size'] : 250, 250, 1, 1000 );
		$batch_size    = Runtime_Limits::bounded_int( apply_filters( 'sssbm_media_batch_size', $batch_size ), $batch_size, 1, 1000 );
		$timeout       = Runtime_Limits::completed_timeout();
		// Zero disables size-based skipping.
		$max_file_size = Runtime_Limits::bounded_int( apply_filters( 'sssbm_media_max_file_size', 0 ), 0, 0, PHP_INT_MAX );

		// Get memory limit and calculate thresholds
		$memory_limit     = self::get_memory_limit();
		$memory_threshold = $memory_limit * 0.9; // 90% threshold

		// Get processed files count in current session
		if ( isset( $params['processed_files_count'] ) ) {
			$processed_files_count = (int) $params['processed_files_count'];
		} else {
			$processed_files_count = 0;
		}

		// What percent of files have we processed?
		$progress = (int) min( ( $processed_files_size / $total_media_files_size ) * 100, 100 );

		// Set progress
		/* translators: 1: Number of files, 2: Progress. */
		Status::info( sprintf( __( 'Backing up %1$d media files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_media_files_count, $progress ), 'ssse-media' );

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

		// Start time
		$start = microtime( true );

		// Get media list file
		$media_list_path = Backup::get_media_list_path();
		$upload_dir      = wp_upload_dir();
		$media_root      = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		$remote_media    = Helper::is_stream_wrapped_path( $media_root );
		Logger::log( 'Media list path: ' . $media_list_path . ' (exists: ' . ( file_exists( $media_list_path ) ? 'yes' : 'no' ) . ')' );
		Logger::log( 'Media bytes offset: ' . $media_bytes_offset . ', Processed files: ' . $processed_files_count . ', Batch size: ' . $batch_size );
		$media_list = @fopen( $media_list_path, 'r' );

		if ( ! is_resource( $media_list ) ) {
			Logger::log( 'Failed to open media list file: ' . $media_list_path );
			throw new \RuntimeException( __( 'Could not open the media file list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set the file pointer at the current index
		if ( fseek( $media_list, $media_bytes_offset ) !== - 1 ) {

			Logger::log( 'Media list fseek successful. Starting ZIP operations...' );

			// Acquire ZIP file lock to prevent concurrent access
			$zip_lock = Helper::waitForZipLock( 30 );
			if ( ! $zip_lock ) {
				Logger::log( 'Failed to acquire ZIP lock for media export' );
				@fclose( $media_list );
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			// Open the archive file for writing using wrapper (supports ZipArchive and PclZip fallback)
			$archive     = new Zip_Archive_Wrapper();
			$backup_path = Backup::get_backup_path();

			// Open the archive (creates if not exists)
			if ( ! $archive->open( $backup_path ) ) {
				Logger::log( 'Failed to open ZIP archive for media export: ' . $archive->getError() );
				Helper::releaseZipLock( $zip_lock );
				@fclose( $media_list );
				$params['completed'] = false;

				return $params;
			}

			$entries_before = $archive->getEntryCount();
			Logger::log( 'Archive opened. Entries before: ' . $entries_before . '. Time since start: ' . round( microtime( true ) - $start, 2 ) . 's' );

			// Loop over files
			while ( false !== ( $media_row = Helper::getcsv( $media_list ) ) ) {
				$row_offset = $media_bytes_offset;
				list( $file_abspath, $file_relpath, $file_size, $file_mtime ) = $media_row;
				$log_path = preg_replace( '/[\x00-\x1F\x7F]+/', '?', (string) $file_relpath );
				$log_path = is_string( $log_path ) && '' !== $log_path ? substr( $log_path, 0, 1000 ) : '[invalid media path]';
				Logger::log( sprintf(
					'Processing media file %d at list offset %d: %s (%s)',
					$processed_files_count + $skipped_files + 1,
					$row_offset,
					$log_path,
					self::format_bytes( (int) $file_size )
				) );
				$listed_abspath = $file_abspath;
				$file_abspath   = Helper::resolve_listed_path_within( $listed_abspath, $file_relpath, $media_root );
				if (
					false !== $file_abspath &&
					( ! is_string( $listed_abspath ) || wp_normalize_path( $file_abspath ) !== wp_normalize_path( $listed_abspath ) )
				) {
					$rebased_files++;
				}
				// Optionally skip very large files based on a filter-configured size limit (in bytes)
				if ( $max_file_size > 0 && $file_size > $max_file_size ) {
					Logger::log( 'Skipping large media file (' . self::format_bytes( $file_size ) . '): ' . $file_abspath );
					$skipped_files++;
					$media_bytes_offset = ftell( $media_list );
					// Adjust totals to reflect exclusion so progress is accurate
					$total_media_files_size = max( 1, $total_media_files_size - $file_size );
					if ( $total_media_files_count > 1 ) {
						$total_media_files_count--;
					}
					$current_batch_count++;
					if ( $current_batch_count >= $batch_size ) {
						$completed = false;
						break;
					}
					if ( $timeout > 0 && ( microtime( true ) - $start ) > $timeout ) {
						$completed = false;
						break;
					}
					// Continue with next file without attempting to add to the archive
					continue;
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
					Logger::log( 'Warning: Media file is outside the current uploads directory, skipping.' );
					$skipped_files++;
				} else {
					$zip_path = self::get_formatted_path( 'uploads' . DIRECTORY_SEPARATOR . $file_relpath );
					if ( ! array_key_exists( $zip_path, $preexisting_entries ) ) {
						$preexisting_entries[ $zip_path ] = $archive->hasEntry( $zip_path );
					}
					$added = true;
					if ( $preexisting_entries[ $zip_path ] ) {
						$entries_reused++;
					} elseif ( ! $remote_media && ! file_exists( $file_abspath ) ) {
						Logger::log( 'Warning: Media file does not exist, skipping: ' . $file_abspath );
						$skipped_files++;
						$added = false;
					} else {
						$added = $remote_media
							? $archive->addStreamFile( $file_abspath, $zip_path )
							: $archive->addFile( $file_abspath, $zip_path );
						if ( ! $added ) {
							Logger::log( 'Warning: Failed to add media file to archive: ' . $file_abspath . ' -> ' . $zip_path );
							$skipped_files++;
						}
					}
					if ( $added ) {
						$file_bytes_offset = 0;
						if ( ! $preexisting_entries[ $zip_path ] ) {
							$entries_added++;
						}
						$processed_files_size += $file_size;
						$processed_files_count++;
					}
				}

				// Advance only after this file was handled. Advancing before the
				// memory check could permanently skip an unprocessed file.
				$media_bytes_offset = ftell( $media_list );

				$current_batch_count++;

				// What percent of files have we processed?
				$progress = (int) min( ( $processed_files_size / $total_media_files_size ) * 100, 100 );

				if ( 0 === $current_batch_count % 25 ) {
					/* translators: 1: Number of files, 2: Progress. */
					Status::info( sprintf( __( 'Backing up %1$d media files...<br />%2$d%% complete', 'simply-static-studio-backup-migrate' ), $total_media_files_count, $progress ), 'ssse-media' );
				}

 				// Check if we've reached the batch limit AFTER processing this file
				if ( $current_batch_count >= $batch_size ) {
					Logger::log( 'Batch limit reached. Processed ' . $current_batch_count . ' files in this batch.' );
					$completed = false;
					break;
				}

				// More than 10 seconds have passed, break and do another request
				if ( $timeout > 0 && ( microtime( true ) - $start ) > $timeout ) {
					Logger::log( 'Timeout reached after ' . round( microtime( true ) - $start, 2 ) . 's. Processed ' . $current_batch_count . ' files in this batch.' );
					$completed = false;
					break;
				}
			}

			Logger::log( 'Batch finished. Files processed: ' . $current_batch_count . '. Flushing archive...' );

			// Flush deferred writes to disk before saving progress.
			if ( ! $archive->flush() ) {
				$zip_error     = $archive->getError();
				$storage_error = $archive->is_storage_exhausted() ? $archive->get_storage_exhausted_error() : '';
				Logger::log( 'Archive flush failed for media batch. ZIP error: ' . $zip_error );
				$archive->close();
				Helper::releaseZipLock( $zip_lock );
				@fclose( $media_list );
				if ( '' !== $storage_error ) {
					throw new \RuntimeException( $storage_error );
				}
				$params['completed']  = false;
				$params['_skip_save'] = true;

				return $params;
			}

			update_option( 'sssbm_media_bytes_offset', (int) $media_bytes_offset, false );

			// Persist progress to DB before closing the archive.
			if ( $current_batch_count > 0 ) {
				$progress_params = [
					'archive_bytes_offset'      => $archive_bytes_offset,
					'file_bytes_offset'         => $file_bytes_offset,
					'media_bytes_offset'        => $media_bytes_offset,
					'processed_files_size'      => $processed_files_size,
					'processed_files_count'     => $processed_files_count,
					'total_media_files_size'    => $total_media_files_size,
					'total_media_files_count'   => $total_media_files_count,
					'completed'                 => $completed,
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
						__( 'Media archive verification found an entry-count mismatch: expected %1$d new entries, but found %2$d. Review the activity log before restoring this backup.', 'simply-static-studio-backup-migrate' ),
						$expected_new,
						$actual_new
					);
					Logger::log( sprintf(
						'Warning: Archive integrity check — expected %d new entries but %d were written (before: %d, after: %d)',
						$expected_new, $actual_new, $entries_before, $entries_after
					) );
					Backup::add_backup_integrity_issue( 'media_archive_entry_count_mismatch', $message, false );
					Status::warning( $message, 'sssbm-media-entry-count-mismatch' );
				}
			}

			if ( $entries_reused > 0 ) {
				Logger::log( sprintf( 'Recognized %d pre-existing media archive entry/entries from a retried batch.', $entries_reused ) );
			}

			if ( $skipped_files > 0 ) {
				$message = sprintf(
					/* translators: %d: Number of skipped files in the latest batch. */
					__( 'Some media files could not be included in the backup (%d in the latest batch). Review the activity log before restoring this backup.', 'simply-static-studio-backup-migrate' ),
					$skipped_files
				);
				Logger::log( sprintf( 'Warning: %d media file(s) were skipped in this batch.', $skipped_files ) );
				Backup::add_backup_integrity_issue( 'media_files_skipped', $message, false );
				Status::warning( $message, 'sssbm-media-files-skipped' );
			}

			if ( $rebased_files > 0 ) {
				Logger::log( sprintf( 'Rebased %d media file path(s) against the current uploads directory.', $rebased_files ) );
			}

			// Release ZIP file lock
			Helper::releaseZipLock( $zip_lock );
		} else {
			@fclose( $media_list );
			throw new \RuntimeException( __( 'Could not resume from the media file list offset.', 'simply-static-studio-backup-migrate' ) );
		}

		// Set archive bytes offset
		$params['archive_bytes_offset'] = $archive_bytes_offset;

		// Set file bytes offset
		$params['file_bytes_offset'] = $file_bytes_offset;

		// Set media bytes offset
		$params['media_bytes_offset'] = $media_bytes_offset;

		// Set processed files size
		$params['processed_files_size'] = $processed_files_size;

		// Set total media files size
		$params['total_media_files_size'] = $total_media_files_size;

		// Set total media files count
		$params['total_media_files_count'] = $total_media_files_count;

		// Set processed files count
		$params['processed_files_count'] = $processed_files_count;

		// Set completed flag
		$params['completed'] = $completed;

		if ( $completed ) {
			Status::success( __( 'Backed up all media files...<br />100% complete', 'simply-static-studio-backup-migrate' ), 'ssse-media' );
		}
		// Close the media list file
		@fclose( $media_list );

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
		if ( ! in_array( 'uploads', $path_array, true ) ) {
			$path_array = array_merge( [ 'uploads' ], $path_array );
		}

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
