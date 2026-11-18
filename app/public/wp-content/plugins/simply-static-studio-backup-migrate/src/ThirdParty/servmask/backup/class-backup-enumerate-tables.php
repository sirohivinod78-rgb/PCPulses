<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\database\Database_Utility;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Enumerate_Tables {

	public static function execute( $params = [] ) {
		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		// Get total tables count
		if ( isset( $params['total_tables_count'] ) ) {
			$total_tables_count = (int) $params['total_tables_count'];
		} else {
			$total_tables_count = 0;
		}

		// Set progress
		Status::info( __( 'Gathering database tables...', 'simply-static-studio-backup-migrate' ), 'enumerate_tables' );

		// Get database client
		$db_client = Database_Utility::create_client();

		// Static Studio gives every managed site its own database. A portable,
		// exact backup must therefore include plugin/custom tables that do not
		// use the WordPress prefix as well. Standalone installs retain the
		// narrower legacy policy because their database may be shared.
		global $wpdb;
		$managed_runtime = defined( 'STATIC_STUDIO_MANAGED_RUNTIME' )
			&& true === STATIC_STUDIO_MANAGED_RUNTIME;
		if ( ! $managed_runtime ) {
			$db_client->add_table_prefix_filter( $wpdb->prefix );

			if ( is_multisite() ) {
				// Always include users, usermeta and sitemeta for subsite exports
				$db_client->add_table_prefix_filter( $wpdb->users );
				$db_client->add_table_prefix_filter( $wpdb->usermeta );
				$db_client->add_table_prefix_filter( $wpdb->sitemeta );
			}
		}
		/*if ( ai1wm_table_prefix() ) {
			$db_client->add_table_prefix_filter( ai1wm_table_prefix() );

			// Include table prefixes (Webba Booking and CiviCRM)
			foreach ( array( 'wbk_', 'civicrm_' ) as $table_name ) {
				$db_client->add_table_prefix_filter( $table_name );
			}
		}*/

		// Create tables list file
		$tables_list = @fopen( Backup::get_tables_list_path(), 'w' );
		if ( ! is_resource( $tables_list ) ) {
			throw new \RuntimeException( __( 'Could not create the database table list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Exclude selected db tables
		$excluded_db_tables = array();
		if ( isset( $params['options']['exclude_db_tables'], $params['excluded_db_tables'] ) ) {
			$excluded_db_tables = explode( ',', $params['excluded_db_tables'] );
		}
		$skipped_multisite_tables = 0;

		// Write table line
		foreach ( $db_client->get_tables() as $table_name ) {
			if ( ! is_multisite() && self::is_multisite_remnant_table( $table_name, $wpdb ) ) {
				$skipped_multisite_tables++;
				continue;
			}
			if ( ! in_array( $table_name, $excluded_db_tables, true ) && Helper::putcsv( $tables_list, array( $table_name ) ) ) {
				$total_tables_count ++;
			}
		}

		if ( $skipped_multisite_tables > 0 ) {
			Logger::log( sprintf( 'Excluded %d obsolete multisite table(s) from this single-site backup.', $skipped_multisite_tables ) );
		}

		// Include selected db tables
		if ( isset( $params['options']['include_db_tables'] ) && ! empty( $params['included_db_tables'] ) ) {
			foreach ( explode( ',', $params['included_db_tables'] ) as $table_name ) {
				if ( ! is_multisite() && self::is_multisite_remnant_table( $table_name, $wpdb ) ) {
					continue;
				}
				if ( Helper::putcsv( $tables_list, array( $table_name ) ) ) {
					$total_tables_count ++;
				}
			}
		}

		// Set progress
		Status::success( __( 'Database tables gathered.', 'simply-static-studio-backup-migrate' ), 'enumerate_tables' );

		// Set total tables count
		$params['total_tables_count'] = $total_tables_count;
		$params['completed']          = true;
		// Close the tables list file
		@fclose( $tables_list );

		return $params;
	}

	/**
	 * Identify WordPress network tables left behind after a multisite-to-single
	 * site conversion. Exporting these tables makes importers classify an
	 * otherwise single-site archive as a network backup.
	 *
	 * @param mixed  $table_name Candidate database table name.
	 * @param object $wpdb WordPress database connection.
	 * @return bool
	 */
	private static function is_multisite_remnant_table( $table_name, $wpdb ) {
		if ( ! is_string( $table_name ) || '' === $table_name ) {
			return false;
		}

		$base_prefix = isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix )
			? $wpdb->base_prefix
			: ( isset( $wpdb->prefix ) && is_string( $wpdb->prefix ) ? $wpdb->prefix : '' );
		if ( '' === $base_prefix ) {
			return false;
		}

		$network_tables = array(
			'blogs',
			'blogmeta',
			'signups',
			'site',
			'sitemeta',
			'registration_log',
			'sitecategories',
			'blog_versions',
		);
		if ( isset( $wpdb->ms_global_tables ) && is_array( $wpdb->ms_global_tables ) ) {
			$network_tables = array_merge( $network_tables, $wpdb->ms_global_tables );
		}
		if ( isset( $wpdb->old_ms_global_tables ) && is_array( $wpdb->old_ms_global_tables ) ) {
			$network_tables = array_merge( $network_tables, $wpdb->old_ms_global_tables );
		}

		$normalized_table = strtolower( $table_name );
		foreach ( array_unique( $network_tables ) as $network_table ) {
			if ( $normalized_table === strtolower( $base_prefix . $network_table ) ) {
				return true;
			}
		}

		$site_tables = array(
			'posts',
			'comments',
			'links',
			'options',
			'postmeta',
			'terms',
			'term_taxonomy',
			'term_relationships',
			'termmeta',
			'commentmeta',
		);
		if ( isset( $wpdb->tables ) && is_array( $wpdb->tables ) ) {
			$site_tables = array_merge( $site_tables, $wpdb->tables );
		}
		$site_tables = array_map( 'preg_quote', array_unique( $site_tables ) );

		return (bool) preg_match(
			'/^' . preg_quote( strtolower( $base_prefix ), '/' ) . '[1-9][0-9]*_(?:' . implode( '|', $site_tables ) . ')$/D',
			$normalized_table
		);
	}
}
