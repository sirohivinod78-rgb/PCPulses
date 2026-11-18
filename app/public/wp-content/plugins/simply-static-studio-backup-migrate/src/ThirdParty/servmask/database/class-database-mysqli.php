<?php

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\database;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

/**
 * MySQL export adapter using the active WordPress database abstraction.
 *
 * WordPress owns connection recovery and driver access. The exporter limits
 * row-producing queries to small pages because wpdb buffers each result set.
 */
class Database_Mysqli extends Database {

	/** @var int Error code for the most recent adapter query. */
	protected $query_errno = 0;

	/** @var string Error text for the most recent adapter query. */
	protected $query_error = '';

	/**
	 * Execute a prepared export statement through WordPress.
	 *
	 * @param string $input SQL built by Database from prepared identifiers/values
	 *                      and the exporter's trusted SQL expressions.
	 * @return \ArrayIterator Buffered result rows (empty for non-row statements).
	 */
	public function query( $input ) {
		$this->query_errno = 0;
		$this->query_error = '';

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Database prepares identifiers/literals at construction; fixed export expressions cannot be supplied as value placeholders.
			$result = $this->wpdb->query( $input );
		} catch ( \Throwable $exception ) {
			$this->query_errno = (int) $exception->getCode();
			$this->query_error = $exception->getMessage();
			throw new \RuntimeException(
				$this->query_failure_message(),
				$this->query_errno,
				$exception
			);
		}

		$this->query_error = (string) $this->wpdb->last_error;
		if ( false === $result || '' !== $this->query_error ) {
			// wpdb intentionally abstracts native error numbers. Preserve the
			// exporter's one supported repair case without reaching into its driver.
			$this->query_errno = preg_match( '/(?:marked as crashed|is crashed|should be repaired)/i', $this->query_error ) ? 1194 : 1;
			throw new \RuntimeException( $this->query_failure_message(), $this->query_errno );
		}

		// Keep wpdb's objects until each row is consumed, avoiding a second full
		// array copy of large values. Release WordPress's native result buffer now.
		$rows = new \ArrayIterator( (array) $this->wpdb->last_result );
		$this->wpdb->flush();

		return $rows;
	}

	/**
	 * Format a failure without exposing SQL containing private database values.
	 *
	 * @return string
	 */
	protected function query_failure_message() {
		return sprintf(
			/* translators: 1: database error code, 2: database error message. */
			__( 'Database query failed (%1$d): %2$s', 'simply-static-studio-backup-migrate' ),
			$this->query_errno,
			'' !== $this->query_error ? $this->query_error : __( 'Unknown database error.', 'simply-static-studio-backup-migrate' )
		);
	}

	/**
	 * Escape a value for the portable SQL dump, preserving literal percentages.
	 *
	 * Unlike executed queries, dump text never passes through WordPress's query
	 * filter, so its internal placeholder escape tokens must be removed here.
	 *
	 * @param string $input Database value.
	 * @return string Escaped value without surrounding SQL quotes.
	 */
	public function escape( $input ) {
		$quoted = $this->wpdb->prepare( '%s', (string) $input );
		return substr( $this->wpdb->remove_placeholder_escape( $quoted ), 1, -1 );
	}

	/** @return int */
	public function errno() {
		return $this->query_errno;
	}

	/** @return string */
	public function error() {
		return $this->query_error;
	}

	/** @return string */
	public function server_info() {
		return $this->wpdb->db_server_info();
	}

	/**
	 * Consume one buffered row without changing its NULL or binary values.
	 *
	 * @param \ArrayIterator $result Result rows.
	 * @return array|false
	 */
	public function fetch_assoc( &$result ) {
		if ( ! $result->valid() ) {
			return false;
		}
		$row = (array) $result->current();
		$result->next();
		return $row;
	}

	/**
	 * @param \ArrayIterator $result Result rows.
	 * @return array|false
	 */
	public function fetch_row( &$result ) {
		$row = $this->fetch_assoc( $result );
		return false === $row ? false : array_values( $row );
	}

	/**
	 * @param \ArrayIterator $result Result rows.
	 * @return int
	 */
	public function num_rows( &$result ) {
		return $result->count();
	}

	/**
	 * @param \ArrayIterator $result Result rows.
	 * @return bool
	 */
	public function free_result( &$result ) {
		$result = null;
		return true;
	}
}
