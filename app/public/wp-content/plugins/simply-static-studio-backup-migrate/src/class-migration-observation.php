<?php

namespace Simply_Static\Backup;

use Simply_Static\Backup\Rest\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit, source-admin-approved progress sharing. This is not a command API.
 *
 * The source capability is generated and retained by PHP. Neither it nor the
 * existing Studio credentials are returned to wp-admin JavaScript or chat.
 */
class Migration_Observation {
	const STATE_OPTION = 'sssbm_migration_observation';
	const SOURCE_OPTION = 'sssbm_migration_source_id';
	const LOCK_OPTION = 'sssbm_migration_observation_lock';
	const CRON_HOOK = 'sssbm_migration_observation';

	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'heartbeat' ) );
		add_action( 'sssbm_studio_credentials_cleared', array( self::class, 'stop' ) );
	}

	public function can_manage( $request = null ) {
		return ! Plugin::is_managed_runtime()
			&& current_user_can( 'manage_options' )
			&& $this->settings->can_manage_backups( $request );
	}

	public function register_routes() {
		foreach ( array( '' => 'status', '/preview' => 'preview', '/confirm' => 'confirm', '/revoke' => 'revoke', '/heartbeat' => 'refresh' ) as $suffix => $method ) {
			register_rest_route( 'sss-backup-migrate/v1', '/migration-observation' . $suffix, array(
				'methods' => '' === $suffix ? 'GET' : 'POST',
				'callback' => array( $this, $method ),
				'permission_callback' => array( $this, 'can_manage' ),
			) );
		}
	}

	/** Local-only removal also fences late responses from in-flight requests. */
	public static function stop() {
		delete_option( self::STATE_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function status() {
		$state = $this->current_state();
		return array(
			'status' => 200,
			'connected' => is_array( $state ) && 'active' === ( $state['state'] ?? '' ),
			'pairing' => is_array( $state ) ? $this->public_details( $state ) : null,
			'last_sent_at' => is_array( $state ) ? ( $state['last_sent_at'] ?? null ) : null,
			'error_code' => is_array( $state ) ? ( $state['error_code'] ?? null ) : null,
			'transfer_tracking_approved' => is_array( $state ) && true === ( $state['transfer_tracking_approved'] ?? false ),
			'transfer' => is_array( $state ) ? ( $state['transfer_receipt'] ?? null ) : null,
		);
	}

	/** Preview authenticates with the existing Studio account and changes no backup. */
	public function preview( $request ) {
		$pairing_id = $this->pairing_id( $request );
		if ( is_wp_error( $pairing_id ) ) {
			return $pairing_id;
		}
		$current = $this->current_state();
		if ( is_array( $current ) && 'active' === $current['state'] ) {
			return $this->error( 'observation_already_connected', 409 );
		}
		if ( is_array( $current ) && $current['pairing_id'] === $pairing_id && ! empty( $current['source_token'] ) ) {
			// Recover an ambiguous confirmation without replacing its saved capability.
			return array( 'status' => 200, 'pairing' => $this->public_details( $current ) );
		}
		$identity = $this->identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$actor = get_option( 'sssbm_studio_user_id', '' );
		$result = $this->settings->request_migration_observation( array_merge( $identity, array( 'action' => 'preview', 'pairing_id' => $pairing_id ) ) );
		$details = $this->validate_details( $result, $identity['source_url'], 15 * MINUTE_IN_SECONDS, 'pending' );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		if ( $details['migration_id'] !== $pairing_id ) {
			return $this->error( 'observation_invalid_response', 502 );
		}
		$state = array_merge( $details, array(
			'pairing_id' => $pairing_id,
			'source_id' => $identity['source_id'],
			'plugin_version' => SSSBM_VERSION,
			'user_id' => $actor,
			'state' => 'pending',
		) );
		if ( ! $this->identity_matches( $state ) ) {
			return $this->error( 'observation_changed', 409 );
		}
		// A preview may not overwrite an active pair established concurrently.
		if ( false === $current ) {
			$saved = add_option( self::STATE_OPTION, $state, '', 'no' );
		} else {
			$saved = $this->replace_exact( self::STATE_OPTION, $current, $state );
		}
		if ( ! $saved ) {
			return $this->error( 'observation_changed', 409 );
		}
		return array( 'status' => 200, 'pairing' => $this->public_details( $state ) );
	}

	/** Confirmation is a separate nonce-protected POST following visible consent. */
	public function confirm( $request ) {
		$pairing_id = $this->pairing_id( $request );
		$transfer_approval = $request->get_param( 'approved_transfer' );
		if ( is_wp_error( $pairing_id ) || true !== $request->get_param( 'approved' ) || ( null !== $transfer_approval && ! is_bool( $transfer_approval ) ) ) {
			return $this->error( 'observation_approval_required', 400 );
		}
		$lock = $this->lock();
		if ( ! $lock ) {
			return $this->error( 'observation_busy', 409 );
		}
		try {
			$state = $this->current_state();
			if ( ! is_array( $state ) || $state['pairing_id'] !== $pairing_id ) {
				return $this->error( 'observation_preview_required', 409 );
			}
			if ( 'active' === $state['state'] ) {
				return $this->status();
			}
			if ( empty( $state['source_token'] ) ) {
				try {
					$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url encodes a random pairing token; it never contains executable code.
				} catch ( \Throwable $exception ) {
					return $this->error( 'observation_unavailable', 503 );
				}
				$with_token = array_merge( $state, array( 'source_token' => $token, 'transfer_tracking_requested' => true === $transfer_approval ) );
				// Persist before network I/O: an ambiguous confirmation retries the same token.
				if ( ! $this->replace_exact( self::STATE_OPTION, $state, $with_token ) ) {
					return $this->error( 'observation_changed', 409 );
				}
				$state = $with_token;
			}
			if ( (bool) ( $state['transfer_tracking_requested'] ?? false ) !== ( true === $transfer_approval ) ) {
				return $this->error( 'observation_changed', 409 );
			}
			$identity = $this->identity();
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$confirmation = array_merge( $identity, array(
				'action' => 'confirm',
				'pairing_id' => $pairing_id,
				'source_token' => $state['source_token'],
			) );
			if ( true === $transfer_approval ) {
				$confirmation['track_transfer'] = true;
			}
			$result = $this->settings->request_migration_observation( $confirmation );
			$details = $this->validate_details( $result, $identity['source_url'], 24 * HOUR_IN_SECONDS, 'active' );
			if ( is_wp_error( $details ) ) {
				return $details;
			}
			if ( $details['migration_id'] !== $state['migration_id'] || $details['site_id'] !== $state['site_id'] || ( true === ( $result['transfer_tracking_approved'] ?? false ) ) !== ( true === $transfer_approval ) ) {
				return $this->error( 'observation_invalid_response', 502 );
			}
			$active = array_merge( $state, $details, array( 'state' => 'active', 'sequence' => 0, 'pending' => null, 'last_sent_at' => null, 'error_code' => null, 'transfer_tracking_approved' => true === $transfer_approval ) );
			if ( ! $this->identity_matches( $state ) || ! $this->replace_exact( self::STATE_OPTION, $state, $active ) ) {
				return $this->error( 'observation_changed', 409 );
			}
			$this->schedule();
			return $this->status();
		} finally {
			$this->unlock( $lock );
		}
	}

	/** Stop locally first, even when the remote broker is unavailable. */
	public function revoke() {
		$state = get_option( self::STATE_OPTION, false );
		if ( is_array( $state ) && ! $this->delete_exact( self::STATE_OPTION, $state ) ) {
			return $this->error( 'observation_changed', 409 );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$remote_revoked = true;
		if ( is_array( $state ) && ! empty( $state['source_token'] ) ) {
			$result = $this->settings->request_migration_observation( array( 'action' => 'revoke', 'migration_id' => $state['migration_id'] ), $state['source_token'] );
			$remote_revoked = ! is_wp_error( $result ) && $state['migration_id'] === ( $result['migration_id'] ?? null ) && 'revoked' === ( $result['status'] ?? null );
		}
		return array( 'status' => 200, 'connected' => false, 'remote_revoked' => $remote_revoked );
	}

	public function refresh() {
		$this->heartbeat();
		return $this->status();
	}

	/** PHP-only artifact association; the observation capability never reaches JS. */
	public function bind_transfer( $artifact_id, $sha256, $bytes ) {
		$state = $this->current_state();
		if ( ! is_array( $state ) || 'active' !== $state['state'] || true !== ( $state['transfer_tracking_approved'] ?? false ) ) {
			return null;
		}
		if ( ! $this->uuid( $artifact_id ) || ! is_string( $sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || ! is_int( $bytes ) || $bytes < 1 ) {
			return $this->error( 'observation_invalid_transfer', 400 );
		}
		$generation = Backup::get_completed_generation_id();
		if ( ! is_string( $generation ) || $this->transfer_generation_changed( $state ) || ( isset( $state['transfer_artifact_id'] ) && ( $state['transfer_artifact_id'] !== $artifact_id || $state['transfer_sha256'] !== $sha256 || $state['transfer_bytes'] !== $bytes ) ) ) {
			return new \WP_Error( 'observation_new_pairing_required', __( 'This MCP pairing tracks another backup. Turn off MCP support or approve a fresh pairing before tracking this transfer.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}
		$lock = $this->lock();
		if ( ! $lock ) {
			return $this->error( 'observation_busy', 409 );
		}
		try {
			$current = $this->current_state();
			if ( $current !== $state ) {
				return $this->error( 'observation_changed', 409 );
			}
			$intent = array_merge( $state, array( 'transfer_generation_id' => $generation, 'transfer_run_identity' => $this->run_identity(), 'transfer_artifact_id' => $artifact_id, 'transfer_sha256' => $sha256, 'transfer_bytes' => $bytes ) );
			if ( ! $this->replace_exact( self::STATE_OPTION, $state, $intent ) ) {
				return $this->error( 'observation_changed', 409 );
			}
			$result = $this->settings->request_migration_observation( array( 'action' => 'bind_transfer', 'migration_id' => $state['migration_id'], 'source_token' => $state['source_token'], 'artifact_id' => $artifact_id, 'sha256' => $sha256, 'bytes' => $bytes ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$destination = $result['destination_site_id'] ?? null;
			$finalized = $result['finalized_at'] ?? null;
			$verified = in_array( $result['status'] ?? null, array( 'ready', 'attached' ), true );
			if ( $state['migration_id'] !== ( $result['migration_id'] ?? null ) || true !== ( $result['transfer_tracking_approved'] ?? null ) || ! is_bool( $result['transfer_bound'] ?? null ) || ! is_bool( $result['destination_authorization_required'] ?? null ) || ( $verified ? $bytes : null ) !== ( $result['archive_bytes'] ?? null ) || ! in_array( $result['status'] ?? null, array( 'not_started', 'pending_upload', 'finalizing', 'ready', 'attached', 'failed', 'aborted', 'expired', 'unavailable' ), true ) || ( 'attached' === $result['status'] ) !== $result['transfer_bound'] || ( null !== $destination && ( ! is_string( $destination ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $destination ) ) ) || ( $verified && ( ! is_string( $finalized ) || false === strtotime( $finalized ) || strtotime( $finalized ) > time() + 30 ) ) || ( ! $verified && null !== $finalized ) || ( $result['transfer_bound'] && null === $destination ) || ( ! $result['transfer_bound'] && null !== $destination ) ) {
				return $this->error( 'observation_invalid_response', 502 );
			}
			$receipt = array_intersect_key( $result, array_flip( array( 'migration_id', 'transfer_tracking_approved', 'transfer_bound', 'status', 'destination_site_id', 'destination_authorization_required', 'archive_bytes', 'finalized_at' ) ) );
			$next = array_merge( $intent, array( 'transfer_receipt' => $receipt ) );
			if ( ! $this->identity_matches( $intent ) || ! $this->replace_exact( self::STATE_OPTION, $intent, $next ) ) {
				return $this->error( 'observation_changed', 409 );
			}
			return $receipt;
		} finally {
			$this->unlock( $lock );
		}
	}

	private function run_identity() {
		$outcome = get_option( Backup::LAST_RUN_OUTCOME_OPTION, false );
		return is_array( $outcome ) && is_string( $outcome['run_token'] ?? null ) ? hash( 'sha256', $outcome['run_token'] ) : null;
	}

	private function transfer_generation_changed( $state ) {
		if ( ! isset( $state['transfer_generation_id'] ) ) {
			return false;
		}
		$current = Backup::get_completed_generation_id();
		return Backup::is_running() || $this->run_identity() !== ( $state['transfer_run_identity'] ?? null ) || ( is_string( $current ) && $current !== $state['transfer_generation_id'] );
	}

	/** Observe durable state only; never start, resume, cancel or transfer a backup. */
	public function heartbeat() {
		$state = $this->current_state();
		if ( ! is_array( $state ) || 'active' !== $state['state'] ) {
			return;
		}
		if ( $this->transfer_generation_changed( $state ) ) {
			$this->revoke();
			return;
		}
		$this->schedule();
		if ( isset( $state['last_attempt_at'] ) && $state['last_attempt_at'] > time() - 45 ) {
			return;
		}
		$lock = $this->lock();
		if ( ! $lock ) {
			return;
		}
		try {
			$state = $this->current_state();
			if ( ! is_array( $state ) || 'active' !== $state['state'] ) {
				return;
			}
			$intent = $state;
			$intent['last_attempt_at'] = time();
			if ( ! is_array( $intent['pending'] ?? null ) ) {
				$intent['pending'] = array( 'sequence' => $state['sequence'] + 1, 'snapshot' => $this->snapshot() );
			}
			if ( ! $this->replace_exact( self::STATE_OPTION, $state, $intent ) ) {
				return;
			}
			$result = $this->settings->request_migration_observation( array_merge( $intent['pending'], array( 'action' => 'observe', 'migration_id' => $state['migration_id'] ) ), $state['source_token'] );
			if ( is_wp_error( $result ) ) {
				$http = $result->get_error_data();
				if ( is_array( $http ) && in_array( $http['status'] ?? 0, array( 401, 403, 410 ), true ) ) {
					if ( $this->delete_exact( self::STATE_OPTION, $intent ) ) {
						wp_clear_scheduled_hook( self::CRON_HOOK );
					}
					return;
				}
				$next = array_merge( $intent, array( 'error_code' => 'observation_unavailable' ) );
			} elseif ( true === ( $result['accepted'] ?? null ) && $state['migration_id'] === ( $result['migration_id'] ?? null ) && $intent['pending']['sequence'] === ( $result['sequence'] ?? null ) ) {
				$next = array_merge( $intent, array( 'sequence' => $intent['pending']['sequence'], 'pending' => null, 'last_sent_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'error_code' => null ) );
			} else {
				$next = array_merge( $intent, array( 'error_code' => 'observation_unavailable' ) );
			}
			// A concurrent local disconnect must never be resurrected by an ACK.
			if ( $this->identity_matches( $intent ) ) {
				$this->replace_exact( self::STATE_OPTION, $intent, $next );
			}
		} finally {
			$this->unlock( $lock );
		}
	}

	/** No translated free text, filenames, credentials, logs or archive locations. */
	public function snapshot() {
		$progress = Backup::get_progress_state();
		$status = in_array( $progress['status'] ?? null, array( 'idle', 'running', 'paused', 'completed', 'failed', 'cancelled' ), true ) ? $progress['status'] : 'idle';
		$stages = array(
			'init' => 'initializing', 'archive' => 'initializing', 'config' => 'initializing', 'config_file' => 'initializing',
			'enumerate_content' => 'archive', 'content' => 'archive',
			'enumerate_plugins' => 'plugins', 'plugins' => 'plugins',
			'enumerate_themes' => 'themes', 'themes' => 'themes',
			'enumerate_media' => 'uploads', 'media' => 'uploads',
			'enumerate_tables' => 'database', 'database' => 'database', 'database_file' => 'database', 'clean' => 'cleanup',
		);
		$stage = 'completed' === $status ? 'complete' : ( 'idle' === $status ? 'waiting' : ( $stages[ $progress['task'] ?? '' ] ?? 'unknown' ) );
		$last = get_option( 'sssbm_last_progress_at', 0 );
		$last = is_int( $last ) || ( is_string( $last ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $last ) ) ? (int) $last : 0;
		$last = $last > 0 && $last <= time() ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : null;
		$task_progress = $progress['task_progress'] ?? null;
		$task_progress = is_int( $task_progress ) && $task_progress >= 0 && $task_progress <= 100 ? $task_progress : null;
		return array(
			'captured_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'status' => $status,
			'stage' => $stage,
			'progress' => 'completed' === $status ? 100 : null,
			'task_progress' => $task_progress,
			'last_progress_at' => $last,
			'error_code' => 'failed' === $status ? 'source_backup_failed' : null,
			'plugin_version' => SSSBM_VERSION,
		);
	}

	private function identity() {
		$url = $this->source_url();
		$user_id = get_option( 'sssbm_studio_user_id', '' );
		if ( false === $url ) {
			return new \WP_Error( 'observation_https_source_required', __( 'Static Studio MCP progress sharing requires an HTTPS WordPress Home URL, or HTTP localhost with an explicit non-default port for a local site.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}
		if ( ! $this->uuid( $user_id ) ) {
			return $this->error( 'observation_studio_connection_required', 409 );
		}
		$source_id = get_option( self::SOURCE_OPTION, false );
		if ( false === $source_id ) {
			add_option( self::SOURCE_OPTION, wp_generate_uuid4(), '', 'no' );
			$source_id = get_option( self::SOURCE_OPTION, false );
		}
		if ( ! $this->uuid( $source_id ) ) {
			return $this->error( 'observation_unavailable', 503 );
		}
		return array( 'source_url' => $url, 'source_id' => $source_id, 'plugin_version' => SSSBM_VERSION );
	}

	private function identity_matches( $state ) {
		return is_string( $state['user_id'] ?? null )
			&& $state['user_id'] === get_option( 'sssbm_studio_user_id', '' )
			&& ( $state['source_url'] ?? null ) === $this->source_url()
			&& is_string( $state['source_id'] ?? null )
			&& $state['source_id'] === get_option( self::SOURCE_OPTION, false )
			&& ( $state['plugin_version'] ?? null ) === SSSBM_VERSION;
	}

	private function current_state() {
		$state = get_option( self::STATE_OPTION, false );
		if ( false === $state ) {
			return false;
		}
		if ( ! is_array( $state ) || ! $this->identity_matches( $state ) || ! is_string( $state['expires_at'] ?? null ) || strtotime( $state['expires_at'] ) <= time() || ! in_array( $state['state'] ?? null, array( 'active', 'pending' ), true ) ) {
			if ( $this->delete_exact( self::STATE_OPTION, $state ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return false;
		}
		return $state;
	}

	private function source_url() {
		$url = home_url( '/' );
		$parts = is_string( $url ) && strlen( $url ) <= 2048 && ! preg_match( '/[\x00-\x20\x7f\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url ) ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host = strtolower( $parts['host'] );
		$path = rtrim( $parts['path'] ?? '/', '/' ) . '/';
		// This is a source identity only, never a network target. LocalWP sends
		// observations outbound to the same fixed HTTPS Studio broker as live sites.
		if ( 'http' === $scheme && 'localhost' === $host && isset( $parts['port'] ) && is_int( $parts['port'] ) && $parts['port'] >= 1 && $parts['port'] <= 65535 && 80 !== $parts['port'] ) {
			return 'http://localhost:' . $parts['port'] . $path;
		}
		if ( 'https' !== $scheme || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		return 'https://' . rtrim( $host, '.' ) . $path;
	}

	private function validate_details( $data, $source_url, $max_lifetime, $expected_status ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$expires = is_string( $data['expires_at'] ?? null ) ? strtotime( $data['expires_at'] ) : false;
		if ( ! is_array( $data ) || $expected_status !== ( $data['status'] ?? null ) || ! $this->uuid( $data['migration_id'] ?? null ) || ! is_string( $data['site_id'] ?? null ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $data['site_id'] ) || ! is_string( $data['site_name'] ?? null ) || strlen( $data['site_name'] ) > 500 || $source_url !== ( $data['source_url'] ?? null ) || array( 'observe_backup' ) !== ( $data['permissions'] ?? null ) || false === $expires || $expires <= time() || $expires > time() + $max_lifetime + 30 ) {
			return $this->error( 'observation_invalid_response', 502 );
		}
		return array(
			'migration_id' => strtolower( $data['migration_id'] ),
			'site_id' => $data['site_id'],
			'site_name' => sanitize_text_field( $data['site_name'] ),
			'source_url' => $source_url,
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
			'permissions' => array( 'observe_backup' ),
		);
	}

	private function public_details( $state ) {
		return array_intersect_key( $state, array_flip( array( 'migration_id', 'site_id', 'site_name', 'source_url', 'expires_at', 'permissions' ) ) );
	}

	private function pairing_id( $request ) {
		$value = $request->get_param( 'pairing_id' );
		return $this->uuid( $value ) ? strtolower( $value ) : $this->error( 'observation_invalid_pairing_id', 400 );
	}

	private function uuid( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value );
	}

	private function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	private function error( $code, $status ) {
		return new \WP_Error( $code, __( 'Static Studio MCP progress sharing could not complete. Review the connection and try again. No backup action was started.', 'simply-static-studio-backup-migrate' ), array( 'status' => $status ) );
	}

	private function lock() {
		$current = get_option( self::LOCK_OPTION, false );
		if ( false !== $current && ( ! is_array( $current ) || (int) ( $current['expires'] ?? 0 ) > time() || ! $this->delete_exact( self::LOCK_OPTION, $current ) ) ) {
			return false;
		}
		$token = wp_generate_uuid4();
		return add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + 60 ), '', 'no' ) ? $token : false;
	}

	private function unlock( $token ) {
		$current = get_option( self::LOCK_OPTION, false );
		if ( is_array( $current ) && $token === ( $current['token'] ?? null ) ) {
			$this->delete_exact( self::LOCK_OPTION, $current );
		}
	}

	/** Exact-value CAS prevents stale network replies from restoring revoked state. */
	private function replace_exact( $key, $before, $after ) {
		if ( $before === $after ) {
			return get_option( $key, false ) === $before;
		}
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $after ), $key, maybe_serialize( $before ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required to fence revocation; the exact option cache is invalidated immediately.
		if ( 1 === $changed ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	}

	private function delete_exact( $key, $value ) {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $value ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Revocation may remove only the exact observed state; invalidate its option cache after success.
		if ( 1 === $changed ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	}
}
