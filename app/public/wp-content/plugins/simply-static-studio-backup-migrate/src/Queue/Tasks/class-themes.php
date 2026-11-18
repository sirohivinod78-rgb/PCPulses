<?php

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Themes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Themes extends Backup_Task {

	public function run() {
		$file_params = $this->get_prerequisite_params( 'enumerate_themes', array( 'total_themes_files_size', 'total_themes_files_count' ) );
		$params      = $this->get_task_params();
		$params      = array_merge( $params, [
			'total_themes_files_size'  => $file_params['total_themes_files_size'],
			'total_themes_files_count' => $file_params['total_themes_files_count'],
		] );

		$init = new Backup_Themes();

		$params = $init->execute( $params, $this->get_id() );

		$this->save_task_params( $params );

		return isset( $params['completed'] ) && $params['completed'];
	}
}
