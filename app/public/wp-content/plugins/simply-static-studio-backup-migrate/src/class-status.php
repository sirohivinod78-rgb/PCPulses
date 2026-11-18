<?php

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Status {

	public static function success( $message, ?string $id = null ) {
		self::message( $message, 'success', $id );
	}

	public static function info( $message, ?string $id = null ) {
		self::message( $message, 'info', $id );
	}

	public static function error( $message, ?string $id = null ) {
		self::message( $message, 'error', $id );
	}

	public static function warning( $message, ?string $id = null ) {
		self::message( $message, 'warning', $id );
	}

	/**
	 * @param string $message
	 * @param string $type
	 * @param string|null $id
	 *
	 * @return array
	 */
	public static function message( string $message, string $type = 'info', ?string $id = null ) {
		if ( ! in_array( $type, array( 'success', 'info', 'error', 'warning' ), true ) ) {
			$type = 'info';
		}

		if ( ! $id ) {
			$id = uniqid( 'sssbm_' );
		}
		$id = substr( sanitize_key( $id ), 0, 128 );
		if ( '' === $id ) {
			$id = uniqid( 'sssbm_' );
		}
		$message = substr( $message, 0, 4096 );

		$message = [
			'id'      => $id,
			'type'    => $type,
			'message' => $message,
			'time'    => time(),
		];

		return self::add_message( $message );
	}

	public static function clear_messages() {
		delete_option( 'sssbm_messages' );
	}

	public static function add_message( $message ) {
		if ( ! is_array( $message ) || empty( $message['id'] ) || ! isset( $message['message'] ) ) {
			return self::get_messages();
		}

		$messages = self::get_messages();
		$ids      = wp_list_pluck( $messages, 'id' );
		if ( in_array( $message['id'], $ids, true ) ) {
			$messages[ array_search( $message['id'], $ids, true ) ] = $message;
		} else {
			$messages[] = $message;
		}

		$messages = array_slice( $messages, -200 );
		self::save_messages( $messages );

		return $messages;
	}

	/**
	 * Get Messages.
	 *
	 * @return false|mixed|null
	 */
	public static function get_messages() {
		$messages = get_option( 'sssbm_messages', [] );

		return is_array( $messages ) ? $messages : [];
	}

	public static function save_messages( $messages ) {
		return is_array( $messages ) && update_option( 'sssbm_messages', $messages, false );
	}
}
