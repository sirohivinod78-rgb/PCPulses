<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions carry plain text to JSON, logs and WP-CLI; HTML output is escaped at the rendering boundary.

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Content;
use Simply_Static\Backup\Zip_Archive_Wrapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content extends Backup_Task {

	public function run() {
		$file_params = $this->get_prerequisite_params( 'enumerate_content', array( 'total_content_files_size', 'total_content_files_count' ) );
		$params      = $this->get_task_params();

		// All file enumerators run before content, making this the first point
		// where the complete file payload can be rejected before a slow PclZip
		// export starts. Resumed content tasks rely on the wrapper's per-flush
		// guard instead so already archived bytes are not counted twice.
		if (
			0 === (int) ( $params['content_bytes_offset'] ?? 0 ) &&
			0 === (int) ( $params['processed_files_count'] ?? 0 )
		) {
			$this->assert_pclzip_capacity( $file_params );
		}

		$params      = array_merge( $params, [
			'total_content_files_size'  => $file_params['total_content_files_size'],
			'total_content_files_count' => $file_params['total_content_files_count'],
		] );

		$init = new Backup_Content();

		$params = $init->execute( $params, $this->get_id() );

		$this->save_task_params( $params );

		return isset( $params['completed'] ) && $params['completed'];
	}

	/**
	 * Stop large fallback exports before PclZip starts rewriting the archive.
	 *
	 * @param array $content_params Enumerated content totals.
	 * @return void
	 * @throws \RuntimeException When the complete file payload needs ZIP64.
	 */
	protected function assert_pclzip_capacity( $content_params ) {
		if ( $this->is_zip_archive_available() ) {
			return;
		}

		$export_params = Backup::get_export_params();
		$total_bytes   = max( 0.0, (float) ( $content_params['total_content_files_size'] ?? 0 ) );
		$total_entries = max( 0, (int) ( $content_params['total_content_files_count'] ?? 0 ) );
		$optional_totals = array(
			'enumerate_media' => array( 'total_media_files_size', 'total_media_files_count' ),
			'enumerate_plugins' => array( 'total_plugins_files_size', 'total_plugins_files_count' ),
			'enumerate_themes' => array( 'total_themes_files_size', 'total_themes_files_count' ),
		);

		foreach ( $optional_totals as $task_id => $keys ) {
			if ( ! isset( $export_params[ $task_id ] ) || ! is_array( $export_params[ $task_id ] ) ) {
				continue;
			}

			$total_bytes += max( 0.0, (float) ( $export_params[ $task_id ][ $keys[0] ] ?? 0 ) );
			$total_entries += max( 0, (int) ( $export_params[ $task_id ][ $keys[1] ] ?? 0 ) );
		}

		$archive_path = Backup::get_backup_path();
		clearstatcache( true, $archive_path );
		$archive_size = @filesize( $archive_path );
		$archive_size = false === $archive_size ? 0 : $archive_size;

		if ( Zip_Archive_Wrapper::would_exceed_pclzip_limits( $archive_size, $total_bytes, 0, $total_entries ) ) {
			throw new \RuntimeException( Zip_Archive_Wrapper::get_pclzip_limit_error() );
		}
	}

	/**
	 * Kept behind a method so the unavailable-extension branch is testable on
	 * development machines that have ZipArchive installed.
	 *
	 * @return bool
	 */
	protected function is_zip_archive_available() {
		return Zip_Archive_Wrapper::is_zip_archive_available();
	}
}
