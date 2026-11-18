<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.unlink_unlink -- Diagnostic files remain private local files; exact-path unlink and chmod must not be redirected by filesystem transports or deletion filters.

namespace Simply_Static\Backup\Logs;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	/** Bound diagnostic storage so repeated integration failures cannot fill disk. */
	private const MAX_LOG_BYTES = 10485760;

	/** Keep one exception or remote error from monopolizing the diagnostic log. */
	private const MAX_LOG_ENTRY_BYTES = 16384;

	public static function clearLog() {
		$debug_file = self::getFilename();
		if ( is_file( $debug_file ) ) {
			@unlink( $debug_file );
		}
	}

	/**
	 * Return the filename for the debug log
	 *
	 * @return string Filename for the debug log
	 */
	public static function getFilename() {
		$storage_dir = Backup::get_storage_path();
		$default     = $storage_dir . 'sssbm-debug.txt';
		$filename    = apply_filters( 'sssbm_debug_log_file', $default, '' );

		return is_string( $filename ) && Helper::is_path_within( $filename, $storage_dir ) ? $filename : $default;
	}

	/**
	 * Save an object/string to the debug log
	 *
	 * @param mixed $object Object to save to the debug log
	 *
	 * @return void
	 */
	public static function log( $object = null ) {
		$debug_file = self::getFilename();
		$debug_dir  = dirname( $debug_file );
		if ( is_link( $debug_file ) ) {
			return;
		}

		if ( ! is_dir( $debug_dir ) && ! wp_mkdir_p( $debug_dir ) ) {
			return;
		}

		// add timestamp and newline
		$message = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ';

		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Private diagnostic provenance intentionally records the caller without arguments or secrets.
		if ( isset( $trace[0]['file'] ) ) {
			$file = basename( $trace[0]['file'] );
			if ( isset( $trace[0]['line'] ) ) {
				$file .= ':' . $trace[0]['line'];
			}
			$message .= '[' . $file . '] ';
		}

		$contents = self::getContent( $object );

		// Keep every entry on one terminal-safe line. Diagnostic files are often
		// opened directly, so strip control bytes that could manipulate a viewer.
		$contents = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $contents );
		$contents = str_replace( array( "\r", "\n" ), '', (string) $contents );

		$message .= $contents . "\n";
		if ( strlen( $message ) > self::MAX_LOG_ENTRY_BYTES ) {
			$message = substr( $message, 0, self::MAX_LOG_ENTRY_BYTES - 1 ) . "\n";
		}

		// WordPress filesystem writes cannot append with native lock ownership.
		// Verify the single-link local inode before changing permissions or writing.
		$handle = Helper::open_lock_file( $debug_file, Backup::get_storage_path() );
		if ( ! is_resource( $handle ) ) {
			return;
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return;
			}
			$stat = fstat( $handle );
			$current_size = is_array( $stat ) ? (int) $stat['size'] : self::MAX_LOG_BYTES;
			$remaining = self::MAX_LOG_BYTES - $current_size;
			if ( $remaining <= 0 || 0 !== fseek( $handle, 0, SEEK_END ) ) {
				return;
			}
			@fwrite( $handle, substr( $message, 0, $remaining ) );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Record exception provenance without copying function arguments or objects.
	 *
	 * PHP can retain arguments in exception traces, including SQL values and
	 * authentication credentials. Preserve useful locations and call names only,
	 * regardless of the server's zend.exception_ignore_args configuration.
	 *
	 * @param \Throwable $exception Exception or error being diagnosed.
	 * @return void
	 */
	public static function log_exception_trace( \Throwable $exception ) {
		$trace = array();
		$keys  = array_fill_keys( array( 'file', 'line', 'class', 'type', 'function' ), true );
		foreach ( array_slice( $exception->getTrace(), 0, 40 ) as $frame ) {
			$trace[] = array_intersect_key( $frame, $keys );
		}

		self::log( array(
			'exception' => get_class( $exception ),
			'trace'     => $trace,
		) );
	}

	/**
	 * Get contents of an object as a string
	 *
	 * @param mixed $object Object to get string for
	 *
	 * @return string         String containing the contents of the object
	 */
	protected static function getContent( $object ) {
		if ( is_string( $object ) ) {
			return $object;
		}

		$contents = wp_json_encode( $object, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$contents = false === $contents ? '[unserializable diagnostic]' : $contents;

		return $contents;
	}
}
