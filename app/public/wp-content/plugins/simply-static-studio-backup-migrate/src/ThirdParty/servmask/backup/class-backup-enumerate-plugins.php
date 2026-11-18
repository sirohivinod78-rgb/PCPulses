<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Exclude_Plugins;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\filter\Recursive_Exclude_Filter;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Directory_Iterator;
use Simply_Static\Backup\ThirdParty\servmask\iterator\Recursive_Iterator_Iterator;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Enumerate_Plugins {

	public static function execute( $params = [] ) {

		$exclude_filters = array();

		// Get total plugins files count
		if ( isset( $params['total_plugins_files_count'] ) ) {
			$total_plugins_files_count = (int) $params['total_plugins_files_count'];
		} else {
			$total_plugins_files_count = 0;
		}

		// Get total plugins files size
		if ( isset( $params['total_plugins_files_size'] ) ) {
			$total_plugins_files_size = (int) $params['total_plugins_files_size'];
		} else {
			$total_plugins_files_size = 0;
		}

		// Set progress
		Status::info( __( 'Gathering plugin files...', 'simply-static-studio-backup-migrate' ), 'enumerate_plugins' );

		// Exclude inactive plugins
		foreach ( get_plugins() as $plugin_name => $plugin_info ) {
			if ( is_plugin_inactive( $plugin_name ) ) {
				$exclude_filters[] = ( dirname( $plugin_name ) === '.' ? basename( $plugin_name ) : dirname( $plugin_name ) );
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

		// Create plugins list file
		$plugins_list = @fopen( Backup::get_plugins_list_path(), 'w' );
		if ( ! is_resource( $plugins_list ) ) {
			throw new \RuntimeException( __( 'Could not create the plugin file list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Enumerate over plugins directory
		if ( isset( $params['options']['no_plugins'] ) === false ) {

			// Iterate over plugins directory
			$iterator = new Recursive_Directory_Iterator( untrailingslashit( Helper::get_plugins_path() ) );

			// Core/incompatible plugins, inactive plugins, and explicit user
			// exclusions are immutable; integrations may only add more paths.
			$mandatory_filters = Helper::merge_mandatory_exclusions( $exclude_filters, Exclude_Plugins::get_core_plugin_exclusions() );
			$filtered_filters  = apply_filters( 'sssbm_exclude_plugins_from_export', $mandatory_filters );
			$effective_filters = Helper::merge_mandatory_exclusions( $filtered_filters, $mandatory_filters );
			$iterator          = new Recursive_Exclude_Filter( $iterator, $effective_filters );

			// Recursively iterate over plugins directory
			$iterator = new Recursive_Iterator_Iterator( $iterator, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );

			// Write path line
			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {

					if ( Helper::putcsv( $plugins_list, array(
						$iterator->getPathname(),
						$iterator->getSubPathname(),
						$iterator->getSize(),
						$iterator->getMTime()
					) ) ) {
						$total_plugins_files_count ++;

						// Add current file size
						$total_plugins_files_size += $iterator->getSize();
					}
				}
			}
		}

		// Set progress
		Status::success( __( 'Plugin files gathered.', 'simply-static-studio-backup-migrate' ), 'enumerate_plugins' );

		// Set total plugins files count
		$params['total_plugins_files_count'] = $total_plugins_files_count;

		// Set total plugins files size
		$params['total_plugins_files_size'] = $total_plugins_files_size;
		$params['completed']                = true;
		// Close the plugins list file
		@fclose( $plugins_list );

		return $params;
	}
}
