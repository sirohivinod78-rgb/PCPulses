<?php

/**
 * CLI exceptions are plain-text diagnostics consumed by WP-CLI, not HTML output.
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 */

namespace Simply_Static\Backup\CLI;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Queue\Exceptions\Retry_Exception;
use Simply_Static\Backup\Queue\Exceptions\Skippable_Exception;
use Simply_Static\Backup\Runtime_Limits;
use Simply_Static\Backup\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Simply Static Studio Backup and Migrate
 */
class Backup_Command {
	const ARTIFACT_CONTRACT = 'simply-static-studio.backup-artifact';
	const PAUSE_CONTRACT = 'simply-static-studio.backup-pause';
	const PROGRESS_CONTRACT = 'simply-static-studio.backup-progress';
	const PURGE_CONTRACT = 'simply-static-studio.backup-purge';

	/**
	 * Registers the WP-CLI command namespace.
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sssbm backup', new self() );
		}
	}

	/**
	 * Run the full backup synchronously via CLI.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sssbm backup run
	 */
	public function run( $args, $assoc_args ) {
		$this->run_full_backup();
	}

	/**
	 * Pause the exact synchronous backup generation at its next checkpoint.
	 */
	public function pause( $args, $assoc_args ) {
		$run_token = Backup::get_current_run_token();
		$process   = Backup::get_process();
		if ( ! is_string( $run_token ) || ! $process->pause( $run_token ) ) {
			\WP_CLI::error( __( 'No active backup generation could be paused.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$json = wp_json_encode(
			array(
				'contract'       => self::PAUSE_CONTRACT,
				'schema_version' => 1,
				'status'         => 'paused',
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $json ) || '' === $json ) {
			\WP_CLI::error( __( 'The backup pause contract could not be encoded.', 'simply-static-studio-backup-migrate' ) );

			return;
		}
		\WP_CLI::line( $json );
	}

	/**
	 * Print durable backup progress as a machine-readable contract.
	 *
	 * This command is intentionally read-only and can be called from a separate
	 * process while `wp sssbm backup run` owns the synchronous generation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sssbm backup progress
	 */
	public function progress( $args, $assoc_args ) {
		try {
			$state = Backup::get_progress_state();
		} catch ( \Throwable $exception ) {
			\WP_CLI::error( $exception->getMessage() );

			return;
		}

		$payload = array_merge(
			array(
				'contract'       => self::PROGRESS_CONTRACT,
				'schema_version' => 1,
			),
			$state
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			\WP_CLI::error( __( 'The backup progress contract could not be encoded.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		// Keep stdout reserved for this one bounded machine contract.
		\WP_CLI::line( $json );
	}

	/**
	 * Print the latest completed backup artifact as a machine-readable contract.
	 *
	 * This command deliberately does not create a backup. Platform workers run
	 * `wp sssbm backup run` first, then call this command to discover the exact
	 * generation that the plugin validated and retained.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sssbm backup artifact
	 */
	public function artifact( $args, $assoc_args ) {
		if ( Backup::is_running() ) {
			\WP_CLI::error( __( 'A backup is still running; no stable artifact is available yet.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		if ( ! Backup::is_completed() ) {
			\WP_CLI::error( __( 'No completed backup artifact is available.', 'simply-static-studio-backup-migrate' ) );

			return;
		}
		if ( ! Backup::revoke_static_download() ) {
			\WP_CLI::error( __( 'The public backup download could not be revoked safely.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$integrity = Backup::get_backup_integrity_status();
		if ( empty( $integrity['is_pushable'] ) ) {
			$message = ! empty( $integrity['warning'] ) && is_scalar( $integrity['warning'] )
				? (string) $integrity['warning']
				: __( 'The completed backup failed its integrity checks.', 'simply-static-studio-backup-migrate' );
			\WP_CLI::error( $message );

			return;
		}

		$path = Backup::get_existing_backup_path();
		if ( ! is_string( $path ) || '' === $path ) {
			\WP_CLI::error( __( 'The completed backup archive is missing or unreadable.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		clearstatcache( true, $path );
		$before = @lstat( $path );
		$sha256 = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$after = @lstat( $path );
		if (
			! is_array( $before ) ||
			! is_array( $after ) ||
			(int) ( $after['size'] ?? 0 ) < 1 ||
			(int) ( $before['nlink'] ?? 0 ) !== 1 ||
			(int) ( $after['nlink'] ?? 0 ) !== 1 ||
			! is_string( $sha256 ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
		) {
			\WP_CLI::error( __( 'The completed backup artifact could not be fingerprinted.', 'simply-static-studio-backup-migrate' ) );

			return;
		}
		foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $identity_key ) {
			if ( (string) ( $before[ $identity_key ] ?? '' ) !== (string) ( $after[ $identity_key ] ?? '' ) ) {
				\WP_CLI::error( __( 'The completed backup artifact changed while it was being fingerprinted.', 'simply-static-studio-backup-migrate' ) );

				return;
			}
		}
		$bytes = (int) $after['size'];

		$payload = array(
			'contract'       => self::ARTIFACT_CONTRACT,
			'schema_version' => 1,
			'path'           => $path,
			'filename'       => basename( $path ),
			'bytes'          => $bytes,
			'sha256'         => $sha256,
			'media_type'     => 'application/zip',
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			\WP_CLI::error( __( 'The backup artifact contract could not be encoded.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		\WP_CLI::line( $json );
	}

	/**
	 * Purge the exact completed generation already verified in object storage.
	 *
	 * ## OPTIONS
	 *
	 * --expected-sha256=<sha256>
	 * : Exact lowercase SHA-256 returned by `wp sssbm backup artifact`.
	 *
	 * --expected-bytes=<bytes>
	 * : Exact positive byte length returned by `wp sssbm backup artifact`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sssbm backup purge --expected-sha256=<sha256> --expected-bytes=<bytes>
	 */
	public function purge( $args, $assoc_args ) {
		$sha256 = isset( $assoc_args['expected-sha256'] ) && is_string( $assoc_args['expected-sha256'] )
			? trim( $assoc_args['expected-sha256'] )
			: '';
		$bytes_value = $assoc_args['expected-bytes'] ?? null;
		$bytes       = filter_var(
			$bytes_value,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || false === $bytes ) {
			\WP_CLI::error( __( 'Both --expected-sha256 and --expected-bytes must contain the exact artifact identity.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		try {
			$status = Backup::purge_completed_artifact( $sha256, (int) $bytes );
		} catch ( \Throwable $exception ) {
			\WP_CLI::error( $exception->getMessage() );

			return;
		}

		if ( ! in_array( $status, array( 'purged', 'already_purged' ), true ) ) {
			\WP_CLI::error( __( 'The backup purge returned an invalid status.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$payload = array(
			'contract'       => self::PURGE_CONTRACT,
			'schema_version' => 1,
			'status'         => $status,
			'artifact'       => array(
				'sha256' => $sha256,
				'bytes'  => (int) $bytes,
			),
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			\WP_CLI::error( __( 'The backup purge contract could not be encoded.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		// Keep stdout reserved for this one bounded machine contract.
		\WP_CLI::line( $json );
	}

	/**
	 * Start the full backup and iterate through all tasks.
	 */
	protected function run_full_backup() {
		if ( is_multisite() && is_main_site() ) {
			\WP_CLI::error( __( 'Network-level multisite backups are not supported. Run the command for an individual subsite.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$process = Backup::get_process();
		$resuming = $process->is_paused();
		$run_token = $resuming ? Backup::get_current_run_token() : false;
		if ( $resuming ) {
			if (
				! is_string( $run_token ) ||
				! Backup::is_running() ||
				$process->is_queued()
			) {
				\WP_CLI::error( __( 'The paused backup generation cannot be resumed safely.', 'simply-static-studio-backup-migrate' ) );

				return;
			}
		} elseif ( ! Backup::acquire_run_lock( $run_token ) ) {
			\WP_CLI::error( __( 'A backup is already running or waiting to resume.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		if ( ! $process->lock_process_for_cli() ) {
			if ( ! $resuming ) {
				Backup::set_as_not_running( $run_token );
			}
			\WP_CLI::error( __( 'The exclusive backup worker lock could not be acquired.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$previous_archive = Backup::is_completed() ? Backup::get_existing_backup_path() : false;
		$paused = false;
		$failure = null;

		try {
			if ( $resuming ) {
				$tasks = $this->get_synchronous_resume_tasks();
				if ( ! $process->resume_synchronous( $run_token ) ) {
					throw new \RuntimeException( __( 'The paused backup generation changed before the CLI worker resumed it.', 'simply-static-studio-backup-migrate' ) );
				}
				\WP_CLI::log( __( 'Resuming Simply Static Studio Backup and Migrate (CLI)…', 'simply-static-studio-backup-migrate' ) );
			} else {
				$tasks = Backup::prepare_export_state( $run_token );
				\WP_CLI::log( __( 'Starting Simply Static Studio Backup and Migrate (CLI)…', 'simply-static-studio-backup-migrate' ) );
			}

			foreach ( $tasks as $task ) {
				$this->run_single_task_internal( $task, $run_token );
			}
		} catch ( Synchronous_Pause_Exception $exception ) {
			$paused = true;
		} catch ( \Throwable $exception ) {
			$owned = Backup::owns_run_lock( $run_token );
			if ( $owned ) {
				Backup::abort_export( $run_token );
				Status::error( $exception->getMessage() );
			}
			$failure = $exception;
		} finally {
			$process->release_process_lock();
		}
		if ( null !== $failure ) {
			// WP_CLI::error() exits in a real CLI process, bypassing finally blocks.
			// Release the worker lease before reporting the failure so a retry can run.
			\WP_CLI::error( $failure->getMessage() );

			return;
		}
		if ( $paused ) {
			\WP_CLI::warning( __( 'Backup paused at a resumable checkpoint.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		$completed_archive = Backup::get_existing_backup_path();
		if (
			! Backup::is_completed() ||
			! $completed_archive ||
			( $previous_archive && wp_normalize_path( $previous_archive ) === wp_normalize_path( $completed_archive ) )
		) {
			if ( Backup::owns_run_lock( $run_token ) ) {
				Backup::abort_export( $run_token );
			}
			\WP_CLI::error( __( 'The new backup failed validation. The previous completed backup was restored when available.', 'simply-static-studio-backup-migrate' ) );

			return;
		}

		\WP_CLI::success( __( 'Backup finished successfully.', 'simply-static-studio-backup-migrate' ) );
	}

	/**
	 * Init task
	 */
	public function init( $args, $assoc_args ) { $this->run_single_task( 'init' ); }
	public function archive( $args, $assoc_args ) { $this->run_single_task( 'archive' ); }
	public function config( $args, $assoc_args ) { $this->run_single_task( 'config' ); }
	public function config_file( $args, $assoc_args ) { $this->run_single_task( 'config_file' ); }
	public function enumerate_content( $args, $assoc_args ) { $this->run_single_task( 'enumerate_content' ); }
	public function enumerate_media( $args, $assoc_args ) { $this->run_single_task( 'enumerate_media' ); }
	public function enumerate_plugins( $args, $assoc_args ) { $this->run_single_task( 'enumerate_plugins' ); }
	public function enumerate_themes( $args, $assoc_args ) { $this->run_single_task( 'enumerate_themes' ); }
	public function enumerate_tables( $args, $assoc_args ) { $this->run_single_task( 'enumerate_tables' ); }
	public function content( $args, $assoc_args ) { $this->run_single_task( 'content' ); }
	public function media( $args, $assoc_args ) { $this->run_single_task( 'media' ); }
	public function plugins( $args, $assoc_args ) { $this->run_single_task( 'plugins' ); }
	public function themes( $args, $assoc_args ) { $this->run_single_task( 'themes' ); }
	public function database( $args, $assoc_args ) { $this->run_single_task( 'database' ); }
	public function database_file( $args, $assoc_args ) { $this->run_single_task( 'database_file' ); }
	public function clean( $args, $assoc_args ) { $this->run_single_task( 'clean' ); }

	/**
	 * Wrapper to run a single task with WP-CLI messages
	 *
	 * @param string $task
	 */
	protected function run_single_task( $task ) {
		\WP_CLI::error(
			sprintf(
				/* translators: %s: backup task ID. */
				__( 'Individual backup task "%s" is disabled. Use "wp sssbm backup run" so the complete generation remains atomic.', 'simply-static-studio-backup-migrate' ),
				$task
			)
		);

		return;
	}

	/**
	 * Run a single task and handle exceptions similar to the background process
	 *
	 * @param string $task
	 * @param string $run_token Exact generation token, or empty for isolated tests.
	 */
	protected function run_single_task_internal( $task, $run_token = '' ) {
		if ( '' !== $run_token && ! Backup::owns_run_lock( $run_token ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" no longer owns this backup run.', 'simply-static-studio-backup-migrate' ), $task ) );
		}

		$taskClass = '\\Simply_Static\\Backup\\Queue\\Tasks\\' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $task ) ) );
		if ( ! class_exists( $taskClass ) ) {
			throw new \RuntimeException( sprintf( /* translators: 1: backup task ID, 2: PHP class name. */ __( 'Task class not found for "%1$s" (%2$s).', 'simply-static-studio-backup-migrate' ), $task, $taskClass ) );
		}

		/** @var object $taskObj */
		$taskObj = new $taskClass();
		if ( '' !== $run_token ) {
			$taskObj->setRunToken( $run_token );
		}

		\WP_CLI::log( sprintf( /* translators: %s: backup task ID. */ __( 'Running task: %s', 'simply-static-studio-backup-migrate' ), $task ) );

		$runtime_limits    = $this->get_task_runtime_limits( $task );
		$iterations       = 0;
		$stalled          = 0;
		$max_iterations   = $runtime_limits['max_iterations'];
		$max_stalled      = $runtime_limits['max_stalled'];
		$delay            = $runtime_limits['delay'];
		$previous_progress = null;

		while ( $iterations < $max_iterations ) {
			try {
				if ( '' !== $run_token && ! Backup::owns_run_lock( $run_token ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" no longer owns this backup run.', 'simply-static-studio-backup-migrate' ), $task ) );
				}
				if ( '' !== $run_token && Backup::get_process()->is_paused() ) {
					throw new Synchronous_Pause_Exception( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" paused before its next iteration.', 'simply-static-studio-backup-migrate' ), $task ) );
				}

				update_option( 'sssbm_current_task', $task, false );
				update_option( 'sssbm_last_progress_at', time(), false );
				$done = $taskObj->perform();
				if ( '' !== $run_token && 'clean' !== $task && ! Backup::owns_run_lock( $run_token ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" lost ownership while it was running.', 'simply-static-studio-backup-migrate' ), $task ) );
				}
				if ( '' !== $run_token && Backup::get_process()->is_paused() ) {
					if ( $done ) {
						$taskObj->resetRetryCount();
						$next_task = Backup::get_next_task( $task );
						if ( is_string( $next_task ) && '' !== $next_task ) {
							update_option( 'sssbm_current_task', $next_task, false );
						}
					}
					throw new Synchronous_Pause_Exception( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" paused after a safe checkpoint.', 'simply-static-studio-backup-migrate' ), $task ) );
				}
				if ( $done ) {
					$taskObj->resetRetryCount();
					return;
				}

				$iterations++;
				$progress = md5( serialize( array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- This in-memory progress fingerprint is never stored or unserialized; native serialization preserves binary filenames and exact scalar types.
					Backup::get_export_param( $task, array() ),
					get_option( 'sssbm_media_bytes_offset', 0 ),
					is_file( Backup::get_database_path() ) ? filesize( Backup::get_database_path() ) : 0,
				) ) );
				$stalled  = null !== $previous_progress && hash_equals( $previous_progress, $progress ) ? $stalled + 1 : 0;
				$previous_progress = $progress;

				if ( $stalled >= $max_stalled ) {
					throw new \RuntimeException( sprintf( /* translators: 1: backup task ID, 2: iteration count. */ __( 'Task "%1$s" stopped making progress after %2$d iterations.', 'simply-static-studio-backup-migrate' ), $task, $stalled ) );
				}

				if ( 1 === $iterations || 0 === $iterations % 10 ) {
					\WP_CLI::log( sprintf( /* translators: 1: backup task ID, 2: iteration count. */ __( 'Task "%1$s" continuing (iteration %2$d)…', 'simply-static-studio-backup-migrate' ), $task, $iterations ) );
				}

				if ( $delay > 0 ) {
					sleep( $delay );
				}
			} catch ( Synchronous_Pause_Exception $e ) {
				throw $e;
			} catch ( Skippable_Exception $e ) {
				if ( '' !== $run_token && ! Backup::owns_run_lock( $run_token ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" no longer owns this backup run.', 'simply-static-studio-backup-migrate' ), $task ), 0, $e );
				}
				$taskObj->resetRetryCount();
				\WP_CLI::warning( sprintf( /* translators: 1: backup task ID, 2: error message. */ __( 'Task "%1$s" skipped: %2$s', 'simply-static-studio-backup-migrate' ), $task, $e->getMessage() ) );
				return;
			} catch ( Retry_Exception $e ) {
				if ( '' !== $run_token && ! Backup::owns_run_lock( $run_token ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" no longer owns this backup run.', 'simply-static-studio-backup-migrate' ), $task ), 0, $e );
				}
				$taskObj->incrementRetryCount();
				if ( $taskObj->hasExceededMaxRetries() ) {
					throw new \RuntimeException( sprintf( /* translators: 1: backup task ID, 2: error message. */ __( 'Task "%1$s" exceeded maximum retries. Last error: %2$s', 'simply-static-studio-backup-migrate' ), $task, $e->getMessage() ), 0, $e );
				}

				$iterations++;
				\WP_CLI::warning( sprintf( /* translators: 1: backup task ID, 2: error message, 3: retry number. */ __( 'Retrying task "%1$s" due to error: %2$s (attempt %3$d)', 'simply-static-studio-backup-migrate' ), $task, $e->getMessage(), $taskObj->getRetryCount() ) );
				sleep( 1 );
			} catch ( \Throwable $e ) {
				if ( '' !== $run_token && ! Backup::owns_run_lock( $run_token ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: backup task ID. */ __( 'Task "%s" no longer owns this backup run.', 'simply-static-studio-backup-migrate' ), $task ), 0, $e );
				}
				throw new \RuntimeException( sprintf( /* translators: 1: backup task ID, 2: error message. */ __( 'Task "%1$s" failed: %2$s', 'simply-static-studio-backup-migrate' ), $task, $e->getMessage() ), 0, $e );
			}
		}

		throw new \RuntimeException( sprintf( /* translators: 1: backup task ID, 2: iteration limit. */ __( 'Task "%1$s" did not complete after %2$d iterations.', 'simply-static-studio-backup-migrate' ), $task, $max_iterations ) );
	}

	/**
	 * Continue the frozen generation from its last durable task checkpoint.
	 *
	 * @return string[]
	 */
	protected function get_synchronous_resume_tasks() {
		$tasks = Backup::get_tasks();
		$current_task = get_option( 'sssbm_current_task', false );
		if ( false === $current_task || '' === $current_task ) {
			return $tasks;
		}
		if ( ! is_string( $current_task ) ) {
			throw new \RuntimeException( __( 'The paused backup task checkpoint is invalid.', 'simply-static-studio-backup-migrate' ) );
		}
		$offset = array_search( $current_task, $tasks, true );
		if ( false === $offset ) {
			throw new \RuntimeException( __( 'The paused backup task is absent from the frozen task sequence.', 'simply-static-studio-backup-migrate' ) );
		}

		return array_slice( $tasks, $offset );
	}

	/**
	 * Resolve bounded CLI loop controls once for a task execution.
	 *
	 * @param string $task Current task ID.
	 * @return array{max_iterations:int,max_stalled:int,delay:int}
	 */
	protected function get_task_runtime_limits( $task ) {
		return array(
			'max_iterations' => Runtime_Limits::bounded_int(
				apply_filters( 'sssbm_cli_max_task_iterations', 100000, $task ),
				100000,
				1,
				100000
			),
			'max_stalled'    => Runtime_Limits::bounded_int(
				apply_filters( 'sssbm_cli_max_stalled_iterations', 20, $task ),
				20,
				1,
				100
			),
			'delay'          => Runtime_Limits::bounded_int(
				apply_filters( 'sssbm_cli_iteration_delay', 0, $task ),
				0,
				0,
				60
			),
		);
	}
}

/** Internal control-flow signal for a generation paused by the platform. */
class Synchronous_Pause_Exception extends \RuntimeException {}
