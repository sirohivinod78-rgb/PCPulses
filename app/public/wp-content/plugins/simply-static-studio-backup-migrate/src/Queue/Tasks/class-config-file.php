<?php

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Config_File;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config_File extends Backup_Task {

	public function run() {
		$params = $this->get_task_params();
		$init = new Backup_Config_File();
		$params = $init->execute( $params );

		$this->save_task_params( $params );

		return ! empty( $params['completed'] );
	}
}
