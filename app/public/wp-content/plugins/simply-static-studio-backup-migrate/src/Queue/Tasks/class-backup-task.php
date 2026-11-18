<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions carry plain text to JSON, logs and WP-CLI; HTML output is escaped at the rendering boundary.

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Queue\Exceptions\Retry_Exception;
use Simply_Static\Backup\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup_Task {

	protected $id = '';

	protected $done = false;

	protected $test = false;
	protected $tables = null;

	/**
	 * Maximum number of retries for this task.
	 *
	 * @var int
	 */
	protected $maxRetries = 1;

	/**
	 * Current retry count.
	 *
	 * @var int|null
	 */
	protected $retryCount = null;

	protected $longRunning = false;

	/**
	 * Token binding this task instance to one backup generation.
	 *
	 * @var string
	 */
	private $runToken = '';

	/**
	 * Bind this task to the run that created its queue item.
	 *
	 * @param string $run_token Run ownership token.
	 * @return $this
	 */
	public function setRunToken( $run_token ) {
		$this->runToken = is_string( $run_token ) ? $run_token : '';

		return $this;
	}

	/**
	 * Return the run token supplied by the queue or CLI orchestrator.
	 *
	 * @return string
	 */
	public function getRunToken() {
		return $this->runToken;
	}

	/**
	 * @param boolean $test If true, it will output queries but not perform them.
	 *
	 * @return void
	 */
	public function setTest( $test ) {
		$this->test = $test;
	}

	public function maybeTestStart() {
		if ( ! $this->test ) {
			return;
		}

		add_filter( 'query', [ $this, 'output_query' ] );
	}

	public function maybeTestEnd() {
		if ( ! $this->test ) {
			return;
		}

		remove_filter( 'query', [ $this, 'output_query' ] );
	}

	public function output_query( $query ) {
		print_r( $query . "\n" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.Security.EscapeOutput.OutputNotEscaped -- Explicit developer-only SQL dry-run output; this mode is never enabled by a browser request.

		if ( 'SHOW TABLES' === $query ) {
			return $query;
		}

		return '';
	}

	/**
	 * @return true
	 */
	protected function run() {
		return true;
	}

	public function isLongRunning() {
		return $this->longRunning;
	}

	public function perform() {
		if ( $this->isLongRunning() && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A fenced backup task must finish its filesystem checkpoint before PHP exits.
		}

		return $this->run();
	}

	public function getFilesystem() {
		return Helper::getFileSystem();
	}

	public function move( $from, $to ) {
		$filesystem = $this->getFilesystem();

		if ( ! $filesystem ) {
			throw new Retry_Exception( __( 'Filesystem is not available.', 'simply-static-studio-backup-migrate' ) );
		}

		if ( ! $filesystem->copy( $from, $to ) ) {
			throw new Retry_Exception( __( 'Could not copy a backup file.', 'simply-static-studio-backup-migrate' ) );
		}
	}

	/**
	 * Get the task name based on the class name.
	 *
	 * @return string
	 */
	public function getTaskName() {
		$className = get_class( $this );
		$parts     = explode( '\\', $className );

		return lcfirst( end( $parts ) );
	}

	/**
	 * Get the option name for storing the retry count.
	 *
	 * @return string
	 */
	protected function getRetryCountOptionName() {
		return 'simply_static_migration_retry_count_' . $this->getTaskName();
	}

	/**
	 * Get the current retry count from the database.
	 *
	 * @return int
	 */
	public function getRetryCount() {
		if ( null === $this->retryCount ) {
			$retryCount       = get_option( $this->getRetryCountOptionName(), 0 );
			$this->retryCount = (int) $retryCount;
		}

		return $this->retryCount;
	}

	/**
	 * Increment the retry count and save it to the database.
	 *
	 * @return void
	 */
	public function incrementRetryCount() {
		$this->retryCount = $this->getRetryCount() + 1;
		update_option( $this->getRetryCountOptionName(), $this->retryCount, false );
	}

	/**
	 * Check if the task has exceeded its maximum retries.
	 *
	 * @return bool
	 */
	public function hasExceededMaxRetries() {
		return $this->getRetryCount() > $this->maxRetries;
	}

	/**
	 * Reset the retry count in the database.
	 *
	 * @return void
	 */
	public function resetRetryCount() {
		delete_option( $this->getRetryCountOptionName() );
		$this->retryCount = 0;
	}

	public function save_task_params( $task_params ) {

		// When no work was done (e.g. ZIP lock failure), skip saving to
		// avoid overwriting progress made by a concurrent process.
		if ( ! empty( $task_params['_skip_save'] ) ) {
			return;
		}

		$params                    = Backup::get_export_params();
		$params[ $this->get_id() ] = $task_params;

		Backup::save_export_params( $params );
	}

	/**
	 * @return mixed|string
	 */
	public function get_task_params() {
		$params = Backup::get_export_params();

		return isset( $params[ $this->get_id() ] ) && is_array( $params[ $this->get_id() ] ) ? $params[ $this->get_id() ] : [];
	}

	/**
	 * Load and validate parameters produced by a prerequisite task.
	 *
	 * @param string $task_id Prerequisite task ID.
	 * @param array  $required_keys Keys required by the current task.
	 * @return array
	 * @throws Retry_Exception When persisted progress is missing or corrupt.
	 */
	protected function get_prerequisite_params( $task_id, $required_keys = array() ) {
		$params = Backup::get_export_params();

		if ( ! isset( $params[ $task_id ] ) || ! is_array( $params[ $task_id ] ) ) {
			throw new Retry_Exception(
				sprintf(
					/* translators: %s: prerequisite backup task ID. */
					__( 'Required backup state is missing for task "%s".', 'simply-static-studio-backup-migrate' ),
					$task_id
				)
			);
		}

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $params[ $task_id ] ) ) {
				throw new Retry_Exception(
					sprintf(
						/* translators: 1: missing state key, 2: prerequisite backup task ID. */
						__( 'Required backup value "%1$s" is missing for task "%2$s".', 'simply-static-studio-backup-migrate' ),
						$key,
						$task_id
					)
				);
			}
		}

		return $params[ $task_id ];
	}

	public function get_id() {
		if ( $this->id ) {
			return $this->id;
		}

		$class_name = get_class( $this );
		$parts      = explode( '\\', $class_name );
		$this->id   = strtolower( end( $parts ) );

		return $this->id;
	}

	protected function status( $message, $id = null ) {
		if ( null === $id ) {
			$id = $this->get_id();
		}

		Status::info( $message, $id );
	}

}
