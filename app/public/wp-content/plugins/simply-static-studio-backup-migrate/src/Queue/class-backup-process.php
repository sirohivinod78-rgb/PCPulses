<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Worker ownership requires native file handles held under flock for the entire request; WP_Filesystem cannot provide these lock semantics.

namespace Simply_Static\Backup\Queue;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Plugin;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Queue\Exceptions\Retry_Exception;
use Simply_Static\Backup\Queue\Exceptions\Skippable_Exception;
use Simply_Static\Backup\Runtime_Limits;
use Simply_Static\Backup\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup_Process extends Background_Process {

	/**
	 * @var string
	 */
	protected $prefix = 'simply_static';

	/**
	 * @var string
	 */
	protected $action = 'backup_process';

	/**
	 * Long ZIP commits can exceed the base library's one-minute lock.
	 *
	 * @var int
	 */
	protected $queue_lock_time = 1800;

	/**
	 * Queue items are scalar arrays; PHP objects must never be unserialized.
	 *
	 * @var bool
	 */
	protected $allowed_batch_data_classes = false;

	/**
	 * Run token observed on the item currently handled by this request.
	 *
	 * @var string
	 */
	private $active_run_token = '';

	/**
	 * OS-held lock for the complete lifetime of a worker request.
	 *
	 * The option lease remains useful across hosts, while this persistent inode
	 * prevents a cron/loopback request on the same host from overlapping a task
	 * merely because its 30-minute lease expired.
	 *
	 * @var resource|null
	 */
	private $stable_process_lock_handle = null;

	/**
	 * Whether this request should hand an incomplete long task to a fresh worker.
	 *
	 * @var bool
	 */
	private $yield_after_task = false;

	/**
	 * Constructor - set up iteration delay filter.
	 */
	public function __construct() {
		if ( is_multisite() ) {
			$this->prefix .= '_' . get_current_blog_id();
		}

		parent::__construct();

		// Add filter for iteration delay (seconds between batches/iterations)
		add_filter( $this->identifier . '_seconds_between_batches', array( $this, 'get_iteration_delay' ) );
	}

	/**
	 * Get the iteration delay from settings.
	 * Disabled delay controls return zero and never occupy a PHP worker sleeping.
	 *
	 * @param int $seconds Default seconds.
	 * @return int
	 */
	public function get_iteration_delay( $seconds ) {
		$settings = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$settings = is_array( $settings ) ? $settings : [];
		if ( ! $this->delays_are_enabled( $settings ) ) {
			return 0;
		}

		return Runtime_Limits::bounded_int( isset( $settings['iteration_delay'] ) ? $settings['iteration_delay'] : 1, 1, 1, 60 );
	}

	/**
	 * Get the task delay from settings.
	 * Minimum 5 seconds to prevent crashing slow-performing shared hosting environments.
	 *
	 * @return int
	 */
	protected function get_task_delay() {
		$settings = Plugin::get_migrated_option( 'sssbm_backup_migrate', [] );
		$settings = is_array( $settings ) ? $settings : [];
		if ( ! $this->delays_are_enabled( $settings ) ) {
			return 0;
		}

		return Runtime_Limits::bounded_int( isset( $settings['task_delay'] ) ? $settings['task_delay'] : 5, 5, 5, 300 );
	}

	/**
	 * Whether the optional hosting-throttle controls are enabled.
	 *
	 * @param array $settings Stored plugin settings.
	 * @return bool
	 */
	private function delays_are_enabled( $settings ) {
		return isset( $settings['batch_delay_enabled'] )
			&& in_array( $settings['batch_delay_enabled'], array( true, 1, '1', 'on', 'true' ), true );
	}

	/**
	 * Acquire both the persistent local worker lock and the database lease.
	 *
	 * @param bool $reset_start_time Whether to reset the request timer.
	 * @return bool
	 */
	public function lock_process( $reset_start_time = true ) {
		if ( ! $this->acquire_stable_process_lock() ) {
			return false;
		}

		if ( parent::lock_process( $reset_start_time ) ) {
			return true;
		}

		$this->release_stable_process_lock();

		return false;
	}

	/**
	 * Wait briefly for a worker that won the CLI startup race to release its lock.
	 *
	 * acquire_run_lock() checks the queue before the synchronous CLI worker takes
	 * the process lock. A background request can enter that narrow gap and own the
	 * lock for one final bounded slice. Retrying the ordinary atomic lock protocol
	 * lets that slice finish without weakening either the filesystem or database
	 * ownership fences.
	 *
	 * @param int $wait_seconds Maximum number of seconds to wait.
	 * @return bool
	 */
	public function lock_process_for_cli( $wait_seconds = 30 ) {
		$wait_seconds = Runtime_Limits::bounded_int( $wait_seconds, 30, 0, 300 );
		$retry_microseconds = Runtime_Limits::bounded_int(
			apply_filters( $this->identifier . '_cli_lock_retry_microseconds', 250000 ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			250000,
			10000,
			1000000
		);
		$deadline = microtime( true ) + $wait_seconds;

		do {
			if ( $this->lock_process( false ) ) {
				return true;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}

			usleep( $retry_microseconds );
		} while ( true );
	}

	/**
	 * Report a live local worker even after its database lease has expired.
	 *
	 * @return bool
	 */
	public function is_processing() {
		if ( is_resource( $this->stable_process_lock_handle ) ) {
			return true;
		}

		$path   = $this->get_stable_process_lock_path();
		$handle = Helper::open_lock_file( $path, Backup::get_storage_path() );
		if ( false === $handle ) {
			// A missing ability to verify exclusivity must fail closed.
			return true;
		}

		$available = @flock( $handle, LOCK_EX | LOCK_NB );
		if ( $available ) {
			@flock( $handle, LOCK_UN );
		}
		@fclose( $handle );

		return ! $available || parent::is_processing();
	}

	/**
	 * Release only the file description owned by this worker.
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		parent::unlock_process();
		$this->release_stable_process_lock();

		return $this;
	}

	/**
	 * Release a worker lock explicitly held by the synchronous CLI lifecycle.
	 *
	 * @return $this
	 */
	public function release_process_lock() {
		return $this->unlock_process();
	}

	/**
	 * Process one bounded queue slice inside an authenticated REST request.
	 *
	 * This is an opt-in fallback for hosts that block WordPress loopback HTTP
	 * requests. The normal process locks still arbitrate ownership, so an already
	 * running background worker always wins and this request becomes a no-op.
	 *
	 * @return bool Whether this request attempted to handle the queue.
	 */
	public function handle_browser_worker_request() {
		if ( ! $this->is_queued() || $this->is_paused() || $this->is_cancelled() || $this->is_processing() ) {
			return false;
		}

		$disable_wp_die = static function () {
			return false;
		};

		$this->suppress_auto_dispatch = true;
		add_filter( $this->identifier . '_wp_die', $disable_wp_die );

		try {
			$this->handle();
		} catch ( \Throwable $exception ) {
			// Ensure an unexpected REST-request failure cannot strand a lock owned
			// by this process object for the remainder of the request.
			$this->unlock_process();
			throw $exception;
		} finally {
			remove_filter( $this->identifier . '_wp_die', $disable_wp_die );
			$this->suppress_auto_dispatch = false;
		}

		return true;
	}

	/**
	 * Reclaim an abandoned queue written before generation tokens existed.
	 *
	 * Imported databases can contain a truthy running flag and scalar task batch
	 * even though the worker that created them cannot exist on the new host. The
	 * queue must be removed before acquire_run_lock() can fence a new generation,
	 * but only after both current worker locks and the exact operation lease are
	 * held. Tokenized, malformed, recently progressing, or otherwise ambiguous
	 * state always fails closed.
	 *
	 * @param string $operation_token Exact backup operation lease token.
	 * @param int    $stale_after Seconds without progress before a started queue is stale.
	 * @param bool   $allow_status_evidence Whether old, validated status messages may date a missing progress marker.
	 * @return bool Whether an exclusively legacy queue was reclaimed.
	 */
	public function reclaim_stale_legacy_queue( $operation_token, $stale_after, $allow_status_evidence = false ) {
		$stale_after = Runtime_Limits::bounded_int( $stale_after, 15 * MINUTE_IN_SECONDS, 60, 24 * HOUR_IN_SECONDS );

		if ( ! Backup::owns_operation_lock( $operation_token, 'backup' ) ) {
			return false;
		}

		// lock_process() first acquires the non-expiring local flock, then the
		// database lease. A live current worker therefore cannot be cleaned even if
		// one of its leases is close to expiry.
		if ( ! $this->lock_process( false ) ) {
			return false;
		}

		$reclaimed = false;
		try {
			$lock_key = $this->identifier . '_process_lock';
			if (
				! Backup::owns_operation_lock( $operation_token, 'backup' ) ||
				false !== get_option( Backup::RUN_LOCK_OPTION, false ) ||
				false !== get_site_transient( $lock_key )
			) {
				return false;
			}

			$snapshot = $this->get_reclaimable_legacy_queue_snapshot( $stale_after, $allow_status_evidence );
			if ( false === $snapshot ) {
				return false;
			}

			// Revalidate every fence after inspection. In particular, an older worker
			// may publish its transient lease while an upgrade request is in flight.
			if (
				! Backup::owns_operation_lock( $operation_token, 'backup' ) ||
				false !== get_option( Backup::RUN_LOCK_OPTION, false ) ||
				false !== get_site_transient( $lock_key ) ||
				$snapshot !== $this->get_reclaimable_legacy_queue_snapshot( $stale_after, $allow_status_evidence )
			) {
				return false;
			}

			// Preflight all observed values before deleting any of them. Each delete
			// then compares the serialized value again at the database boundary.
			foreach ( $snapshot['batches'] as $batch ) {
				if ( get_site_option( $batch['key'], false ) !== $batch['data'] ) {
					return false;
				}
			}
			if (
				false !== $snapshot['status'] &&
				get_site_option( $this->get_status_key(), false ) !== $snapshot['status']
			) {
				return false;
			}

			foreach ( $snapshot['batches'] as $batch ) {
				if (
					false !== get_site_transient( $lock_key ) ||
					! Backup::owns_operation_lock( $operation_token, 'backup' ) ||
					! $this->delete_exact_site_option_value( $batch['key'], $batch['data'] )
				) {
					return false;
				}
			}

			if (
				false !== $snapshot['status'] &&
				! $this->delete_exact_site_option_value( $this->get_status_key(), $snapshot['status'] )
			) {
				return false;
			}

			if (
				false !== get_site_transient( $lock_key ) ||
				! Backup::owns_operation_lock( $operation_token, 'backup' ) ||
				! empty( $this->get_batches( 1 ) )
			) {
				return false;
			}

			$this->clear_scheduled_event();
			Logger::log( 'Reclaimed an abandoned untokenized legacy backup queue before starting a new generation.' );
			$reclaimed = true;
		} finally {
			$this->unlock_process();
		}

		return $reclaimed;
	}

	/**
	 * Capture only the narrowly recognized pre-token queue contract.
	 *
	 * A missing current task and progress timestamp matches the imported-site
	 * shape and contains no evidence that a worker began. Once a legacy task has
	 * started, a real timestamp must prove that it exceeded the caller's bounded
	 * grace period.
	 *
	 * @param int  $stale_after No-progress grace period.
	 * @param bool $allow_status_evidence Whether a CLI caller may use old legacy status timestamps.
	 * @return array|false
	 */
	private function get_reclaimable_legacy_queue_snapshot( $stale_after, $allow_status_evidence = false ) {
		$running = Plugin::get_migrated_option( 'sssbm_export_process_running', false );
		if (
			false !== get_option( Backup::RUN_LOCK_OPTION, false ) ||
			! in_array( $running, array( false, true, 1, '1' ), true ) ||
			false !== get_option( 'sssbm_cancel_run_token', false ) ||
			false !== get_option( 'sssbm_paused_run_token', false )
		) {
			return false;
		}

		$current_task = get_option( 'sssbm_current_task', false );
		$progress     = get_option( 'sssbm_last_progress_at', false );
		$has_task     = false !== $current_task && '' !== $current_task;
		$has_progress = false !== $progress && '' !== $progress && 0 !== $progress && '0' !== $progress;

		if ( $has_task && ( ! is_string( $current_task ) || ! in_array( $current_task, Backup::get_all_tasks(), true ) ) ) {
			return false;
		}
		if ( $has_progress && ( ! is_numeric( $progress ) || (int) $progress <= 0 ) ) {
			return false;
		}
		$status_evidence = false;
		if ( $has_task && ! $has_progress ) {
			// Older releases did not persist the progress marker. Only a synchronous
			// CLI recovery may date that state from the complete, bounded legacy
			// status ledger, and it gets a full-day grace period to absorb WordPress
			// timezone offsets and clock skew. Browser/background starts remain
			// fail-closed when the marker is absent.
			if ( ! $allow_status_evidence ) {
				return false;
			}
			$status_evidence = $this->get_stale_legacy_status_evidence( max( $stale_after, 24 * HOUR_IN_SECONDS ) );
			if ( false === $status_evidence ) {
				return false;
			}
		}
		if ( false === $running && ( $has_task || $has_progress ) ) {
			// A missing legacy running flag is reclaimable only for the pristine
			// imported queue shape. Any evidence that work started remains fenced.
			return false;
		}
		if ( $has_progress && (int) $progress > time() - $stale_after ) {
			return false;
		}

		$batches = $this->get_batches();
		if ( empty( $batches ) || count( $batches ) > 100 ) {
			return false;
		}

		$snapshot   = array();
		$total_items = 0;
		$allowed     = array_fill_keys( Backup::get_all_tasks(), true );
		foreach ( $batches as $batch ) {
			if (
				! is_object( $batch ) ||
				! isset( $batch->key, $batch->data ) ||
				! is_string( $batch->key ) ||
				0 !== strpos( $batch->key, $this->identifier . '_batch_' ) ||
				! is_array( $batch->data ) ||
				empty( $batch->data ) ||
				array_keys( $batch->data ) !== range( 0, count( $batch->data ) - 1 )
			) {
				return false;
			}

			$total_items += count( $batch->data );
			if ( $total_items > 100 ) {
				return false;
			}

			foreach ( $batch->data as $item ) {
				if ( ! is_string( $item ) || ! isset( $allowed[ $item ] ) ) {
					// Modern queue items are arrays containing a run token. They must
					// never be treated as an upgrade artifact, even if their lock is gone.
					return false;
				}
			}

			$snapshot[] = array(
				'key'  => $batch->key,
				'data' => $batch->data,
			);
		}

		$status = get_site_option( $this->get_status_key(), false );
		if ( ! in_array( $status, array( false, 0, '0', self::STATUS_CANCELLED, self::STATUS_PAUSED, '1', '2' ), true ) ) {
			return false;
		}

		return array(
			'batches'         => $snapshot,
			'status'          => $status,
			'running'         => $running,
			'current_task'    => $current_task,
			'progress'        => $progress,
			'status_evidence' => $status_evidence,
		);
	}

	/**
	 * Validate the complete legacy status ledger as an activity-age witness.
	 *
	 * No individual message is trusted in isolation: every retained entry must
	 * match the bounded Status contract and the newest timestamp must be old.
	 * The exact ledger is returned as part of the reclaim snapshot so a concurrent
	 * update invalidates the compare-before-delete fence.
	 *
	 * @param int $stale_after Required age in seconds.
	 * @return array|false
	 */
	private function get_stale_legacy_status_evidence( $stale_after ) {
		$messages = get_option( 'sssbm_messages', false );
		if ( ! is_array( $messages ) || empty( $messages ) || count( $messages ) > 200 ) {
			return false;
		}

		$newest = 0;
		foreach ( $messages as $message ) {
			if (
				! is_array( $message ) ||
				! isset( $message['id'], $message['type'], $message['message'], $message['time'] ) ||
				! is_string( $message['id'] ) ||
				'' === $message['id'] ||
				! is_string( $message['type'] ) ||
				! in_array( $message['type'], array( 'success', 'info', 'error', 'warning' ), true ) ||
				! is_string( $message['message'] ) ||
				! is_numeric( $message['time'] ) ||
				(int) $message['time'] <= 0
			) {
				return false;
			}
			$newest = max( $newest, (int) $message['time'] );
		}

		// Status::message() historically used WordPress's local timestamp. Requiring
		// the entry to be stale against both clocks prevents a timezone offset from
		// making recent work appear abandoned.
		$wp_now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Legacy status ledgers used offset timestamps; both clocks must be checked before reclaiming their worker lock.
		if ( ! is_numeric( $wp_now ) ) {
			return false;
		}
		$oldest_cutoff = min( time(), (int) $wp_now ) - $stale_after;
		if ( $newest > $oldest_cutoff ) {
			return false;
		}

		return array(
			'messages'  => $messages,
			'newest_at' => $newest,
		);
	}

	/**
	 * Stable lock filename. The file itself intentionally persists between runs.
	 *
	 * @return string
	 */
	protected function get_stable_process_lock_path() {
		return Backup::get_storage_path() . '.sssbm-worker-' . md5( $this->identifier ) . '.lock';
	}

	/**
	 * Acquire the non-expiring in-process lock without blocking another request.
	 *
	 * @return bool
	 */
	protected function acquire_stable_process_lock() {
		if ( is_resource( $this->stable_process_lock_handle ) ) {
			return false;
		}

		$path   = $this->get_stable_process_lock_path();
		$handle = Helper::open_lock_file( $path, Backup::get_storage_path() );
		if ( false === $handle ) {
			return false;
		}

		if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
			@fclose( $handle );

			return false;
		}

		$this->stable_process_lock_handle = $handle;

		return true;
	}

	/**
	 * Release this request's stable lock without removing its inode.
	 *
	 * @return void
	 */
	protected function release_stable_process_lock() {
		if ( ! is_resource( $this->stable_process_lock_handle ) ) {
			$this->stable_process_lock_handle = null;

			return;
		}

		@flock( $this->stable_process_lock_handle, LOCK_UN );
		@fclose( $this->stable_process_lock_handle );
		$this->stable_process_lock_handle = null;
	}

	/**
	 * Perform task with queued item.
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return mixed
	 */
	protected function task( $item ) {
		$queue_item = $this->normalize_queue_item( $item );
		if ( false === $queue_item ) {
			Logger::log( 'Discarding an invalid or stale backup queue item.' );
			return false;
		}

		$task_name              = $queue_item['task'];
		$run_token              = $queue_item['run_token'];
		$this->active_run_token = $run_token;
		if ( ! Backup::owns_run_lock( $run_token ) ) {
			Logger::log( 'Discarding an invalid or stale backup queue item.' );
			return false;
		}

		$task_sequence = get_option( 'sssbm_task_sequence', array() );
		if (
			! Backup::is_valid_task_sequence( $task_sequence ) ||
			! in_array( $task_name, $task_sequence, true ) ||
			! in_array( $task_name, Backup::get_all_tasks(), true )
		) {
			Status::error( __( 'Backup stopped because its frozen task sequence was missing or invalid.', 'simply-static-studio-backup-migrate' ) );
			Backup::abort_export( $run_token );
			return false;
		}

		$task = $this->getItem( $task_name );

		if ( ! $task ) {
			Logger::log( 'Invalid or unavailable backup task encountered.' );
			Status::error( __( 'Backup stopped because a required task was unavailable.', 'simply-static-studio-backup-migrate' ) );
			Backup::abort_export( $run_token );
			return false;
		}
		$task->setRunToken( $run_token );

		// Track current task for progress calculation.
		update_option( 'sssbm_current_task', $task_name, false );
		update_option( 'sssbm_last_progress_at', time(), false );

		try {
			Logger::log( 'Performing: ' . $task_name );
			$done = $task->perform();
			Logger::log( 'Task perform() returned: ' . ( $done ? 'true' : 'false' ) );

			// A lease may have been reclaimed while a long task was inside a blocking
			// filesystem/database call. Never route or pause a replacement run.
			if ( ! Backup::owns_run_lock( $run_token ) ) {
				return false;
			}

			if ( ! $done ) {
				Logger::log( 'Not done with: ' . $task_name );

				if ( $this->has_stalled( $task_name ) ) {
					$exception = new \RuntimeException( sprintf( 'Task "%s" stopped making progress.', $task_name ) );
					$this->pauseExecution( $exception, $task_name, $task );

					return $item;
				}

				// A resumable long task may have just completed an expensive ZIP
				// commit. End this request cleanly instead of immediately starting
				// another commit that can be killed without reaching its checkpoint.
				$this->yield_after_task = $task->isLongRunning();

				return $item;
			}

			// Task completed successfully, reset retry count
			$task->resetRetryCount();
			$this->reset_stall_state();
			delete_option( 'sssbm_watchdog_state' );

			$nextTask = Backup::get_next_task( $task_name );

			if ( null === $nextTask ) {
				// No more tasks.
				Logger::log( "No more tasks" );

				return false;
			}

			Logger::log( 'New task: ' . $nextTask );

			// Apply task delay to prevent crashing slow-performing shared hosting environments
			$task_delay = $this->get_task_delay();
			if ( $task_delay > 0 ) {
				Logger::log( 'Waiting ' . $task_delay . ' seconds before next task...' );
				sleep( $task_delay );
			}

			return Backup::make_queue_item( $nextTask, $run_token );

		} catch ( Skippable_Exception $e ) {
			if ( ! Backup::owns_run_lock( $run_token ) ) {
				return false;
			}
			$task->resetRetryCount();
			$this->reset_stall_state();
			delete_option( 'sssbm_watchdog_state' );
			Logger::log( 'Skippable: ' . $e->getMessage() );
			Logger::log_exception_trace( $e );
			$nextTask = Backup::get_next_task( $task_name );
			if ( null === $nextTask ) {
				// No more tasks.
				Logger::log( "No more tasks" );

				return false;
			}

			Logger::log( 'New task: ' . $nextTask );

			// Apply task delay to prevent crashing slow-performing shared hosting environments
			$task_delay = $this->get_task_delay();
			if ( $task_delay > 0 ) {
				Logger::log( 'Waiting ' . $task_delay . ' seconds before next task...' );
				sleep( $task_delay );
			}

			return Backup::make_queue_item( $nextTask, $run_token );
		} catch ( Retry_Exception $e ) {
			if ( ! Backup::owns_run_lock( $run_token ) ) {
				return false;
			}
			$task->incrementRetryCount();

			if ( $task->hasExceededMaxRetries() ) {
				Logger::log( 'Task has exceeded maximum retries: ' . $task_name );
				$this->pauseExecution( $e, $task_name, $task );

				return $item;
			}

			Logger::log( 'Retry: ' . $e->getMessage() );
			Logger::log_exception_trace( $e );

			Logger::log( 'Retrying task: ' . $task_name . ' (Attempt ' . $task->getRetryCount() . ')' );

			return $item;
		} catch ( \Throwable $e ) {
			if ( ! Backup::owns_run_lock( $run_token ) ) {
				return false;
			}

			$task_sequence = get_option( 'sssbm_task_sequence', array() );
			if ( ! Backup::is_valid_task_sequence( $task_sequence ) ) {
				Status::error( __( 'Backup stopped because its frozen task sequence was missing or invalid.', 'simply-static-studio-backup-migrate' ) );
				Backup::abort_export( $run_token );
				return false;
			}

			// Catch all errors and exceptions (including PHP Error objects in PHP 7+)
			Logger::log( 'Error (' . get_class( $e ) . '): ' . $e->getMessage() );
			Logger::log( 'File: ' . $e->getFile() . ':' . $e->getLine() );
			Logger::log_exception_trace( $e );
			$this->pauseExecution( $e, $task_name, $task );

			// Something to do here when exception is met: pause migration.
			return $item;
		}
	}

	/**
	 * Yield incomplete long-running work to the next loopback request.
	 *
	 * @return bool
	 */
	public function should_continue() {
		return ! $this->yield_after_task && parent::should_continue();
	}

	/**
	 * Validate a serialized queue payload without instantiating any classes.
	 *
	 * @param mixed $item Candidate queue item.
	 * @return array|false
	 */
	private function normalize_queue_item( $item ) {
		if (
			! is_array( $item ) ||
			! isset( $item['task'], $item['run_token'] ) ||
			! is_string( $item['task'] ) ||
			! is_string( $item['run_token'] ) ||
			'' === $item['run_token'] ||
			'' === $item['task']
		) {
			return false;
		}

		return array(
			'task'      => $item['task'],
			'run_token' => $item['run_token'],
		);
	}

	/**
	 * Record an unchanged progress signature and report when retries are stalled.
	 *
	 * @param string $task_name Current task.
	 * @return bool
	 */
	private function has_stalled( $task_name ) {
		$archive_path = Backup::get_existing_backup_path();
		$signature    = md5( serialize( array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashes internal progress state only; the result is never unserialized or sent to the browser.
			Backup::get_export_param( $task_name, array() ),
			get_option( 'sssbm_media_bytes_offset', 0 ),
			$this->safe_file_size( Backup::get_database_path() ),
			$this->safe_file_size( $archive_path ),
		) ) );
		$state        = get_option( 'sssbm_task_stall_state', array() );
		$made_progress = ! is_array( $state ) ||
			( $state['task'] ?? '' ) !== $task_name ||
			( $state['signature'] ?? '' ) !== $signature;
		$count        = is_array( $state ) && ( $state['task'] ?? '' ) === $task_name && ( $state['signature'] ?? '' ) === $signature
			? (int) ( $state['count'] ?? 0 ) + 1
			: 1;

		if ( $made_progress ) {
			delete_option( 'sssbm_watchdog_state' );
		}

		update_option( 'sssbm_task_stall_state', array(
			'task'      => $task_name,
			'signature' => $signature,
			'count'     => $count,
		), false );

		$limit = $this->get_max_stalled_task_iterations( $task_name );

		return $count >= $limit;
	}

	/**
	 * Get the bounded number of unchanged task iterations before pausing.
	 *
	 * @param string $task_name Current task.
	 * @return int
	 */
	protected function get_max_stalled_task_iterations( $task_name ) {
		return Runtime_Limits::bounded_int(
			apply_filters( 'sssbm_max_stalled_task_iterations', 5, $task_name ),
			5,
			2,
			100
		);
	}

	/**
	 * Return a file size without emitting warnings during concurrent cleanup.
	 *
	 * @param string|false $path File path.
	 * @return int
	 */
	private function safe_file_size( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return 0;
		}

		$size = @filesize( $path );

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Reset persisted no-progress state.
	 *
	 * @return void
	 */
	public function reset_stall_state() {
		delete_option( 'sssbm_task_stall_state' );
	}

	protected function pauseExecution( $exception, $taskName, $taskObject ) {
		if ( ! $this->pause( $this->active_run_token ) ) {
			return;
		}
		Status::error(
			sprintf(
				/* translators: %s: error that caused the backup to pause. */
				__( 'Backup paused. Reason: %s', 'simply-static-studio-backup-migrate' ),
				$exception->getMessage()
			)
		);
		Logger::log( $exception->getMessage() );
		Logger::log_exception_trace( $exception );
		do_action( 'simply_static_studio_migration_process_failed', [
			'message'     => $exception->getMessage(),
			'code'        => $exception->getCode(),
			'task'        => $taskName,
			'task_object' => $taskObject
		],
			$this
		);
	}

	/**
	 * @param $item
	 *
	 * @return false|MigrationTask
	 */
	protected function getItem( $item ) {
		if ( ! is_string( $item ) || ! in_array( $item, Backup::get_all_tasks(), true ) ) {
			return false;
		}

		$className = '\Simply_Static\Backup\Queue\Tasks\\' . ucfirst( $item );

		if ( class_exists( $className ) ) {
			return new $className();
		}

		return false;
	}

	/**
	 * Persist which exact run a cancellation request is allowed to abort.
	 *
	 * @param string|null $run_token Expected run token, or null for current run.
	 * @param string      $outcome Terminal outcome when cancellation is observed.
	 * @return bool Whether the exact generation was marked for cancellation.
	 */
	public function request_cancel( $run_token = null, $outcome = 'cancelled' ) {
		if ( null === $run_token ) {
			$run_token = Backup::get_current_run_token();
		}

		$legacy_run = false === $run_token
			&& false === get_option( Backup::RUN_LOCK_OPTION, false )
			&& ( Backup::is_running() || $this->is_queued() );
		if ( ! ( ( is_string( $run_token ) && Backup::owns_run_lock( $run_token ) ) || $legacy_run ) ) {
			return false;
		}

		$marker_run_token = $legacy_run ? '' : $run_token;
		$requested_at     = time();
		$existing_marker  = get_option( 'sssbm_cancel_run_token', false );
		if (
			is_array( $existing_marker ) &&
			isset( $existing_marker['run_token'], $existing_marker['requested_at'] ) &&
			is_string( $existing_marker['run_token'] ) &&
			$marker_run_token === $existing_marker['run_token'] &&
			is_numeric( $existing_marker['requested_at'] ) &&
			(int) $existing_marker['requested_at'] > 0
		) {
			// Repeated clicks must not postpone bounded stale-worker recovery.
			$requested_at = min( time(), (int) $existing_marker['requested_at'] );
		}

		$marker = array(
			'run_token'   => $marker_run_token,
			'outcome'     => 'cancelled' === $outcome ? 'cancelled' : 'failed',
			'requested_at' => $requested_at,
		);
		$stored = update_option( 'sssbm_cancel_run_token', $marker, false );
		if ( ! $stored && get_option( 'sssbm_cancel_run_token', false ) !== $marker ) {
			return false;
		}
		delete_option( 'sssbm_paused_run_token' );
		parent::request_cancel();

		return true;
	}

	/**
	 * Treat a cancellation marker as authoritative for its bound generation.
	 *
	 * The marker deliberately wins over the shared status value. A pause request
	 * from a refreshed browser must never overwrite an accepted cancellation.
	 *
	 * @return bool
	 */
	public function is_cancelled() {
		$marker = get_option( 'sssbm_cancel_run_token', false );
		if ( is_string( $marker ) && '' !== $marker ) {
			return Backup::owns_run_lock( $marker );
		}
		if ( ! is_array( $marker ) || ! isset( $marker['run_token'] ) || ! is_string( $marker['run_token'] ) ) {
			return false;
		}

		if ( '' === $marker['run_token'] ) {
			return false === get_option( Backup::RUN_LOCK_OPTION, false ) && ( Backup::is_running() || $this->is_queued() );
		}

		return Backup::owns_run_lock( $marker['run_token'] );
	}

	/**
	 * Reclaim a cancelled queue whose database lease outlived its worker.
	 *
	 * Normal cancellation always gets the first opportunity to acquire both
	 * locks. A zero grace period permits immediate recovery only while this
	 * request owns the stable filesystem lock, which proves the old PHP worker no
	 * longer owns that shared archive lock. The exact old database lease is
	 * compare-and-deleted before a replacement cleanup lease is claimed, so a
	 * concurrently changed generation remains untouched.
	 *
	 * @param int $stale_after Seconds without progress before recovery.
	 * @return bool Whether the cancelled queue was cleaned.
	 */
	public function cleanup_cancelled_if_stale( $stale_after = 0 ) {
		$stale_after = Runtime_Limits::bounded_int( $stale_after, 0, 0, 1800 );
		if (
			! $this->is_cancelled() ||
			( $stale_after > 0 && ! $this->cancellation_is_stale( $stale_after ) )
		) {
			return false;
		}

		if ( ! $this->acquire_stable_process_lock() ) {
			return false;
		}

		$database_lock_acquired = false;
		$cleaned                = false;
		try {
			if (
				! $this->is_cancelled() ||
				( $stale_after > 0 && ! $this->cancellation_is_stale( $stale_after ) )
			) {
				return false;
			}

			$lock_key     = $this->identifier . '_process_lock';
			$lease        = get_option( $lock_key, false );
			$legacy_lease = get_site_transient( $lock_key );
			if ( false !== $legacy_lease ) {
				// A pre-upgrade worker owns only this transient and does not hold
				// the stable filesystem lock. Never overlap or delete its lease.
				return false;
			}
			if (
				! is_array( $lease ) ||
				empty( $lease['token'] ) ||
				! is_string( $lease['token'] ) ||
				! isset( $lease['expires'] ) ||
				! is_numeric( $lease['expires'] ) ||
				(int) $lease['expires'] <= 0
			) {
				// Forced recovery is safe only for a lease created by the new
				// option-plus-flock protocol. Unknown formats fail closed.
				return false;
			}
			if ( ! $this->delete_process_lock_value( $lock_key, $lease ) ) {
				return false;
			}

			// parent::lock_process() checks the legacy transient again before
			// claiming the replacement option lease, closing the upgrade race.
			if ( ! parent::lock_process( false ) ) {
				return false;
			}
			$database_lock_acquired = true;

			if ( ! $this->is_cancelled() ) {
				return false;
			}

			Logger::log( $stale_after > 0
				? 'Reclaiming a stale cancelled backup worker lease after the no-progress grace period.'
				: 'Reclaiming an abandoned cancelled backup worker lease immediately.'
			);
			$this->delete_all();
			$cleaned = true;
		} finally {
			if ( $database_lock_acquired ) {
				parent::unlock_process();
			}
			$this->release_stable_process_lock();
		}

		return $cleaned;
	}

	/**
	 * Determine whether cancellation and worker progress have both been quiet.
	 *
	 * @param int $stale_after Required quiet interval in seconds.
	 * @return bool
	 */
	private function cancellation_is_stale( $stale_after ) {
		$marker = get_option( 'sssbm_cancel_run_token', false );
		$requested_at = is_array( $marker ) && isset( $marker['requested_at'] ) && is_numeric( $marker['requested_at'] )
			? max( 0, (int) $marker['requested_at'] )
			: 0;
		$last_progress = max( 0, (int) get_option( 'sssbm_last_progress_at', 0 ) );
		$run_lock      = get_option( Backup::RUN_LOCK_OPTION, false );
		$run_created   = is_array( $run_lock ) && isset( $run_lock['created'] ) && is_numeric( $run_lock['created'] )
			? max( 0, (int) $run_lock['created'] )
			: 0;
		$inactive_since = max( $requested_at, $last_progress, $run_created );
		if ( 0 === $inactive_since ) {
			// Unknown age must never be interpreted as proof that a worker died.
			return false;
		}

		return $inactive_since <= time() - $stale_after;
	}

	/**
	 * Pause only the exact generation that requested it.
	 *
	 * @param string|null $run_token Expected run token, or null for active worker.
	 * @return bool
	 */
	public function pause( $run_token = null ) {
		if ( null === $run_token ) {
			$run_token = '' !== $this->active_run_token ? $this->active_run_token : Backup::get_current_run_token();
		}
		if ( ! is_string( $run_token ) || ! Backup::owns_run_lock( $run_token ) || $this->is_cancelled() ) {
			return false;
		}

		if ( ! update_option( 'sssbm_paused_run_token', $run_token, false ) && get_option( 'sssbm_paused_run_token', '' ) !== $run_token ) {
			return false;
		}

		parent::pause();

		return true;
	}

	/**
	 * Treat a paused status as active only for its bound generation.
	 *
	 * @return bool
	 */
	public function is_paused() {
		if ( $this->is_cancelled() || ! parent::is_paused() ) {
			return false;
		}

		$paused_token = get_option( 'sssbm_paused_run_token', '' );

		return is_string( $paused_token ) && '' !== $paused_token && Backup::owns_run_lock( $paused_token );
	}

	/**
	 * Resume only when the pause marker belongs to the current generation.
	 *
	 * @param string|null $run_token Expected run token, or null for current run.
	 * @return bool
	 */
	public function resume( $run_token = null ) {
		if ( null === $run_token ) {
			$run_token = Backup::get_current_run_token();
		}
		$paused_token = get_option( 'sssbm_paused_run_token', '' );
		if (
			! is_string( $run_token ) ||
			! is_string( $paused_token ) ||
			'' === $paused_token ||
			! hash_equals( $paused_token, $run_token ) ||
			! Backup::owns_run_lock( $run_token ) ||
			! $this->is_paused()
		) {
			return false;
		}

		delete_option( 'sssbm_paused_run_token' );
		parent::resume();

		return true;
	}

	/**
	 * Resume an exact paused generation inside the already locked CLI worker.
	 *
	 * Unlike resume(), this deliberately does not dispatch a loopback request:
	 * the synchronous CLI process that owns the stable worker lock continues the
	 * frozen task sequence itself.
	 *
	 * @param string|null $run_token Expected run token.
	 * @return bool
	 */
	public function resume_synchronous( $run_token = null ) {
		if ( null === $run_token ) {
			$run_token = Backup::get_current_run_token();
		}
		$paused_token = get_option( 'sssbm_paused_run_token', '' );
		if (
			! is_string( $run_token ) ||
			! is_string( $paused_token ) ||
			'' === $paused_token ||
			! hash_equals( $paused_token, $run_token ) ||
			! Backup::owns_run_lock( $run_token ) ||
			! $this->is_paused()
		) {
			return false;
		}

		delete_option( 'sssbm_paused_run_token' );
		delete_site_option( $this->get_status_key() );
		$this->resumed();

		return ! $this->is_paused();
	}

	/**
	 * Clean files only after the active worker has observed cancellation.
	 *
	 * @return void
	 */
	protected function cancelled() {
		$cancellation = get_option( 'sssbm_cancel_run_token', false );
		if ( is_array( $cancellation ) && isset( $cancellation['run_token'], $cancellation['outcome'] ) ) {
			$run_token = is_string( $cancellation['run_token'] ) ? $cancellation['run_token'] : '';
			$outcome   = 'cancelled' === $cancellation['outcome'] ? 'cancelled' : 'failed';
			Backup::abort_export( $run_token, $outcome );
		} elseif ( is_string( $cancellation ) && '' !== $cancellation ) {
			// Backward compatibility for a cancellation marker stored by an older build.
			Backup::abort_export( $cancellation, 'cancelled' );
		}
		delete_option( 'sssbm_cancel_run_token' );
		delete_option( 'sssbm_paused_run_token' );
		parent::cancelled();
	}

	/**
	 * Ensure malformed queues cannot leave the public running flag stranded.
	 *
	 * @return void
	 */
	protected function completed() {
		$this->reset_stall_state();
		delete_option( 'sssbm_watchdog_state' );
		delete_option( 'sssbm_paused_run_token' );

		if (
			'' !== $this->active_run_token &&
			Backup::owns_run_lock( $this->active_run_token ) &&
			Backup::is_running() &&
			! Backup::is_completed()
		) {
			Status::error( __( 'Backup stopped before all required tasks completed.', 'simply-static-studio-backup-migrate' ) );
			Backup::abort_export( $this->active_run_token );
		}

		parent::completed();
	}
}
