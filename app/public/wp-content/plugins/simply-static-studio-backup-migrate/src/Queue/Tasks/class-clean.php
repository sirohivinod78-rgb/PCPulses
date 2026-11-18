<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions carry plain text to JSON, logs and WP-CLI; HTML output is escaped at the rendering boundary.

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Clean;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Clean extends Backup_Task {

	public function run() {
		$run_token = $this->getRunToken();
		if ( ! Backup::owns_run_lock( $run_token ) ) {
			throw new \RuntimeException( __( 'The clean task no longer owns this backup run.', 'simply-static-studio-backup-migrate' ) );
		}

		$params = Backup::get_export_params();

		$init = new Backup_Clean();

		$params = $init->execute( $params, $run_token );

		return true;
	}
}
