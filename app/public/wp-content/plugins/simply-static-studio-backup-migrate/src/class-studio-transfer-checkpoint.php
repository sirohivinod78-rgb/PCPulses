<?php

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Private, credential-free recovery state for one immutable Studio transfer. */
class Studio_Transfer_Checkpoint {
	const OPTION = 'sssbm_studio_transfer_checkpoint';

	/** Fingerprint a stable archive; a filename and byte count alone are insufficient. */
	public static function fingerprint( $path, $bytes ) {
		clearstatcache( true, $path );
		$before = @lstat( $path );
		$sha256 = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$after = @lstat( $path );
		$generation = Backup::get_completed_generation_id();
		if ( ! is_array( $before ) || ! is_array( $after ) || is_link( $path ) || 1 !== (int) ( $after['nlink'] ?? 0 ) || (int) ( $after['size'] ?? 0 ) !== $bytes || ! is_string( $sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || ! is_string( $generation ) ) {
			return self::error( 'studio_archive_changed' );
		}
		$identity = array();
		foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime', 'nlink' ) as $key ) {
			if ( (string) ( $before[ $key ] ?? '' ) !== (string) ( $after[ $key ] ?? '' ) ) {
				return self::error( 'studio_archive_changed' );
			}
			$identity[ $key ] = (string) $after[ $key ];
		}
		return array( 'generation_id' => $generation, 'sha256' => $sha256, 'bytes' => $bytes, 'file_identity' => $identity );
	}

	/** Load only the same account, bytes, archive generation and original target. */
	public static function prepare( $archive, $actor, $target, $operation_token ) {
		$current = get_option( self::OPTION, false );
		if ( false !== $current && ! self::valid( $current ) ) {
			return self::error( 'studio_transfer_changed' );
		}
		$completed_previous_generation = is_array( $current ) && 'queued' === ( $current['stage'] ?? null ) && ( $current['archive']['generation_id'] ?? null ) !== $archive['generation_id'];
		if ( false !== $current && ! $completed_previous_generation ) {
			if ( ! is_array( $current ) || 1 !== ( $current['schema_version'] ?? null ) || $actor !== ( $current['actor'] ?? null ) || $archive !== ( $current['archive'] ?? null ) || ! in_array( $current['stage'] ?? null, array( 'init', 'uploading', 'finalizing', 'creating', 'created', 'queued' ), true ) ) {
				return self::error( 'studio_transfer_changed' );
			}
			// Remembering a confirmed new site changes the UI dropdown to that exact
			// destination. It remains the same transfer, not an existing-site retarget.
			$confirmed_new_site = '' === ( $current['target']['site_id'] ?? null ) && ! empty( $current['destination_site_id'] ) && $target['site_id'] === $current['destination_site_id'];
			if ( ! $confirmed_new_site && $target !== ( $current['target'] ?? null ) ) {
				return self::error( 'studio_transfer_changed' );
			}
			return $current;
		}
		$state = array(
			'schema_version' => 1,
			'request_id' => wp_generate_uuid4(),
			'actor' => $actor,
			'archive' => $archive,
			'target' => $target,
			'stage' => 'init',
			'artifact_id' => null,
			'site_identifier' => null,
			'destination_site_id' => null,
		);
		return self::save( $current, $state, $operation_token ) ? $state : self::error( 'studio_transfer_changed' );
	}

	private static function valid( $state ) {
		if ( ! is_array( $state ) || 1 !== ( $state['schema_version'] ?? null ) || ! is_string( $state['request_id'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $state['request_id'] ) || ! is_string( $state['actor'] ?? null ) || ! is_array( $state['archive'] ?? null ) || ! is_array( $state['target'] ?? null ) || ! in_array( $state['stage'] ?? null, array( 'init', 'uploading', 'finalizing', 'creating', 'created', 'queued' ), true ) ) {
			return false;
		}
		$unknown = array_diff( array_keys( $state ), array( 'schema_version', 'request_id', 'actor', 'archive', 'target', 'stage', 'artifact_id', 'site_identifier', 'destination_site_id' ) );
		if ( $unknown || ( 'init' !== $state['stage'] && ( ! is_string( $state['artifact_id'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $state['artifact_id'] ) || ! is_string( $state['site_identifier'] ?? null ) || ! preg_match( '/^[a-z]{2}[a-z0-9]{13}$/D', $state['site_identifier'] ) ) ) ) {
			return false;
		}
		if ( in_array( $state['stage'], array( 'created', 'queued' ), true ) && ( ! is_string( $state['destination_site_id'] ?? null ) || ! preg_match( '/^[1-9][0-9]{0,19}$/D', $state['destination_site_id'] ) ) ) {
			return false;
		}
		return true;
	}

	/** Exact-value writes fence late HTTP replies after another lease takes over. */
	public static function save( $before, $after, $operation_token ) {
		if ( ! Backup::owns_operation_lock( $operation_token, 'studio_push' ) ) {
			return false;
		}
		if ( false === $before ) {
			return add_option( self::OPTION, $after, '', 'no' );
		}
		if ( $before === $after ) {
			return get_option( self::OPTION, false ) === $before;
		}
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $after ), self::OPTION, maybe_serialize( $before ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-value CAS protects a credential-free local recovery receipt.
		if ( 1 === $changed ) {
			wp_cache_delete( self::OPTION, 'options' );
			return true;
		}
		return false;
	}

	public static function error( $code ) {
		return new \WP_Error( $code, __( 'The saved Studio transfer no longer matches this backup, account or destination. Resume it with its original settings before starting another transfer.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
	}
}
