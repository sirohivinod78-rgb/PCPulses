<?php

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Status;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Clean {

	public static function execute( $params = [], $run_token = '' ) {
		if ( ! Backup::owns_run_lock( $run_token ) ) {
			throw new \RuntimeException( __( 'The clean task no longer owns this backup run.', 'simply-static-studio-backup-migrate' ) );
		}

		// Delete storage files
		if ( isset( $params['sssbm_export_cancel'] ) ) {
			do_action( 'sssbm_status_export_canceled', $params );

			Status::info( __( 'Backup canceled.', 'simply-static-studio-backup-migrate' ), 'ssse-clean' );
			Backup::abort_export( $run_token, 'cancelled' );
		} else {
			$promoted = Backup::finalize_export( $run_token );
			if ( ! $promoted ) {
				Status::error( __( 'The new backup archive failed its final consistency check. The previous completed backup was restored when available.', 'simply-static-studio-backup-migrate' ), 'ssse-clean' );
			} else {
				Status::success( __( 'Backup done.', 'simply-static-studio-backup-migrate' ), 'ssse-clean' );
			}

			$integrity = Backup::get_backup_integrity_status();
			if ( ! $integrity['is_pushable'] && ! empty( $integrity['warning'] ) ) {
				Status::warning( $integrity['warning'], 'sssbm-backup-integrity' );
			}

		}

		if ( Backup::owns_run_lock( $run_token ) ) {
			Backup::set_as_not_running( $run_token );
		}

		// Exit in console
		if ( defined( 'WP_CLI' ) ) {
			return $params;
		}

		return true;
	}
}
