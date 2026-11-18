<?php

/* phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local backup cursors and locks require stream handles, restrictive permissions, and exact-path cleanup; WP_Filesystem has no equivalent seek/lock API. */
/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions propagate as worker/CLI data, never direct HTML output; React escapes their admin rendering. */

namespace Simply_Static\Backup\ThirdParty\servmask\backup;


use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\ThirdParty\servmask\database\Database_Utility;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Backup_Database {

	public static function execute( $params = [] ) {
		// Set exclude database
		if ( isset( $params['options']['no_database'] ) ) {
			return $params;
		}

		// Set query offset
		if ( isset( $params['query_offset'] ) ) {
			$query_offset = (int) $params['query_offset'];
		} else {
			$query_offset = 0;
		}

		// Set table index
		if ( isset( $params['table_index'] ) ) {
			$table_index = (int) $params['table_index'];
		} else {
			$table_index = 0;
		}

		// Set table offset
		if ( isset( $params['table_offset'] ) ) {
			$table_offset = (int) $params['table_offset'];
		} else {
			$table_offset = 0;
		}

		// Set table rows
		if ( isset( $params['table_rows'] ) ) {
			$table_rows = (int) $params['table_rows'];
		} else {
			$table_rows = 0;
		}

		// Set total tables count
		if ( isset( $params['total_tables_count'] ) ) {
			$total_tables_count = (int) $params['total_tables_count'];
		} else {
			$total_tables_count = 1;
		}

		// What percent of tables have we processed?
		$progress = $total_tables_count > 0 ? (int) ( ( $table_index / $total_tables_count ) * 100 ) : 0;

		// Set progress
		/* translators: 1: Progress, 2: Number of records. */
		Status::info( sprintf( __( 'Backing up database...<br />%1$d%% complete<br />%2$s records saved', 'simply-static-studio-backup-migrate' ), $progress, number_format_i18n( $table_rows ) ), 'ssse-database' );

		// Get tables list file
		$tables_list = @fopen( Backup::get_tables_list_path(), 'r' );
		if ( ! is_resource( $tables_list ) ) {
			throw new \RuntimeException( __( 'Could not open the database table list.', 'simply-static-studio-backup-migrate' ) );
		}

		// Loop over tables
		$tables = array();
		while ( false !== ( $table_row = Helper::getcsv( $tables_list ) ) ) {
			list( $table_name ) = $table_row;
			$tables[] = $table_name;
		}

		// Close the tables list file
		@fclose( $tables_list );

		// Get database client
		$db_client = Database_Utility::create_client();

		// Do not write migration credentials or ephemeral process state into the
		// raw dump. The database-file task still performs a read-only verification
		// pass before archiving, preserving the fail-closed security boundary.
		$sensitive_option_conditions = array();
		foreach ( Helper::get_sensitive_option_prefixes() as $prefix ) {
			// Compare literal prefixes without dialect-specific LIKE escape rules.
			// Both MySQL and WordPress's SQLite drop-ins support SUBSTR().
			$sensitive_option_conditions[] = sprintf(
				"SUBSTR(`option_name`, 1, %d) != '%s'",
				strlen( $prefix ),
				$db_client->escape( $prefix )
			);
		}
		if ( $sensitive_option_conditions ) {
			$db_client->set_table_where_query(
				Helper::get_table_prefix() . 'options',
				implode( ' AND ', $sensitive_option_conditions )
			);
		}

		// Exclude spam comments
		if ( isset( $params['options']['no_spam_comments'] ) ) {
			$db_client->set_table_where_query( Helper::get_table_prefix() . 'comments', "`comment_approved` != 'spam'" )
			          ->set_table_where_query( Helper::get_table_prefix() . 'commentmeta', sprintf( "`comment_ID` IN ( SELECT `comment_ID` FROM `%s` WHERE `comment_approved` != 'spam' )", Helper::get_table_prefix() . 'comments' ) );
		}

		// Exclude post revisions
		if ( isset( $params['options']['no_post_revisions'] ) ) {
			$db_client->set_table_where_query( Helper::get_table_prefix() . 'posts', "`post_type` != 'revision'" );
		}

		$old_table_prefixes = $old_column_prefixes = array();
		$new_table_prefixes = $new_column_prefixes = array();

		// Set table prefixes
		/*
		 * Not needed for now. In case we start offering direct connection to the site, we can replace it.
		 if ( Helper::get_table_prefix() ) {
			$old_table_prefixes[] = Helper::get_table_prefix();
			$new_table_prefixes[] = ai1wm_servmask_prefix();
		} else {
			foreach ( $tables as $table_name ) {
				$old_table_prefixes[] = $table_name;
				$new_table_prefixes[] = ai1wm_servmask_prefix() . $table_name;
			}
		}

		// Set column prefixes
		if ( strlen( Helper::get_table_prefix() ) > 1 ) {
			$old_column_prefixes[] = Helper::get_table_prefix();
			$new_column_prefixes[] = ai1wm_servmask_prefix();
		} else {
			foreach ( array( 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' ) as $column_prefix ) {
				$old_column_prefixes[] = Helper::get_table_prefix() . $column_prefix;
				$new_column_prefixes[] = ai1wm_servmask_prefix() . $column_prefix;
			}
		}*/

		$db_client->set_tables( $tables )
		          ->set_old_table_prefixes( $old_table_prefixes )
		          ->set_new_table_prefixes( $new_table_prefixes )
		          ->set_old_column_prefixes( $old_column_prefixes )
		          ->set_new_column_prefixes( $new_column_prefixes );

		// Exclude column prefixes
		$db_client->set_reserved_column_prefixes( array(
			'wp_force_deactivated_plugins',
			'wp_page_for_privacy_policy',
			'wp_rocket_settings',
			'wp_rocket_dismiss_imagify_notice',
			'wp_rocket_no_licence',
			'wp_rocket_rocketcdn_old_url',
			'wp_rocket_hide_deactivation_form'
		) );

		// Set table select columns
		/*
		 * For now, leaving the info for active plugins and themes in.
		 * if ( ( $column_names = $db_client->get_column_names( Helper::get_table_prefix() . 'options' ) ) ) {
			if ( isset( $column_names['option_name'], $column_names['option_value'] ) ) {
				$column_names['option_value'] = sprintf( "(CASE WHEN option_name = '%s' THEN 'a:0:{}' WHEN (option_name = '%s' OR option_name = '%s') THEN '' ELSE option_value END) AS option_value", 'active_plugins', 'template', 'stylesheet' );
			}

			$db_client->set_table_select_columns( Helper::get_table_prefix() . 'options', $column_names );
		}*/

		// Set table prefix columns
		$db_client->set_table_prefix_columns( Helper::get_table_prefix() . 'options', array( 'option_name' ) )
		          ->set_table_prefix_columns( Helper::get_table_prefix() . 'usermeta', array( 'meta_key' ) );

		// Backup database
		if ( $db_client->export( Backup::get_database_path(), $query_offset, $table_index, $table_offset, $table_rows ) ) {

			// Set progress
			Status::success( __( 'Backing up Database...', 'simply-static-studio-backup-migrate' ), 'ssse-database' );

			$params['completed'] = true;

		} else {

			// What percent of tables have we processed?
			$progress = $total_tables_count > 0 ? (int) ( ( $table_index / $total_tables_count ) * 100 ) : 0;

			// Set progress
			/* translators: 1: Progress, 2: Number of records. */
			Status::info( sprintf( __( 'Backing up database...<br />%1$d%% complete<br />%2$s records saved', 'simply-static-studio-backup-migrate' ), $progress, number_format_i18n( $table_rows ) ), 'ssse-database' );

			// Set query offset
			$params['query_offset'] = $query_offset;

			// Set table index
			$params['table_index'] = $table_index;

			// Set table offset
			$params['table_offset'] = $table_offset;

			// Set table rows
			$params['table_rows'] = $table_rows;

			// Set total tables count
			$params['total_tables_count'] = $total_tables_count;

			// Set completed flag
			$params['completed'] = false;
		}

		return $params;
	}
}
