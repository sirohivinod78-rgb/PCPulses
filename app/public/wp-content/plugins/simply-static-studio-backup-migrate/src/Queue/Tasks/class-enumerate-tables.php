<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions carry plain text to JSON, logs and WP-CLI; HTML output is escaped at the rendering boundary.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink -- Subsite isolation rewrites a streamed table list with an atomic native rename; WP_Filesystem cannot provide streaming handles or atomic promotion.

namespace Simply_Static\Backup\Queue\Tasks;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Queue\Exceptions\Retry_Exception;
use Simply_Static\Backup\ThirdParty\servmask\backup\Backup_Enumerate_Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Enumerate_Tables extends Backup_Task {

	public function run() {
		$params = $this->get_task_params();
		$params = self::execute_scoped( $params );

		$this->save_task_params( $params );

		return isset( $params['completed'] ) && $params['completed'];
	}

	/**
	 * Enumerate tables and enforce subsite isolation for every call path.
	 *
	 * Database dump recovery must use this entry point too; invoking the vendored
	 * enumerator directly would reintroduce network-global user and site tables.
	 *
	 * @param array $params Current task parameters.
	 * @return array Updated task parameters.
	 */
	public static function execute_scoped( $params = array() ) {
		$params = Backup_Enumerate_Tables::execute( $params );

		if ( is_multisite() && ! is_main_site() ) {
			$params['total_tables_count'] = self::restrict_to_current_site_tables();
		}

		return $params;
	}

	/**
	 * Remove network-global tables from a subsite export.
	 *
	 * WordPress stores users, user metadata, and network configuration in global
	 * tables. Exporting those tables from a subsite would disclose unrelated
	 * sites' users, password hashes, sessions, and network metadata.
	 *
	 * @return int Number of allowed tables retained.
	 * @throws Retry_Exception When the table list cannot be rewritten safely.
	 */
	private static function restrict_to_current_site_tables() {
		global $wpdb;

		$list_path = Backup::get_tables_list_path();
		$temp_path = $list_path . '.filtering';
		$source    = @fopen( $list_path, 'rb' );
		$target    = @fopen( $temp_path, 'wb' );

		if ( ! is_resource( $source ) || ! is_resource( $target ) ) {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}
			if ( is_resource( $target ) ) {
				fclose( $target );
			}
			@unlink( $temp_path );

			throw new Retry_Exception( __( 'The database table list could not be filtered for this subsite.', 'simply-static-studio-backup-migrate' ) );
		}

		$count  = 0;
		$prefix = (string) $wpdb->prefix;
		if ( '' === $prefix ) {
			fclose( $source );
			fclose( $target );
			@unlink( $temp_path );

			throw new Retry_Exception( __( 'The current subsite database prefix is unavailable.', 'simply-static-studio-backup-migrate' ) );
		}
		while ( false !== ( $row = Helper::getcsv( $source ) ) ) {
			if ( ! is_array( $row ) || ! isset( $row[0] ) || ! is_string( $row[0] ) ) {
				continue;
			}

			$table_name = $row[0];
			if ( 0 !== strpos( $table_name, $prefix ) ) {
				continue;
			}

			Helper::putcsv( $target, array( $table_name ) );
			$count++;
		}

		fclose( $source );
		fclose( $target );

		if ( ! @rename( $temp_path, $list_path ) ) {
			@unlink( $temp_path );
			throw new Retry_Exception( __( 'The filtered database table list could not be saved.', 'simply-static-studio-backup-migrate' ) );
		}

		return $count;
	}
}
