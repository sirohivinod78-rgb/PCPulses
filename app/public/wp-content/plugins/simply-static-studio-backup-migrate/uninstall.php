<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Sensitive local backup cleanup must preserve symlink boundaries and active native worker locks; remote filesystem transports and filtered deletion cannot preserve those guarantees.
/**
 * Uninstall routine for Simply Static Studio Backup and Migrate
 *
 * This file is executed by WordPress during plugin uninstallation.
 * Having this file ensures WordPress does NOT load the main plugin file,
 * avoiding any side effects that could interfere with deletion.
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$sssbm_options = array(
	'sssbm_backup_migrate',
	'sssbm_export_process_running',
	'sssbm_export_complete',
	'sss_backup_migrate',
	'sss_export_process_running',
	'sss_export_complete',
	'sssbm_archive_file_path',
	'sssbm_backup_operation_lock',
	'sssbm_backup_run_lock',
	'sssbm_backup_integrity_issues',
	'sssbm_backup_purge_receipt',
	'sssbm_cancel_run_token',
	'sssbm_completed_task_sequence',
	'sssbm_consumed_backup_generation',
	'sssbm_backup_download_started',
	'sssbm_current_task',
	'sssbm_export_params',
	'sssbm_last_progress_at',
	'sssbm_last_run_outcome',
	'sssbm_media_bytes_offset',
	'sssbm_messages',
	'sssbm_package_bytes_offset',
	'sssbm_paused_run_token',
	'sssbm_previous_archive_file_path',
	'sssbm_previous_backup_integrity_issues',
	'sssbm_previous_completed_task_sequence',
	'sssbm_static_download',
	'sssbm_storage_key',
	'sssbm_studio_push_lock',
	'sssbm_studio_transfer_checkpoint',
	'sssbm_studio_access_token',
	'sssbm_studio_auth_lock',
	'sssbm_studio_hardypress_user',
	'sssbm_studio_refresh_token',
	'sssbm_studio_site_link',
	'sssbm_studio_token_expires',
	'sssbm_studio_user_email',
	'sssbm_studio_user_id',
	'sssbm_migration_observation',
	'sssbm_migration_source_id',
	'sssbm_migration_observation_lock',
	'sssbm_task_sequence',
	'sssbm_task_stall_state',
	'sssbm_watchdog_state',
);

// The keyed directory is private to this plugin, so remove its complete tree,
// including custom archive names and future/nested working artifacts. Symlinks
// are unlinked and never traversed.
$sssbm_remove_tree = null;
$sssbm_remove_tree = static function ( $path, $preserve_path = '' ) use ( &$sssbm_remove_tree ) {
	if ( '' !== $preserve_path && $path === $preserve_path ) {
		return;
	}

	if ( is_link( $path ) ) {
		@unlink( $path );

		return;
	}

	if ( ! is_dir( $path ) ) {
		if ( is_file( $path ) ) {
			@unlink( $path );
		}

		return;
	}

	try {
		$iterator = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $entry ) {
			$sssbm_remove_tree( $entry->getPathname(), $preserve_path );
		}
	} catch ( Throwable $exception ) {
		// Best effort during uninstall; rmdir below will safely fail if anything remains.
	}

	// The root holding an open worker lock is removed after the lock handle is
	// closed. This ordering also works on Windows, where open files cannot be
	// unlinked.
	if ( '' === $preserve_path || dirname( $preserve_path ) !== $path ) {
		@rmdir( $path );
	}
};

// Keep this list in sync with Backup::get_tasks().
$sssbm_tasks = array(
	'init',
	'archive',
	'config',
	'config_file',
	'enumerate_content',
	'enumerate_media',
	'enumerate_plugins',
	'enumerate_themes',
	'enumerate_tables',
	'content',
	'media',
	'plugins',
	'themes',
	'database',
	'database_file',
	'clean',
);

// Clean one site's options, transients, scheduled work, and files. This is
// invoked for every site because a network uninstall otherwise leaves
// credentials and backups in subsite uploads directories.
$sssbm_cleanup_site = static function () use ( $sssbm_options, $sssbm_tasks, $sssbm_remove_tree ) {
	$storage_key = function_exists( 'get_option' ) ? get_option( 'sssbm_storage_key', '' ) : '';
	$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	$identifier  = function_exists( 'is_multisite' ) && is_multisite()
		? 'simply_static_' . $blog_id . '_backup_process'
		: 'simply_static_backup_process';

	if ( function_exists( 'delete_option' ) ) {
		foreach ( $sssbm_options as $option ) {
			delete_option( $option );
		}

		// The current worker lock is an atomic, per-blog option. Also remove the
		// unsuffixed key left by plugin versions that predate multisite isolation.
		delete_option( $identifier . '_process_lock' );
		delete_option( 'simply_static_backup_process_process_lock' );

		foreach ( $sssbm_tasks as $task ) {
			delete_option( 'simply_static_migration_retry_count_' . $task );
		}
	}

	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( 'sssbm_activation_redirect' );
		delete_transient( 'sssbm_download_token' );
	}

	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( $identifier . '_cron' );
		// Also remove events left by pre-isolation plugin versions.
		wp_clear_scheduled_hook( 'simply_static_backup_process_cron' );
		wp_clear_scheduled_hook( 'sssbm_revoke_static_download' );
		wp_clear_scheduled_hook( 'sssbm_migration_observation' );
	}

	if ( function_exists( 'delete_site_option' ) ) {
		delete_site_option( $identifier . '_status' );
	}

	if ( function_exists( 'delete_site_transient' ) ) {
		delete_site_transient( $identifier . '_process_lock' );
	}

	global $wpdb;
	if ( isset( $wpdb ) ) {
		$batch_prefix = $wpdb->esc_like( $identifier . '_batch_' ) . '%';
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $batch_prefix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared deletion removes only this plugin's queue records during uninstall; no cacheable result is retained.
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $batch_prefix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared deletion removes only this plugin's queue records during uninstall; no cacheable result is retained.
		}
	}

	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return;
	}

	$uploads = wp_upload_dir();
	$uploads_basedir = is_array( $uploads ) && is_string( $uploads['basedir'] ?? null )
		? $uploads['basedir']
		: '';
	if ( '' === $uploads_basedir ) {
		return;
	}

	$uploads_are_local = ! preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*://#', $uploads_basedir );
	$legacy_dir = $uploads_are_local
		? trailingslashit( $uploads_basedir ) . 'simply-static' . DIRECTORY_SEPARATOR
		: '';
	// Resolve the local offload fallback without loading the main plugin bootstrap.
	if ( ! class_exists( '\\Simply_Static\\Backup\\Helper', false ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'src/class-helper.php';
	}
	$content_root = \Simply_Static\Backup\Helper::get_content_path();
	$fallback_root = trailingslashit( $content_root ) . '.simply-static';
	$storage_base = $uploads_are_local
		? $legacy_dir
		: trailingslashit( $fallback_root ) . 'backup-migrate' . DIRECTORY_SEPARATOR;
	// Do not traverse a replacement of the shared uploads storage directory.
	// Strip the separator: is_link() follows directory symlinks with a trailing slash.
	if ( $uploads_are_local && is_link( rtrim( $legacy_dir, '/\\' ) ) ) {
		return;
	}
	if ( ! $uploads_are_local ) {
		$real_content_root = realpath( $content_root );
		$real_storage_base = realpath( $storage_base );
		$expected_storage_base = false !== $real_content_root
			? trailingslashit( wp_normalize_path( $real_content_root ) ) . '.simply-static/backup-migrate'
			: '';
		if (
			is_link( $fallback_root ) ||
			is_link( rtrim( $storage_base, '/\\' ) ) ||
			( false !== $real_storage_base && wp_normalize_path( $real_storage_base ) !== $expected_storage_base )
		) {
			return;
		}
	}
	$private_dir = '';
	if ( is_string( $storage_key ) && preg_match( '/^[a-f0-9]{32}$/', $storage_key ) ) {
		$private_dir = $storage_base . 'backup-' . $storage_key . DIRECTORY_SEPARATOR;
	}

	foreach ( array_filter( array( $legacy_dir ) ) as $dir ) {
		$files = array(
			$dir . 'media.list',
			$dir . 'config.json',
			$dir . 'content.list',
			$dir . 'plugins.list',
			$dir . 'themes.list',
			$dir . 'db.sql',
			$dir . 'db.sql.redacting',
			$dir . 'db.sql.redacting-original',
			$dir . 'db.sql.redacting.lock',
			$dir . 'db.sql.database-file.lock',
			$dir . 'tables.list',
			$dir . 'tables.list.filtering',
			$dir . 'sssbm-debug.txt',
			$dir . 'backup.lock',
		);

		foreach ( array( $dir . 'studio-backup-*.zip', $dir . 'studio-backup-*.zip.bak', $dir . '*.list' ) as $pattern ) {
			$matches = glob( $pattern );
			if ( is_array( $matches ) ) {
				$files = array_merge( $files, $matches );
			}
		}

		foreach ( array_unique( array_filter( $files ) ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				@unlink( $file );
			}
		}

	}

	// Static handoffs live in isolated, randomly named capability directories.
	// The current root belongs exclusively to this plugin. Also remove the exact
	// legacy leaf pattern without traversing a symlink.
	if ( $uploads_are_local ) {
		$download_root = trailingslashit( $uploads_basedir ) . 'simply-static-downloads';
		$sssbm_remove_tree( $download_root );

		$download_directories = glob( $legacy_dir . 'download-*', GLOB_ONLYDIR );
		if ( is_array( $download_directories ) ) {
			foreach ( $download_directories as $download_directory ) {
				if ( preg_match( '/^download-[a-f0-9]{32}$/D', basename( $download_directory ) ) ) {
					$sssbm_remove_tree( $download_directory );
				}
			}
		}
	}

	if ( '' !== $private_dir ) {
		$private_root = rtrim( $private_dir, '/\\' );
		if ( is_link( $private_root ) ) {
			@unlink( $private_root );
		} elseif ( is_dir( $private_root ) ) {
			$worker_lock_path = $private_root . DIRECTORY_SEPARATOR . '.sssbm-worker-' . md5( $identifier ) . '.lock';
			// Never follow a planted symlink while trying to establish ownership.
			$worker_lock      = \Simply_Static\Backup\Helper::open_lock_file( $worker_lock_path, $private_root );
			if ( is_resource( $worker_lock ) && @flock( $worker_lock, LOCK_EX | LOCK_NB ) ) {
				$sssbm_remove_tree( $private_root, $worker_lock_path );
				@flock( $worker_lock, LOCK_UN );
				@fclose( $worker_lock );
				@unlink( $worker_lock_path );
				@rmdir( $private_root );
			} elseif ( is_resource( $worker_lock ) ) {
				@fclose( $worker_lock );
			}
		}
	}

	// These succeed only when no unrelated Simply Static component is using them.
	@rmdir( $storage_base );
	if ( ! $uploads_are_local ) {
		@rmdir( $fallback_root );
	}
};

$sssbm_cleanup_site();

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
	$sssbm_current_blog_id = get_current_blog_id();
	$sssbm_site_ids        = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $sssbm_site_ids as $sssbm_site_id ) {
		if ( (int) $sssbm_site_id === (int) $sssbm_current_blog_id ) {
			continue;
		}

		switch_to_blog( $sssbm_site_id );
		$sssbm_cleanup_site();
		restore_current_blog();
	}
}

// Best-effort: remove any leftover site transients/options used by our background process
if ( function_exists( 'delete_site_option' ) ) {
	// These keys are derived from Queue\Background_Process identifier "simply_static_backup_process"
	// but since we don't load classes here, we perform broad cleanup.
	$sssbm_maybe_keys = array(
		'simply_static_backup_process_status',
	);
	foreach ( $sssbm_maybe_keys as $sssbm_key ) {
		delete_site_option( $sssbm_key );
	}
}

if ( function_exists( 'delete_site_transient' ) ) {
	delete_site_transient( 'simply_static_backup_process_process_lock' );
}

// Queue batches use generated option names, so remove them with a constrained
// prefix query instead of leaving serialized work items behind.
global $wpdb;

if ( isset( $wpdb ) ) {
	$sssbm_queue_prefix = $wpdb->esc_like( 'simply_static_backup_process_batch_' ) . '%';

	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared deletion removes only this plugin's queue records during uninstall; no cacheable result is retained.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$sssbm_queue_prefix
			)
		);
	} else {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared deletion removes only this plugin's queue records during uninstall; no cacheable result is retained.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$sssbm_queue_prefix
			)
		);
	}
}
