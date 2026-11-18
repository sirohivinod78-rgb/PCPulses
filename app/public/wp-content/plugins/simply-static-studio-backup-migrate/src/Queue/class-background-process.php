<?php

namespace Simply_Static\Backup\Queue;

use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Runtime_Limits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract BackgroundProcess class.
 *
 * From: https://github.com/deliciousbrains/wp-background-processing
 *
 * @abstract
 * @extends Async_Request
 */
abstract class Background_Process extends Async_Request {
	/**
	 * The default query arg name used for passing the chain ID to new processes.
	 */
	const CHAIN_ID_ARG_NAME = 'chain_id';

	/**
	 * Unique background process chain ID.
	 *
	 * @var string
	 */
	private $chain_id;

	/**
	 * Unique token proving ownership of the current process lock.
	 *
	 * @var string
	 */
	private $process_lock_token = '';

	/**
	 * Action
	 *
	 * (default value: 'background_process')
	 *
	 * @var string
	 * @access protected
	 */
	protected $action = 'background_process';

	/**
	 * Start time of current process.
	 *
	 * (default value: 0)
	 *
	 * @var int
	 * @access protected
	 */
	protected $start_time = 0;

	/**
	 * Cron_hook_identifier
	 *
	 * @var string
	 * @access protected
	 */
	protected $cron_hook_identifier;

	/**
	 * Cron_interval_identifier
	 *
	 * @var string
	 * @access protected
	 */
	protected $cron_interval_identifier;

	/**
	 * Restrict object instantiation when using unserialize.
	 *
	 * @var bool|array
	 */
	protected $allowed_batch_data_classes = true;

	/**
	 * Whether the current request's caller will explicitly schedule the next slice.
	 *
	 * @var bool
	 */
	protected $suppress_auto_dispatch = false;

	/**
	 * The status set when process is cancelling.
	 *
	 * @var int
	 */
	const STATUS_CANCELLED = 1;

	/**
	 * The status set when process is paused or pausing.
	 *
	 * @var int;
	 */
	const STATUS_PAUSED = 2;

	/**
	 * Initiate new background process.
	 *
	 * @param bool|array $allowed_batch_data_classes Optional. Array of class names that can be unserialized. Default true (any class).
	 */
	public function __construct( $allowed_batch_data_classes = true ) {
		parent::__construct();

		if ( empty( $allowed_batch_data_classes ) && false !== $allowed_batch_data_classes ) {
			$allowed_batch_data_classes = true;
		}

		if ( ! is_bool( $allowed_batch_data_classes ) && ! is_array( $allowed_batch_data_classes ) ) {
			$allowed_batch_data_classes = true;
		}

		// If allowed_batch_data_classes property set in subclass,
		// only apply override if not allowing any class.
		if ( true === $this->allowed_batch_data_classes || true !== $allowed_batch_data_classes ) {
			$this->allowed_batch_data_classes = $allowed_batch_data_classes;
		}

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- get_cron_interval() bounds this health check to at least one minute.

		// Ensure dispatch query args included extra data.
		add_filter( $this->identifier . '_query_args', array( $this, 'filter_dispatch_query_args' ) );
	}

	/**
	 * Schedule the cron healthcheck and dispatch an async request to start processing the queue.
	 *
	 * @access public
	 * @return array|\WP_Error|false HTTP Response array, WP_Error on failure, or false if not attempted.
	 */
	public function dispatch() {
		if ( $this->is_processing() ) {
			Logger::log( 'Background process is already running; no duplicate dispatch was sent.' );
			// Process already running.
			return false;
		}

		/**
		 * Filter fired before background process dispatches its next process.
		 *
		 * @param bool   $cancel   Should the dispatch be cancelled? Default false.
		 * @param string $chain_id The background process chain ID.
		 */
		$cancel = apply_filters( $this->identifier . '_pre_dispatch', false, $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.

		if ( $cancel ) {
			Logger::log( 'Background process dispatch was cancelled by a filter.' );

			return new \WP_Error(
				'background_dispatch_cancelled',
				__( 'The background process dispatch was cancelled.', 'simply-static-studio-backup-migrate' )
			);
		}
		// Schedule the cron healthcheck.
		$this->schedule_event();
		// Perform remote post.
		$result = parent::dispatch();

		if ( is_wp_error( $result ) ) {
			Logger::log( 'Dispatch failed (loopback request error): ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Push to the queue.
	 *
	 * Note, save must be called in order to persist queued items to a batch for processing.
	 *
	 * @param mixed $data Data.
	 *
	 * @return $this
	 */
	public function push_to_queue( $data ) {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Save the queued items for future processing.
	 *
	 * @return $this
	 */
	public function save() {
		$key = $this->generate_key();

		if ( ! empty( $this->data ) ) {
			update_site_option( $key, $this->data );
		}

		// Clean out data so that new data isn't prepended with closed session's data.
		$this->data = array();

		return $this;
	}

	/**
	 * Update a batch's queued items.
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 *
	 * @return $this
	 */
	public function update( $key, $data ) {
		if ( ! empty( $data ) ) {
			update_site_option( $key, $data );
		}

		return $this;
	}

	/**
	 * Delete a batch of queued items.
	 *
	 * @param string $key Key.
	 *
	 * @return $this
	 */
	public function delete( $key ) {
		delete_site_option( $key );

		return $this;
	}

	/**
	 * Delete entire job queue.
	 */
	public function delete_all() {
		$this->discard_queue();

		$this->cancelled();
	}

	/**
	 * Delete queued state without invoking completion or cancellation callbacks.
	 *
	 * This is reserved for setup failures where the job never started. Running
	 * cancellation must continue to use delete_all() so subclass cleanup runs.
	 *
	 * @return $this
	 */
	public function discard_queue() {
		$batches = $this->get_batches();

		foreach ( $batches as $batch ) {
			$this->delete( $batch->key );
		}

		delete_site_option( $this->get_status_key() );
		$this->clear_scheduled_event();

		return $this;
	}

	/**
	 * Cancel job on next batch.
	 */
	public function cancel() {
		$this->request_cancel();
		$this->dispatch();
	}

	/**
	 * Persist a cancellation request without starting another loopback request.
	 *
	 * Callers that can safely clean an idle queue immediately should follow this
	 * with cleanup_cancelled_if_idle(). An active worker will observe the status
	 * before continuing with another item.
	 *
	 * @return void
	 */
	public function request_cancel() {
		update_site_option( $this->get_status_key(), self::STATUS_CANCELLED );
	}

	/**
	 * Atomically clean a cancelled queue only when no worker owns the lock.
	 *
	 * The cancellation state is checked again after acquiring the process lock,
	 * closing the gap between a read-only is_processing() check and delete_all().
	 *
	 * @return bool Whether this request owned the lock and cleaned the queue.
	 */
	public function cleanup_cancelled_if_idle() {
		if ( ! $this->lock_process( false ) ) {
			return false;
		}

		$cleaned = false;

		try {
			if ( $this->is_cancelled() ) {
				$this->delete_all();
				$cleaned = true;
			}
		} finally {
			// unlock_process() compare-and-deletes only the token acquired above.
			$this->unlock_process();
		}

		return $cleaned;
	}

	/**
	 * Has the process been cancelled?
	 *
	 * @return bool
	 */
	public function is_cancelled() {
		return $this->get_status() === self::STATUS_CANCELLED;
	}

	/**
	 * Called when background process has been cancelled.
	 */
	protected function cancelled() {
		do_action( $this->identifier . '_cancelled', $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Pause job on next batch.
	 */
	public function pause() {
		update_site_option( $this->get_status_key(), self::STATUS_PAUSED );
	}

	/**
	 * Has the process been paused?
	 *
	 * @return bool
	 */
	public function is_paused() {
		return $this->get_status() === self::STATUS_PAUSED;
	}

	/**
	 * Called when background process has been paused.
	 */
	protected function paused() {
		do_action( $this->identifier . '_paused', $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Resume job.
	 */
	public function resume() {
		delete_site_option( $this->get_status_key() );

		$this->schedule_event();
		$this->dispatch();
		$this->resumed();
	}

	/**
	 * Called when background process has been resumed.
	 */
	protected function resumed() {
		do_action( $this->identifier . '_resumed', $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Is queued?
	 *
	 * @return bool
	 */
	public function is_queued() {
		return ! $this->is_queue_empty();
	}

	/**
	 * Is the tool currently active, e.g. starting, working, paused or cleaning up?
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->is_queued() || $this->is_processing() || $this->is_paused() || $this->is_cancelled();
	}

	/**
	 * Generate key for a batch.
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @param int    $length Optional max length to trim key to, defaults to 64 characters.
	 * @param string $key    Optional string to append to identifier before hash, defaults to "batch".
	 *
	 * @return string
	 */
	protected function generate_key( $length = 64, $key = 'batch' ) {
		$unique  = md5( microtime() . wp_rand() );
		$prepend = $this->identifier . '_' . $key . '_';

		return substr( $prepend . $unique, 0, $length );
	}

	/**
	 * Get the status key.
	 *
	 * @return string
	 */
	protected function get_status_key() {
		return $this->identifier . '_status';
	}

	/**
	 * Get the status value for the process.
	 *
	 * @return int
	 */
	protected function get_status() {
		global $wpdb;

		if ( is_multisite() ) {
			$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
				$wpdb->prepare(
					"SELECT meta_value FROM $wpdb->sitemeta WHERE meta_key = %s AND site_id = %d LIMIT 1",
					$this->get_status_key(),
					get_current_network_id()
				)
			);
		} else {
			$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
				$wpdb->prepare(
					"SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",
					$this->get_status_key()
				)
			);
		}

		return absint( $status );
	}

	/**
	 * Maybe process a batch of queued items.
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		check_ajax_referer( $this->identifier, 'nonce' );

		// Background process already running.
		if ( $this->is_processing() ) {
			return $this->maybe_wp_die();
		}

		// Cancel requested.
		if ( $this->is_cancelled() ) {
			$this->cleanup_cancelled_if_idle();

			return $this->maybe_wp_die();
		}

		// Pause requested.
		if ( $this->is_paused() ) {
			$this->clear_scheduled_event();
			$this->paused();

			return $this->maybe_wp_die();
		}

		// No data to process.
		if ( $this->is_queue_empty() ) {
			return $this->maybe_wp_die();
		}

		$this->handle();

		return $this->maybe_wp_die();
	}

	/**
	 * Is queue empty?
	 *
	 * @return bool
	 */
	protected function is_queue_empty() {
		$batch = $this->get_batch();
		if ( empty( $batch ) ) {
			return true;
		}

		if ( ! is_object( $batch ) || empty( $batch->data ) || ! is_array( $batch->data ) ) {
			if ( is_object( $batch ) && ! empty( $batch->key ) ) {
				$this->delete( $batch->key );
			}

			return true;
		}

		return false;
	}

	/**
	 * Is process running?
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 *
	 * @return bool
	 *
	 * @deprecated 1.1.0 Superseded.
	 * @see        is_processing()
	 */
	protected function is_process_running() {
		return $this->is_processing();
	}

	/**
	 * Is the background process currently running?
	 *
	 * @return bool
	 */
	public function is_processing() {
		$lock = get_option( $this->identifier . '_process_lock', false );
		if ( is_array( $lock ) && ! empty( $lock['token'] ) && ! empty( $lock['expires'] ) && (int) $lock['expires'] > time() ) {
			return true;
		}

		// Respect a short-lived lock created by a pre-upgrade worker.
		return (bool) get_site_transient( $this->identifier . '_process_lock' );
	}

	/**
	 * Lock process.
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 *
	 * @param bool $reset_start_time Optional, default true.
	 */
	public function lock_process( $reset_start_time = true ) {
		if ( $reset_start_time ) {
			$this->start_time = time(); // Set start time of current process.
		}

		$lock_duration = $this->get_queue_lock_duration();
		$lock_key      = $this->identifier . '_process_lock';
		$token         = wp_generate_uuid4();
		$payload       = array(
			'token'   => $token,
			'expires' => time() + $lock_duration,
		);
		// Do not overlap a worker started by a pre-upgrade version that still
		// owns the legacy transient-backed lock.
		$legacy_locked = (bool) get_site_transient( $lock_key );
		$locked        = $legacy_locked
			? false
			: add_option( $lock_key, $payload, '', 'no' );

		if ( ! $locked && ! $legacy_locked ) {
			$current = get_option( $lock_key, false );
			if ( ! is_array( $current ) || empty( $current['expires'] ) || (int) $current['expires'] <= time() ) {
				$this->delete_process_lock_value( $lock_key, $current );
				$locked = add_option( $lock_key, $payload, '', 'no' );
			}
		}

		if ( $locked ) {
			$this->process_lock_token = $token;
		}

		/**
		 * Action to note whether the background process managed to create its lock.
		 *
		 * The lock is used to signify that a process is running a task and no other
		 * process should be allowed to run the same task until the lock is released.
		 *
		 * @param bool   $locked        Whether the lock was successfully created.
		 * @param string $microtime     Unique token used for the lock.
		 * @param int    $lock_duration Max number of seconds that the lock will live for.
		 * @param string $chain_id      Current background process chain ID.
		 */
		do_action(
			$this->identifier . '_process_locked', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			$locked,
			$token,
			$lock_duration,
			$this->get_chain_id()
		);

		return $locked;
	}

	/**
	 * Unlock process.
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		$lock_key = $this->identifier . '_process_lock';
		$current  = get_option( $lock_key, false );
		$unlocked = false;

		if (
			is_array( $current ) &&
			! empty( $current['token'] ) &&
			'' !== $this->process_lock_token &&
			hash_equals( (string) $current['token'], $this->process_lock_token )
		) {
			$unlocked = $this->delete_process_lock_value( $lock_key, $current );
		}

		// This process never creates the pre-upgrade transient lease. Deleting it
		// here could release a concurrently started legacy worker that this request
		// does not own. The legacy worker remains responsible for its own transient.
		$this->process_lock_token = '';

		/**
		 * Action to note whether the background process managed to release its lock.
		 *
		 * The lock is used to signify that a process is running a task and no other
		 * process should be allowed to run the same task until the lock is released.
		 *
		 * @param bool   $unlocked Whether the lock was released.
		 * @param string $chain_id Current background process chain ID.
		 */
		do_action( $this->identifier . '_process_unlocked', $unlocked, $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.

		return $this;
	}

	/**
	 * Delete only the exact lock value that was observed (compare-and-delete).
	 *
	 * @param string $lock_key Option key.
	 * @param mixed  $value Observed option value.
	 * @return bool
	 */
	protected function delete_process_lock_value( $lock_key, $value ) {
		global $wpdb;

		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$lock_key,
					maybe_serialize( $value )
				)
			);

			if ( false !== $deleted && $deleted > 0 ) {
				wp_cache_delete( $lock_key, 'options' );

				return true;
			}

			return false;
		}

		if ( get_option( $lock_key, false ) === $value ) {
			return delete_option( $lock_key );
		}

		return false;
	}

	/**
	 * Compare-and-delete one observed queue/status value.
	 *
	 * Queue state uses network options on multisite and regular options on a
	 * single site. Direct comparison prevents a cleanup request from deleting a
	 * replacement value written after it inspected the legacy queue.
	 *
	 * @param string $key Site option key.
	 * @param mixed  $value Exact observed value.
	 * @return bool
	 */
	protected function delete_exact_site_option_value( $key, $value ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return get_site_option( $key, false ) === $value ? delete_site_option( $key ) : false;
		}

		if ( is_multisite() ) {
			$network_id = get_current_network_id();
			$deleted    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s AND meta_value = %s AND site_id = %d",
					$key,
					maybe_serialize( $value ),
					$network_id
				)
			);
			if ( false !== $deleted && $deleted > 0 ) {
				wp_cache_delete( $network_id . ':' . $key, 'site-options' );

				return true;
			}

			return false;
		}

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
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

	/**
	 * Get batch.
	 *
	 * @return stdClass Return the first batch of queued items.
	 */
	protected function get_batch() {
		return array_reduce(
			$this->get_batches( 1 ),
			static function ( $carry, $batch ) {
				return $batch;
			},
			array()
		);
	}

	/**
	 * Get batches.
	 *
	 * @param int $limit Number of batches to return, defaults to all.
	 *
	 * @return array of stdClass
	 */
	public function get_batches( $limit = 0 ) {
		global $wpdb;

		if ( empty( $limit ) || ! is_int( $limit ) ) {
			$limit = 0;
		}

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$sql = '
			SELECT *
			FROM ' . $table . '
			WHERE ' . $column . ' LIKE %s
			ORDER BY ' . $key_column . ' ASC
			';

		$args = array( $key );

		if ( ! empty( $limit ) ) {
			$sql .= ' LIMIT %d';

			$args[] = $limit;
		}

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queue leases and status require fresh prepared database reads/writes; table and column names are fixed WordPress schema fields.
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$args
			)
		);

		$batches = array();

		if ( ! empty( $items ) ) {
			$allowed_classes = $this->allowed_batch_data_classes;

			$batches = array_map(
				static function ( $item ) use ( $column, $value_column, $allowed_classes ) {
					$batch       = new \stdClass();
					$batch->key  = $item->{$column};
					$data        = static::maybe_unserialize( $item->{$value_column}, $allowed_classes );
					$batch->data = is_array( $data ) ? $data : array();

					return $batch;
				},
				$items
			);
		}

		return $batches;
	}

	/**
	 * Handle a dispatched request.
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 */
	protected function handle() {
		if ( ! $this->lock_process() ) {
			return $this->maybe_wp_die();
		}

		// Cancellation or pausing can be requested after maybe_handle() checks the
		// state but before this request acquires the lock. Recheck while we own it
		// so a cancelled task cannot start and a paused task cannot run once more.
		if ( $this->is_cancelled() ) {
			try {
				$this->delete_all();
			} finally {
				$this->unlock_process();
			}

			return $this->maybe_wp_die();
		}

		if ( $this->is_paused() ) {
			$this->clear_scheduled_event();
			$this->paused();
			$this->unlock_process();

			return $this->maybe_wp_die();
		}

		/**
		 * Number of seconds to sleep between items. Defaults to 0 seconds, minimum 0.
		 *
		 * @param int $seconds
		 */
		$throttle_seconds = $this->get_throttle_seconds();

		/**
		 * Maximum number of queue items to process per request.
		 *
		 * Low values reduce peak memory/CPU usage on weak hosts at the cost of speed.
		 * Can be overridden per process or globally via filters.
		 *
		 * @param int $items Default 5
		 */
		$items_per_request = $this->get_items_per_request();

		do {
			$batch = $this->get_batch();
			if ( ! is_object( $batch ) || empty( $batch->key ) || empty( $batch->data ) || ! is_array( $batch->data ) ) {
				if ( is_object( $batch ) && ! empty( $batch->key ) ) {
					$this->delete( $batch->key );
				}
				break;
			}

			$processed_in_request = 0;
			foreach ( $batch->data as $key => $value ) {
				$task = $this->task( $value );

				if ( false !== $task ) {
					$batch->data[ $key ] = $task;
				} else {
					unset( $batch->data[ $key ] );
				}

				$processed_in_request++;

				// Keep the batch up to date while processing it.
				if ( ! empty( $batch->data ) ) {
					$this->update( $batch->key, $batch->data );
				}

				// Let the server breathe a little.
				sleep( $throttle_seconds );

				// Per-request item limit reached, or batch limits reached, or pause/cancel requested.
				if ( $processed_in_request >= $items_per_request || ! $this->should_continue() ) {
					break;
				}
			}

			// Delete current batch if fully processed.
			if ( empty( $batch->data ) ) {
				$this->delete( $batch->key );
			}
		} while ( ! $this->is_queue_empty() && $this->should_continue() );

		$this->unlock_process();

		// If cancellation arrived while an item was executing, reclaim the lock
		// and perform cleanup here instead of dispatching another request.
		if ( $this->is_cancelled() ) {
			$this->cleanup_cancelled_if_idle();

			return $this->maybe_wp_die();
		}

		// Start next batch or complete process.
		if ( ! $this->is_queue_empty() ) {
			if ( ! $this->suppress_auto_dispatch ) {
				$this->dispatch();
			}
		} else {
			$this->complete();
		}

		return $this->maybe_wp_die();
	}

	/**
	 * Get the bounded process lock lease.
	 *
	 * @return int
	 */
	protected function get_queue_lock_duration() {
		$default = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60;
		$default = Runtime_Limits::bounded_int( $default, 60, 60, 24 * HOUR_IN_SECONDS );

		return Runtime_Limits::bounded_int(
			apply_filters( $this->identifier . '_queue_lock_time', $default ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			$default,
			60,
			24 * HOUR_IN_SECONDS
		);
	}

	/**
	 * Get the bounded delay between queue items.
	 *
	 * @return int
	 */
	protected function get_throttle_seconds() {
		$value = apply_filters(
			$this->identifier . '_seconds_between_batches', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			apply_filters(
				$this->prefix . '_seconds_between_batches', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
				0
			)
		);

		return Runtime_Limits::bounded_int( $value, 0, 0, 60 );
	}

	/**
	 * Get the bounded number of queue items handled by one request.
	 *
	 * @return int
	 */
	protected function get_items_per_request() {
		$value = apply_filters(
			$this->identifier . '_items_per_request', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			apply_filters( $this->prefix . '_items_per_request', 5 ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
		);

		return Runtime_Limits::bounded_int( $value, 5, 1, 100 );
	}

	/**
	 * Memory exceeded?
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	protected function memory_exceeded() {
		$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_memory_exceeded', $return ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @return int
	 */
	protected function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}

		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Time limit exceeded?
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		$time_limit = $this->get_default_time_limit();
		$finish     = $this->start_time + $time_limit;
		$return     = false;

		if ( time() >= $finish ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_time_exceeded', $return ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Get the bounded per-request processing time limit.
	 *
	 * @return int
	 */
	protected function get_default_time_limit() {
		return Runtime_Limits::bounded_int(
			apply_filters( $this->identifier . '_default_time_limit', 20 ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			20,
			1,
			60
		);
	}

	/**
	 * Complete processing.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		delete_site_option( $this->get_status_key() );

		// Remove the cron healthcheck job from the cron schedule.
		$this->clear_scheduled_event();

		$this->completed();
	}

	/**
	 * Called when background process has completed.
	 */
	protected function completed() {
		do_action( $this->identifier . '_completed', $this->get_chain_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
	}

	/**
	 * Get the cron healthcheck interval in minutes.
	 *
	 * Default is 5 minutes, minimum is 1 minute.
	 *
	 * @return int
	 */
	public function get_cron_interval() {
		$interval = 5;

		if ( property_exists( $this, 'cron_interval' ) ) {
			$interval = $this->cron_interval;
		}

		$interval = Runtime_Limits::bounded_int( $interval, 5, 1, 1440 );
		$interval = Runtime_Limits::bounded_int( apply_filters( $this->cron_interval_identifier, $interval ), $interval, 1, 1440 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.

		return $interval;
	}

	/**
	 * Schedule the cron healthcheck job.
	 *
	 * @access public
	 *
	 * @param mixed $schedules Schedules.
	 *
	 * @return mixed
	 */
	public function schedule_cron_healthcheck( $schedules ) {
		$interval = $this->get_cron_interval();

		// WordPress and other plugins can inspect schedules before init. Keep the
		// interval available then without triggering just-in-time translations.
		if ( ! did_action( 'init' ) ) {
			$display = 1 === $interval ? 'Every Minute' : sprintf( 'Every %d Minutes', $interval );
		} elseif ( 1 === $interval ) {
			$display = __( 'Every Minute', 'simply-static-studio-backup-migrate' );
		} else {
			$display = sprintf(
				/* translators: %d: interval in minutes. */
				__( 'Every %d Minutes', 'simply-static-studio-backup-migrate' ),
				$interval
			);
		}

		// Adds an "Every NNN Minute(s)" schedule to the existing cron schedules.
		$schedules[ $this->cron_interval_identifier ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			'display'  => $display,
		);

		return $schedules;
	}

	/**
	 * Handle cron healthcheck event.
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 */
	public function handle_cron_healthcheck() {
		if ( $this->is_processing() ) {
			// Background process already running.
			return;
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			$this->clear_scheduled_event();
			return;
		}

		$this->dispatch();
	}

	/**
	 * Schedule the cron healthcheck event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			Logger::log( 'Event not yet scheduled, scheduling it' );
			wp_schedule_event(
				time() + ( $this->get_cron_interval() * MINUTE_IN_SECONDS ),
				$this->cron_interval_identifier,
				$this->cron_hook_identifier
			);
		}
	}

	/**
	 * Clear scheduled cron healthcheck event.
	 */
	protected function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}

	/**
	 * Cancel the background process.
	 *
	 * Stop processing queue items, clear cron job and delete batch.
	 *
	 * @deprecated 1.1.0 Superseded.
	 * @see        cancel()
	 */
	public function cancel_process() {
		$this->cancel();
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
	abstract protected function task( $item );

	/**
	 * Maybe unserialize data, but not if an object.
	 *
	 * @param mixed      $data            Data to be unserialized.
	 * @param bool|array $allowed_classes Array of class names that can be unserialized.
	 *
	 * @return mixed
	 */
	protected static function maybe_unserialize( $data, $allowed_classes ) {
		if ( is_serialized( $data ) ) {
			$options = array();
			if ( is_bool( $allowed_classes ) || is_array( $allowed_classes ) ) {
				$options['allowed_classes'] = $allowed_classes;
			}

			return @unserialize( $data, $options ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Reads WordPress-owned queue serialization with explicit allowed_classes; malformed stored batches must not emit output.
		}

		return $data;
	}

	/**
	 * Should any processing continue?
	 *
	 * @return bool
	 */
	public function should_continue() {
		/**
		 * Filter whether the current background process should continue running the task
		 * if there is data to be processed.
		 *
		 * If the processing time or memory limits have been exceeded, the value will be false.
		 * If pause or cancel have been requested, the value will be false.
		 *
		 * It is very unlikely that you would want to override a false value with true.
		 *
		 * If false is returned here, it does not necessarily mean background processing is
		 * complete. If there is batch data still to be processed and pause or cancel have not
		 * been requested, it simply means this background process should spawn a new process
		 * for the chain to continue processing and then close itself down.
		 *
		 * @param bool   $continue Should the current process continue processing the task?
		 * @param string $chain_id The current background process chain's ID.
		 *
		 * @return bool
		 */
		return apply_filters(
			$this->identifier . '_should_continue', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			! ( $this->time_exceeded() || $this->memory_exceeded() || $this->is_paused() || $this->is_cancelled() ),
			$this->get_chain_id()
		);
	}

	/**
	 * Get the string used to identify this type of background process.
	 *
	 * @return string
	 */
	public function get_identifier() {
		return $this->identifier;
	}

	/**
	 * Return the current background process chain's ID.
	 *
	 * If the chain's ID hasn't been set before this function is first used,
	 * and hasn't been passed as a query arg during dispatch,
	 * the chain ID will be generated before being returned.
	 *
	 * @return string
	 */
	public function get_chain_id() {
		if ( empty( $this->chain_id ) && wp_doing_ajax() ) {
			check_ajax_referer( $this->identifier, 'nonce' );

			if ( isset( $_GET[ $this->get_chain_id_arg_name() ] ) && is_string( $_GET[ $this->get_chain_id_arg_name() ] ) ) {
				$chain_id = sanitize_key( wp_unslash( $_GET[ $this->get_chain_id_arg_name() ] ) );

				if ( wp_is_uuid( $chain_id ) ) {
					$this->chain_id = $chain_id;

					return $this->chain_id;
				}
			}
		}

		if ( empty( $this->chain_id ) ) {
			$this->chain_id = wp_generate_uuid4();
		}

		return $this->chain_id;
	}

	/**
	 * Filters the query arguments used during an async request.
	 *
	 * @param array $args Current query args.
	 *
	 * @return array
	 */
	public function filter_dispatch_query_args( $args ) {
		$args[ $this->get_chain_id_arg_name() ] = $this->get_chain_id();

		return $args;
	}

	/**
	 * Get the query arg name used for passing the chain ID to new processes.
	 *
	 * @return string
	 */
	private function get_chain_id_arg_name() {
		static $chain_id_arg_name;

		if ( ! empty( $chain_id_arg_name ) ) {
			return $chain_id_arg_name;
		}

		/**
		 * Filter the query arg name used for passing the chain ID to new processes.
		 *
		 * If you encounter problems with using the default query arg name, you can
		 * change it with this filter.
		 *
		 * @param string $chain_id_arg_name Default "chain_id".
		 *
		 * @return string
		 */
		$chain_id_arg_name = apply_filters( $this->identifier . '_chain_id_arg_name', self::CHAIN_ID_ARG_NAME ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.

		if ( ! is_string( $chain_id_arg_name ) || empty( $chain_id_arg_name ) ) {
			$chain_id_arg_name = self::CHAIN_ID_ARG_NAME;
		}

		return $chain_id_arg_name;
	}
}
