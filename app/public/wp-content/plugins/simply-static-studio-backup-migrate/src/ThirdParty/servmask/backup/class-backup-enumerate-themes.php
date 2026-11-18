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

class Backup_Enumerate_Themes {

	public static function execute( $params = [] ) {

		$exclude_filters = array();
		$theme_directories = search_theme_directories();
		$theme_directories = is_array( $theme_directories ) ? $theme_directories : array();
		$active_theme_paths = array();
		foreach ( array_unique( array( get_template(), get_stylesheet() ) ) as $active_theme ) {
			if ( isset( $theme_directories[ $active_theme ]['theme_root'] ) ) {
				$active_theme_paths[] = untrailingslashit( $theme_directories[ $active_theme ]['theme_root'] ) . DIRECTORY_SEPARATOR . $active_theme;
			}
		}

		// Get total themes files count
		if ( isset( $params['total_themes_files_count'] ) ) {
			$total_themes_files_count = (int) $params['total_themes_files_count'];
		} else {
			$total_themes_files_count = 0;
		}

		// Get total themes files size
		if ( isset( $params['total_themes_files_size'] ) ) {
			$total_themes_files_size = (int) $params['total_themes_files_size'];
		} else {
			$total_themes_files_size = 0;
		}

		// Set progress
		Status::info( __( 'Gathering theme files...', 'simply-static-studio-backup-migrate' ), 'enumerate_themes' );

		// Exclude inactive themes
		foreach ( $theme_directories as $theme_name => $theme_info ) {
			// PHP converts numeric directory names used as array keys to integers.
			if ( ! in_array( (string) $theme_name, array( get_template(), get_stylesheet() ), true ) ) {
				if ( isset( $theme_info['theme_root'] ) ) {
					$exclude_filters[] = $theme_info['theme_root'] . DIRECTORY_SEPARATOR . $theme_name;
				}
			}
		}

		// Exclude selected files
		if ( isset( $params['options']['exclude_files'], $params['excluded_files'] ) ) {
			if ( ( $excluded_files = explode( ',', $params['excluded_files'] ) ) ) {
				foreach ( $excluded_files as $excluded_path ) {
					$exclude_filters[] = Helper::get_content_path() . DIRECTORY_SEPARATOR . untrailingslashit( $excluded_path );
				}
			}
		}

		// Create themes list file
		$themes_list = @fopen( Backup::get_themes_list_path(), 'w' );
		if ( ! is_resource( $themes_list ) ) {
			throw new \RuntimeException( __( 'Could not create the theme file list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Enumerate over themes directory
		if ( isset( $params['options']['no_themes'] ) === false ) {
			foreach ( Helper::get_themes_dirs() as $theme_dir ) {
				if ( is_dir( $theme_dir ) ) {

					// Iterate over themes directory
					$iterator = new Recursive_Directory_Iterator( $theme_dir );

					// Inactive themes and explicit user exclusions remain mandatory even
					// when a later integration returns an empty or malformed value.
					$mandatory_filters = $exclude_filters;
					$filtered_filters  = apply_filters( 'sssbm_exclude_themes_from_export', $mandatory_filters );
					$effective_filters = Helper::merge_mandatory_exclusions( $filtered_filters, $mandatory_filters );
					$iterator          = new Recursive_Exclude_Filter( $iterator, $effective_filters );

					// Recursively iterate over themes directory
					$iterator = new Recursive_Iterator_Iterator( $iterator, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );

					// Write path line
					foreach ( $iterator as $item ) {
						if ( $item->isFile() ) {
							// Multiple registered roots can contain the same stylesheet
							// slug. Include only the exact active parent/child paths that
							// WordPress resolved, otherwise a shadow copy from a later root
							// can replace the real theme at the same archive entry name.
							$belongs_to_active_theme = false;
							foreach ( $active_theme_paths as $active_theme_path ) {
								if ( Helper::is_path_within( $iterator->getPathname(), $active_theme_path ) ) {
									$belongs_to_active_theme = true;
									break;
								}
							}
							if ( ! $belongs_to_active_theme ) {
								continue;
							}

							if ( Helper::putcsv( $themes_list, array(
								$iterator->getPathname(),
								$iterator->getSubPathname(),
								$iterator->getSize(),
								$iterator->getMTime()
							) ) ) {
								$total_themes_files_count ++;

								// Add current file size
								$total_themes_files_size += $iterator->getSize();
							}
						}
					}
				}
			}
		}

		// Set progress
		Status::success( __( 'Theme files gathered.', 'simply-static-studio-backup-migrate' ), 'enumerate_themes' );

		// Set total themes files count
		$params['total_themes_files_count'] = $total_themes_files_count;

		// Set total themes files size
		$params['total_themes_files_size'] = $total_themes_files_size;
		$params['completed']               = true;
		// Close the themes list file
		@fclose( $themes_list );

		return $params;
	}
}
