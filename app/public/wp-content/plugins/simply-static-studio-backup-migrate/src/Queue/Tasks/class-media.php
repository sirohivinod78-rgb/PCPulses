<?php

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media extends Backup_Task {

	public function run() {

		$file_params = $this->get_prerequisite_params( 'enumerate_media', array( 'total_media_files_size', 'total_media_files_count' ) );
		$params      = $this->get_task_params();
		$params      = array_merge( $params, [
			'total_media_files_size'  => $file_params['total_media_files_size'],
			'total_media_files_count' => $file_params['total_media_files_count'],
		] );

		$init = new Backup_Media();

		$params = $init->execute( $params, $this->get_id() );

		$this->save_task_params( $params );

		return isset( $params['completed'] ) && $params['completed'];
	}
}
