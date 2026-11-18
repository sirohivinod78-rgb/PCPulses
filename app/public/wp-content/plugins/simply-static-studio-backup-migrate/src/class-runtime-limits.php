<?php

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize extension-controlled runtime limits before using them in hot paths.
 */
final class Runtime_Limits {
	/**
	 * Normalize a finite numeric scalar to a bounded integer.
	 *
	 * @param mixed $value Filtered value.
	 * @param int   $default Fallback for malformed values.
	 * @param int   $minimum Inclusive minimum.
	 * @param int   $maximum Inclusive maximum.
	 * @return int
	 */
	public static function bounded_int( $value, $default, $minimum, $maximum ) {
		$default = min( $maximum, max( $minimum, (int) $default ) );
		$number  = self::finite_number( $value );

		if ( null === $number ) {
			return $default;
		}

		// Compare before casting so values near PHP_INT_MAX cannot overflow to
		// a negative integer on builds where the float rounds past the integer.
		if ( $number <= (float) $minimum ) {
			return $minimum;
		}

		if ( $number >= (float) $maximum ) {
			return $maximum;
		}

		return (int) $number;
	}

	/**
	 * Get the per-request exporter time budget.
	 *
	 * An explicit numeric zero remains available for CLI runs and tests that
	 * deliberately disable cooperative yielding. Other valid values are kept
	 * between one and sixty seconds; malformed and negative values use the
	 * ten-second default.
	 *
	 * @return float
	 */
	public static function completed_timeout() {
		$number = self::finite_number( apply_filters( 'sssbm_completed_timeout', 10 ) );

		if ( null === $number || $number < 0 ) {
			return 10.0;
		}

		if ( 0.0 === $number ) {
			return 0.0;
		}

		return min( 60.0, max( 1.0, $number ) );
	}

	/**
	 * Convert a finite numeric scalar to a float.
	 *
	 * @param mixed $value Candidate value.
	 * @return float|null
	 */
	private static function finite_number( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return null;
			}
		} elseif ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return is_finite( $number ) ? $number : null;
	}
}
