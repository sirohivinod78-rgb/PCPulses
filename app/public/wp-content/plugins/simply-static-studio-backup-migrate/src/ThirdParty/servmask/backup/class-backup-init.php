<?php

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Status;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Init {

	public static function execute( $params = [] ) {
		$blog_id = null;

		do_action( 'sssbm_status_export_init', $params );

		Status::clear_messages();

		// Keep the last completed generation available for rollback until this
		// replacement reaches the final clean task.
		Backup::clear_all_export_files( true );
		Backup::delete_export_params();
		Backup::reset_task_retry_counts();
		delete_option( 'sssbm_current_task' );
		delete_transient( 'sssbm_download_token' );

		// Set progress
		Status::info( __( 'Preparing backup...', 'simply-static-studio-backup-migrate' ), 'ssse-export-init' );

		return $params;
	}
}
