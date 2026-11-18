<?php

namespace Simply_Static\Backup\ThirdParty\servmask\database;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Database_Utility {

	protected static $db_client = null;

	public static function set_client( $db_client ) {
		self::$db_client = $db_client;
	}

	/**
	 * Get MySQLClient to be used for DB manipulation
	 *
	 * @return Database
	 */
	public static function create_client() {
		global $wpdb;

		if ( self::$db_client ) {
			return self::$db_client;
		}

		if ( $wpdb instanceof \WP_SQLite_DB || $wpdb instanceof \WP_SQLite_DB\wpsqlitedb ) {
			// Modern SQLite drop-ins expose a MySQL dialect through wpdb. Native
			// SQLite metadata statements (PRAGMA/sqlite_master) bypass that contract.
			if ( method_exists( $wpdb, 'get_driver' ) || ( defined( 'WP_SQLITE_AST_DRIVER' ) && WP_SQLITE_AST_DRIVER ) ) {
				return new Database_Mysqli( $wpdb );
			}

			return new Database_Sqlite( $wpdb );
		}

		return new Database_Mysqli( $wpdb );
	}

	/**
	 * Replace all occurrences of the search string with the replacement string.
	 * This function is case-sensitive.
	 *
	 * @param  string $data    Data to replace
	 * @param  array  $search  List of string we're looking to replace
	 * @param  array  $replace What we want it to be replaced with
	 * @return string          The original string with all elements replaced as needed
	 */
	public static function replace_values( $data, $search = array(), $replace = array() ) {
		return strtr( $data, array_combine( $search, $replace ) );
	}

	/**
	 * Replace serialized occurrences of the search string with the replacement string.
	 * This function is case-sensitive.
	 *
	 * @param  string $data    Data to replace
	 * @param  array  $search  List of string we're looking to replace
	 * @param  array  $replace What we want it to be replaced with
	 * @return string          The original array with all elements replaced as needed
	 */
	public static function replace_serialized_values( $data, $search = array(), $replace = array() ) {
		$pos = 0;

		$result = self::parse_serialized_values( $data, $pos, $search, $replace );
		if ( $pos !== strlen( $data ) ) {
			// Failed to parse entire data
			return strtr( $data, array_combine( $search, $replace ) );
		}

		return $result;
	}

	/**
	 * Parse serialized string and replace needed substitutions.
	 * This function is case-sensitive.
	 *
	 * @param  string  $data    Serialized data
	 * @param  integer $pos     Character position
	 * @param  array   $search  List of string we're looking to replace
	 * @param  array   $replace What we want it to be replaced with
	 * @return string           The original string with all elements replaced as needed
	 */
	public static function parse_serialized_values( $data, &$pos, $search = array(), $replace = array() ) {
		$length = strlen( $data );
		if ( $pos >= $length ) {
			return '';
		}

		$type = $data[ $pos ];
		$pos++;

		switch ( $type ) {
			case 's':
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$len_end = strpos( $data, ':', $pos );
				if ( $len_end === false ) {
					return '';
				}

				$length_token = substr( $data, $pos, $len_end - $pos );
				$str_length = (int) $length_token;
				if ( ! preg_match( '/^[0-9]+$/D', $length_token ) || $str_length > $length - $len_end - 2 ) {
					return '';
				}

				$pos = $len_end + 1;
				if ( ( $data[ $pos ] ?? null ) !== '"' ) {
					return '';
				}

				$pos++;
				$str = substr( $data, $pos, $str_length );

				$pos += $str_length;
				if ( ( $data[ $pos ] ?? null ) !== '"' ) {
					return '';
				}

				$pos++;
				if ( ( $data[ $pos ] ?? null ) !== ';' ) {
					return '';
				}

				$pos++;

				// If the string is a single letter, skip any parsing or replacement.
				if ( $str_length === 1 ) {
					return 's:' . $str_length . ':"' . $str . '";';
				}

				// Attempt to parse the string as serialized data
				$pos_inner  = 0;
				$parsed_str = self::parse_serialized_values( $str, $pos_inner, $search, $replace );
				if ( $pos_inner === strlen( $str ) ) {
					// The string is serialized data, use the parsed string
					$new_str = $parsed_str;
				} else {
					// Regular string, perform replacement
					$new_str = strtr( $str, array_combine( $search, $replace ) );
				}

				return 's:' . strlen( $new_str ) . ':"' . $new_str . '";';

			case 'i':
			case 'd':
			case 'b':
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$end = strpos( $data, ';', $pos );
				if ( $end === false ) {
					return '';
				}

				$value = substr( $data, $pos, $end - $pos );
				$pos   = $end + 1;

				return $type . ':' . $value . ';';

			case 'N':
				if ( ( $data[ $pos ] ?? null ) !== ';' ) {
					return '';
				}

				$pos++;

				return 'N;';

			case 'a':
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$len_end = strpos( $data, ':', $pos );
				if ( $len_end === false ) {
					return '';
				}

				$length_token = substr( $data, $pos, $len_end - $pos );
				$array_length = (int) $length_token;
				if ( ! preg_match( '/^[0-9]+$/D', $length_token ) || $array_length > $length ) {
					return '';
				}

				$pos = $len_end + 1;
				if ( ( $data[ $pos ] ?? null ) !== '{' ) {
					return '';
				}

				$pos++;
				$result = 'a:' . $array_length . ':{';
				for ( $i = 0; $i < $array_length * 2; $i++ ) {
					$element = self::parse_serialized_values( $data, $pos, $search, $replace );
					if ( $element === '' ) {
						return '';
					}

					$result .= $element;
				}

				if ( ( $data[ $pos ] ?? null ) !== '}' ) {
					return '';
				}

				$pos++;
				$result .= '}';

				return $result;

			case 'O':
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$class_len_end = strpos( $data, ':', $pos );
				if ( $class_len_end === false ) {
					return '';
				}

				$length_token = substr( $data, $pos, $class_len_end - $pos );
				$class_length = (int) $length_token;
				if ( ! preg_match( '/^[0-9]+$/D', $length_token ) || $class_length > $length - $class_len_end - 2 ) {
					return '';
				}

				$pos = $class_len_end + 1;
				if ( ( $data[ $pos ] ?? null ) !== '"' ) {
					return '';
				}

				$pos++;
				$class_name = substr( $data, $pos, $class_length );

				$pos += $class_length;
				if ( ( $data[ $pos ] ?? null ) !== '"' ) {
					return '';
				}

				$pos++;
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$prop_len_end = strpos( $data, ':', $pos );
				if ( $prop_len_end === false ) {
					return '';
				}

				$length_token = substr( $data, $pos, $prop_len_end - $pos );
				$prop_count = (int) $length_token;
				if ( ! preg_match( '/^[0-9]+$/D', $length_token ) || $prop_count > $length ) {
					return '';
				}

				$pos = $prop_len_end + 1;
				if ( ( $data[ $pos ] ?? null ) !== '{' ) {
					return '';
				}

				$pos++;
				$result = 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . $prop_count . ':{';
				for ( $i = 0; $i < $prop_count * 2; $i++ ) {
					$element = self::parse_serialized_values( $data, $pos, $search, $replace );
					if ( $element === '' ) {
						return '';
					}

					$result .= $element;
				}

				if ( ( $data[ $pos ] ?? null ) !== '}' ) {
					return '';
				}

				$pos++;
				$result .= '}';

				return $result;

			case 'R':
			case 'r':
				if ( ( $data[ $pos ] ?? null ) !== ':' ) {
					return '';
				}

				$pos++;
				$end = strpos( $data, ';', $pos );
				if ( $end === false ) {
					return '';
				}

				$ref = substr( $data, $pos, $end - $pos );
				$pos = $end + 1;

				return $type . ':' . $ref . ';';

			default:
				return '';
		}
	}

	/**
	 * Escape MySQL special characters
	 *
	 * @param  string $data Data to escape
	 * @return string
	 */
	public static function escape_mysql( $data ) {
		return strtr(
			$data,
			array_combine(
				array( "\x00", "\n", "\r", '\\', "'", '"', "\x1a" ),
				array( '\\0', '\\n', '\\r', '\\\\', "\\'", '\\"', '\\Z' )
			)
		);
	}

	/**
	 * Unescape MySQL special characters
	 *
	 * @param  string $data Data to unescape
	 * @return string
	 */
	public static function unescape_mysql( $data ) {
		return strtr(
			$data,
			array_combine(
				array( '\\0', '\\n', '\\r', '\\\\', "\\'", '\\"', '\\Z' ),
				array( "\x00", "\n", "\r", '\\', "'", '"', "\x1a" )
			)
		);
	}

	/**
	 * Encode base64 characters
	 *
	 * @param  string $data Data to encode
	 * @return string
	 */
	public static function base64_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes stored page-builder data during portable database export; no code is evaluated.
		return base64_encode( $data );
	}

	/**
	 * Encode base64 characters
	 *
	 * @param  string $data Data to decode
	 * @return string
	 */
	public static function base64_decode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes stored page-builder data for content replacement; no code is evaluated.
		return base64_decode( $data );
	}

	/**
	 * Validate base64 data
	 *
	 * @param  string  $data Data to validate
	 * @return boolean
	 */
	public static function base64_validate( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Checks canonical base64 data before content replacement; never executes the decoded value.
		return base64_encode( base64_decode( $data ) ) === $data;
	}
}
