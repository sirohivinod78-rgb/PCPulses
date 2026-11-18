<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.unlink_unlink -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Queue\Tasks\Enumerate_Tables;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\Zip_Archive_Wrapper;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Database_File {

	/**
	 * Maximum number of attempts before giving up on adding the database file.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Maximum number of stalled attempts while regenerating a missing database dump.
	 */
	const MAX_RECOVERY_STALLED_ATTEMPTS = 3;

	public static function execute( $params = [] ) {
		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		$database_path = Backup::get_database_path();
		$task_lock     = Helper::open_lock_file( $database_path . '.database-file.lock', Backup::get_storage_path() );
		if ( ! is_resource( $task_lock ) || ! flock( $task_lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $task_lock ) ) {
				fclose( $task_lock );
			}
			Logger::log( 'Another worker owns the database-file task lock.' );
			$params['completed']  = false;
			$params['_skip_save'] = true;

			return $params;
		}

		try {
			return self::execute_locked( $params, $database_path );
		} finally {
			flock( $task_lock, LOCK_UN );
			fclose( $task_lock );
		}
	}

	/**
	 * Execute the database-file task while its generation lock is held.
	 *
	 * @param array  $params Current task parameters.
	 * @param string $database_path Absolute database dump path.
	 * @return array Updated task parameters.
	 */
	private static function execute_locked( $params, $database_path ) {
		unset( $params['_skip_save'] );
		$archive_retry_count   = isset( $params['database_archive_retry_count'] )
			? (int) $params['database_archive_retry_count']
			: ( ! isset( $params['database_redaction_retry_count'] ) && isset( $params['database_file_retry_count'] ) ? (int) $params['database_file_retry_count'] : 0 );
		$redaction_retry_count = isset( $params['database_redaction_retry_count'] ) ? (int) $params['database_redaction_retry_count'] : 0;

		if ( ! Helper::recover_database_dump_redaction( $database_path ) ) {
			$redaction_error = Helper::get_database_dump_redaction_error();
			Logger::log(
				'Could not recover an interrupted database dump redaction'
				. ( '' !== $redaction_error ? ' (reason: ' . $redaction_error . ')' : '' )
				. '.'
			);

			return self::retry_database_redaction( $params, $redaction_retry_count, $database_path );
		}

		if ( ! self::database_dump_is_ready( $database_path ) || ! empty( $params['database_file_recovery_params'] ) ) {
			Logger::log( 'Database dump is missing, unreadable, empty, or recovery is incomplete: ' . $database_path );

			$params = self::recover_missing_database_dump( $params, $database_path );

			if ( ! empty( $params['database_file_recovery_failed'] ) ) {
				return self::finish_with_database_issue(
					$params,
					'database_missing',
					Backup::get_missing_database_message(),
					__( 'Database backup failed - database dump file is missing.', 'simply-static-studio-backup-migrate' )
				);
			}

			if ( isset( $params['completed'] ) && false === $params['completed'] ) {
				return $params;
			}

			if ( ! self::database_dump_is_ready( $database_path ) ) {
				return self::finish_with_database_issue(
					$params,
					'database_missing',
					Backup::get_missing_database_message(),
					__( 'Database backup failed - database dump file is missing.', 'simply-static-studio-backup-migrate' )
				);
			}
		}

		$stored_fingerprint = isset( $params['database_dump_redacted'] ) && is_string( $params['database_dump_redacted'] ) ? $params['database_dump_redacted'] : '';
		$dump_fingerprint   = '' !== $stored_fingerprint ? self::get_dump_fingerprint( $database_path ) : false;
		if ( '' === $stored_fingerprint || false === $dump_fingerprint || ! hash_equals( $stored_fingerprint, $dump_fingerprint ) ) {
			$redacted = Helper::redact_database_dump( $database_path );
			if ( $redacted ) {
				clearstatcache( true, $database_path );
			}
			$dump_fingerprint = $redacted ? self::get_dump_fingerprint( $database_path ) : false;

			// A successful rewrite is not sufficient unless the exact resulting
			// generation can be identified. This fails closed when hashing fails.
			if ( ! $redacted || false === $dump_fingerprint ) {
				$redaction_error = $redacted ? 'fingerprint_failed' : Helper::get_database_dump_redaction_error();
				Logger::log(
					sprintf(
						'Database dump credential redaction failed (attempt %1$d of %2$d%3$s).',
						$redaction_retry_count + 1,
						self::MAX_RETRIES,
						'' !== $redaction_error ? '; reason: ' . $redaction_error : ''
					)
				);

				return self::retry_database_redaction( $params, $redaction_retry_count, $database_path );
			}

			$params['database_dump_redacted'] = $dump_fingerprint;
		}

		$total_database_size = filesize( $database_path );

		// Set progress
		/* translators: Progress. */
		Status::info( __( 'Adding database to backup archive...', 'simply-static-studio-backup-migrate' ), 'ssse-database-file' );

		$zip_lock = Helper::acquireZipLock();
		if ( ! $zip_lock ) {
			Logger::log( 'Failed to acquire ZIP lock for database archive step.' );
			$params['completed'] = false;

			return $params;
		}

		// Keep the lock through entry validation: another writer must not mutate
		// the central directory between close() and the verification read.
		$archive      = new Zip_Archive_Wrapper();
		$archive_open = false;
		$backup_path  = Backup::get_backup_path();
		try {
			if ( ! $archive->open( $backup_path ) ) {
				Logger::log( 'Failed to open ZIP archive for database file: ' . $archive->getError() );

				$archive_retry_count++;
				$params['database_archive_retry_count'] = $archive_retry_count;
				$params['database_file_retry_count']    = $archive_retry_count;

				if ( $archive_retry_count >= self::MAX_RETRIES ) {
					Logger::log( 'Database file task exceeded maximum retries (' . self::MAX_RETRIES . '). Skipping.' );
					return self::finish_with_database_issue(
						$params,
						'database_not_archived',
						__( 'Backup completed, but the database could not be added to the backup archive. Push to Studio has been disabled for this backup.', 'simply-static-studio-backup-migrate' ),
						__( 'Database backup failed - could not open backup archive after multiple attempts.', 'simply-static-studio-backup-migrate' )
					);
				}

				$params['completed'] = false;
				return $params;
			}
			$archive_open = true;

			// Use addLargeFile() to avoid reading the entire database dump into
			// PHP memory — the file can easily exceed the memory_limit on sites
			// with large databases (e.g. WPML, WooCommerce).
			Logger::log( 'Adding database file to archive (' . size_format( $total_database_size ) . '): ' . $database_path );

			$database_added = $archive->addLargeFile( $database_path, 'db.sql' );

			// Close before validating so PclZip buffered writes are flushed too.
			$database_closed = $archive->close();
			$archive_open    = false;
			if ( ! $database_closed && $archive->is_storage_exhausted() ) {
				throw new \RuntimeException( $archive->get_storage_exhausted_error() );
			}
			$database_added  = $database_added && $database_closed && Backup::archive_contains_file( $backup_path, 'db.sql' );
		} finally {
			if ( $archive_open ) {
				$archive->close();
			}
			Helper::releaseZipLock( $zip_lock );
		}

		if ( $database_added ) {

			// Set progress
			Status::success( __( 'Database backed up.', 'simply-static-studio-backup-migrate' ), 'ssse-database-file' );

			$params['completed'] = true;

		} else {

			$archive_retry_count++;
			$params['database_archive_retry_count'] = $archive_retry_count;
			$params['database_file_retry_count']    = $archive_retry_count;

			Logger::log( 'Failed to add database file to archive (attempt ' . $archive_retry_count . ' of ' . self::MAX_RETRIES . ').' );

			if ( $archive_retry_count >= self::MAX_RETRIES ) {
				Logger::log( 'Database file task exceeded maximum retries (' . self::MAX_RETRIES . '). Skipping.' );
				return self::finish_with_database_issue(
					$params,
					'database_not_archived',
					__( 'Backup completed, but the database could not be added to the backup archive. Push to Studio has been disabled for this backup.', 'simply-static-studio-backup-migrate' ),
					__( 'Database backup failed - could not add database to archive after multiple attempts.', 'simply-static-studio-backup-migrate' )
				);
			} else {
				// Set progress
				Status::info( __( 'Retrying database archive step...', 'simply-static-studio-backup-migrate' ), 'ssse-database-file' );
				$params['completed'] = false;
			}
		}

		return $params;
	}

	private static function database_dump_is_ready( $database_path ) {
		return file_exists( $database_path ) && is_readable( $database_path ) && filesize( $database_path ) > 0;
	}

	/**
	 * Get a content-derived identity for one exact database dump generation.
	 *
	 * Size and modification time are not generation-safe: an interrupted or
	 * malicious rewrite can preserve both and otherwise bypass redaction.
	 * hash_file() streams the dump, so memory use remains constant for large sites.
	 *
	 * @param string $database_path Absolute database dump path.
	 * @return string|false Prefixed SHA-256 digest, or false on failure.
	 */
	private static function get_dump_fingerprint( $database_path ) {
		$fingerprint = @hash_file( 'sha256', $database_path );

		return is_string( $fingerprint ) && '' !== $fingerprint ? 'sha256:' . $fingerprint : false;
	}

	/**
	 * Retry a failed redaction without ever retaining raw credentials after the
	 * final attempt.
	 *
	 * @param array  $params Current task parameters.
	 * @param int    $retry_count Current retry count.
	 * @param string $database_path Absolute database dump path.
	 * @return array Updated task parameters.
	 */
	private static function retry_database_redaction( $params, $retry_count, $database_path ) {
		$retry_count++;
		$params['database_redaction_retry_count'] = $retry_count;
		$params['database_file_retry_count']      = $retry_count;

		if ( $retry_count >= self::MAX_RETRIES ) {
			$cleanup_succeeded = true;
			foreach ( array( $database_path, $database_path . '.redacting', $database_path . '.redacting-original' ) as $artifact_path ) {
				$cleanup_succeeded = self::erase_database_dump_artifact( $artifact_path ) && $cleanup_succeeded;
			}
			if ( ! $cleanup_succeeded ) {
				$params['database_cleanup_failed'] = true;
				Logger::log( 'Raw database dump cleanup could not be verified after redaction failure.' );
			}

			return self::finish_with_database_issue(
				$params,
				'database_not_redacted',
				__( 'Backup completed, but sensitive migration credentials could not be removed from the database dump. Push to Studio has been disabled for this backup.', 'simply-static-studio-backup-migrate' ),
				__( 'Database backup failed - sensitive credentials could not be removed safely.', 'simply-static-studio-backup-migrate' )
			);
		}

		$params['completed'] = false;

		return $params;
	}

	/**
	 * Remove a potentially credential-bearing dump artifact, falling back to a
	 * verified truncation when directory permissions prevent unlinking it.
	 *
	 * @param string $artifact_path Absolute artifact path.
	 * @return bool Whether no credential-bearing content remains at the path.
	 */
	private static function erase_database_dump_artifact( $artifact_path ) {
		if ( ! file_exists( $artifact_path ) && ! is_link( $artifact_path ) ) {
			return true;
		}
		if ( is_link( $artifact_path ) ) {
			return @unlink( $artifact_path );
		}

		if ( @unlink( $artifact_path ) ) {
			return true;
		}

		$handle = Helper::open_lock_file( $artifact_path, Backup::get_storage_path() );
		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$erased = flock( $handle, LOCK_EX ) && ftruncate( $handle, 0 ) && fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		clearstatcache( true, $artifact_path );

		return $erased && ( ! file_exists( $artifact_path ) || 0 === (int) filesize( $artifact_path ) );
	}

	private static function recover_missing_database_dump( $params, $database_path ) {
		$recovery_attempt = isset( $params['database_file_recovery_attempt'] ) ? (int) $params['database_file_recovery_attempt'] : 0;

		if ( file_exists( $database_path ) && ! is_readable( $database_path ) ) {
			@unlink( $database_path );
		}

		$recovery_attempt++;
		$params['database_file_recovery_attempt'] = $recovery_attempt;

		Logger::log( 'Database dump missing. Attempting to regenerate database dump (attempt ' . $recovery_attempt . ').' );
		Status::info( __( 'Database dump missing - trying another database backup method...', 'simply-static-studio-backup-migrate' ), 'ssse-database-file' );

		if ( ! file_exists( Backup::get_tables_list_path() ) || 0 === (int) filesize( Backup::get_tables_list_path() ) ) {
			Logger::log( 'Database tables list is missing or empty. Rebuilding tables list before dump recovery.' );
			$enumerate_params = Enumerate_Tables::execute_scoped( array() );
			Backup::save_export_param( 'enumerate_tables', $enumerate_params );
		}

		$previous_recovery_params = isset( $params['database_file_recovery_params'] ) && is_array( $params['database_file_recovery_params'] )
			? $params['database_file_recovery_params']
			: [];
		$previous_signature       = self::get_recovery_signature( $previous_recovery_params );
		$recovery_params          = $previous_recovery_params;
		$export_params            = Backup::get_export_params();

		if ( empty( $recovery_params['total_tables_count'] ) && ! empty( $export_params['enumerate_tables']['total_tables_count'] ) ) {
			$recovery_params['total_tables_count'] = (int) $export_params['enumerate_tables']['total_tables_count'];
		}

		$recovery_params = Backup_Database::execute( $recovery_params );

		if ( empty( $recovery_params['completed'] ) ) {
			$new_signature    = self::get_recovery_signature( $recovery_params );
			$stalled_attempts = isset( $params['database_file_recovery_stalled_attempts'] ) ? (int) $params['database_file_recovery_stalled_attempts'] : 0;

			if ( $new_signature === $previous_signature ) {
				$stalled_attempts++;
			} else {
				$stalled_attempts = 0;
			}

			if ( $stalled_attempts >= self::MAX_RECOVERY_STALLED_ATTEMPTS ) {
				Logger::log( 'Database dump recovery stalled after ' . self::MAX_RECOVERY_STALLED_ATTEMPTS . ' attempts.' );
				$params['database_file_recovery_failed'] = true;
				unset( $params['database_file_recovery_params'], $params['database_file_recovery_stalled_attempts'], $params['completed'] );

				return $params;
			}

			$params['database_file_recovery_params']            = $recovery_params;
			$params['database_file_recovery_stalled_attempts'] = $stalled_attempts;
			$params['completed']                                = false;

			return $params;
		}

		unset( $params['database_file_recovery_params'], $params['database_file_recovery_stalled_attempts'], $params['completed'] );

		return $params;
	}

	private static function get_recovery_signature( $params ) {
		return implode( ':', [
			isset( $params['query_offset'] ) ? (int) $params['query_offset'] : 0,
			isset( $params['table_index'] ) ? (int) $params['table_index'] : 0,
			isset( $params['table_offset'] ) ? (int) $params['table_offset'] : 0,
			isset( $params['table_rows'] ) ? (int) $params['table_rows'] : 0,
		] );
	}

	private static function finish_with_database_issue( $params, $code, $studio_message, $status_message ) {
		Backup::add_backup_integrity_issue( $code, $studio_message, true );
		Status::warning( $status_message, 'ssse-database-file' );
		Logger::log( $status_message );

		$params['completed'] = true;

		return $params;
	}
}
