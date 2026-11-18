<?php

/**
 * Local backup I/O requires bounded streams, exclusive locks, atomic same-volume
 * renames, verified deletion results, and owner-only permissions. WP_Filesystem
 * remote transports cannot preserve these guarantees for sensitive backup files.
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 *
 * Exceptions carry plain-text diagnostics to CLI, logs, or JSON; HTML consumers
 * escape at their rendering boundary, never while constructing these messages.
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 */

namespace Simply_Static\Backup;

use Simply_Static\Backup\Logs\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup {

	const BACKUP_INTEGRITY_ISSUES_OPTION = 'sssbm_backup_integrity_issues';
	const RUN_LOCK_OPTION = 'sssbm_backup_run_lock';
	const OPERATION_LOCK_OPTION = 'sssbm_backup_operation_lock';
	const STORAGE_KEY_OPTION = 'sssbm_storage_key';
	const PREVIOUS_ARCHIVE_OPTION = 'sssbm_previous_archive_file_path';
	const PREVIOUS_INTEGRITY_OPTION = 'sssbm_previous_backup_integrity_issues';
	const PREVIOUS_TASK_SEQUENCE_OPTION = 'sssbm_previous_completed_task_sequence';
	const COMPLETED_TASK_SEQUENCE_OPTION = 'sssbm_completed_task_sequence';
	const CONSUMED_GENERATION_OPTION = 'sssbm_consumed_backup_generation';
	const CONSUMED_GENERATION_CONTRACT = 'simply-static-studio.consumed-backup-generation';
	const DOWNLOAD_STARTED_OPTION = 'sssbm_backup_download_started';
	const DOWNLOAD_STARTED_CONTRACT = 'simply-static-studio.backup-download-started';
	const LAST_RUN_OUTCOME_OPTION = 'sssbm_last_run_outcome';
	const PURGE_RECEIPT_OPTION = 'sssbm_backup_purge_receipt';
	const PURGE_RECEIPT_CONTRACT = 'simply-static-studio.backup-purge-receipt';
	const STATIC_DOWNLOAD_OPTION = 'sssbm_static_download';
	const STATIC_DOWNLOAD_HOOK = 'sssbm_revoke_static_download';

	/**
	 * Return the list of tasks.
	 *
	 * @return string[]
	 */
	public static function get_tasks() {
		$snapshot = get_option( 'sssbm_task_sequence', array() );
		if ( self::is_running() ) {
			if ( ! self::is_valid_task_sequence( $snapshot ) ) {
				throw new \RuntimeException( __( 'The frozen backup task sequence is missing or invalid.', 'simply-static-studio-backup-migrate' ) );
			}

			return array_values( $snapshot );
		}

		return self::build_task_sequence_from_settings();
	}

	/**
	 * Build a new task sequence from live settings.
	 *
	 * This deliberately bypasses an older running flag so a newly fenced owner
	 * can initialize after reclaiming a stale generation.
	 *
	 * @return string[]
	 */
	private static function build_task_sequence_from_settings() {
		$settings = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$managed_runtime = defined( 'STATIC_STUDIO_MANAGED_RUNTIME' ) && true === STATIC_STUDIO_MANAGED_RUNTIME;

		// Missing include settings remain enabled for backward compatibility;
		// malformed values fail closed rather than unexpectedly broadening a backup.
		// Platform backups are always complete and re-importable: imported plugin
		// preferences cannot silently omit files while the managed UI is disabled.
		$include_plugins  = $managed_runtime || self::setting_is_enabled( $settings, 'include_plugins', true );
		$include_themes   = $managed_runtime || self::setting_is_enabled( $settings, 'include_themes', true );
		$include_media    = $managed_runtime || self::setting_is_enabled( $settings, 'include_media', true );
		$include_database = self::should_include_database();

		$tasks = [
			'init',
			'archive',
			'config',
			'config_file',
			'enumerate_content',
		];

		// Add media tasks if included
		if ( $include_media ) {
			$tasks[] = 'enumerate_media';
		}

		// Add plugins tasks if included
		if ( $include_plugins ) {
			$tasks[] = 'enumerate_plugins';
		}

		// Add themes tasks if included
		if ( $include_themes ) {
			$tasks[] = 'enumerate_themes';
		}

		// Add database tasks if included
		if ( $include_database ) {
			$tasks[] = 'enumerate_tables';
		}

		// Add content task (always included)
		$tasks[] = 'content';

		// Add media task if included
		if ( $include_media ) {
			$tasks[] = 'media';
		}

		// Add plugins task if included
		if ( $include_plugins ) {
			$tasks[] = 'plugins';
		}

		// Add themes task if included
		if ( $include_themes ) {
			$tasks[] = 'themes';
		}

		// Add database tasks if included
		if ( $include_database ) {
			$tasks[] = 'database';
			$tasks[] = 'database_file';
		}

		// Always add clean task at the end
		$tasks[] = 'clean';

		return $tasks;
	}

	/**
	 * Parse a stored boolean setting without PHP's array/string truthiness traps.
	 *
	 * @param array  $settings Settings array.
	 * @param string $key Setting key.
	 * @param bool   $default Default when absent.
	 * @return bool
	 */
	private static function setting_is_enabled( $settings, $key, $default ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			return (bool) $default;
		}

		return in_array( $settings[ $key ], array( true, 1, '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Validate a persisted pipeline before a worker uses it for task routing.
	 *
	 * @param mixed $sequence Candidate task sequence.
	 * @return bool
	 */
	public static function is_valid_task_sequence( $sequence ) {
		if (
			! is_array( $sequence ) ||
			empty( $sequence ) ||
			array_keys( $sequence ) !== range( 0, count( $sequence ) - 1 )
		) {
			return false;
		}

		$all_tasks     = self::get_all_tasks();
		$last_position = -1;
		$seen          = array();
		foreach ( $sequence as $task ) {
			if ( ! is_string( $task ) ) {
				return false;
			}
			if ( isset( $seen[ $task ] ) ) {
				return false;
			}
			$seen[ $task ] = true;

			$position = array_search( $task, $all_tasks, true );
			if ( false === $position || $position <= $last_position ) {
				return false;
			}
			$last_position = $position;
		}

		return 'init' === $sequence[0] && 'clean' === $sequence[ count( $sequence ) - 1 ];
	}

	/**
	 * Return every task class that may appear in a queue.
	 *
	 * @return string[]
	 */
	public static function get_all_tasks() {
		return array(
			'init',
			'archive',
			'config',
			'config_file',
			'enumerate_content',
			'enumerate_media',
			'enumerate_plugins',
			'enumerate_themes',
			'enumerate_tables',
			'content',
			'media',
			'plugins',
			'themes',
			'database',
			'database_file',
			'clean',
		);
	}

	/**
	 * Return a bounded, machine-readable snapshot of backup progress.
	 *
	 * The snapshot is derived only from durable task state, so a separate CLI
	 * process can read it while the synchronous backup worker is running.
	 *
	 * @return array{status:string,progress:int,phase:?string,task:?string,task_progress:?int}
	 */
	public static function get_progress_state() {
		$process          = self::get_process();
		$last_outcome     = self::get_last_run_outcome();
		$is_paused        = $process->is_paused();
		$is_active        = self::is_running() || $process->is_active();
		$status           = 'idle';
		$progress         = 0;
		$task_progress    = null;
		$current_task     = null;
		$phase            = null;

		if ( $is_paused ) {
			$status = 'paused';
		} elseif ( in_array( $last_outcome, array( 'failed', 'cancelled' ), true ) ) {
			$status = $last_outcome;
		} elseif ( $is_active ) {
			$status = 'running';
		} elseif (
			self::is_completed() &&
			! self::is_completed_generation_consumed() &&
			! self::is_completed_generation_download_started()
		) {
			$status = 'completed';
		}

		if ( in_array( $status, array( 'running', 'paused', 'completed' ), true ) ) {
			$tasks       = self::get_tasks();
			$total_tasks = count( $tasks );
			$stored_task = get_option( 'sssbm_current_task', '' );
			$current_task = is_string( $stored_task ) && in_array( $stored_task, $tasks, true )
				? $stored_task
				: null;

			if ( 'completed' === $status ) {
				$progress = 100;
			} elseif ( null !== $current_task && $total_tasks > 0 ) {
				$current_index = array_search( $current_task, $tasks, true );
				$task_progress = self::get_task_progress( $current_task );
				$task_fraction = null === $task_progress ? 0 : $task_progress / 100;
				$progress      = (int) min(
					99,
					max( 1, round( ( ( $current_index + $task_fraction ) / $total_tasks ) * 100 ) )
				);
			}

			$phase = self::get_progress_phase( $current_task );
		}

		return array(
			'status'        => $status,
			'progress'      => $progress,
			'phase'         => $phase,
			'task'          => $current_task,
			'task_progress' => $task_progress,
		);
	}

	/**
	 * Return structured progress for a resumable task.
	 *
	 * @param string $task Task identifier.
	 * @return int|null Percentage, or null when the task has no measurable units.
	 */
	public static function get_task_progress( $task ) {
		$params = self::get_export_param( $task, array() );
		if ( ! is_array( $params ) ) {
			return null;
		}

		if ( ! empty( $params['completed'] ) ) {
			return 100;
		}

		$fields = array(
			'content'  => array( 'content_processed_files_size', 'total_content_files_size' ),
			'media'    => array( 'processed_files_size', 'total_media_files_size' ),
			'plugins'  => array( 'processed_files_size', 'total_plugins_files_size' ),
			'themes'   => array( 'processed_files_size', 'total_themes_files_size' ),
			'database' => array( 'table_index', 'total_tables_count' ),
		);

		if ( ! isset( $fields[ $task ] ) ) {
			return null;
		}

		list( $processed_key, $total_key ) = $fields[ $task ];
		$processed = $params[ $processed_key ] ?? null;
		$total     = $params[ $total_key ] ?? null;
		if ( ! is_numeric( $processed ) || ! is_numeric( $total ) || (float) $total <= 0 ) {
			return null;
		}

		return (int) round( min( 1, max( 0, (float) $processed / (float) $total ) ) * 100 );
	}

	/**
	 * Map internal tasks to a small, stable phase vocabulary for platform UIs.
	 *
	 * @param string|null $task Task identifier.
	 * @return string|null
	 */
	private static function get_progress_phase( $task ) {
		if ( ! is_string( $task ) || '' === $task ) {
			return null;
		}

		$phases = array(
			'init'              => 'preparing',
			'archive'           => 'preparing',
			'config'            => 'preparing',
			'config_file'       => 'preparing',
			'enumerate_content' => 'scanning',
			'enumerate_media'   => 'scanning',
			'enumerate_plugins' => 'scanning',
			'enumerate_themes'  => 'scanning',
			'enumerate_tables'  => 'scanning',
			'content'           => 'archiving_files',
			'media'             => 'archiving_files',
			'plugins'           => 'archiving_files',
			'themes'            => 'archiving_files',
			'database'          => 'archiving_database',
			'database_file'     => 'archiving_database',
			'clean'             => 'finalizing',
		);

		return $phases[ $task ] ?? null;
	}

	public static function get_next_task( $task = null ) {
		$tasks = self::get_tasks();

		if ( null === $task ) {
			return $tasks[0];
		}

		$currentIndex = array_search( $task, $tasks, true );

		if ( false === $currentIndex || $currentIndex < 0 ) {
			return null;
		}

		$nextIndex = $currentIndex + 1;

		if ( ! isset( $tasks[ $nextIndex ] ) ) {
			return null;
		}

		return $tasks[ $nextIndex ];
	}

	public static function is_running() {
		return (bool) Plugin::get_migrated_option( 'sssbm_export_process_running', false );
	}

	public static function is_completed() {
		return (bool) Plugin::get_migrated_option( 'sssbm_export_complete', false );
	}

	public static function set_as_running( $run_token = '' ) {
		if ( ! self::may_mutate_run( $run_token ) ) {
			return false;
		}

		update_option( 'sssbm_export_process_running', true, false );

		return true;
	}

	public static function set_as_not_running( $run_token = '' ) {
		$run_lock = get_option( self::RUN_LOCK_OPTION, false );

		// Legacy state created before run tokens existed can still be cleaned up,
		// but a tokenized run may only be released by its exact owner.
		if ( false === $run_lock ) {
			if ( '' !== $run_token ) {
				return false;
			}

			delete_option( 'sssbm_export_process_running' );
			delete_option( 'sss_export_process_running' );
			delete_option( 'sssbm_cancel_run_token' );
			delete_option( 'sssbm_paused_run_token' );

			return true;
		}

		if ( ! self::run_lock_matches_token( $run_lock, $run_token ) ) {
			return false;
		}

		// Delete the observed run lock first while its operation lease still blocks
		// a replacement. Only then clear shared state and release the lease last.
		if ( ! self::delete_exact_option_value( self::RUN_LOCK_OPTION, $run_lock ) ) {
			return false;
		}

		delete_option( 'sssbm_export_process_running' );
		delete_option( 'sss_export_process_running' );
		delete_option( 'sssbm_cancel_run_token' );
		delete_option( 'sssbm_paused_run_token' );

		if ( ! empty( $run_lock['operation_token'] ) ) {
			self::release_operation_lock( (string) $run_lock['operation_token'] );
		}

		return true;
	}

	public static function set_as_completed() {
		update_option( 'sssbm_export_complete', true, false );
	}

	public static function set_as_not_completed() {
		delete_option( 'sssbm_export_complete' );
		delete_option( 'sss_export_complete' );
	}

	/**
	 * Return a stable identifier for the currently completed archive generation.
	 *
	 * The archive may move between private storage and a short-lived public
	 * download directory, so its absolute path is deliberately excluded. New
	 * generations use a randomized filename; the byte length prevents a reused
	 * legacy filename from silently inheriting an unrelated marker.
	 *
	 * @return string|false Lowercase SHA-256 generation identifier, or false.
	 */
	public static function get_completed_generation_id() {
		if ( ! self::is_completed() ) {
			return false;
		}

		$archive_path = self::get_existing_backup_path();
		if ( ! is_string( $archive_path ) || '' === $archive_path ) {
			return false;
		}

		clearstatcache( true, $archive_path );
		$stat = @lstat( $archive_path );
		if ( ! is_array( $stat ) || is_link( $archive_path ) || (int) ( $stat['size'] ?? 0 ) < 1 ) {
			return false;
		}

		return hash(
			'sha256',
			self::CONSUMED_GENERATION_CONTRACT . "\0" . basename( $archive_path ) . "\0" . (string) (int) $stat['size']
		);
	}

	/**
	 * Whether the current (or explicitly identified) completed generation was used.
	 *
	 * @param string|false $generation_id Optional generation identifier.
	 * @return bool
	 */
	public static function is_completed_generation_consumed( $generation_id = false ) {
		if ( false === $generation_id ) {
			$generation_id = self::get_completed_generation_id();
		}
		if ( ! is_string( $generation_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $generation_id ) ) {
			return false;
		}

		$marker = get_option( self::CONSUMED_GENERATION_OPTION, false );

		return
			is_array( $marker ) &&
			self::CONSUMED_GENERATION_CONTRACT === ( $marker['contract'] ?? null ) &&
			1 === ( $marker['schema_version'] ?? null ) &&
			is_string( $marker['generation_id'] ?? null ) &&
			(bool) preg_match( '/^[a-f0-9]{64}$/D', $marker['generation_id'] ) &&
			hash_equals( $marker['generation_id'], $generation_id );
	}

	/**
	 * Whether a static-server handoff was prepared for the current generation.
	 *
	 * A 302 response proves only that the browser was given a capability URL; it
	 * cannot prove that the web server delivered every byte to the client. Keep
	 * this presentation state separate from consumption so failed or cancelled
	 * handoffs remain fully retryable.
	 *
	 * @param string|false $generation_id Optional generation identifier.
	 * @return bool
	 */
	public static function is_completed_generation_download_started( $generation_id = false ) {
		if ( false === $generation_id ) {
			$generation_id = self::get_completed_generation_id();
		}
		if ( ! is_string( $generation_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $generation_id ) ) {
			return false;
		}

		$marker = get_option( self::DOWNLOAD_STARTED_OPTION, false );

		return
			is_array( $marker ) &&
			self::DOWNLOAD_STARTED_CONTRACT === ( $marker['contract'] ?? null ) &&
			1 === ( $marker['schema_version'] ?? null ) &&
			is_string( $marker['generation_id'] ?? null ) &&
			(bool) preg_match( '/^[a-f0-9]{64}$/D', $marker['generation_id'] ) &&
			hash_equals( $marker['generation_id'], $generation_id );
	}

	/**
	 * Record a retryable static-server download handoff for the exact generation.
	 *
	 * @param string $generation_id Expected completed generation identifier.
	 * @param string $operation_token Exact download lease fencing this state change.
	 * @return bool Whether the marker was persisted for the current generation.
	 */
	public static function mark_completed_generation_download_started( $generation_id, $operation_token ) {
		if (
			! is_string( $generation_id ) ||
			! preg_match( '/^[a-f0-9]{64}$/D', $generation_id ) ||
			! self::owns_operation_lock( $operation_token, 'download' )
		) {
			return false;
		}

		$current_generation = self::get_completed_generation_id();
		if ( ! is_string( $current_generation ) || ! hash_equals( $generation_id, $current_generation ) ) {
			return false;
		}

		$marker = array(
			'contract'       => self::DOWNLOAD_STARTED_CONTRACT,
			'schema_version' => 1,
			'generation_id'  => $generation_id,
			'started_at'     => time(),
		);
		$updated = update_option( self::DOWNLOAD_STARTED_OPTION, $marker, false );

		return $updated || get_option( self::DOWNLOAD_STARTED_OPTION, false ) === $marker;
	}

	/**
	 * Mark the exact completed generation as used without deleting its archive.
	 *
	 * Keeping completion and archive state intact preserves failed-run recovery
	 * and diagnostics. The marker only suppresses reuse through the UI and Studio
	 * push API until a distinct generation completes successfully.
	 *
	 * @param string $generation_id Expected generation identifier captured before transfer.
	 * @param string $method Either `download` or `studio`.
	 * @param string $operation_token Exact transfer lease fencing this state change.
	 * @return bool Whether the marker was persisted for the still-current generation.
	 */
	public static function mark_completed_generation_consumed( $generation_id, $method, $operation_token = '' ) {
		if (
			! is_string( $generation_id ) ||
			! preg_match( '/^[a-f0-9]{64}$/D', $generation_id ) ||
			! in_array( $method, array( 'download', 'studio' ), true )
		) {
			return false;
		}
		$owns_transfer_lease = 'studio' === $method
			? self::owns_operation_lock( $operation_token, 'studio_push' )
			: self::owns_operation_lock( $operation_token, 'download' );
		if ( ! $owns_transfer_lease ) {
			return false;
		}

		$current_generation = self::get_completed_generation_id();
		if ( ! is_string( $current_generation ) || ! hash_equals( $generation_id, $current_generation ) ) {
			return false;
		}

		$marker = array(
			'contract'       => self::CONSUMED_GENERATION_CONTRACT,
			'schema_version' => 1,
			'generation_id'  => $generation_id,
			'method'         => $method,
			'consumed_at'    => time(),
		);
		$updated = update_option( self::CONSUMED_GENERATION_OPTION, $marker, false );
		if ( ! $updated && get_option( self::CONSUMED_GENERATION_OPTION, false ) !== $marker ) {
			return false;
		}

		// Existing shared links must not permit an already-used generation to be
		// downloaded again. An in-flight request has already validated its token.
		delete_transient( 'sssbm_download_token' );

		return true;
	}

	/**
	 * Return the last generation's validated lifecycle outcome.
	 *
	 * @return string Empty when no trustworthy outcome has been recorded.
	 */
	public static function get_last_run_outcome() {
		$outcome = get_option( self::LAST_RUN_OUTCOME_OPTION, false );
		if (
			! is_array( $outcome ) ||
			! isset( $outcome['status'], $outcome['run_token'] ) ||
			! is_string( $outcome['status'] ) ||
			! is_string( $outcome['run_token'] ) ||
			! in_array( $outcome['status'], array( 'running', 'completed', 'cancelled', 'failed' ), true )
		) {
			return '';
		}

		// A running outcome is meaningful only for the exact active generation.
		if ( 'running' === $outcome['status'] && ! self::may_mutate_run( $outcome['run_token'] ) ) {
			return '';
		}

		return $outcome['status'];
	}

	/**
	 * Persist an outcome only while the caller still owns its generation.
	 *
	 * @param string $status Lifecycle status.
	 * @param string $run_token Expected run token.
	 * @return bool
	 */
	private static function set_run_outcome( $status, $run_token ) {
		if (
			! in_array( $status, array( 'running', 'completed', 'cancelled', 'failed' ), true ) ||
			! self::may_mutate_run( $run_token )
		) {
			return false;
		}

		$value = array(
			'run_token' => (string) $run_token,
			'status'    => $status,
			'updated'   => time(),
		);
		$updated = update_option( self::LAST_RUN_OUTCOME_OPTION, $value, false );

		return $updated || get_option( self::LAST_RUN_OUTCOME_OPTION, false ) === $value;
	}

	private static $processes = array();

	public static function get_process() {
		$site_id = is_multisite() ? get_current_blog_id() : 0;
		if ( ! isset( self::$processes[ $site_id ] ) ) {
			self::$processes[ $site_id ] = new \Simply_Static\Backup\Queue\Backup_Process();
		}

		return self::$processes[ $site_id ];
	}

	/**
	 * Atomically claim ownership of a new backup run.
	 *
	 * @return bool
	 */
	public static function acquire_run_lock( &$run_token = null ) {
		$run_token = false;
		$operation_token = self::acquire_operation_lock( 'backup', 2 * HOUR_IN_SECONDS );
		if ( ! $operation_token ) {
			return false;
		}

		if ( self::has_legacy_studio_push_lock() ) {
			self::release_operation_lock( $operation_token );
			return false;
		}

		$is_cli      = defined( 'WP_CLI' ) && WP_CLI;
		$stale_after = $is_cli ? 2 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		$process     = self::get_process();

		if (
			$process->is_active() &&
			(
				! $process->reclaim_stale_legacy_queue( $operation_token, $stale_after, $is_cli ) ||
				$process->is_active()
			)
		) {
			self::release_operation_lock( $operation_token );
			return false;
		}

		// Read generation ownership only after the process-level reclaim fence.
		$existing_lock = get_option( self::RUN_LOCK_OPTION, false );
		$lock_created  = is_array( $existing_lock ) && isset( $existing_lock['created'] )
			? (int) $existing_lock['created']
			: ( is_numeric( $existing_lock ) ? (int) $existing_lock : 0 );

		if ( self::is_running() ) {
			$last_progress = max( (int) get_option( 'sssbm_last_progress_at', 0 ), $lock_created );
			if ( $last_progress > time() - $stale_after ) {
				self::release_operation_lock( $operation_token );
				return false;
			}
		} elseif ( false !== $existing_lock && $lock_created > time() - ( 15 * MINUTE_IN_SECONDS ) ) {
			// Covers a process death between claiming the lock and setting running.
			self::release_operation_lock( $operation_token );
			return false;
		}

		if ( false !== $existing_lock && ! self::delete_exact_option_value( self::RUN_LOCK_OPTION, $existing_lock ) ) {
			self::release_operation_lock( $operation_token );
			return false;
		}

		$lock = array(
			'token'           => wp_generate_uuid4(),
			'operation_token' => $operation_token,
			'created'         => time(),
		);

		if ( add_option( self::RUN_LOCK_OPTION, $lock, '', 'no' ) ) {
			$run_token = $lock['token'];
			return true;
		}

		self::release_operation_lock( $operation_token );

		return false;
	}

	/**
	 * Return the exact token for the currently claimed backup generation.
	 *
	 * @return string|false
	 */
	public static function get_current_run_token() {
		$run_lock = get_option( self::RUN_LOCK_OPTION, false );

		return is_array( $run_lock ) && isset( $run_lock['token'] ) && is_string( $run_lock['token'] ) && '' !== $run_lock['token']
			? $run_lock['token']
			: false;
	}

	/**
	 * Whether a caller still owns the exact backup generation it started with.
	 *
	 * @param string $run_token Expected run token.
	 * @return bool
	 */
	public static function owns_run_lock( $run_token ) {
		return self::run_lock_matches_token( get_option( self::RUN_LOCK_OPTION, false ), $run_token );
	}

	/**
	 * Build a serializable, generation-bound queue item.
	 *
	 * @param string $task Task name.
	 * @param string $run_token Run ownership token.
	 * @return array
	 */
	public static function make_queue_item( $task, $run_token ) {
		return array(
			'task'      => $task,
			'run_token' => $run_token,
		);
	}

	/**
	 * Compare a stored run lock with an expected token.
	 *
	 * @param mixed  $run_lock Stored lock payload.
	 * @param string $run_token Expected token.
	 * @return bool
	 */
	private static function run_lock_matches_token( $run_lock, $run_token ) {
		return is_array( $run_lock )
			&& isset( $run_lock['token'] )
			&& is_string( $run_lock['token'] )
			&& is_string( $run_token )
			&& '' !== $run_token
			&& hash_equals( $run_lock['token'], $run_token );
	}

	/**
	 * Permit legacy untokenized state only when no tokenized run exists.
	 *
	 * @param string $run_token Expected token, or empty for legacy state.
	 * @return bool
	 */
	private static function may_mutate_run( $run_token ) {
		$run_lock = get_option( self::RUN_LOCK_OPTION, false );

		return false === $run_lock ? '' === $run_token : self::run_lock_matches_token( $run_lock, $run_token );
	}

	/**
	 * Atomically exclude backup mutation from Studio transfers.
	 *
	 * @param string $type Operation type (backup, download, studio_push, or maintenance).
	 * @param int    $ttl Lease lifetime in seconds.
	 * @return string|false Ownership token.
	 */
	public static function acquire_operation_lock( $type, $ttl ) {
		if ( ! in_array( $type, array( 'backup', 'download', 'studio_push', 'maintenance' ), true ) ) {
			return false;
		}

		$token   = wp_generate_uuid4();
		$payload = array(
			'token'   => $token,
			'type'    => $type,
			'expires' => time() + max( 60, (int) $ttl ),
		);

		if ( add_option( self::OPERATION_LOCK_OPTION, $payload, '', 'no' ) ) {
			return $token;
		}

		$current = get_option( self::OPERATION_LOCK_OPTION, false );
		if ( is_array( $current ) && ! empty( $current['expires'] ) && (int) $current['expires'] > time() ) {
			return false;
		}

		if ( false !== $current && ! self::delete_exact_option_value( self::OPERATION_LOCK_OPTION, $current ) ) {
			return false;
		}

		return add_option( self::OPERATION_LOCK_OPTION, $payload, '', 'no' ) ? $token : false;
	}

	/**
	 * Release the shared operation lease only when the token still owns it.
	 *
	 * @param string $token Ownership token.
	 * @return bool
	 */
	public static function release_operation_lock( $token ) {
		$current = get_option( self::OPERATION_LOCK_OPTION, false );

		if (
			! is_array( $current ) ||
			empty( $current['token'] ) ||
			! is_string( $token ) ||
			'' === $token ||
			! hash_equals( (string) $current['token'], $token )
		) {
			return false;
		}

		return self::delete_exact_option_value( self::OPERATION_LOCK_OPTION, $current );
	}

	/**
	 * Extend an exact live operation lease without reviving or overwriting it.
	 *
	 * @param string $token Ownership token.
	 * @param string $type Expected operation type.
	 * @param int    $ttl New lifetime from now, in seconds.
	 * @return bool Whether this caller still owns the renewed lease.
	 */
	public static function renew_operation_lock( $token, $type, $ttl ) {
		if (
			! is_string( $token ) ||
			'' === $token ||
			! in_array( $type, array( 'backup', 'download', 'studio_push', 'maintenance' ), true )
		) {
			return false;
		}

		$current = get_option( self::OPERATION_LOCK_OPTION, false );
		if (
			! is_array( $current ) ||
			! is_string( $current['token'] ?? null ) ||
			! is_string( $current['type'] ?? null ) ||
			! is_numeric( $current['expires'] ?? null ) ||
			$current['type'] !== $type ||
			(int) $current['expires'] <= time() ||
			! hash_equals( $current['token'], $token )
		) {
			return false;
		}

		$new_expiry = time() + max( 60, (int) $ttl );
		if ( (int) $current['expires'] >= $new_expiry ) {
			return true;
		}

		$renewed = $current;
		$renewed['expires'] = $new_expiry;

		return self::replace_exact_option_value( self::OPERATION_LOCK_OPTION, $current, $renewed );
	}

	/**
	 * Whether a caller still owns the exact, unexpired operation lease.
	 *
	 * @param string $token Expected ownership token.
	 * @param string $type Expected operation type, or empty for any type.
	 * @return bool
	 */
	public static function owns_operation_lock( $token, $type = '' ) {
		$current = get_option( self::OPERATION_LOCK_OPTION, false );

		return is_array( $current )
			&& isset( $current['token'], $current['type'], $current['expires'] )
			&& is_string( $current['token'] )
			&& is_string( $current['type'] )
			&& is_numeric( $current['expires'] )
			&& is_string( $token )
			&& '' !== $token
			&& ( '' === $type || $current['type'] === $type )
			&& (int) $current['expires'] > time()
			&& hash_equals( $current['token'], $token );
	}

	/**
	 * Whether a direct upload or post-redeploy fetch lease is active.
	 *
	 * @return bool
	 */
	public static function has_active_studio_push() {
		$operation = get_option( self::OPERATION_LOCK_OPTION, false );
		if (
			is_array( $operation ) &&
			'studio_push' === ( $operation['type'] ?? '' ) &&
			! empty( $operation['token'] ) &&
			! empty( $operation['expires'] ) &&
			(int) $operation['expires'] > time()
		) {
			return true;
		}

		return self::has_legacy_studio_push_lock();
	}

	/**
	 * Preserve exclusion for a transfer started by the previous lock protocol.
	 *
	 * @return bool
	 */
	private static function has_legacy_studio_push_lock() {
		$lock = get_option( 'sssbm_studio_push_lock', false );

		return is_array( $lock ) && ! empty( $lock['token'] ) && ! empty( $lock['expires'] ) && (int) $lock['expires'] > time();
	}

	/**
	 * Compare-and-delete an observed option value without removing a replacement.
	 *
	 * @param string $key Option key.
	 * @param mixed  $value Observed value.
	 * @return bool
	 */
	private static function delete_exact_option_value( $key, $value ) {
		global $wpdb;

		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic compare-and-delete protects lock ownership; the options cache is invalidated below.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$key,
					maybe_serialize( $value )
				)
			);

			if ( false !== $deleted && $deleted > 0 ) {
				wp_cache_delete( $key, 'options' );

				return true;
			}

			return false;
		}

		return get_option( $key, false ) === $value ? delete_option( $key ) : false;
	}

	/** Compare-and-replace an option value without overwriting a newer owner. */
	private static function replace_exact_option_value( $key, $expected, $replacement ) {
		global $wpdb;

		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic compare-and-swap protects lock ownership; the options cache is invalidated below.
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $replacement ),
					$key,
					maybe_serialize( $expected )
				)
			);

			if ( false !== $updated && $updated > 0 ) {
				wp_cache_delete( $key, 'options' );

				return true;
			}

			return false;
		}

		if ( get_option( $key, false ) !== $expected ) {
			return false;
		}

		$updated = update_option( $key, $replacement, false );

		return $updated || get_option( $key, false ) === $replacement;
	}

	public static function get_storage_url() {
		$uploads_dir = wp_upload_dir();
		if (
			! is_array( $uploads_dir ) ||
			! is_string( $uploads_dir['basedir'] ?? null ) ||
			! is_string( $uploads_dir['baseurl'] ?? null ) ||
			'' === $uploads_dir['baseurl'] ||
			! self::is_local_filesystem_path( $uploads_dir['basedir'] ) ||
			( defined( 'STATIC_STUDIO_MANAGED_RUNTIME' ) && true === STATIC_STUDIO_MANAGED_RUNTIME )
		) {
			return '';
		}

		$simply_static_dir = trailingslashit( $uploads_dir['baseurl'] ) . 'simply-static/' . self::get_storage_directory_name() . '/';

		return $simply_static_dir;
	}

	/**
	 * Whether a path uses the local filesystem instead of a PHP stream wrapper.
	 *
	 * Working files require realpath(), lstat(), atomic rename(), and flock().
	 * Upload offload plugins can expose paths such as s3://bucket/uploads that
	 * support some file operations but cannot safely provide those semantics.
	 *
	 * @param mixed $path Candidate path.
	 * @return bool
	 */
	private static function is_local_filesystem_path( $path ) {
		return is_string( $path )
			&& '' !== $path
			&& ! preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*://#', $path );
	}

	public static function get_storage_path() {
		$uploads_dir = wp_upload_dir();
		$uploads_basedir = is_array( $uploads_dir ) && is_string( $uploads_dir['basedir'] ?? null )
			? $uploads_dir['basedir']
			: '';
		if ( '' === $uploads_basedir ) {
			throw new \RuntimeException( __( 'The WordPress uploads path is unavailable.', 'simply-static-studio-backup-migrate' ) );
		}

		$uploads_are_local = self::is_local_filesystem_path( $uploads_basedir );
		$legacy_dir = $uploads_are_local
			? trailingslashit( $uploads_basedir ) . 'simply-static' . DIRECTORY_SEPARATOR
			: '';
		$base_dir = $legacy_dir;
		$managed_runtime = defined( 'STATIC_STUDIO_MANAGED_RUNTIME' ) && true === STATIC_STUDIO_MANAGED_RUNTIME;
		// Every working directory must be a real local directory, including the
		// normal uploads path. A planted directory symlink must never redirect
		// chmod(), protection files, or sensitive work files outside this root.
		$strict_local_storage = true;

		if ( ! $uploads_are_local && ! $managed_runtime ) {
			$content_root = rtrim( wp_normalize_path( Helper::get_content_path() ), '/' );
			if ( ! self::is_local_filesystem_path( $content_root ) ) {
				throw new \RuntimeException( __( 'A local backup storage root is unavailable.', 'simply-static-studio-backup-migrate' ) );
			}

			$state_root = $content_root . '/.simply-static';
			$configured_root = $state_root . '/backup-migrate';
			foreach ( array( $state_root, $configured_root ) as $local_directory ) {
				if ( is_link( $local_directory ) || ( file_exists( $local_directory ) && ! is_dir( $local_directory ) ) ) {
					throw new \RuntimeException( __( 'The local backup storage path is unsafe.', 'simply-static-studio-backup-migrate' ) );
				}
				if ( ! is_dir( $local_directory ) && ! wp_mkdir_p( $local_directory ) ) {
					throw new \RuntimeException( __( 'The local backup storage path could not be created.', 'simply-static-studio-backup-migrate' ) );
				}
				@chmod( $local_directory, 0700 );
			}

			$real_content_root = realpath( Helper::get_content_path() );
			$real_root = realpath( $configured_root );
			$expected_real_root = false !== $real_content_root
				? rtrim( wp_normalize_path( $real_content_root ), '/' ) . '/.simply-static/backup-migrate'
				: '';
			if ( false === $real_root || '' === $expected_real_root || wp_normalize_path( $real_root ) !== $expected_real_root ) {
				throw new \RuntimeException( __( 'The local backup storage path could not be verified.', 'simply-static-studio-backup-migrate' ) );
			}

			$base_dir = trailingslashit( $configured_root );
		}

		if ( $managed_runtime ) {
			if ( ! defined( 'STATIC_STUDIO_BACKUP_STORAGE_ROOT' ) || ! is_string( STATIC_STUDIO_BACKUP_STORAGE_ROOT ) ) {
				throw new \RuntimeException( __( 'The managed backup storage root is unavailable.', 'simply-static-studio-backup-migrate' ) );
			}

			$expected_root = rtrim( wp_normalize_path( Helper::get_wordpress_path() ), '/' ) . '/.static-studio/backup-migrate';
			$configured_root = rtrim( wp_normalize_path( STATIC_STUDIO_BACKUP_STORAGE_ROOT ), '/' );
			if ( $configured_root !== $expected_root ) {
				throw new \RuntimeException( __( 'The managed backup storage root is invalid.', 'simply-static-studio-backup-migrate' ) );
			}

			$state_root = dirname( $configured_root );
			foreach ( array( $state_root, $configured_root ) as $managed_directory ) {
				if ( is_link( $managed_directory ) || ( file_exists( $managed_directory ) && ! is_dir( $managed_directory ) ) ) {
					throw new \RuntimeException( __( 'The managed backup storage path is unsafe.', 'simply-static-studio-backup-migrate' ) );
				}
				if ( ! is_dir( $managed_directory ) && ! wp_mkdir_p( $managed_directory ) ) {
					throw new \RuntimeException( __( 'The managed backup storage path could not be created.', 'simply-static-studio-backup-migrate' ) );
				}
				@chmod( $managed_directory, 0700 );
			}

			$real_wordpress_root = realpath( Helper::get_wordpress_path() );
			$real_root = realpath( $configured_root );
			$expected_real_root = false !== $real_wordpress_root
				? rtrim( wp_normalize_path( $real_wordpress_root ), '/' ) . '/.static-studio/backup-migrate'
				: '';
			if ( false === $real_root || '' === $expected_real_root || wp_normalize_path( $real_root ) !== $expected_real_root ) {
				throw new \RuntimeException( __( 'The managed backup storage path could not be verified.', 'simply-static-studio-backup-migrate' ) );
			}

			$base_dir = trailingslashit( $configured_root );
		}

		$storage_directory_name = self::get_storage_directory_name();
		$simply_static_dir = $base_dir . $storage_directory_name . DIRECTORY_SEPARATOR;
		if ( is_link( rtrim( $base_dir, '/\\' ) ) || ( file_exists( $base_dir ) && ! is_dir( $base_dir ) ) ) {
			throw new \RuntimeException( __( 'The backup storage directory is unsafe.', 'simply-static-studio-backup-migrate' ) );
		}
		if ( $strict_local_storage && ( is_link( rtrim( $simply_static_dir, '/\\' ) ) || ( file_exists( $simply_static_dir ) && ! is_dir( $simply_static_dir ) ) ) ) {
			throw new \RuntimeException( __( 'The backup storage directory is unsafe.', 'simply-static-studio-backup-migrate' ) );
		}

		if ( ! is_dir( $base_dir ) && ! wp_mkdir_p( $base_dir ) && $strict_local_storage ) {
			throw new \RuntimeException( __( 'The backup storage path could not be created.', 'simply-static-studio-backup-migrate' ) );
		}

		if ( ! is_dir( $simply_static_dir ) && ! wp_mkdir_p( $simply_static_dir ) && $strict_local_storage ) {
			throw new \RuntimeException( __( 'The backup storage directory could not be created.', 'simply-static-studio-backup-migrate' ) );
		}
		if ( $strict_local_storage ) {
			$real_base = realpath( $base_dir );
			$real_private = realpath( $simply_static_dir );
			if (
				false === $real_base ||
				false === $real_private ||
				wp_normalize_path( $real_private ) !== rtrim( wp_normalize_path( $real_base ), '/' ) . '/' . $storage_directory_name
			) {
				throw new \RuntimeException( __( 'The backup storage directory could not be verified.', 'simply-static-studio-backup-migrate' ) );
			}
		}
		@chmod( $simply_static_dir, 0700 );

		if ( '' !== $legacy_dir && $base_dir !== $legacy_dir ) {
			self::migrate_legacy_storage_files(
				$legacy_dir . $storage_directory_name . DIRECTORY_SEPARATOR,
				$simply_static_dir
			);
		}
		if ( '' !== $legacy_dir ) {
			self::migrate_legacy_storage_files( $legacy_dir, $simply_static_dir );
		}
		self::protect_storage_directory( $simply_static_dir );

		return $simply_static_dir;
	}

	/**
	 * Last machine-readable reason a static handoff could not be prepared.
	 *
	 * @var string
	 */
	private static $static_download_failure = '';

	/**
	 * Move a completed archive into a short-lived web-server capability.
	 *
	 * Some managed and network filesystems do not support hard links. A rename
	 * within the uploads filesystem is still constant-time, does not duplicate a
	 * multi-gigabyte ZIP, and is the same portable publication primitive already
	 * used by the archive writer. Only the ZIP moves; SQL, logs, and work files
	 * remain in the protected private directory.
	 *
	 * @param string $archive_path Current completed archive path.
	 * @param int    $ttl Lifetime of the public capability in seconds.
	 * @param array  $expected_identity Optional fstat() identity from the authorized handle.
	 * @return string Public URL, or an empty string when static delivery is unavailable.
	 */
	public static function prepare_static_download( $archive_path, $ttl, $expected_identity = array() ) {
		self::$static_download_failure = '';

		if (
			'\\' === DIRECTORY_SEPARATOR ||
			( defined( 'STATIC_STUDIO_MANAGED_RUNTIME' ) && true === STATIC_STUDIO_MANAGED_RUNTIME )
		) {
			return self::fail_static_download( 'unsupported_runtime' );
		}
		if (
			! is_int( $ttl ) ||
			$ttl < 1 ||
			! is_array( $expected_identity ) ||
			! self::is_valid_archive_path( $archive_path, true ) ||
			self::is_running() ||
			! self::is_completed() ||
			wp_normalize_path( (string) self::get_existing_backup_path() ) !== wp_normalize_path( $archive_path ) ||
			! self::static_download_filename_is_safe( basename( $archive_path ) )
		) {
			return self::fail_static_download( 'invalid_archive_state' );
		}

		$existing = get_option( self::STATIC_DOWNLOAD_OPTION, false );
		if ( self::static_download_marker_is_valid( $existing, $archive_path, $expected_identity ) ) {
			return (string) $existing['url'];
		}

		if ( false !== $existing ) {
			if ( ! self::revoke_static_download() ) {
				return self::fail_static_download( 'stale_capability_not_revoked' );
			}

			// Revocation may have moved an expired capability back to private
			// storage. Continue with the current canonical name for the same inode.
			$current_archive = self::get_existing_backup_path();
			if ( ! is_string( $current_archive ) || '' === $current_archive ) {
				return self::fail_static_download( 'archive_missing_after_revoke' );
			}
			$archive_path = $current_archive;
		}

		clearstatcache( true, $archive_path );
		$source_before = @lstat( $archive_path );
		if (
			! is_array( $source_before ) ||
			is_link( $archive_path ) ||
			(int) ( $source_before['nlink'] ?? 0 ) !== 1 ||
			(int) ( $source_before['size'] ?? 0 ) < 1 ||
			! self::static_download_identity_matches( $source_before, $expected_identity )
		) {
			return self::fail_static_download( 'archive_identity_changed' );
		}

		$root = self::get_static_download_root();
		$url_root = self::get_static_download_url_root();
		if (
			'' === $root ||
			'' === $url_root ||
			is_link( rtrim( $root, '/\\' ) ) ||
			( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ||
			! is_dir( $root ) ||
			! is_writable( $root )
		) {
			return self::fail_static_download( 'public_storage_unavailable' );
		}
		@chmod( $root, 0755 );
		clearstatcache( true, $root );
		$root_stat = @lstat( $root );
		if (
			! is_array( $root_stat ) ||
			( (int) ( $root_stat['mode'] ?? 0 ) & 0005 ) !== 0005
		) {
			return self::fail_static_download( 'public_root_permissions_failed' );
		}

		$root_index = $root . 'index.html';
		if (
			is_link( $root_index ) ||
			( file_exists( $root_index ) && ! is_file( $root_index ) ) ||
			( ! is_file( $root_index ) && false === @file_put_contents( $root_index, '', LOCK_EX ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native lock and verified local identity protect the static-download capability root.
		) {
			return self::fail_static_download( 'public_root_index_failed' );
		}
		@chmod( $root_index, 0644 );
		clearstatcache( true, $root_index );
		$root_index_stat = @lstat( $root_index );
		if (
			is_link( $root_index ) ||
			! is_file( $root_index ) ||
			! is_array( $root_index_stat ) ||
			( (int) ( $root_index_stat['mode'] ?? 0 ) & 0004 ) !== 0004
		) {
			return self::fail_static_download( 'public_root_index_failed' );
		}

		$directory = '';
		$id = '';
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			try {
				$id = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				$id = md5( wp_generate_password( 64, true, true ) . microtime( true ) . $attempt );
			}

			$directory = $root . 'download-' . $id . DIRECTORY_SEPARATOR;
			if ( ! file_exists( $directory ) && ! is_link( $directory ) && @mkdir( $directory, 0755 ) ) {
				break;
			}
			$directory = '';
		}
		if ( '' === $directory ) {
			return self::fail_static_download( 'capability_directory_failed' );
		}

		$public_path = $directory . basename( $archive_path );
		$index_path = $directory . 'index.html';
		if (
			file_exists( $index_path ) ||
			is_link( $index_path ) ||
			false === @file_put_contents( $index_path, '', LOCK_EX ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only an empty index under the verified private capability directory with a native lock.
		) {
			self::remove_static_download_directory( $directory, $public_path );

			return self::fail_static_download( 'capability_index_failed' );
		}
		@chmod( $index_path, 0644 );

		$url = $url_root . 'download-' . $id . '/' . rawurlencode( basename( $archive_path ) );
		$marker = array(
			'version'     => 2,
			'id'          => $id,
			'mode'        => 'move',
			'state'       => 'preparing',
			'source'      => wp_normalize_path( $archive_path ),
			'public_path' => wp_normalize_path( $public_path ),
			'url'         => $url,
			'expires'     => time() + $ttl,
		);

		if ( ! update_option( self::STATIC_DOWNLOAD_OPTION, $marker, false ) && get_option( self::STATIC_DOWNLOAD_OPTION, false ) !== $marker ) {
			self::remove_static_download_directory( $directory, $public_path );

			return self::fail_static_download( 'capability_marker_failed' );
		}

		$scheduled = function_exists( 'wp_schedule_single_event' )
			? wp_schedule_single_event( $marker['expires'], self::STATIC_DOWNLOAD_HOOK, array( $id ) )
			: false;
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'capability_cleanup_schedule_failed' );
		}

		if ( ! @rename( $archive_path, $public_path ) ) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'archive_move_failed' );
		}

		clearstatcache( true, $archive_path );
		clearstatcache( true, $public_path );
		$public_stat = @lstat( $public_path );
		if (
			file_exists( $archive_path ) ||
			is_link( $archive_path ) ||
			! is_array( $public_stat ) ||
			is_link( $public_path ) ||
			(int) ( $public_stat['nlink'] ?? 0 ) !== 1 ||
			! self::static_download_identity_matches( $public_stat, $source_before ) ||
			! self::static_download_identity_matches( $public_stat, $expected_identity )
		) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'archive_move_identity_failed' );
		}

		$current_archive = get_option( 'sssbm_archive_file_path', '' );
		if (
			! is_string( $current_archive ) ||
			wp_normalize_path( $current_archive ) !== wp_normalize_path( $archive_path ) ||
			( ! update_option( 'sssbm_archive_file_path', $public_path, false ) &&
				wp_normalize_path( (string) get_option( 'sssbm_archive_file_path', '' ) ) !== wp_normalize_path( $public_path ) )
		) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'archive_marker_move_failed' );
		}

		@chmod( $directory, 0755 );
		@chmod( $public_path, 0644 );
		clearstatcache( true, $directory );
		clearstatcache( true, $public_path );
		$directory_stat = @lstat( $directory );
		$readable_stat = @lstat( $public_path );
		if (
			! is_array( $directory_stat ) ||
			! is_array( $readable_stat ) ||
			( (int) ( $directory_stat['mode'] ?? 0 ) & 0005 ) !== 0005 ||
			( (int) ( $readable_stat['mode'] ?? 0 ) & 0004 ) !== 0004
		) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'public_permissions_failed' );
		}

		$active_marker = $marker;
		$active_marker['state'] = 'active';
		if (
			! update_option( self::STATIC_DOWNLOAD_OPTION, $active_marker, false ) &&
			get_option( self::STATIC_DOWNLOAD_OPTION, false ) !== $active_marker
		) {
			self::revoke_static_download( $id );

			return self::fail_static_download( 'capability_commit_failed' );
		}

		return $url;
	}

	/**
	 * Return the last static-handoff failure code for diagnostics.
	 *
	 * @return string
	 */
	public static function get_static_download_failure() {
		return self::$static_download_failure;
	}

	/**
	 * Record one non-sensitive failure code and return the public API sentinel.
	 *
	 * @param string $code Failure code.
	 * @return string
	 */
	private static function fail_static_download( $code ) {
		self::$static_download_failure = is_string( $code ) ? $code : 'unknown';

		return '';
	}

	/**
	 * Revoke the current static-download capability.
	 *
	 * The optional ID fences delayed cron events so an older event can never
	 * remove a newer download capability.
	 *
	 * @param string $expected_id Expected capability ID, or empty for the current one.
	 * @return bool Whether no public archive capability remains for the captured marker.
	 */
	public static function revoke_static_download( $expected_id = '' ) {
		$marker = get_option( self::STATIC_DOWNLOAD_OPTION, false );
		if ( false === $marker ) {
			return true;
		}
		if (
			! is_array( $marker ) ||
			! is_string( $marker['id'] ?? null ) ||
			! preg_match( '/^[a-f0-9]{32}$/D', $marker['id'] )
		) {
			return false;
		}
		if ( '' !== $expected_id && ! hash_equals( $marker['id'], (string) $expected_id ) ) {
			return true;
		}

		$public_path = is_string( $marker['public_path'] ?? null ) ? $marker['public_path'] : '';
		$directory = '' !== $public_path ? dirname( $public_path ) . DIRECTORY_SEPARATOR : '';
		if ( ! self::static_download_directory_is_safe( $directory, $marker['id'] ) ) {
			// A database imported from another site may contain an ephemeral
			// marker whose absolute path cannot identify anything in this site's
			// uploads root. Forget it without touching the foreign path.
			$roots = array( self::get_static_download_root(), self::get_legacy_static_download_root() );
			$is_local_path = false;
			foreach ( array_filter( $roots ) as $root ) {
				// A replaced local directory may now resolve outside uploads. Keep
				// that marker for a safe retry instead of mistaking it for an
				// imported foreign path and forgetting an unreclaimed capability.
				$local_prefix = trailingslashit( wp_normalize_path( $root ) );
				if ( 0 === strpos( wp_normalize_path( $public_path ), $local_prefix ) || Helper::is_path_within( $public_path, $root ) ) {
					$is_local_path = true;
					break;
				}
			}
			if (
				'' !== $public_path &&
				! $is_local_path &&
				self::delete_exact_option_value( self::STATIC_DOWNLOAD_OPTION, $marker )
			) {
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( self::STATIC_DOWNLOAD_HOOK, array( $marker['id'] ) );
				}

				return true;
			}

			return false;
		}

		$source = is_string( $marker['source'] ?? null ) ? $marker['source'] : '';
		$is_moved_marker =
			2 === (int) ( $marker['version'] ?? 0 ) &&
			'move' === ( $marker['mode'] ?? '' ) &&
			in_array( $marker['state'] ?? '', array( 'preparing', 'active' ), true );

		if ( $is_moved_marker ) {
			if (
				! self::is_valid_private_archive_path( $source ) ||
				basename( $source ) !== basename( $public_path ) ||
				! self::static_download_filename_is_safe( basename( $source ) )
			) {
				return false;
			}

			$current_archive = get_option( 'sssbm_archive_file_path', '' );
			$current_normalized = is_string( $current_archive ) ? wp_normalize_path( $current_archive ) : '';
			$source_normalized = wp_normalize_path( $source );
			$public_normalized = wp_normalize_path( $public_path );
			if ( ! in_array( $current_normalized, array( $source_normalized, $public_normalized ), true ) ) {
				return false;
			}

			clearstatcache( true, $source );
			clearstatcache( true, $public_path );
			$source_exists = is_file( $source ) && ! is_link( $source );
			$public_exists = is_file( $public_path ) && ! is_link( $public_path );
			if ( $source_exists && $public_exists ) {
				return false;
			}

			if ( $public_exists ) {
				$public_stat = @lstat( $public_path );
				if ( ! is_array( $public_stat ) || (int) ( $public_stat['nlink'] ?? 0 ) !== 1 ) {
					return false;
				}
				@chmod( $public_path, 0600 );
				if ( file_exists( $source ) || is_link( $source ) || ! @rename( $public_path, $source ) ) {
					@chmod( $public_path, 0644 );

					return false;
				}
				$source_exists = true;
			}

			if ( ! $source_exists ) {
				return false;
			}

			if (
				$public_normalized === $current_normalized &&
				! update_option( 'sssbm_archive_file_path', $source, false ) &&
				wp_normalize_path( (string) get_option( 'sssbm_archive_file_path', '' ) ) !== $source_normalized
			) {
				// Keep the database marker and filesystem location coherent if
				// persisting the private name fails.
				if ( ! file_exists( $public_path ) && ! is_link( $public_path ) && @rename( $source, $public_path ) ) {
					@chmod( $public_path, 0644 );
				}

				return false;
			}

			@chmod( $source, 0600 );
			self::remove_static_download_directory( $directory, $public_path );
		} else {
			// Backward-compatible cleanup for version-one hard-link markers
			// created by the previous release candidate.
			self::remove_static_download_directory( $directory, $public_path );
			if ( file_exists( $public_path ) || is_link( $public_path ) ) {
				return false;
			}
			if ( '' !== $source && self::is_valid_private_archive_path( $source, true ) ) {
				clearstatcache( true, $source );
				$source_stat = @lstat( $source );
				if ( is_array( $source_stat ) && (int) ( $source_stat['nlink'] ?? 0 ) === 1 ) {
					@chmod( $source, 0600 );
				}
			}
		}

		$current = get_option( self::STATIC_DOWNLOAD_OPTION, false );
		if ( $current === $marker && ! self::delete_exact_option_value( self::STATIC_DOWNLOAD_OPTION, $marker ) ) {
			return false;
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::STATIC_DOWNLOAD_HOOK, array( $marker['id'] ) );
		}

		return true;
	}

	/**
	 * Return the uploads-backed root used only for public download capabilities.
	 *
	 * @return string
	 */
	private static function get_static_download_root() {
		$uploads = wp_upload_dir();
		if (
			! is_array( $uploads ) ||
			! is_string( $uploads['basedir'] ?? null ) ||
			! self::is_local_filesystem_path( $uploads['basedir'] )
		) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . 'simply-static-downloads' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Return the location used by the first hard-link release candidate.
	 *
	 * @return string
	 */
	private static function get_legacy_static_download_root() {
		$uploads = wp_upload_dir();
		if (
			! is_array( $uploads ) ||
			! is_string( $uploads['basedir'] ?? null ) ||
			! self::is_local_filesystem_path( $uploads['basedir'] )
		) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . 'simply-static' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Return the uploads URL root for public capability directories.
	 *
	 * Upload URLs may legitimately use a host-managed CDN. The URL still comes
	 * from WordPress' uploads configuration and is strictly limited to HTTP(S)
	 * without credentials, query parameters, fragments, or control characters.
	 *
	 * @return string
	 */
	private static function get_static_download_url_root() {
		$uploads = wp_upload_dir();
		$baseurl = is_array( $uploads ) && is_string( $uploads['baseurl'] ?? null ) ? $uploads['baseurl'] : '';
		$sanitized_baseurl = '' !== $baseurl ? esc_url_raw( $baseurl ) : '';
		$upload_parts = '' !== $baseurl ? wp_parse_url( $baseurl ) : false;
		if (
			'' === $sanitized_baseurl ||
			! hash_equals( $baseurl, $sanitized_baseurl ) ||
			preg_match( '/[\x00-\x20\x7f]/', $baseurl ) ||
			! is_array( $upload_parts ) ||
			! in_array( strtolower( (string) ( $upload_parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ||
			'' === (string) ( $upload_parts['host'] ?? '' ) ||
			isset( $upload_parts['user'] ) ||
			isset( $upload_parts['pass'] ) ||
			isset( $upload_parts['query'] ) ||
			isset( $upload_parts['fragment'] )
		) {
			return '';
		}

		return trailingslashit( $sanitized_baseurl ) . 'simply-static-downloads/';
	}

	/**
	 * Validate a persisted static-download marker and moved archive identity.
	 *
	 * @param mixed  $marker Persisted marker.
	 * @param string $archive_path Expected private archive path.
	 * @param array  $expected_identity Optional fstat() identity from the authorized handle.
	 * @return bool
	 */
	private static function static_download_marker_is_valid( $marker, $archive_path, $expected_identity = array() ) {
		if (
			! is_array( $marker ) ||
			2 !== (int) ( $marker['version'] ?? 0 ) ||
			! is_string( $marker['id'] ?? null ) ||
			! preg_match( '/^[a-f0-9]{32}$/D', $marker['id'] ) ||
			'move' !== ( $marker['mode'] ?? '' ) ||
			'active' !== ( $marker['state'] ?? '' ) ||
			! is_string( $marker['source'] ?? null ) ||
			! self::is_valid_private_archive_path( $marker['source'] ) ||
			! is_string( $marker['public_path'] ?? null ) ||
			wp_normalize_path( $archive_path ) !== wp_normalize_path( $marker['public_path'] ) ||
			! is_string( $marker['url'] ?? null ) ||
			! is_int( $marker['expires'] ?? null ) ||
			$marker['expires'] <= time() ||
			! self::static_download_directory_is_safe( dirname( $marker['public_path'] ) . DIRECTORY_SEPARATOR, $marker['id'] ) ||
			basename( $marker['public_path'] ) !== basename( $marker['source'] ) ||
			! self::static_download_filename_is_safe( basename( $marker['public_path'] ) )
		) {
			return false;
		}

		$url_root = self::get_static_download_url_root();
		$expected_url = $url_root . 'download-' . $marker['id'] . '/' . rawurlencode( basename( $archive_path ) );
		if ( '' === $url_root || ! hash_equals( $expected_url, $marker['url'] ) ) {
			return false;
		}

		clearstatcache( true, $archive_path );
		$public_stat = @lstat( $marker['public_path'] );

		return
			is_array( $public_stat ) &&
			! is_link( $marker['public_path'] ) &&
			! file_exists( $marker['source'] ) &&
			! is_link( $marker['source'] ) &&
			(int) ( $public_stat['nlink'] ?? 0 ) === 1 &&
			wp_normalize_path( (string) get_option( 'sssbm_archive_file_path', '' ) ) === wp_normalize_path( $marker['public_path'] ) &&
			self::static_download_identity_matches( $public_stat, $expected_identity );
	}

	/**
	 * Compare a path stat with the handle identity authorized by the caller.
	 *
	 * @param array $stat Path lstat() result.
	 * @param array $expected Optional handle fstat() result.
	 * @return bool
	 */
	private static function static_download_identity_matches( $stat, $expected ) {
		if ( empty( $expected ) ) {
			return true;
		}
		if ( ! is_array( $stat ) || ! is_array( $expected ) ) {
			return false;
		}

		foreach ( array( 'dev', 'ino', 'size' ) as $key ) {
			if ( ! array_key_exists( $key, $expected ) || (string) ( $stat[ $key ] ?? '' ) !== (string) $expected[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a capability directory without following or traversing it.
	 *
	 * @param string $directory Directory path.
	 * @param string $id Capability ID.
	 * @return bool
	 */
	private static function static_download_directory_is_safe( $directory, $id ) {
		if ( ! is_string( $directory ) || ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			return false;
		}

		$normalized = rtrim( wp_normalize_path( $directory ), '/' );
		if ( basename( $normalized ) !== 'download-' . $id || is_link( $normalized ) ) {
			return false;
		}

		$roots = array( self::get_static_download_root(), self::get_legacy_static_download_root() );
		foreach ( array_filter( $roots ) as $root ) {
			if ( is_link( rtrim( $root, '/\\' ) ) ) {
				continue;
			}
			if ( dirname( $normalized ) === rtrim( wp_normalize_path( $root ), '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove only the known leaf files in one capability directory.
	 *
	 * @param string $directory Capability directory.
	 * @param string $public_path Public archive path.
	 * @return void
	 */
	private static function remove_static_download_directory( $directory, $public_path ) {
		if ( is_string( $public_path ) && '' !== $public_path && ( is_file( $public_path ) || is_link( $public_path ) ) ) {
			@unlink( $public_path );
		}
		$index_path = is_string( $directory ) ? $directory . 'index.html' : '';
		if ( '' !== $index_path && ( is_file( $index_path ) || is_link( $index_path ) ) ) {
			@unlink( $index_path );
		}
		if ( is_string( $directory ) && '' !== $directory && is_dir( $directory ) && ! is_link( rtrim( $directory, '/\\' ) ) ) {
			@rmdir( $directory );
		}
	}

	/**
	 * Whether a filename is safe to place verbatim in a capability URL.
	 *
	 * @param string $filename Archive filename.
	 * @return bool
	 */
	private static function static_download_filename_is_safe( $filename ) {
		return
			is_string( $filename ) &&
			strlen( $filename ) <= 255 &&
			(bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $filename );
	}

	/**
	 * Remove one exact, already-uploaded backup generation and its sensitive work files.
	 *
	 * A maintenance lease fences normal backup mutation. The two-phase receipt makes a
	 * retry safe if PHP exits after unlinking the archive but before clearing its exact
	 * WordPress markers. No archive discovery or wildcard deletion is performed here.
	 *
	 * @param string $expected_sha256 Lowercase SHA-256 recorded by the platform.
	 * @param int    $expected_bytes Exact positive archive length recorded by the platform.
	 * @return string `purged` or `already_purged`.
	 */
	public static function purge_completed_artifact( $expected_sha256, $expected_bytes ) {
		if ( ! is_string( $expected_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) || ! is_int( $expected_bytes ) || $expected_bytes < 1 ) {
			throw new \InvalidArgumentException( __( 'The expected backup artifact identity is invalid.', 'simply-static-studio-backup-migrate' ) );
		}

		$operation_token = self::acquire_operation_lock( 'maintenance', 2 * HOUR_IN_SECONDS );
		if ( ! $operation_token ) {
			throw new \RuntimeException( __( 'Backup storage is busy and cannot be purged.', 'simply-static-studio-backup-migrate' ) );
		}

		try {
			if ( self::is_running() || false !== get_option( self::RUN_LOCK_OPTION, false ) ) {
				throw new \RuntimeException( __( 'A backup is running or waiting to resume.', 'simply-static-studio-backup-migrate' ) );
			}
			if ( ! self::revoke_static_download() ) {
				throw new \RuntimeException( __( 'The public backup download could not be revoked safely.', 'simply-static-studio-backup-migrate' ) );
			}

			$storage = self::get_storage_path();
			$receipt = self::read_purge_receipt();
			$receipt_matches = self::purge_receipt_matches( $receipt, $expected_sha256, $expected_bytes );
			if ( is_array( $receipt ) && 'purging' === $receipt['status'] && ! $receipt_matches ) {
				throw new \RuntimeException( __( 'A different backup purge is waiting to resume.', 'simply-static-studio-backup-migrate' ) );
			}
			$marker = get_option( 'sssbm_archive_file_path', false );
			$archive = false;

			if ( is_string( $marker ) && '' !== $marker ) {
				if ( ! self::is_valid_archive_path( $marker ) ) {
					throw new \RuntimeException( __( 'The completed backup archive marker is unsafe.', 'simply-static-studio-backup-migrate' ) );
				}
				$archive = $marker;
			} elseif ( false !== $marker && '' !== $marker ) {
				throw new \RuntimeException( __( 'The completed backup archive marker is invalid.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( false !== $archive && ! self::is_completed() ) {
				throw new \RuntimeException( __( 'The completed backup archive state is inconsistent.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( false === $archive && $receipt_matches && 'purging' === $receipt['status'] ) {
				$archive = self::purge_receipt_archive_path( $receipt, $storage );
			}
			if ( false === $archive && ! $receipt_matches ) {
				throw new \RuntimeException( __( 'No matching completed backup purge receipt is available.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( false !== $archive && file_exists( $archive ) ) {
				self::assert_purge_artifact_identity( $archive, $expected_sha256, $expected_bytes );
			} elseif ( false !== $archive && is_link( $archive ) ) {
				throw new \RuntimeException( __( 'The completed backup archive is a symbolic link.', 'simply-static-studio-backup-migrate' ) );
			} elseif ( false !== $archive && ! ( $receipt_matches && 'purging' === $receipt['status'] ) ) {
				throw new \RuntimeException( __( 'The completed backup archive is missing.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( false !== $archive ) {
				$filename = basename( $archive );
				$phase_receipt = array(
					'contract'       => self::PURGE_RECEIPT_CONTRACT,
					'schema_version' => 1,
					'status'         => 'purging',
					'sha256'         => $expected_sha256,
					'bytes'          => $expected_bytes,
					'filename'       => $filename,
					'path_hash'      => hash( 'sha256', wp_normalize_path( $archive ) ),
					'updated_at'     => time(),
				);
				self::write_purge_receipt( $phase_receipt );
				$receipt = $phase_receipt;
				$receipt_matches = true;
			}

			$status = false === $archive ? 'already_purged' : 'purged';
			$files = self::purge_sensitive_file_allowlist( $storage, $archive );
			foreach ( $files as $file ) {
				self::unlink_exact_sensitive_file( $file, $storage );
			}

			$current_marker = get_option( 'sssbm_archive_file_path', false );
			if ( false !== $archive && false !== $current_marker ) {
				if ( $current_marker !== $marker && wp_normalize_path( (string) $current_marker ) !== wp_normalize_path( $archive ) ) {
					throw new \RuntimeException( __( 'The completed backup generation changed during purge.', 'simply-static-studio-backup-migrate' ) );
				}
				if ( ! self::delete_exact_option_value( 'sssbm_archive_file_path', $current_marker ) ) {
					throw new \RuntimeException( __( 'The completed backup archive marker could not be cleared safely.', 'simply-static-studio-backup-migrate' ) );
				}
			}

			foreach ( array( 'sssbm_export_complete', 'sss_export_complete' ) as $completed_option ) {
				$completed_marker = get_option( $completed_option, false );
				if ( false !== $completed_marker && ! self::delete_exact_option_value( $completed_option, $completed_marker ) ) {
					throw new \RuntimeException( __( 'The completed backup state could not be cleared safely.', 'simply-static-studio-backup-migrate' ) );
				}
			}

			delete_option( self::BACKUP_INTEGRITY_ISSUES_OPTION );
			delete_option( self::COMPLETED_TASK_SEQUENCE_OPTION );
			delete_option( self::CONSUMED_GENERATION_OPTION );
			delete_option( self::DOWNLOAD_STARTED_OPTION );
			delete_option( self::LAST_RUN_OUTCOME_OPTION );
			delete_option( 'sssbm_task_sequence' );
			self::delete_export_params();
			delete_option( 'sssbm_media_bytes_offset' );
			delete_option( 'sssbm_current_task' );
			delete_option( 'sssbm_last_progress_at' );
			delete_option( 'sssbm_task_stall_state' );
			delete_option( 'sssbm_watchdog_state' );
			delete_option( 'sssbm_cancel_run_token' );
			delete_option( 'sssbm_paused_run_token' );
			delete_transient( 'sssbm_download_token' );

			$final_receipt = array(
				'contract'       => self::PURGE_RECEIPT_CONTRACT,
				'schema_version' => 1,
				'status'         => 'purged',
				'sha256'         => $expected_sha256,
				'bytes'          => $expected_bytes,
				'filename'       => $receipt_matches ? (string) ( $receipt['filename'] ?? '' ) : '',
				'path_hash'      => $receipt_matches ? (string) ( $receipt['path_hash'] ?? '' ) : '',
				'updated_at'     => time(),
			);
			self::write_purge_receipt( $final_receipt );

			return $status;
		} finally {
			self::release_operation_lock( $operation_token );
		}
	}

	/** @return array|false */
	private static function read_purge_receipt() {
		$receipt = get_option( self::PURGE_RECEIPT_OPTION, false );
		if ( false === $receipt ) {
			return false;
		}
		if (
			! is_array( $receipt ) ||
			self::PURGE_RECEIPT_CONTRACT !== ( $receipt['contract'] ?? '' ) ||
			1 !== (int) ( $receipt['schema_version'] ?? 0 ) ||
			! in_array( $receipt['status'] ?? '', array( 'purging', 'purged' ), true ) ||
			! is_string( $receipt['sha256'] ?? null ) ||
			! preg_match( '/^[a-f0-9]{64}$/D', $receipt['sha256'] ) ||
			! is_int( $receipt['bytes'] ?? null ) ||
			$receipt['bytes'] < 1 ||
			! is_string( $receipt['filename'] ?? null ) ||
			strlen( $receipt['filename'] ) > 255 ||
			! is_string( $receipt['path_hash'] ?? null ) ||
			( '' !== $receipt['path_hash'] && ! preg_match( '/^[a-f0-9]{64}$/D', $receipt['path_hash'] ) )
		) {
			throw new \RuntimeException( __( 'The backup purge receipt is invalid.', 'simply-static-studio-backup-migrate' ) );
		}

		return $receipt;
	}

	private static function purge_receipt_matches( $receipt, $sha256, $bytes ) {
		return is_array( $receipt )
			&& isset( $receipt['sha256'], $receipt['bytes'] )
			&& is_string( $receipt['sha256'] )
			&& hash_equals( $receipt['sha256'], $sha256 )
			&& (int) $receipt['bytes'] === $bytes;
	}

	private static function purge_receipt_archive_path( $receipt, $storage ) {
		$filename = (string) ( $receipt['filename'] ?? '' );
		if ( '' === $filename || basename( $filename ) !== $filename || 'zip' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			throw new \RuntimeException( __( 'The backup purge receipt archive is invalid.', 'simply-static-studio-backup-migrate' ) );
		}
		$path = $storage . $filename;
		if ( ! self::is_valid_archive_path( $path ) || ! hash_equals( (string) $receipt['path_hash'], hash( 'sha256', wp_normalize_path( $path ) ) ) ) {
			throw new \RuntimeException( __( 'The backup purge receipt path is invalid.', 'simply-static-studio-backup-migrate' ) );
		}

		return $path;
	}

	private static function write_purge_receipt( $receipt ) {
		if ( ! update_option( self::PURGE_RECEIPT_OPTION, $receipt, false ) && get_option( self::PURGE_RECEIPT_OPTION, false ) !== $receipt ) {
			throw new \RuntimeException( __( 'The backup purge receipt could not be persisted.', 'simply-static-studio-backup-migrate' ) );
		}
	}

	private static function assert_purge_artifact_identity( $path, $expected_sha256, $expected_bytes ) {
		if ( ! self::is_valid_archive_path( $path, true ) || is_link( $path ) ) {
			throw new \RuntimeException( __( 'The completed backup archive is unsafe.', 'simply-static-studio-backup-migrate' ) );
		}
		clearstatcache( true, $path );
		$before = @lstat( $path );
		$sha256 = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$after = @lstat( $path );
		$identity_keys = array( 'dev', 'ino', 'size', 'mtime', 'ctime' );
		if (
			! is_array( $before ) ||
			! is_array( $after ) ||
			! is_string( $sha256 ) ||
			(int) ( $before['nlink'] ?? 0 ) !== 1 ||
			(int) ( $after['nlink'] ?? 0 ) !== 1
		) {
			throw new \RuntimeException( __( 'The completed backup archive could not be fingerprinted.', 'simply-static-studio-backup-migrate' ) );
		}
		foreach ( $identity_keys as $key ) {
			if ( (string) ( $before[ $key ] ?? '' ) !== (string) ( $after[ $key ] ?? '' ) ) {
				throw new \RuntimeException( __( 'The completed backup archive changed during purge validation.', 'simply-static-studio-backup-migrate' ) );
			}
		}
		if ( (int) ( $after['size'] ?? 0 ) !== $expected_bytes || ! hash_equals( $expected_sha256, strtolower( $sha256 ) ) ) {
			throw new \RuntimeException( __( 'The completed backup archive does not match the uploaded artifact.', 'simply-static-studio-backup-migrate' ) );
		}
	}

	private static function purge_sensitive_file_allowlist( $storage, $archive ) {
		$database = $storage . 'db.sql';
		$files = array(
			$database,
			$database . '.redacting',
			$database . '.redacting-original',
			$database . '.redacting.lock',
			$database . '.database-file.lock',
			$storage . 'config.json',
			$storage . 'tables.list',
			$storage . 'tables.list.filtering',
			$storage . 'themes.list',
			$storage . 'plugins.list',
			$storage . 'content.list',
			$storage . 'media.list',
			$storage . 'sssbm-debug.txt',
		);
		if ( false !== $archive ) {
			array_unshift( $files, $archive, $archive . '.bak' );
		}

		return array_values( array_unique( $files ) );
	}

	private static function unlink_exact_sensitive_file( $path, $storage ) {
		if ( ! is_string( $path ) || dirname( wp_normalize_path( $path ) ) !== rtrim( wp_normalize_path( $storage ), '/' ) ) {
			throw new \RuntimeException( __( 'A sensitive backup cleanup path is unsafe.', 'simply-static-studio-backup-migrate' ) );
		}
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		$stat = @lstat( $path );
		if (
			is_link( $path ) ||
			! is_file( $path ) ||
			! is_array( $stat ) ||
			(int) ( $stat['nlink'] ?? 0 ) !== 1 ||
			! @unlink( $path )
		) {
			throw new \RuntimeException( __( 'A sensitive backup work file could not be removed safely.', 'simply-static-studio-backup-migrate' ) );
		}
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( __( 'A sensitive backup work file remained after purge.', 'simply-static-studio-backup-migrate' ) );
		}
	}

	/**
	 * Return the persisted, unguessable working-directory name.
	 *
	 * @return string
	 */
	private static function get_storage_directory_name() {
		$key = get_option( self::STORAGE_KEY_OPTION, '' );
		if ( is_string( $key ) && preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return 'backup-' . $key;
		}

		try {
			$key = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $exception ) {
			$key = md5( wp_generate_password( 64, true, true ) . microtime( true ) );
		}

		if ( ! add_option( self::STORAGE_KEY_OPTION, $key, '', 'no' ) ) {
			$stored_key = get_option( self::STORAGE_KEY_OPTION, '' );
			if ( is_string( $stored_key ) && preg_match( '/^[a-f0-9]{32}$/', $stored_key ) ) {
				$key = $stored_key;
			} else {
				update_option( self::STORAGE_KEY_OPTION, $key, false );
			}
		}

		return 'backup-' . $key;
	}

	/**
	 * Move predictable working files created by older versions into private storage.
	 *
	 * @param string $legacy_dir Previous shared directory.
	 * @param string $private_dir Current private directory.
	 * @return void
	 */
	private static function migrate_legacy_storage_files( $legacy_dir, $private_dir ) {
		static $migrated = array();

		$migration_key = wp_normalize_path( $legacy_dir ) . '=>' . wp_normalize_path( $private_dir );
		if ( isset( $migrated[ $migration_key ] ) || self::is_running() || ! is_dir( $legacy_dir ) || ! is_dir( $private_dir ) ) {
			return;
		}

		$files = array(
			'db.sql',
			'db.sql.redacting',
			'db.sql.redacting-original',
			'db.sql.redacting.lock',
			'db.sql.database-file.lock',
			'config.json',
			'tables.list',
			'tables.list.filtering',
			'themes.list',
			'plugins.list',
			'content.list',
			'media.list',
			'sssbm-debug.txt',
		);
		$legacy_archive_files = self::get_legacy_archive_filenames( $legacy_dir );
		$files = array_merge( $files, $legacy_archive_files );
		$current_archive = get_option( 'sssbm_archive_file_path', '' );
		if (
			is_string( $current_archive ) &&
			'' !== $current_archive &&
			wp_normalize_path( dirname( $current_archive ) ) === rtrim( wp_normalize_path( $legacy_dir ), '/' ) &&
			'zip' === strtolower( pathinfo( $current_archive, PATHINFO_EXTENSION ) )
		) {
			$files[] = basename( $current_archive );
			$files[] = basename( $current_archive ) . '.bak';
		}

		foreach ( array_unique( $files ) as $filename ) {
			$source               = $legacy_dir . $filename;
			$destination          = $private_dir . $filename;
			$is_archive_candidate = in_array( $filename, $legacy_archive_files, true );
			$is_current_archive   = is_string( $current_archive ) && wp_normalize_path( $source ) === wp_normalize_path( $current_archive );
			$is_current_backup    = is_string( $current_archive ) && wp_normalize_path( $source ) === wp_normalize_path( $current_archive . '.bak' );
			$expected_fingerprint = false;

			if ( is_link( $source ) ) {
				throw new \RuntimeException( __( 'A legacy backup storage artifact is unsafe.', 'simply-static-studio-backup-migrate' ) );
			}
			if ( ! is_file( $source ) ) {
				if ( $is_archive_candidate && file_exists( $source ) ) {
					throw new \RuntimeException( __( 'A legacy backup archive is not a regular file.', 'simply-static-studio-backup-migrate' ) );
				}
				continue;
			}
			$source_stat = @lstat( $source );
			if ( ! is_array( $source_stat ) || (int) ( $source_stat['nlink'] ?? 0 ) !== 1 ) {
				throw new \RuntimeException( __( 'A legacy backup storage artifact has an unsafe file identity.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( $is_archive_candidate && ! $is_current_archive && ! $is_current_backup ) {
				$quarantine = self::get_legacy_archive_quarantine_destination( $source, $private_dir );
				$destination = $quarantine['path'];
				$expected_fingerprint = $quarantine['fingerprint'];
			}
			if ( is_link( $destination ) || ( file_exists( $destination ) && ! is_file( $destination ) ) ) {
				throw new \RuntimeException( __( 'A managed backup storage destination is unsafe.', 'simply-static-studio-backup-migrate' ) );
			}

			if ( is_file( $destination ) ) {
				if (
					! self::migration_files_are_identical( $source, $destination ) ||
					( is_array( $expected_fingerprint ) && (
						! self::legacy_archive_fingerprint_matches( $source, $expected_fingerprint ) ||
						! self::legacy_archive_fingerprint_matches( $destination, $expected_fingerprint )
					) )
				) {
					throw new \RuntimeException( __( 'A legacy backup storage artifact conflicts with its managed destination.', 'simply-static-studio-backup-migrate' ) );
				}
				@chmod( $destination, 0600 );
				if ( get_option( 'sssbm_archive_file_path', '' ) === $source ) {
					if ( ! update_option( 'sssbm_archive_file_path', $destination, false ) && get_option( 'sssbm_archive_file_path', '' ) !== $destination ) {
						throw new \RuntimeException( __( 'The migrated backup archive marker could not be committed.', 'simply-static-studio-backup-migrate' ) );
					}
				}
				if ( ! @unlink( $source ) ) {
					throw new \RuntimeException( __( 'A migrated legacy backup artifact could not be removed.', 'simply-static-studio-backup-migrate' ) );
				}
				continue;
			}

			if ( is_array( $expected_fingerprint ) && ! self::legacy_archive_fingerprint_matches( $source, $expected_fingerprint ) ) {
				throw new \RuntimeException( __( 'A legacy backup archive changed during migration.', 'simply-static-studio-backup-migrate' ) );
			}

			$moved = @rename( $source, $destination );
			if ( $moved ) {
				if ( is_array( $expected_fingerprint ) && ! self::legacy_archive_fingerprint_matches( $destination, $expected_fingerprint ) ) {
					@rename( $destination, $source );
					throw new \RuntimeException( __( 'A legacy backup archive changed during migration.', 'simply-static-studio-backup-migrate' ) );
				}
			} else {
				if (
					! @copy( $source, $destination ) ||
					! self::migration_files_are_identical( $source, $destination ) ||
					( is_array( $expected_fingerprint ) && (
						! self::legacy_archive_fingerprint_matches( $source, $expected_fingerprint ) ||
						! self::legacy_archive_fingerprint_matches( $destination, $expected_fingerprint )
					) ) ||
					! @unlink( $source )
				) {
					@unlink( $destination );
					throw new \RuntimeException( __( 'A legacy backup storage artifact could not be migrated safely.', 'simply-static-studio-backup-migrate' ) );
				}
			}
			@chmod( $destination, 0600 );

			if ( get_option( 'sssbm_archive_file_path', '' ) === $source ) {
				if ( ! update_option( 'sssbm_archive_file_path', $destination, false ) && get_option( 'sssbm_archive_file_path', '' ) !== $destination ) {
					@rename( $destination, $source );
					throw new \RuntimeException( __( 'The migrated backup archive marker could not be committed.', 'simply-static-studio-backup-migrate' ) );
				}
			}
		}

		// Mark this directory only after every matching artifact was handled. A
		// caught migration exception must not turn a same-request retry into a
		// fail-open skip.
		$migrated[ $migration_key ] = true;
	}

	/**
	 * Return exact archive filenames immediately inside an older storage directory.
	 *
	 * Directory iteration is intentional here: managed upgrades must account for
	 * every old archive without using a wildcard as authority for a destructive
	 * operation. Matching links, hard links, and non-regular entries fail closed.
	 *
	 * @param string $legacy_dir Previous shared directory.
	 * @return string[]
	 */
	private static function get_legacy_archive_filenames( $legacy_dir ) {
		$filenames = array();

		try {
			$iterator = new \FilesystemIterator( $legacy_dir, \FilesystemIterator::SKIP_DOTS );
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException( __( 'Legacy backup storage could not be inspected safely.', 'simply-static-studio-backup-migrate' ), 0, $exception );
		}

		foreach ( $iterator as $entry ) {
			$filename = $entry->getFilename();
			if ( ! self::is_legacy_archive_filename( $filename ) ) {
				continue;
			}

			$path = $legacy_dir . $filename;
			clearstatcache( true, $path );
			$stat = @lstat( $path );
			if (
				is_link( $path ) ||
				! is_file( $path ) ||
				! is_array( $stat ) ||
				(int) ( $stat['nlink'] ?? 0 ) !== 1
			) {
				throw new \RuntimeException( __( 'A legacy backup archive has an unsafe file identity.', 'simply-static-studio-backup-migrate' ) );
			}

			$filenames[] = $filename;
		}

		sort( $filenames, SORT_STRING );

		return array_values( array_unique( $filenames ) );
	}

	private static function is_legacy_archive_filename( $filename ) {
		return is_string( $filename ) &&
			strlen( $filename ) <= 255 &&
			basename( $filename ) === $filename &&
			1 === preg_match( '/\Astudio-backup-.+\.zip(?:\.bak)?\z/D', $filename );
	}

	/**
	 * Build a deterministic private name for an unreferenced legacy generation.
	 *
	 * The source path participates in the token so equal filenames from the old
	 * shared root and its private child cannot overwrite one another.
	 *
	 * @param string $source Legacy archive path.
	 * @param string $private_dir Managed private directory.
	 * @return array{path:string,fingerprint:array{sha256:string,bytes:int}}
	 */
	private static function get_legacy_archive_quarantine_destination( $source, $private_dir ) {
		$fingerprint = self::fingerprint_legacy_archive( $source );
		$suffix = preg_match( '/\.zip\.bak\z/D', basename( $source ) ) ? '.zip.bak' : '.zip';
		$token = hash(
			'sha256',
			wp_normalize_path( $source ) . "\0" . $fingerprint['sha256'] . "\0" . $fingerprint['bytes']
		);

		return array(
			'path'        => $private_dir . 'studio-backup-legacy-' . $token . $suffix,
			'fingerprint' => $fingerprint,
		);
	}

	/**
	 * Fingerprint one stable, regular, single-link legacy archive.
	 *
	 * @param string $path Archive path.
	 * @return array{sha256:string,bytes:int}
	 */
	private static function fingerprint_legacy_archive( $path ) {
		clearstatcache( true, $path );
		$before = @lstat( $path );
		$sha256 = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$after = @lstat( $path );
		$identity_keys = array( 'dev', 'ino', 'size', 'mtime', 'ctime' );

		if (
			is_link( $path ) ||
			! is_file( $path ) ||
			! is_array( $before ) ||
			! is_array( $after ) ||
			! is_string( $sha256 ) ||
			! preg_match( '/^[a-f0-9]{64}$/D', strtolower( $sha256 ) ) ||
			(int) ( $before['nlink'] ?? 0 ) !== 1 ||
			(int) ( $after['nlink'] ?? 0 ) !== 1
		) {
			throw new \RuntimeException( __( 'A legacy backup archive could not be fingerprinted safely.', 'simply-static-studio-backup-migrate' ) );
		}

		foreach ( $identity_keys as $key ) {
			if ( (string) ( $before[ $key ] ?? '' ) !== (string) ( $after[ $key ] ?? '' ) ) {
				throw new \RuntimeException( __( 'A legacy backup archive changed while it was inspected.', 'simply-static-studio-backup-migrate' ) );
			}
		}

		return array(
			'sha256' => strtolower( $sha256 ),
			'bytes'  => (int) $after['size'],
		);
	}

	private static function legacy_archive_fingerprint_matches( $path, $expected ) {
		try {
			$actual = self::fingerprint_legacy_archive( $path );
		} catch ( \Throwable $exception ) {
			return false;
		}

		return is_array( $expected ) &&
			isset( $expected['sha256'], $expected['bytes'] ) &&
			is_string( $expected['sha256'] ) &&
			hash_equals( $expected['sha256'], $actual['sha256'] ) &&
			(int) $expected['bytes'] === $actual['bytes'];
	}

	private static function migration_files_are_identical( $source, $destination ) {
		clearstatcache( true, $source );
		clearstatcache( true, $destination );
		$source_stat = @lstat( $source );
		$destination_stat = @lstat( $destination );
		if (
			is_link( $source ) ||
			is_link( $destination ) ||
			! is_file( $source ) ||
			! is_file( $destination ) ||
			! is_array( $source_stat ) ||
			! is_array( $destination_stat ) ||
			(int) ( $source_stat['nlink'] ?? 0 ) !== 1 ||
			(int) ( $destination_stat['nlink'] ?? 0 ) !== 1 ||
			(int) ( $source_stat['size'] ?? -1 ) !== (int) ( $destination_stat['size'] ?? -2 )
		) {
			return false;
		}

		$source_hash = @hash_file( 'sha256', $source );
		$destination_hash = @hash_file( 'sha256', $destination );

		return is_string( $source_hash ) && is_string( $destination_hash ) && hash_equals( $source_hash, $destination_hash );
	}

	/**
	 * Add best-effort web-server rules for the directory containing sensitive dumps.
	 *
	 * Random archive names remain the cross-server fallback for hosts (notably
	 * nginx) that do not consume per-directory configuration files.
	 *
	 * @param string $directory Storage directory.
	 * @return void
	 */
	private static function protect_storage_directory( $directory ) {
		static $protected = array();

		if ( isset( $protected[ $directory ] ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return;
		}

		$files = array(
			'.htaccess' => "# Simply Static Backup and Migrate\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $filename => $contents ) {
			$path = $directory . $filename;
			$stat = @lstat( $path );
			if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || ( is_array( $stat ) && (int) ( $stat['nlink'] ?? 0 ) !== 1 ) ) {
				throw new \RuntimeException( __( 'The backup storage directory is unsafe.', 'simply-static-studio-backup-migrate' ) );
			}
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protection files require verified local paths, owner-only permissions and exclusive native locks.
			}
			if ( is_file( $path ) ) {
				@chmod( $path, 0600 );
			}
		}
		$protected[ $directory ] = true;
	}

	public static function get_database_path() {
		return self::get_storage_path() . 'db.sql';
	}

	public static function get_tables_list_path() {
		return self::get_storage_path() . 'tables.list';
	}

	public static function get_themes_list_path() {
		return self::get_storage_path() . 'themes.list';
	}

	public static function get_plugins_list_path() {
		return self::get_storage_path() . 'plugins.list';
	}

	public static function get_content_list_path() {
		return self::get_storage_path() . 'content.list';
	}

	public static function get_media_list_path() {
		return self::get_storage_path() . 'media.list';
	}

	public static function get_config_file_path() {
		return self::get_storage_path() . 'config.json';
	}

	public static function get_backup_url() {
		$existing = self::get_existing_backup_path();
		if ( $existing ) {
			$marker = get_option( self::STATIC_DOWNLOAD_OPTION, false );
			if (
				is_array( $marker ) &&
				is_string( $marker['public_path'] ?? null ) &&
				is_string( $marker['url'] ?? null ) &&
				wp_normalize_path( $marker['public_path'] ) === wp_normalize_path( $existing )
			) {
				return $marker['url'];
			}

			$storage_url = self::get_storage_url();

			return '' !== $storage_url ? $storage_url . basename( $existing ) : '';
		}

		return '';
	}

	public static function get_backup_path() {
		// Use a persistent archive path for the entire run to avoid split ZIP files.
		$existing = get_option( 'sssbm_archive_file_path', '' );
		$existing = self::maybe_migrate_legacy_archive( $existing );
		if ( self::is_valid_archive_path( $existing ) ) {
			return $existing;
		}

		if ( '' !== $existing ) {
			delete_option( 'sssbm_archive_file_path' );
		}

		$simply_static_dir = self::get_storage_path();
		$today        = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$default_path = self::generate_available_archive_path( $simply_static_dir, $today );
		$path         = apply_filters( 'sssbm_archive_file', $default_path, '' );

		// A deterministic filter must never select the retained predecessor (or
		// any pre-existing file), because the archive task intentionally truncates
		// its selected path before writing a new generation.
		if ( ! self::is_valid_archive_path( $path ) || ! self::archive_path_is_available( $path ) ) {
			$path = $default_path;
		}

		// add_option() makes concurrent first access choose one path atomically.
		if ( ! add_option( 'sssbm_archive_file_path', $path, '', 'no' ) ) {
			$winner = get_option( 'sssbm_archive_file_path', '' );
			if ( self::is_valid_archive_path( $winner ) ) {
				return $winner;
			}

			update_option( 'sssbm_archive_file_path', $path, false );
		}

		return $path;
	}

	/**
	 * Generate an unused randomized path for a new archive generation.
	 *
	 * @param string $directory Private storage directory.
	 * @param string $date Date component for the filename.
	 * @return string
	 */
	private static function generate_available_archive_path( $directory, $date ) {
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			try {
				$random = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				$random = md5( wp_generate_password( 64, true, true ) . microtime( true ) . $attempt );
			}

			$path = $directory . 'studio-backup-' . $date . '-' . $random . '.zip';
			if ( self::archive_path_is_available( $path ) ) {
				return $path;
			}
		}

		throw new \RuntimeException( __( 'A unique backup archive path could not be created safely.', 'simply-static-studio-backup-migrate' ) );
	}

	/**
	 * Whether a candidate is unused and distinct from the retained predecessor.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private static function archive_path_is_available( $path ) {
		if ( ! is_string( $path ) || file_exists( $path ) || is_link( $path ) ) {
			return false;
		}

		$previous = get_option( self::PREVIOUS_ARCHIVE_OPTION, '' );
		if ( is_string( $previous ) && '' !== $previous ) {
			return wp_normalize_path( $previous ) !== wp_normalize_path( $path );
		}

		return true;
	}

	/**
	 * Return an existing, readable archive path without creating a new marker.
	 *
	 * @return string|false
	 */
	public static function get_existing_backup_path() {
		$path = get_option( 'sssbm_archive_file_path', '' );
		$path = self::maybe_migrate_legacy_archive( $path );

		return self::is_valid_archive_path( $path, true ) ? $path : false;
	}

	/**
	 * Move an archive created by an older version out of the predictable shared root.
	 *
	 * @param mixed $path Persisted legacy path.
	 * @return mixed Migrated path when successful, otherwise the original value.
	 */
	private static function maybe_migrate_legacy_archive( $path ) {
		if ( self::is_running() || ! is_string( $path ) || '' === $path || ! is_file( $path ) || 'zip' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return $path;
		}

		$uploads_dir = wp_upload_dir();
		if (
			! is_array( $uploads_dir ) ||
			! is_string( $uploads_dir['basedir'] ?? null ) ||
			! self::is_local_filesystem_path( $uploads_dir['basedir'] )
		) {
			return $path;
		}
		$legacy_root = trailingslashit( $uploads_dir['basedir'] ) . 'simply-static';
		$real_parent = realpath( dirname( $path ) );
		$real_legacy = realpath( $legacy_root );

		if ( false === $real_parent || false === $real_legacy || wp_normalize_path( $real_parent ) !== wp_normalize_path( $real_legacy ) ) {
			return $path;
		}

		$destination = self::get_storage_path() . basename( $path );
		$current_path = get_option( 'sssbm_archive_file_path', '' );
		if ( is_string( $current_path ) && $current_path !== $path && is_file( $current_path ) ) {
			return $current_path;
		}
		if ( is_file( $destination ) ) {
			@chmod( $destination, 0600 );
			update_option( 'sssbm_archive_file_path', $destination, false );
			@unlink( $path );

			return $destination;
		}
		if ( ! @rename( $path, $destination ) && ! ( @copy( $path, $destination ) && @unlink( $path ) ) ) {
			return $path;
		}
		@chmod( $destination, 0600 );

		update_option( 'sssbm_archive_file_path', $destination, false );

		return $destination;
	}

	/**
	 * Validate that an archive path is either private or the exact active capability.
	 *
	 * @param mixed $path Candidate path.
	 * @param bool  $must_exist Whether the archive must already exist.
	 * @return bool
	 */
	public static function is_valid_archive_path( $path, $must_exist = false ) {
		return self::is_valid_private_archive_path( $path, $must_exist )
			|| self::is_valid_static_archive_path( $path, $must_exist );
	}

	/**
	 * Validate one ZIP path below this site's protected working directory.
	 *
	 * @param mixed $path Candidate path.
	 * @param bool  $must_exist Whether the archive must already exist.
	 * @return bool
	 */
	private static function is_valid_private_archive_path( $path, $must_exist = false ) {
		if ( ! is_string( $path ) || '' === $path || 'zip' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}

		// realpath() returns false for a dangling symlink, which would otherwise
		// make Helper::is_path_within() validate the link name as a harmless
		// non-existent child. Archive paths must always name a real leaf, never a
		// link whose target may be created outside private storage later.
		clearstatcache( true, $path );
		if ( is_link( $path ) ) {
			return false;
		}

		if ( ! Helper::is_path_within( $path, self::get_storage_path() ) ) {
			return false;
		}

		return ! $must_exist || ( is_file( $path ) && is_readable( $path ) );
	}

	/**
	 * Validate only the exact public path committed by an active move marker.
	 *
	 * @param mixed $path Candidate path.
	 * @param bool  $must_exist Whether the archive must already exist.
	 * @return bool
	 */
	private static function is_valid_static_archive_path( $path, $must_exist = false ) {
		$marker = get_option( self::STATIC_DOWNLOAD_OPTION, false );
		if (
			! is_string( $path ) ||
			'' === $path ||
			! is_array( $marker ) ||
			2 !== (int) ( $marker['version'] ?? 0 ) ||
			'move' !== ( $marker['mode'] ?? '' ) ||
			'active' !== ( $marker['state'] ?? '' ) ||
			! is_string( $marker['id'] ?? null ) ||
			! preg_match( '/^[a-f0-9]{32}$/D', $marker['id'] ) ||
			! is_string( $marker['source'] ?? null ) ||
			! self::is_valid_private_archive_path( $marker['source'] ) ||
			! is_string( $marker['public_path'] ?? null ) ||
			wp_normalize_path( $path ) !== wp_normalize_path( $marker['public_path'] ) ||
			wp_normalize_path( (string) get_option( 'sssbm_archive_file_path', '' ) ) !== wp_normalize_path( $marker['public_path'] ) ||
			! is_string( $marker['url'] ?? null ) ||
			! is_int( $marker['expires'] ?? null ) ||
			basename( $marker['source'] ) !== basename( $marker['public_path'] ) ||
			! self::static_download_filename_is_safe( basename( $marker['public_path'] ) ) ||
			! self::static_download_directory_is_safe( dirname( $marker['public_path'] ) . DIRECTORY_SEPARATOR, $marker['id'] ) ||
			file_exists( $marker['source'] ) ||
			is_link( $marker['source'] )
		) {
			return false;
		}

		$url_root = self::get_static_download_url_root();
		$expected_url = $url_root . 'download-' . $marker['id'] . '/' . rawurlencode( basename( $marker['public_path'] ) );
		if ( '' === $url_root || ! hash_equals( $expected_url, $marker['url'] ) ) {
			return false;
		}

		clearstatcache( true, $path );
		if ( is_link( $path ) ) {
			return false;
		}
		if ( ! $must_exist ) {
			return true;
		}

		$stat = @lstat( $path );

		return
			is_file( $path ) &&
			is_readable( $path ) &&
			is_array( $stat ) &&
			(int) ( $stat['nlink'] ?? 0 ) === 1;
	}

	public static function should_include_database() {
		if ( defined( 'STATIC_STUDIO_MANAGED_RUNTIME' ) && true === STATIC_STUDIO_MANAGED_RUNTIME ) {
			return true;
		}

		$settings = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$settings = is_array( $settings ) ? $settings : [];

		return self::setting_is_enabled( $settings, 'include_database', true );
	}

	public static function clear_backup_integrity_issues() {
		delete_option( self::BACKUP_INTEGRITY_ISSUES_OPTION );
	}

	public static function add_backup_integrity_issue( $code, $message, $blocks_studio_push = true ) {
		$code   = sanitize_key( $code );
		if ( '' === $code || ! is_scalar( $message ) ) {
			return;
		}
		$message = sanitize_text_field( (string) $message );
		$issues = self::get_backup_integrity_issues();

		$issues[ $code ] = [
			'code'               => $code,
			'message'            => $message,
			'blocks_studio_push' => (bool) $blocks_studio_push,
			'time'               => time(),
		];

		update_option( self::BACKUP_INTEGRITY_ISSUES_OPTION, $issues, false );
	}

	public static function get_backup_integrity_issues() {
		$issues = get_option( self::BACKUP_INTEGRITY_ISSUES_OPTION, [] );

		if ( ! is_array( $issues ) ) {
			return [];
		}

		return array_filter( $issues, function ( $issue ) {
			return is_array( $issue ) && ! empty( $issue['code'] ) && isset( $issue['message'] ) && is_scalar( $issue['message'] );
		} );
	}

	public static function get_missing_database_message() {
		if ( self::generation_includes_database() ) {
			return __( 'Backup completed, but the database dump is missing. Push to Studio has been disabled for this backup. Create a new backup and make sure Include Database is enabled before pushing to Studio.', 'simply-static-studio-backup-migrate' );
		}

		return __( 'This backup does not include a database. Push to Studio has been disabled for this backup. Enable Include Database and create a new backup before pushing to Studio.', 'simply-static-studio-backup-migrate' );
	}

	public static function archive_contains_file( $archive_path, $relative_path ) {
		if ( ! is_string( $archive_path ) || $archive_path === '' || ! file_exists( $archive_path ) ) {
			return false;
		}

		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		if ( '' === $relative_path || in_array( '..', explode( '/', $relative_path ), true ) ) {
			return false;
		}

		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $archive_path ) ) {
				$contains = false !== $zip->locateName( $relative_path );
				$zip->close();

				return $contains;
			}
		}

		if ( ! class_exists( '\PclZip' ) && defined( 'ABSPATH' ) ) {
			require_once Helper::get_wordpress_path() . 'wp-admin/includes/class-pclzip.php';
		}

		if ( class_exists( '\PclZip' ) ) {
			$archive = new \PclZip( $archive_path );
			$list    = $archive->listContent();

			if ( is_array( $list ) ) {
				foreach ( $list as $entry ) {
					if ( isset( $entry['filename'] ) && ltrim( str_replace( '\\', '/', $entry['filename'] ), '/' ) === $relative_path ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	public static function get_backup_integrity_status() {
		$issues       = self::get_backup_integrity_issues();
		$archive_path = self::get_existing_backup_path();
		$consumed     = self::is_completed_generation_consumed();

		if ( self::is_completed() ) {
			if ( ! $archive_path ) {
				$issues['archive_missing'] = array(
					'code'               => 'archive_missing',
					'message'            => __( 'The completed backup archive is missing or unreadable. Create a new backup before pushing to Studio.', 'simply-static-studio-backup-migrate' ),
					'blocks_studio_push' => true,
					'time'               => time(),
				);
			} else {
				if ( ! self::archive_contains_file( $archive_path, 'config.json' ) ) {
					$issues['configuration_missing'] = array(
						'code'               => 'configuration_missing',
						'message'            => __( 'The backup configuration is missing from the archive. Create a new backup before pushing to Studio.', 'simply-static-studio-backup-migrate' ),
						'blocks_studio_push' => true,
						'time'               => time(),
					);
				}

				if ( ! self::archive_contains_file( $archive_path, 'db.sql' ) ) {
					$code = self::generation_includes_database() ? 'database_missing' : 'database_excluded';

					if ( ! isset( $issues[ $code ] ) ) {
						$issues[ $code ] = array(
							'code'               => $code,
							'message'            => self::get_missing_database_message(),
							'blocks_studio_push' => true,
							'time'               => time(),
						);
					}
				}
			}
		}

		$blocking_issues = array_filter( $issues, function ( $issue ) {
			return ! empty( $issue['blocks_studio_push'] );
		} );
		$warning         = '';

		if ( ! empty( $blocking_issues ) ) {
			$first_issue = reset( $blocking_issues );
			$warning     = $first_issue['message'] ?? '';
		}

		return [
			'issues'             => array_values( $issues ),
			'has_issues'         => ! empty( $issues ),
			// Consumption is a successful lifecycle transition, not archive damage.
			// Keep it out of `issues` while still preventing reuse of the generation.
			'is_pushable'        => empty( $blocking_issues ) && ! $consumed,
			'can_push_to_studio' => empty( $blocking_issues ) && ! $consumed,
			'warning'            => $warning,
		];
	}

	public static function start_export() {
		$process = self::get_process();
		$run_token = false;

		// The absent-option add is atomic in wp_options and closes the double-click race.
		if ( ! self::acquire_run_lock( $run_token ) ) {
			Logger::log( 'Backup already running.' );

			return false;
		}

		try {
			$task_sequence = self::prepare_export_state( $run_token );
			$first_task = isset( $task_sequence[0] ) ? $task_sequence[0] : null;

			if ( ! is_string( $first_task ) || '' === $first_task ) {
				throw new \RuntimeException( __( 'No backup task is available.', 'simply-static-studio-backup-migrate' ) );
			}

			$process->push_to_queue( self::make_queue_item( $first_task, $run_token ) );
			$dispatch_result = $process->save()->dispatch();
			$dispatch_failed = is_wp_error( $dispatch_result )
				|| ( false === $dispatch_result && ! $process->is_processing() )
				|| ( false !== $dispatch_result && ! is_array( $dispatch_result ) );

			if ( $dispatch_failed ) {
				$process->discard_queue();
				self::abort_export( $run_token );
				Status::error( __( 'Backup could not start because the loopback request failed.', 'simply-static-studio-backup-migrate' ) );
				$dispatch_message = is_wp_error( $dispatch_result )
					? $dispatch_result->get_error_message()
					: __( 'WordPress did not accept the background request.', 'simply-static-studio-backup-migrate' );

				return new \WP_Error(
					'backup_dispatch_failed',
					/* translators: %s: Backup file path or underlying request failure. */
					sprintf( __( 'Backup loopback request failed: %s', 'simply-static-studio-backup-migrate' ), $dispatch_message ),
					array( 'status' => 502 )
				);
			}
		} catch ( \Throwable $exception ) {
			$process->discard_queue();
			self::abort_export( $run_token );
			throw $exception;
		}

		return true;
	}

	/**
	 * Initialize a claimed run without deleting its retained predecessor.
	 *
	 * Callers must own the run/operation lock before invoking this method.
	 *
	 * @return string[] Frozen task sequence for this run.
	 */
	public static function prepare_export_state( $run_token = '' ) {
		if ( ! self::owns_run_lock( $run_token ) ) {
			throw new \RuntimeException( __( 'The backup run is no longer owned by this request.', 'simply-static-studio-backup-migrate' ) );
		}
		if ( ! self::revoke_static_download() ) {
			throw new \RuntimeException( __( 'The previous public backup download could not be revoked safely.', 'simply-static-studio-backup-migrate' ) );
		}

		Logger::clearLog();
		self::preserve_previous_completed_archive();
		self::set_as_not_completed();
		self::delete_export_params();
		self::clear_backup_integrity_issues();
		$task_sequence = self::build_task_sequence_from_settings();
		self::reset_task_retry_counts( $task_sequence );
		update_option( 'sssbm_task_sequence', $task_sequence, false );
		delete_option( 'sssbm_archive_file_path' );
		delete_option( 'sssbm_media_bytes_offset' );
		delete_option( 'sssbm_current_task' );
		delete_option( 'sssbm_task_stall_state' );
		delete_option( 'sssbm_watchdog_state' );
		delete_option( 'sssbm_cancel_run_token' );
		delete_option( 'sssbm_paused_run_token' );
		delete_transient( 'sssbm_download_token' );
		update_option( 'sssbm_last_progress_at', time(), false );
		if ( ! self::set_as_running( $run_token ) ) {
			throw new \RuntimeException( __( 'The backup run ownership changed during initialization.', 'simply-static-studio-backup-migrate' ) );
		}
		if ( ! self::set_run_outcome( 'running', $run_token ) ) {
			throw new \RuntimeException( __( 'The backup lifecycle state could not be initialized safely.', 'simply-static-studio-backup-migrate' ) );
		}

		return $task_sequence;
	}

	/**
	 * Retain the last completed generation until its replacement succeeds.
	 *
	 * @return void
	 */
	private static function preserve_previous_completed_archive() {
		$archive_path = self::is_completed() ? self::get_existing_backup_path() : false;

		if ( $archive_path ) {
			update_option( self::PREVIOUS_ARCHIVE_OPTION, $archive_path, false );
			update_option( self::PREVIOUS_INTEGRITY_OPTION, self::get_backup_integrity_issues(), false );
			$completed_sequence = get_option( self::COMPLETED_TASK_SEQUENCE_OPTION, array() );
			if ( self::is_valid_task_sequence( $completed_sequence ) ) {
				update_option( self::PREVIOUS_TASK_SEQUENCE_OPTION, array_values( $completed_sequence ), false );
			} else {
				delete_option( self::PREVIOUS_TASK_SEQUENCE_OPTION );
			}

			return;
		}

		$previous = get_option( self::PREVIOUS_ARCHIVE_OPTION, '' );
		if ( ! self::is_valid_archive_path( $previous, true ) ) {
			delete_option( self::PREVIOUS_ARCHIVE_OPTION );
			delete_option( self::PREVIOUS_INTEGRITY_OPTION );
			delete_option( self::PREVIOUS_TASK_SEQUENCE_OPTION );
		}
	}

	/**
	 * Restore the retained completed generation after a failed or cancelled run.
	 *
	 * @return void
	 */
	public static function abort_export( $run_token = '', $outcome = 'failed' ) {
		if ( ! self::may_mutate_run( $run_token ) ) {
			return false;
		}
		$outcome = 'cancelled' === $outcome ? 'cancelled' : 'failed';

		$previous          = get_option( self::PREVIOUS_ARCHIVE_OPTION, '' );
		$previous_issues   = get_option( self::PREVIOUS_INTEGRITY_OPTION, array() );
		$previous_sequence = get_option( self::PREVIOUS_TASK_SEQUENCE_OPTION, array() );
		$previous_is_valid = self::is_valid_archive_path( $previous, true );

		self::clear_all_export_files( true, true );
		if ( ! self::may_mutate_run( $run_token ) ) {
			return false;
		}
		self::set_as_not_completed();

		if ( $previous_is_valid ) {
			update_option( 'sssbm_archive_file_path', $previous, false );
			self::set_as_completed();
			if ( self::is_valid_task_sequence( $previous_sequence ) ) {
				update_option( self::COMPLETED_TASK_SEQUENCE_OPTION, array_values( $previous_sequence ), false );
			} else {
				delete_option( self::COMPLETED_TASK_SEQUENCE_OPTION );
			}
			if ( is_array( $previous_issues ) && ! empty( $previous_issues ) ) {
				update_option( self::BACKUP_INTEGRITY_ISSUES_OPTION, $previous_issues, false );
			}
		} else {
			delete_option( self::COMPLETED_TASK_SEQUENCE_OPTION );
		}

		delete_option( self::PREVIOUS_ARCHIVE_OPTION );
		delete_option( self::PREVIOUS_INTEGRITY_OPTION );
		delete_option( self::PREVIOUS_TASK_SEQUENCE_OPTION );
		$outcome_saved = self::set_run_outcome( $outcome, $run_token );
		$released      = self::set_as_not_running( $run_token );

		return $outcome_saved && $released;
	}

	/**
	 * Promote the new generation and remove the retained predecessor.
	 *
	 * @return bool Whether the new archive passed final consistency validation.
	 */
	public static function finalize_export( $run_token = '' ) {
		if ( ! self::may_mutate_run( $run_token ) ) {
			return false;
		}

		$task_sequence = get_option( 'sssbm_task_sequence', array() );
		if ( ! self::is_valid_task_sequence( $task_sequence ) ) {
			self::abort_export( $run_token );

			return false;
		}

		$current  = self::get_existing_backup_path();
		$previous = get_option( self::PREVIOUS_ARCHIVE_OPTION, '' );

		if ( ! $current || ! self::archive_is_consistent( $current ) ) {
			self::abort_export( $run_token );

			return false;
		}

		if ( ! self::may_mutate_run( $run_token ) ) {
			return false;
		}

		$completed_sequence = array_values( $task_sequence );
		update_option( self::COMPLETED_TASK_SEQUENCE_OPTION, $completed_sequence, false );
		if ( get_option( self::COMPLETED_TASK_SEQUENCE_OPTION, false ) !== $completed_sequence ) {
			self::abort_export( $run_token );

			return false;
		}
		if ( ! self::set_run_outcome( 'completed', $run_token ) ) {
			self::abort_export( $run_token );

			return false;
		}
		self::set_as_completed();
		if ( ! self::is_completed() ) {
			self::abort_export( $run_token );

			return false;
		}

		if (
			self::is_valid_archive_path( $previous, true ) &&
			wp_normalize_path( $previous ) !== wp_normalize_path( $current )
		) {
			@unlink( $previous );
		}

		delete_option( self::PREVIOUS_ARCHIVE_OPTION );
		delete_option( self::PREVIOUS_INTEGRITY_OPTION );
		delete_option( self::PREVIOUS_TASK_SEQUENCE_OPTION );

		return true;
	}

	/**
	 * Whether the generation being reported was configured with a database task.
	 *
	 * Completed backups use their promoted frozen sequence; active workers use the
	 * current frozen sequence. Only legacy generations fall back to live settings.
	 *
	 * @return bool
	 */
	private static function generation_includes_database() {
		$sequence = self::is_completed()
			? get_option( self::COMPLETED_TASK_SEQUENCE_OPTION, array() )
			: get_option( 'sssbm_task_sequence', array() );

		if ( self::is_valid_task_sequence( $sequence ) ) {
			return in_array( 'database_file', $sequence, true );
		}

		return self::should_include_database();
	}

	/**
	 * Perform a one-time final central-directory/header consistency check.
	 *
	 * Per-batch opens skip expensive checks for very large archives. Running the
	 * full structural check once at promotion keeps polling inexpensive while
	 * preventing a corrupt generation from replacing the last known-good backup.
	 *
	 * @param string $archive_path Completed archive path.
	 * @return bool
	 */
	private static function archive_is_consistent( $archive_path ) {
		if ( ! self::is_valid_archive_path( $archive_path, true ) ) {
			return false;
		}

		if ( class_exists( '\ZipArchive' ) ) {
			$zip    = new \ZipArchive();
			$result = $zip->open( $archive_path, \ZipArchive::CHECKCONS );
			if ( true !== $result ) {
				return false;
			}

			return $zip->close();
		}

		if ( ! class_exists( '\PclZip' ) && defined( 'ABSPATH' ) ) {
			require_once Helper::get_wordpress_path() . 'wp-admin/includes/class-pclzip.php';
		}

		if ( class_exists( '\PclZip' ) ) {
			$archive = new \PclZip( $archive_path );

			return 0 !== $archive->properties() && is_array( $archive->listContent() );
		}

		return false;
	}

	/**
	 * Clear retry counters left by an interrupted or failed previous run.
	 *
	 * @return void
	 */
	public static function reset_task_retry_counts( $tasks = null ) {
		$tasks = is_array( $tasks ) ? $tasks : self::get_tasks();
		foreach ( $tasks as $task_name ) {
			$class_name = '\\Simply_Static\\Backup\\Queue\\Tasks\\' . ucfirst( $task_name );
			if ( class_exists( $class_name ) ) {
				$task = new $class_name();
				$task->resetRetryCount();
			}
		}
	}

	public static function delete_export_params() {
		delete_option( 'sssbm_export_params' );
	}

	public static function get_export_params() {
		$params = get_option( 'sssbm_export_params', [] );

		return is_array( $params ) ? $params : [];
	}

	public static function save_export_params( $params ) {
		if ( is_array( $params ) ) {
			update_option( 'sssbm_export_params', $params, false );
		}
	}

	public static function get_export_param( $key, $default = null ) {
		$params = self::get_export_params();

		return isset( $params[ $key ] ) ? $params[ $key ] : $default;
	}

	public static function save_export_param( $key, $value ) {
		$params         = self::get_export_params();
		$params[ $key ] = $value;
		self::save_export_params( $params );
	}

	public static function clear_all_export_files( $preserve_previous_archive = false, $preserve_debug_log = false ) {
		if ( ! self::revoke_static_download() ) {
			throw new \RuntimeException( __( 'The public backup download could not be revoked safely.', 'simply-static-studio-backup-migrate' ) );
		}

		$dir = self::get_storage_path();
		$archive_path = self::get_existing_backup_path();
		$previous_archive = $preserve_previous_archive ? get_option( self::PREVIOUS_ARCHIVE_OPTION, '' ) : '';
		$previous_archive = self::is_valid_archive_path( $previous_archive, true ) ? wp_normalize_path( $previous_archive ) : '';

		// Collect known single-file paths
		$files = [
			self::get_media_list_path(),
			self::get_config_file_path(),
			self::get_content_list_path(),
			self::get_plugins_list_path(),
			self::get_themes_list_path(),
			self::get_database_path(),
			self::get_tables_list_path(),
			self::get_tables_list_path() . '.filtering',
			$preserve_debug_log ? false : Logger::getFilename(),
			$archive_path,
			self::get_database_path() . '.redacting',
			self::get_database_path() . '.redacting-original',
			self::get_database_path() . '.redacting.lock',
			self::get_database_path() . '.database-file.lock',
		];

		// Add all matching ZIP archives generated by this plugin
		$zip_files = glob( $dir . 'studio-backup-*.zip' );
		if ( is_array( $zip_files ) ) {
			$files = array_merge( $files, $zip_files );
		}

		$zip_backups = glob( $dir . 'studio-backup-*.zip.bak' );
		if ( is_array( $zip_backups ) ) {
			$files = array_merge( $files, $zip_backups );
		}

		// Add any list files just in case (future-proofing)
		$list_files = glob( $dir . '*.list' );
		if ( is_array( $list_files ) ) {
			$files = array_merge( $files, $list_files );
		}

		// Deduplicate
		$files = array_unique( array_filter( $files ) );

		foreach ( $files as $file ) {
			if ( '' !== $previous_archive && is_string( $file ) && wp_normalize_path( $file ) === $previous_archive ) {
				continue;
			}

			if ( is_string( $file ) && Helper::is_path_within( $file, $dir ) && is_file( $file ) ) {
				@unlink( $file );
			}
		}

		// Also clear the persisted archive path marker
		delete_option( 'sssbm_archive_file_path' );
		// Clear persisted media offset
		delete_option( 'sssbm_media_bytes_offset' );
		delete_option( 'sssbm_last_progress_at' );
		delete_option( 'sssbm_task_stall_state' );
		delete_option( 'sssbm_watchdog_state' );
		self::clear_backup_integrity_issues();

		if ( ! $preserve_previous_archive ) {
			delete_option( self::PREVIOUS_ARCHIVE_OPTION );
			delete_option( self::PREVIOUS_INTEGRITY_OPTION );
			delete_option( self::PREVIOUS_TASK_SEQUENCE_OPTION );
			delete_option( self::COMPLETED_TASK_SEQUENCE_OPTION );
			delete_option( self::CONSUMED_GENERATION_OPTION );
			delete_option( self::DOWNLOAD_STARTED_OPTION );
		}
	}
}
