<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\Zip_Archive_Wrapper;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Archive {

	public static function execute( $params = [] ) {

		do_action( 'sssbm_status_export_start', $params );

		// Set progress
		Status::info( __( 'Creating backup file...', 'simply-static-studio-backup-migrate' ), 'ssse-export-init' );

		$zip_lock = Helper::acquireZipLock();
		if ( ! $zip_lock ) {
			throw new \RuntimeException( __( 'Could not acquire the backup archive lock.', 'simply-static-studio-backup-migrate' ) );
		}

		try {
			$backup_path = Backup::get_backup_path();
			clearstatcache( true, $backup_path );
			if ( file_exists( $backup_path ) || is_link( $backup_path ) ) {
				// unlink() removes the link itself and never its target. Including
				// is_link() is essential for dangling links because file_exists()
				// follows the missing target and returns false.
				if ( ! @unlink( $backup_path ) ) {
					throw new \RuntimeException( __( 'The existing backup archive could not be removed safely.', 'simply-static-studio-backup-migrate' ) );
				}
			}

			// Create empty archive file using wrapper (supports ZipArchive and PclZip fallback)
			$archive = new Zip_Archive_Wrapper();
			Logger::log( 'Using ZIP library: ' . $archive->get_library_name() );

			if ( ! $archive->create( $backup_path ) ) {
				Logger::log( 'Failed to create backup archive: ' . $archive->getError() );
				if ( $archive->is_storage_exhausted() ) {
					throw new \RuntimeException( $archive->get_storage_exhausted_error() );
				}
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: archive library error message. */
						__( 'Failed to create backup archive: %s', 'simply-static-studio-backup-migrate' ),
						$archive->getError()
					)
				);
			}
		} finally {
			Helper::releaseZipLock( $zip_lock );
		}

		// Set progress
		Status::success( __( 'Backup file created.', 'simply-static-studio-backup-migrate' ), 'ssse-export-init' );

		return $params;
	}
}
