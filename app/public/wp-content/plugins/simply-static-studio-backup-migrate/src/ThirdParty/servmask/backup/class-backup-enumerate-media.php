<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\filter\Recursive_Exclude_Filter;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Directory_Iterator;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Iterator_Iterator;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Enumerate_Media {

	public static function execute( $params = [] ) {

		$exclude_filters = array();

		// Get total media files count
		if ( isset( $params['total_media_files_count'] ) ) {
			$total_media_files_count = (int) $params['total_media_files_count'];
		} else {
			$total_media_files_count = 0;
		}

		// Get total media files size
		if ( isset( $params['total_media_files_size'] ) ) {
			$total_media_files_size = (int) $params['total_media_files_size'];
		} else {
			$total_media_files_size = 0;
		}

		// Set progress
		Status::info( __( 'Gathering media files...', 'simply-static-studio-backup-migrate' ), 'enumerate_media' );

		// Exclude selected files
		if ( isset( $params['options']['exclude_files'], $params['excluded_files'] ) ) {
			if ( ( $excluded_files = explode( ',', $params['excluded_files'] ) ) ) {
				foreach ( $excluded_files as $excluded_path ) {
					$exclude_filters[] = Helper::get_content_path() . DIRECTORY_SEPARATOR . untrailingslashit( $excluded_path );
				}
			}
		}

		// Create media list file
		$media_list = @fopen( Backup::get_media_list_path(), 'w' );
		if ( ! is_resource( $media_list ) ) {
			throw new \RuntimeException( __( 'Could not create the media file list.', 'simply-static-studio-backup-migrate' ) );
		}

		$exclude_filters[] = untrailingslashit( Backup::get_storage_path() );

		// Enumerate over media directory
		if ( isset( $params['options']['no_media'] ) === false ) {
			$upload_dir = wp_upload_dir();
			if ( is_dir( untrailingslashit( $upload_dir['basedir'] ) ) ) {
				$exclude_filters[] = untrailingslashit( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'simply-static';
				$exclude_filters[] = untrailingslashit( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'simply-static-downloads';

				// Large archives produced by other backup plugins commonly live in
				// uploads. Including them makes this backup recursively balloon and
				// can exhaust disk space.
				foreach ( array( 'ai1wm-backups', 'updraft', 'backups-dup-pro', 'backups-dup-lite', 'backupbuddy_backups', 'backup-guard' ) as $backup_dir ) {
					$exclude_filters[] = untrailingslashit( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . $backup_dir;
				}

				// Iterate over media directory
				$iterator = new Recursive_Directory_Iterator( untrailingslashit( $upload_dir['basedir'] ) );

				// Filters may add paths but cannot remove private storage, the current
				// archive tree, user exclusions, or known backup directories.
				$mandatory_filters = $exclude_filters;
				$filtered_filters  = apply_filters( 'sssbm_exclude_media_from_export', $mandatory_filters );
				$effective_filters = Helper::merge_mandatory_exclusions( $filtered_filters, $mandatory_filters );
				$iterator          = new Recursive_Exclude_Filter( $iterator, $effective_filters );

				// Recursively iterate over content directory
				$iterator = new Recursive_Iterator_Iterator( $iterator, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );


				// Write path line
				foreach ( $iterator as $item ) {
					if ( $item->isFile() ) {
						if ( Helper::putcsv( $media_list, array(
							$iterator->getPathname(),
							$iterator->getSubPathname(),
							$iterator->getSize(),
							$iterator->getMTime()
						) ) ) {
							$total_media_files_count ++;

							// Add current file size
							$total_media_files_size += $iterator->getSize();
						}
					}
				}
			}
		}

		// Set progress
		Status::success( __( 'Media files gathered.', 'simply-static-studio-backup-migrate' ), 'enumerate_media' );

		// Set total media files count
		$params['total_media_files_count'] = $total_media_files_count;

		// Set total media files size
		$params['total_media_files_size'] = $total_media_files_size;

		$params['completed'] = true;

		// Close the media list file
		@fclose( $media_list );

		return $params;
	}
}
