<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Private backup downloads and uploads require bounded native file streams; WP_Filesystem cannot expose handles, seek offsets or snapshot inode metadata.

namespace Simply_Static\Backup\Rest;

use Simply_Static\Backup\Backup;
use Simply_Static\Backup\Plugin;
use Simply_Static\Backup\Helper;
use Simply_Static\Backup\Logs\Logger;
use Simply_Static\Backup\Status;
use Simply_Static\Backup\Studio_Transfer_Checkpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	/** Maximum response body retained for auth API calls. */
	private const MAX_AUTH_RESPONSE_BYTES = 262144;

	/** Maximum response body retained for Studio API calls. */
	private const MAX_API_RESPONSE_BYTES = 1048576;

	/** Maximum response body retained from the streamed archive upload. */
	private const MAX_UPLOAD_RESPONSE_BYTES = 65536;

	/** Chunk size used by Studio's canonical multipart migration upload. */
	private const STUDIO_UPLOAD_PART_SIZE = 26214400;

	/** Maximum archive size accepted by Studio's migration storage. */
	private const STUDIO_UPLOAD_MAX_BYTES = 10737418240;

	/** Maximum part URLs Studio signs in one request. */
	private const STUDIO_UPLOAD_SIGN_BATCH_SIZE = 10;

	/** Supabase storage origin allowed for signed Studio upload capabilities. */
	private const STUDIO_STORAGE_ORIGIN = 'https://yingqdimvkeqontaesxk.storage.supabase.co';

	/** Canonical private bucket used by Docker migration artifacts. */
	private const STUDIO_MIGRATION_BUCKET = 'platform_migrations';

	/** Archives at or above this size are handed to the web server by default. */
	private const STATIC_DOWNLOAD_THRESHOLD = 104857600;

	/** Lifetime of one generated static-download capability. */
	private const STATIC_DOWNLOAD_TTL = 21600;

	/** Renewable lease used while PHP streams a backup response. */
	private const DOWNLOAD_OPERATION_TTL = 300;

	/** Renewable lease used while Studio persists and queues an uploaded artifact. */
	private const STUDIO_OPERATION_TTL = 900;

	/** Maximum interval between lease checks during a PHP download stream. */
	private const OPERATION_RENEW_INTERVAL = 30;

	/** Serializes Studio credential changes within a WordPress site. */
	private const STUDIO_AUTH_LOCK_OPTION = 'sssbm_studio_auth_lock';

	/** Remembers the Studio site created or selected by this WordPress site. */
	private const STUDIO_SITE_LINK_OPTION = 'sssbm_studio_site_link';

	/** Remembers which connected Studio user was recognized as a HardyPress customer. */
	private const STUDIO_HARDYPRESS_USER_OPTION = 'sssbm_studio_hardypress_user';

	/** Provider used by the phase-one Push to Studio integration. */
	private const STUDIO_LEGACY_HOSTING_PROVIDER = 'spinupwp';

	/** Docker provider that requires the private-artifact handoff. */
	private const STUDIO_DOCKER_HOSTING_PROVIDER = 'static-studio-docker';

	/** Default Studio PHP version when the source runtime cannot be detected. */
	private const DEFAULT_STUDIO_PHP_VERSION = '8.3';

	/** PHP runtimes supported by the Studio backup-import environment. */
	private const STUDIO_PHP_VERSIONS = array( '7.4', '8.1', '8.2', '8.3', '8.4' );

	/** HTTP status callers should expose when the latest token refresh failed. */
	private $studio_refresh_failure_status = 401;

	/** Retain source capability handling entirely on the WordPress server. */
	private $migration_observation;

	/**
	 * Contains the number of failed tests.
	 *
	 * @var int
	 */
	public int $failed_tests = 0;

	/**
	 * Contains instance or null
	 *
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Returns instance of SS_Admin_Settings.
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Setting up admin fields
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		$this->migration_observation = new \Simply_Static\Backup\Migration_Observation( $this );
	}

	/**
	 * Get the PHP runtimes available for Studio backup imports.
	 *
	 * @return string[]
	 */
	public static function get_studio_php_versions() {
		return self::STUDIO_PHP_VERSIONS;
	}

	/**
	 * Match a source PHP runtime to the closest Studio import runtime.
	 *
	 * @param mixed $source_version Full or major/minor source PHP version.
	 * @return string
	 */
	public static function get_recommended_studio_php_version( $source_version = PHP_VERSION ) {
		if ( ! is_string( $source_version ) || ! preg_match( '/^(\d{1,2})\.(\d{1,2})/', $source_version, $matches ) ) {
			return self::DEFAULT_STUDIO_PHP_VERSION;
		}

		$source_id   = ( (int) $matches[1] * 100 ) + (int) $matches[2];
		$recommended = self::DEFAULT_STUDIO_PHP_VERSION;
		$distance    = PHP_INT_MAX;

		foreach ( self::STUDIO_PHP_VERSIONS as $version ) {
			list( $major, $minor ) = array_map( 'intval', explode( '.', $version, 2 ) );
			$candidate_distance    = abs( $source_id - ( $major * 100 ) - $minor );

			if ( $candidate_distance < $distance ) {
				$recommended = $version;
				$distance    = $candidate_distance;
			}
		}

		return $recommended;
	}

	/**
	 * Require a high-trust capability for backup data and lifecycle controls.
	 *
	 * A backup contains password hashes, private content, configuration, and
	 * plugin code, so content-editor capabilities are not sufficient.
	 *
	 * @param \WP_REST_Request|null $request Current REST request.
	 * @return bool
	 */
	public function can_manage_backups( $request = null ) {
		$default_capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		$capability         = apply_filters( 'sssbm_manage_backup_capability', $default_capability, $request );

		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	/**
	 * Setup Rest API endpoints.
	 *
	 * @return void
	 */
	public function rest_api_init() {
		register_rest_route( 'sss-backup-migrate/v1', '/settings', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/settings', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_settings' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/activity-log', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_activity_log' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/export-log', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_export_log' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/start-export', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'start_export' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/browser-worker', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'run_browser_worker' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/cancel-export', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'cancel_export' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/pause-export', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'pause_export' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/resume-export', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'resume_export' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/is-running', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'is_running' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/debug-log', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_debug_log' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/delete-backups', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'delete_all_backups' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/download-backup', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'download_backup' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/download-debug-log', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'download_debug_log' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/download-backup-shared', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'download_backup_shared' ],
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/send-otp', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'send_otp' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/verify-otp', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_otp' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'disconnect_from_studio' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/connection-status', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_connection_status' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/studio-sites', array(
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_studio_sites' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );

		register_rest_route( 'sss-backup-migrate/v1', '/push-to-studio', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'push_to_studio' ],
			'permission_callback' => [ $this, 'can_manage_backups' ],
		) );
	}

	/**
	 * Get settings via Rest API.
	 *
	 * @return false|mixed|null
	 */
	public function get_settings() {
		$settings = Plugin::get_migrated_option( 'sssbm_backup_migrate', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['http_basic_auth_configured'] = ! empty( $settings['http_basic_auth_username'] ) && ! empty( $settings['http_basic_auth_password'] );
		$settings['http_basic_auth_password']   = '';

		return $settings;
	}

	/**
	 * Save settings via rest API.
	 *
	 * @param object $request given request.
	 *
	 * @return array
	 */
	public function save_settings( $request ) {
		$params = $request->get_params();
		if ( ! is_array( $params ) || empty( $params ) ) {
			return new \WP_Error(
				'no_settings',
				__( 'No settings were provided.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 400 )
			);
		}

		$validated_booleans = array();
		foreach ( array( 'include_plugins', 'include_themes', 'include_media', 'include_database' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$value = $this->normalize_boolean_setting( $params[ $field ], false );
				if ( null === $value ) {
					return $this->invalid_setting_error( $field );
				}
				$validated_booleans[ $field ] = $value;
			}
		}

		$integer_fields    = array(
			'task_delay'      => array( 5, 300 ),
			'iteration_delay' => array( 1, 60 ),
			'batch_size'      => array( 1, 1000 ),
		);
		$validated_integers = array();
		foreach ( $integer_fields as $field => $bounds ) {
			if ( array_key_exists( $field, $params ) ) {
				$value = $this->normalize_integer_setting( $params[ $field ], $bounds[0], $bounds[1] );
				if ( null === $value ) {
					return $this->invalid_setting_error( $field );
				}
				$validated_integers[ $field ] = $value;
			}
		}

		$batch_delay_enabled = null;
		if ( array_key_exists( 'batch_delay_enabled', $params ) ) {
			$batch_delay_enabled = $this->normalize_boolean_setting( $params['batch_delay_enabled'], true );
			if ( null === $batch_delay_enabled ) {
				return $this->invalid_setting_error( 'batch_delay_enabled' );
			}
		}

		$basic_auth_enabled = null;
		if ( array_key_exists( 'http_basic_auth_enabled', $params ) ) {
			$basic_auth_enabled = $this->normalize_boolean_setting( $params['http_basic_auth_enabled'], true );
			if ( null === $basic_auth_enabled ) {
				return $this->invalid_setting_error( 'http_basic_auth_enabled' );
			}
		}

		foreach ( array( 'http_basic_auth_username', 'http_basic_auth_password' ) as $field ) {
			if ( array_key_exists( $field, $params ) && ! is_string( $params[ $field ] ) ) {
				return $this->invalid_setting_error( $field );
			}
		}

		// Apply only after every supplied value has passed validation so a bad
		// field cannot cause a partial settings update.
		$options = Plugin::get_migrated_option( 'sssbm_backup_migrate', array() );
		$options = is_array( $options ) ? $options : array();
		foreach ( $validated_booleans as $field => $value ) {
			$options[ $field ] = $value;
		}
		foreach ( $validated_integers as $field => $value ) {
			$options[ $field ] = $value;
		}
		if ( null !== $batch_delay_enabled ) {
			$options['batch_delay_enabled'] = $batch_delay_enabled ? 'on' : '';
		}
		if ( null !== $basic_auth_enabled ) {
			$options['http_basic_auth_enabled'] = $basic_auth_enabled ? 'on' : '';
		}

		$auth_was_explicitly_disabled = false === $basic_auth_enabled;
		if ( $auth_was_explicitly_disabled ) {
			unset( $options['http_basic_auth_username'], $options['http_basic_auth_password'] );
		} else {
			if ( array_key_exists( 'http_basic_auth_username', $params ) ) {
				$username = $this->sanitize_basic_auth_username( (string) $params['http_basic_auth_username'] );
				if ( '' !== $username ) {
					$options['http_basic_auth_username'] = $username;
				}
			}

			if ( array_key_exists( 'http_basic_auth_password', $params ) ) {
				$password = $this->sanitize_basic_auth_password( (string) $params['http_basic_auth_password'] );
				if ( '' !== $password ) {
					$options['http_basic_auth_password'] = $password;
				}
			}
		}

		// An empty password in the UI is a mask meaning "keep the stored
		// password". Validate the fully merged result, after sanitization, so a
		// general settings save cannot enable unusable Basic Auth credentials.
		if ( 'on' === ( $options['http_basic_auth_enabled'] ?? '' ) ) {
			$username = isset( $options['http_basic_auth_username'] ) && is_string( $options['http_basic_auth_username'] )
				? $this->sanitize_basic_auth_username( $options['http_basic_auth_username'] )
				: '';
			$password = isset( $options['http_basic_auth_password'] ) && is_string( $options['http_basic_auth_password'] )
				? $this->sanitize_basic_auth_password( $options['http_basic_auth_password'] )
				: '';

			if ( '' === $username || '' === $password ) {
				return new \WP_Error(
					'incomplete_basic_auth_credentials',
					__( 'A non-empty username and password are required when HTTP Basic Auth is enabled.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 400 )
				);
			}

			$options['http_basic_auth_username'] = $username;
			$options['http_basic_auth_password'] = $password;
		}

		unset( $options['http_basic_auth_configured'] );
		update_option( 'sssbm_backup_migrate', $options, false );
		$persisted = get_option( 'sssbm_backup_migrate', null );
		if ( ! is_array( $persisted ) || $persisted !== $options ) {
			return new \WP_Error(
				'settings_not_saved',
				__( 'The settings could not be saved. Please check the WordPress database and try again.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}

		return array( 'status' => 200, 'message' => 'Ok' );
	}

	/**
	 * Strictly normalize a boolean setting.
	 *
	 * @param mixed $value Candidate setting value.
	 * @param bool  $allow_checkbox_strings Whether "on" and an empty string are accepted.
	 * @return bool|null Normalized value, or null when invalid.
	 */
	private function normalize_boolean_setting( $value, $allow_checkbox_strings ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( 1 === $value || '1' === $value || 'true' === $value ) {
			return true;
		}

		if ( 0 === $value || '0' === $value || 'false' === $value ) {
			return false;
		}

		if ( $allow_checkbox_strings && 'on' === $value ) {
			return true;
		}

		if ( $allow_checkbox_strings && '' === $value ) {
			return false;
		}

		return null;
	}

	/**
	 * Strictly normalize a bounded integer setting.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $minimum Minimum accepted value.
	 * @param int   $maximum Maximum accepted value.
	 * @return int|null Normalized value, or null when invalid.
	 */
	private function normalize_integer_setting( $value, $minimum, $maximum ) {
		if ( is_int( $value ) ) {
			$integer = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\s*[0-9]+\s*$/D', $value ) ) {
			$integer = (int) trim( $value );
		} else {
			return null;
		}

		return $integer >= $minimum && $integer <= $maximum ? $integer : null;
	}

	/**
	 * Return a consistent error for a rejected settings field.
	 *
	 * @param string $field Field name.
	 * @return \WP_Error
	 */
	private function invalid_setting_error( $field ) {
		return new \WP_Error(
			'invalid_setting',
			sprintf(
				/* translators: %s: invalid request or settings field name. */
				__( 'The value supplied for %s is invalid.', 'simply-static-studio-backup-migrate' ),
				$field
			),
			array( 'status' => 400, 'setting' => $field )
		);
	}

	/**
	 * Sanitize an HTTP Basic Auth username (colons are not valid in user-id).
	 *
	 * @param string $username Raw username.
	 * @return string
	 */
	private function sanitize_basic_auth_username( $username ) {
		$username = preg_replace( '/[\x00-\x1F\x7F:]/', '', $username );

		return substr( trim( (string) $username ), 0, 255 );
	}

	/**
	 * Preserve valid password punctuation while stripping header control bytes.
	 *
	 * @param string $password Raw password.
	 * @return string
	 */
	private function sanitize_basic_auth_password( $password ) {
		$password = preg_replace( '/[\x00-\x1F\x7F]/', '', $password );

		return substr( (string) $password, 0, 1024 );
	}

	/**
	 * Get Activity Log.
	 *
	 * @return array
	 */
	public function get_activity_log( $request ) {
		$activity_log = Status::get_messages();
		$activity_log = is_array( $activity_log ) ? $activity_log : array();

		$progress_state = Backup::get_progress_state();
		$progress       = $progress_state['progress'];
		$task_progress  = $progress_state['task_progress'];
		$current_action = '';
		$is_running     = in_array( $progress_state['status'], array( 'running', 'paused' ), true );

		// Get the latest message as current action.
		if ( ! empty( $activity_log ) ) {
			foreach ( array_reverse( $activity_log ) as $last_message ) {
				if ( is_array( $last_message ) && isset( $last_message['message'] ) && is_scalar( $last_message['message'] ) ) {
					$current_action = wp_strip_all_tags( (string) $last_message['message'] );
					break;
				}
			}
		}

		// Include debug log availability so the frontend can show the button
		// without a separate request.
		$debug_file    = Logger::getFilename();
		$debug_log_url = '';
		if (
			is_file( $debug_file ) &&
			is_readable( $debug_file ) &&
			! is_link( $debug_file ) &&
			Helper::is_path_within( $debug_file, Backup::get_storage_path() )
		) {
			$debug_log_url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'sss-backup-migrate/v1/download-debug-log' ) );
		}

		return array(
			'status'           => 200,
			'data'             => $activity_log,
			'running'          => $is_running,
			'progress'         => $progress,
			'task_progress'    => $task_progress,
			'current_action'   => $current_action,
			'debug_log_url'    => $debug_log_url,
			'backup_integrity' => Backup::get_backup_integrity_status(),
		);
	}

	/**
	 * Get Backup Log
	 *
	 * @return array
	 */
	public function get_export_log( $request ) {
		$params    = $request->get_params();
		$params    = is_array( $params ) ? $params : array();
		$per_page = array_key_exists( 'per_page', $params )
			? $this->normalize_integer_setting( $params['per_page'], 1, 100 )
			: 20;
		$page = array_key_exists( 'page', $params )
			? $this->normalize_integer_setting( $params['page'], 1, 1000000 )
			: 1;
		if ( null === $per_page || null === $page ) {
			return new \WP_Error(
				'invalid_pagination',
				__( 'The export log pagination values are invalid.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 400 )
			);
		}
		$messages  = Status::get_messages();
		$messages  = is_array( $messages ) ? $messages : array();
		$export_log = array_slice( array_reverse( $messages ), ( $page - 1 ) * $per_page, $per_page );

		return array(
			'status' => 200,
			'data'   => $export_log,
			'total'  => count( $messages ),
		);
	}

	/**
	 * Start Backup
	 *
	 * @return array|\WP_Error
	 */
	public function start_export( $request ) {
		if ( is_multisite() && is_main_site() ) {
			return new \WP_Error( 'network_backup_unsupported', __( 'Multisite backups on network level are not supported.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}

		$blog_id = get_current_blog_id();
		do_action( 'sssbm_before_perform_action', $blog_id, 'start' );

		try {
			$start_result = Backup::start_export();
		} catch ( \Throwable $exception ) {
			$debug_message = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $exception->getMessage() ) );
			Logger::log( 'Backup start failed: ' . substr( (string) $debug_message, 0, 1000 ) );
			Status::error( __( 'Backup could not be started safely. Please review the debug log and try again.', 'simply-static-studio-backup-migrate' ) );

			return new \WP_Error(
				'backup_start_failed',
				__( 'Backup could not be started safely. Please review the debug log and try again.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}
		if ( is_wp_error( $start_result ) ) {
			return $start_result;
		}
		if ( ! $start_result ) {
			return new \WP_Error(
				'backup_already_running',
				__( 'A backup is already running or waiting to resume.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}

		do_action( 'sssbm_after_perform_action', $blog_id, 'start' );

		return array(
			'status' => 200,
		);
	}

	/**
	 * Process one queue slice using the current authenticated browser request.
	 *
	 * This endpoint is intentionally capability- and nonce-protected by the REST
	 * route. It is used only by the explicit browser-worker admin mode when a host
	 * blocks the plugin's normal loopback requests.
	 *
	 * @return array|\WP_Error
	 */
	public function run_browser_worker() {
		$process = Backup::get_process();

		if ( $process->is_cancelled() ) {
			$this->cleanup_cancelled_process( $process );
		}

		if ( ! Backup::is_running() ) {
			return array(
				'status'    => 200,
				'processed' => false,
				'running'   => $process->is_active(),
				'paused'    => $process->is_paused(),
			);
		}

		try {
			$processed = $process->handle_browser_worker_request();
		} catch ( \Throwable $exception ) {
			$debug_message = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $exception->getMessage() ) );
			Logger::log( 'Browser-assisted backup request failed: ' . substr( (string) $debug_message, 0, 1000 ) );

			return new \WP_Error(
				'browser_worker_failed',
				__( 'Browser-assisted processing could not continue. Reload the backup page to retry.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'status'    => 200,
			'processed' => $processed,
			'running'   => Backup::is_running() || $process->is_active(),
			'paused'    => $process->is_paused(),
		);
	}

	/**
	 * Cancel Backup
	 *
	 * @param object $request given request.
	 *
	 * @return array|\WP_Error
	 */
	public function cancel_export( $request ) {
		Logger::log( "Received request to cancel static archive generation" );
		$blog_id = get_current_blog_id();
		$process = Backup::get_process();
		$run_token = Backup::get_current_run_token();

		if ( ! Backup::is_running() && ! $process->is_active() ) {
			return array( 'status' => 200 );
		}

		do_action( 'sssbm_before_perform_action', $blog_id, 'cancel' );

		if ( ! $process->request_cancel( $run_token ) ) {
			return new \WP_Error( 'backup_run_changed', __( 'The backup generation changed before cancellation could be applied.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		Status::error( __( 'Backup cancellation requested. Waiting for the current archive write to finish safely.', 'simply-static-studio-backup-migrate' ) );
		delete_transient( 'sssbm_download_token' );
		$cleaned = $this->cleanup_cancelled_process( $process );
		Logger::log( $cleaned
			? 'Backup cancellation completed.'
			: 'Backup cancellation is pending while the current worker releases its lease.'
		);

		do_action( 'sssbm_after_perform_action', $blog_id, 'cancel' );

		return array( 'status' => 200 );
	}

	/**
	 * Is running
	 *
	 * @return array
	 */
	public function is_running( $request ) {
		$process = Backup::get_process();

		if ( $process->is_cancelled() ) {
			$this->cleanup_cancelled_process( $process );
		} elseif ( ! Backup::is_running() && ! Backup::is_completed() && $process->is_queued() && ! $process->is_processing() && ! $process->is_paused() ) {
			if ( $process->request_cancel( null, 'failed' ) && $this->cleanup_cancelled_process( $process ) ) {
				Status::error( __( 'An orphaned backup queue was removed safely.', 'simply-static-studio-backup-migrate' ) );
			}
		}

		// Detect stale/stuck backup processes.
		// If the process is marked as running but the background queue is empty
		// and it's not actually processing, the loopback request likely failed.
		if ( Backup::is_running() && ! Backup::is_completed() ) {
			$is_queue_active = $process->is_active();

			if ( ! $is_queue_active ) {
				// The process flag says running, but the queue is empty/inactive.
				// This means the background loopback request failed silently.
				Logger::log( 'Stale backup process detected — process marked as running but queue is inactive. Cleaning up.' );
				Status::error( __( 'Backup failed — the background process could not continue. This usually happens when your hosting blocks loopback HTTP requests. Please try again or contact your hosting provider.', 'simply-static-studio-backup-migrate' ) );
				$run_token = Backup::get_current_run_token();
				Backup::abort_export( is_string( $run_token ) ? $run_token : '' );
			} elseif ( ! $process->is_paused() && $process->is_queued() && ! $process->is_processing() ) {
				$this->watchdog_stalled_queue( $process );
			}
		}

		$backup_url = '';

		$backup_size = 0;

		$archive_path = Backup::get_existing_backup_path();
		$backup_consumed       = Backup::is_completed_generation_consumed();
		$backup_download_started = Backup::is_completed_generation_download_started();
		if ( Backup::is_completed() && $archive_path && ! $backup_consumed ) {
			// Token-based URL — works for both browser downloads and server-side fetching
			// (no cookie/nonce auth required).
			$token = get_transient( 'sssbm_download_token' );

			if ( ! $token ) {
				$token = wp_generate_password( 32, false );
				set_transient( 'sssbm_download_token', $token, 48 * HOUR_IN_SECONDS );
			}

			$backup_url = rest_url( 'sss-backup-migrate/v1/download-backup-shared' );
			$backup_url = add_query_arg( 'token', $token, $backup_url );

			$size        = @filesize( $archive_path );
			$backup_size = false === $size ? 0 : $size;
		}

		return array(
			'status'           => 200,
			'running'          => Backup::is_running() || $process->is_active(),
			'paused'           => $process->is_paused(),
			'backup'           => $backup_url,
			'backup_link'      => $backup_url,
			'backup_size'      => $backup_size,
			'backup_consumed'  => $backup_consumed,
			'backup_download_started' => $backup_download_started,
			'backup_integrity' => Backup::get_backup_integrity_status(),
			'last_run_outcome' => Backup::get_last_run_outcome(),
		);
	}

	/**
	 * Clean a cancelled process normally, then recover an abandoned lease as
	 * soon as the stable filesystem lock proves the old worker is gone.
	 *
	 * @param \Simply_Static\Backup\Queue\Backup_Process $process Backup process.
	 * @return bool Whether cancellation cleanup completed.
	 */
	private function cleanup_cancelled_process( $process ) {
		if ( $process->cleanup_cancelled_if_idle() ) {
			return true;
		}

		$stale_after = $this->get_bounded_integer_filter(
			'sssbm_cancel_stale_after',
			0,
			0,
			30 * MINUTE_IN_SECONDS
		);

		return $process->cleanup_cancelled_if_stale( $stale_after );
	}

	/**
	 * Retry a queued-but-idle loopback process, then fail it after bounded attempts.
	 *
	 * @param \Simply_Static\Backup\Queue\Backup_Process $process Backup process.
	 * @return void
	 */
	private function watchdog_stalled_queue( $process ) {
		$last_progress = (int) get_option( 'sssbm_last_progress_at', 0 );
		$run_lock      = get_option( Backup::RUN_LOCK_OPTION, array() );
		if ( is_array( $run_lock ) && isset( $run_lock['created'] ) ) {
			$last_progress = max( $last_progress, (int) $run_lock['created'] );
		}

		$stale_after = $this->get_bounded_integer_filter(
			'sssbm_watchdog_stale_after',
			5 * MINUTE_IN_SECONDS,
			MINUTE_IN_SECONDS,
			HOUR_IN_SECONDS
		);
		if ( $last_progress > time() - $stale_after ) {
			return;
		}

		$state = get_option( 'sssbm_watchdog_state', array() );
		$state = is_array( $state ) ? $state : array();
		if ( (int) ( $state['progress'] ?? 0 ) !== $last_progress ) {
			$state = array( 'attempts' => 0, 'last_attempt' => 0, 'progress' => $last_progress );
		}

		$retry_after = $this->get_bounded_integer_filter(
			'sssbm_watchdog_retry_after',
			MINUTE_IN_SECONDS,
			30,
			15 * MINUTE_IN_SECONDS
		);
		if ( (int) ( $state['last_attempt'] ?? 0 ) > time() - $retry_after ) {
			return;
		}

		$state['attempts']     = (int) ( $state['attempts'] ?? 0 ) + 1;
		$state['last_attempt'] = time();
		$state['progress']     = $last_progress;
		update_option( 'sssbm_watchdog_state', $state, false );

		$max_attempts = $this->get_bounded_integer_filter( 'sssbm_watchdog_max_attempts', 3, 1, 10 );
		if ( $state['attempts'] >= $max_attempts ) {
			if ( $process->request_cancel( null, 'failed' ) ) {
				$this->cleanup_cancelled_process( $process );
				Status::error( __( 'Backup failed after repeated loopback retries. Your host may be blocking WordPress loopback requests.', 'simply-static-studio-backup-migrate' ) );
			}

			return;
		}

		$result = $process->dispatch();
		if ( is_wp_error( $result ) ) {
			Logger::log( 'Background process watchdog dispatch failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Read a filtered integer without casting malformed plugin values.
	 *
	 * @param string $hook    Filter hook.
	 * @param int    $default Default value.
	 * @param int    $minimum Minimum allowed value.
	 * @param int    $maximum Maximum allowed value.
	 * @return int
	 */
	private function get_bounded_integer_filter( $hook, $default, $minimum, $maximum ) {
		$value = apply_filters( $hook, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Private callers supply only literal sssbm_ watchdog filter names.

		if ( is_int( $value ) ) {
			$normalized = $value;
		} elseif ( is_string( $value ) && preg_match( '/^-?[0-9]+$/D', trim( $value ) ) ) {
			$normalized = (int) trim( $value );
		} else {
			$normalized = $default;
		}

		return min( $maximum, max( $minimum, $normalized ) );
	}

	/**
	 * Pause Backup
	 *
	 * @param object $request given request.
	 *
	 * @return array|\WP_Error
	 */
	public function pause_export( $request ) {
		Logger::log( "Received request to pause static archive generation" );
		$blog_id = get_current_blog_id();
		$process = Backup::get_process();
		$run_token = Backup::get_current_run_token();

		if ( $process->is_cancelled() ) {
			return new \WP_Error( 'backup_cancelling', __( 'Cancellation is already in progress for this backup.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		if ( ! is_string( $run_token ) || ! Backup::is_running() || ! $process->is_active() ) {
			return new \WP_Error( 'backup_not_active', __( 'There is no active backup to pause.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		do_action( 'sssbm_before_perform_action', $blog_id, 'pause' );

		if ( ! $process->pause( $run_token ) ) {
			return new \WP_Error( 'backup_run_changed', __( 'The backup generation changed before it could be paused.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		do_action( 'sssbm_after_perform_action', $blog_id, 'pause' );

		return array( 'status' => 200 );
	}

	/**
	 * Resume Backup
	 *
	 * @param object $request given request.
	 *
	 * @return array|\WP_Error
	 */
	public function resume_export( $request ) {
		Logger::log( "Received request to resume static archive generation" );
		$blog_id = get_current_blog_id();
		$process = Backup::get_process();
		$run_token = Backup::get_current_run_token();

		if ( ! is_string( $run_token ) || ! $process->is_paused() ) {
			return new \WP_Error( 'backup_not_paused', __( 'The backup is not paused.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		do_action( 'sssbm_before_perform_action', $blog_id, 'resume' );

		if ( ! $process->resume( $run_token ) ) {
			return new \WP_Error( 'backup_run_changed', __( 'The paused backup generation changed before it could be resumed.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}
		$process->reset_stall_state();
		delete_option( 'sssbm_watchdog_state' );

		do_action( 'sssbm_after_perform_action', $blog_id, 'resume' );

		return array( 'status' => 200 );
	}

	/**
	 * Get Debug Log URL if it exists.
	 *
	 * @return array
	 */
	public function get_debug_log() {
		$debug_file = Logger::getFilename();
		$exists     = is_file( $debug_file )
			&& is_readable( $debug_file )
			&& ! is_link( $debug_file )
			&& Helper::is_path_within( $debug_file, Backup::get_storage_path() );
		$url        = '';

		if ( $exists ) {
			$url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'sss-backup-migrate/v1/download-debug-log' ) );
		}

		return array(
			'status' => 200,
			'exists' => $exists,
			'url'    => $url,
		);
	}

	/**
	 * Download backup file via REST API.
	 *
	 * Large backups are authorized here and then handed to the web server. Small
	 * backups, and hosts that cannot create a safe static capability, retain the
	 * PHP streaming fallback.
	 *
	 * @return \WP_Error|\WP_REST_Response|void
	 */
	public function download_backup() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
		if ( 'GET' !== $method ) {
			return new \WP_Error(
				'download_method_not_allowed',
				__( 'Could not download the file.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 405 )
			);
		}

		$archive_path = Backup::get_existing_backup_path();

		if ( ! $archive_path ) {
			return new \WP_Error(
				'backup_not_found',
				__( 'Could not download the file.', 'simply-static-studio-backup-migrate' ),
				[ 'status' => 404 ]
			);
		}

		// Never expose the replacement generation while it is still being
		// mutated. Completed state is cleared at launch and restored only after
		// the one-time final ZipArchive::CHECKCONS promotion succeeds.
		if ( Backup::is_running() || ! Backup::is_completed() ) {
			return new \WP_Error(
				'backup_not_ready',
				__( 'The backup is still being prepared.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}
		if ( Backup::has_active_studio_push() ) {
			return new \WP_Error(
				'studio_push_in_progress',
				__( 'Another Studio push is already in progress.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}

		$generation_id = Backup::get_completed_generation_id();
		if ( is_string( $generation_id ) && Backup::is_completed_generation_consumed( $generation_id ) ) {
			return new \WP_Error(
				'backup_consumed',
				__( 'This backup has already been transferred. Create a new backup before downloading it again.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}
		if ( ! is_string( $generation_id ) ) {
			return new \WP_Error(
				'backup_unreadable',
				__( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}

		$handle = @fopen( $archive_path, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return new \WP_Error(
				'backup_unreadable',
				__( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}
		if ( ! Backup::is_valid_archive_path( $archive_path, true ) ) {
			fclose( $handle );

			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}

		$filename = sanitize_file_name( basename( $archive_path ) );
		$stat     = fstat( $handle );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			fclose( $handle );
			return new \WP_Error(
				'backup_unreadable',
				__( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}
		$filesize          = (int) $stat['size'];
		$range_header = isset( $_SERVER['HTTP_RANGE'] ) && is_string( $_SERVER['HTTP_RANGE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) )
			: null;
		$range        = $this->parse_download_range( $range_header, $filesize );

		$static_url = false === $range
			? ''
			: $this->get_static_download_url( $archive_path, $filesize, $stat, $generation_id );
		if ( '' !== $static_url ) {
			fclose( $handle );

			return new \WP_REST_Response(
				null,
				302,
				array(
					'Location'               => $static_url,
					'Cache-Control'          => 'no-cache, no-store, must-revalidate, max-age=0',
					'Pragma'                 => 'no-cache',
					'Expires'                => '0',
					'X-Content-Type-Options' => 'nosniff',
					'Referrer-Policy'        => 'no-referrer',
				)
			);
		}

		// Keep the exact generation fenced for the entire PHP fallback stream.
		// Downloads never borrow a Studio lease: only the Studio result may consume
		// a generation owned by an in-flight push. The shutdown release protects
		// against client disconnects and fatal errors during the stream.
		$transfer_lock = Backup::acquire_operation_lock( 'download', self::DOWNLOAD_OPERATION_TTL );

		if ( ! is_string( $transfer_lock ) || '' === $transfer_lock ) {
			fclose( $handle );

			return new \WP_Error(
				'backup_operation_in_progress',
				__( 'A backup or Studio transfer is already in progress.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}

		$release_transfer_lock = true;
		$release_download_fence = static function () use ( &$release_transfer_lock, $transfer_lock ) {
			if ( true === $release_transfer_lock ) {
				Backup::release_operation_lock( $transfer_lock );
				$release_transfer_lock = false;
			}
		};
		register_shutdown_function( static function () use ( $transfer_lock ) {
			Backup::release_operation_lock( $transfer_lock );
		} );
		$next_lease_renewal = time() + self::OPERATION_RENEW_INTERVAL;
		$renew_download_lease = static function () use ( $transfer_lock, &$next_lease_renewal ) {
			if ( ! Backup::owns_operation_lock( $transfer_lock, 'download' ) ) {
				return false;
			}
			if ( time() < $next_lease_renewal ) {
				return true;
			}

			$renewed = Backup::renew_operation_lock( $transfer_lock, 'download', self::DOWNLOAD_OPERATION_TTL );
			if ( $renewed ) {
				$next_lease_renewal = time() + self::OPERATION_RENEW_INTERVAL;
			}

			return $renewed;
		};

		$current_generation = Backup::get_completed_generation_id();
		if (
			Backup::is_running() ||
			! Backup::is_completed() ||
			! is_string( $current_generation ) ||
			! hash_equals( $generation_id, $current_generation )
		) {
			fclose( $handle );
			$release_download_fence();

			return new \WP_Error(
				'backup_not_ready',
				__( 'The backup generation changed before the download could start.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}
		if ( Backup::is_completed_generation_consumed( $generation_id ) ) {
			fclose( $handle );
			$release_download_fence();

			return new \WP_Error(
				'backup_consumed',
				__( 'This backup has already been transferred. Create a new backup before downloading it again.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}

		if ( false === $range ) {
			fclose( $handle );
			$release_download_fence();

			return new \WP_REST_Response(
				array(
					'code'    => 'range_not_satisfiable',
					'message' => __( 'The requested download range is not available.', 'simply-static-studio-backup-migrate' ),
				),
				416,
				array(
					'Accept-Ranges'          => 'bytes',
					'Content-Range'          => 'bytes */' . $filesize,
					'Cache-Control'          => 'no-cache, no-store, must-revalidate, max-age=0',
					'Pragma'                 => 'no-cache',
					'Expires'                => '0',
					'X-Content-Type-Options' => 'nosniff',
				)
			);
		}

		$offset = 0;
		$length = $filesize;
		if ( is_array( $range ) ) {
			$offset = $range['start'];
			$length = $range['length'];
			if ( 0 !== fseek( $handle, $offset ) ) {
				fclose( $handle );
				$release_download_fence();

				return new \WP_Error(
					'backup_unreadable',
					__( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 500 )
				);
			}

			status_header( 206 );
			header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $filesize );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Flush any existing output buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$streamed = $this->stream_file_snapshot( $handle, $length, $renew_download_lease );
		fclose( $handle );
		if ( 0 === $offset && $filesize === $length && $length === $streamed ) {
			if ( ! Backup::mark_completed_generation_consumed( $generation_id, 'download', $transfer_lock ) ) {
				Logger::log( 'The backup stream completed, but its consumed-generation marker could not be saved.' );
			}
		}
		$release_download_fence();
		exit;
	}

	/**
	 * Parse one HTTP byte range for a completed backup snapshot.
	 *
	 * A null result means the header should be ignored, including unsupported or
	 * malformed ranges and multipart requests this fallback cannot represent.
	 * False means a valid byte range cannot overlap the current snapshot and the
	 * caller must return 416 Range Not Satisfiable.
	 *
	 * @param mixed $range_header Raw HTTP Range header.
	 * @param int   $filesize Snapshot size in bytes.
	 * @return array{start:int,end:int,length:int}|null|false
	 */
	private function parse_download_range( $range_header, $filesize ) {
		if ( null === $range_header || '' === $range_header ) {
			return null;
		}
		if ( ! is_string( $range_header ) ) {
			return null;
		}
		if ( ! is_int( $filesize ) || $filesize < 0 ) {
			return false;
		}

		$range_header = trim( $range_header );
		if ( ! preg_match( '/^bytes=([^,]+)$/iD', $range_header, $matches ) ) {
			return null;
		}

		$range_spec = trim( $matches[1] );
		if ( ! preg_match( '/^([0-9]*)-([0-9]*)$/D', $range_spec, $bounds ) || ( '' === $bounds[1] && '' === $bounds[2] ) ) {
			return null;
		}
		if ( 0 === $filesize ) {
			return false;
		}

		if ( '' === $bounds[1] ) {
			$suffix_length = $this->normalize_range_integer( $bounds[2] );
			if ( 0 === $suffix_length ) {
				return false;
			}

			$start = $suffix_length >= $filesize ? 0 : $filesize - $suffix_length;
			$end   = $filesize - 1;
		} else {
			$start = $this->normalize_range_integer( $bounds[1] );
			if ( $start >= $filesize ) {
				return false;
			}

			$end = '' === $bounds[2] ? $filesize - 1 : $this->normalize_range_integer( $bounds[2] );
			if ( $end < $start ) {
				return null;
			}
			$end = min( $end, $filesize - 1 );
		}

		return array(
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start + 1,
		);
	}

	/**
	 * Convert range digits to a bounded platform integer without overflowing.
	 *
	 * @param string $digits ASCII decimal digits.
	 * @return int
	 */
	private function normalize_range_integer( $digits ) {
		$normalized = ltrim( $digits, '0' );
		if ( '' === $normalized ) {
			return 0;
		}

		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $normalized ) > strlen( $maximum ) || ( strlen( $normalized ) === strlen( $maximum ) && strcmp( $normalized, $maximum ) > 0 ) ) {
			return PHP_INT_MAX;
		}

		return (int) $normalized;
	}

	/**
	 * Prepare a static-server handoff when the archive is large enough.
	 *
	 * Returning false from `sssbm_static_download_threshold` disables the
	 * handoff. Returning zero forces it for every non-empty archive.
	 *
	 * @param string $archive_path Current completed archive path.
	 * @param int    $filesize Archive size in bytes.
	 * @param array  $identity Optional fstat() identity from the opened archive.
	 * @param string $generation_id Expected completed generation identifier.
	 * @return string Public capability URL or an empty string for PHP streaming.
	 */
	private function get_static_download_url( $archive_path, $filesize, $identity = array(), $generation_id = '' ) {
		if ( ! is_int( $filesize ) || $filesize < 1 || ! is_array( $identity ) ) {
			return '';
		}
		if ( ! $this->should_use_static_download( $archive_path, $filesize ) ) {
			return '';
		}

		$ttl = $this->get_bounded_integer_filter(
			'sssbm_static_download_ttl',
			self::STATIC_DOWNLOAD_TTL,
			5 * MINUTE_IN_SECONDS,
			48 * HOUR_IN_SECONDS
		);

		$download_lock = Backup::acquire_operation_lock( 'download', 2 * MINUTE_IN_SECONDS );
		if ( ! $download_lock ) {
			return '';
		}

		try {
			$url = Backup::prepare_static_download( $archive_path, $ttl, $identity );
			$failure = Backup::get_static_download_failure();
			if ( '' !== $url && ! Backup::mark_completed_generation_download_started( $generation_id, $download_lock ) ) {
				// The capability is still safe and retryable. Failure to persist this
				// presentation-only marker must never turn a download into an error.
				Logger::log( 'The static backup handoff succeeded, but its retryable download-started marker could not be saved.' );
			}
			Logger::log(
				'' !== $url
					? 'Large backup download handed off to the web server.'
					: sprintf(
						'Static backup download handoff unavailable (%s); using PHP streaming fallback.',
						'' !== $failure ? $failure : 'unknown'
					)
			);

			return $url;
		} catch ( \Throwable $exception ) {
			Backup::revoke_static_download();
			Logger::log( 'Static backup download handoff unavailable (unexpected_error); using PHP streaming fallback.' );

			return '';
		} finally {
			Backup::release_operation_lock( $download_lock );
		}
	}

	/**
	 * Whether an archive should avoid a long-running PHP transfer.
	 *
	 * @param string $archive_path Archive path.
	 * @param int    $filesize Archive size in bytes.
	 * @return bool
	 */
	private function should_use_static_download( $archive_path, $filesize ) {
		if ( ! is_string( $archive_path ) || '' === $archive_path || ! is_int( $filesize ) || $filesize < 1 ) {
			return false;
		}

		$threshold = apply_filters( 'sssbm_static_download_threshold', self::STATIC_DOWNLOAD_THRESHOLD, $archive_path, $filesize );
		if ( false === $threshold ) {
			return false;
		}
		if ( is_int( $threshold ) && $threshold >= 0 ) {
			$normalized_threshold = $threshold;
		} elseif ( is_string( $threshold ) && preg_match( '/^[0-9]+$/D', trim( $threshold ) ) ) {
			$normalized_threshold = strlen( ltrim( trim( $threshold ), '0' ) ) > strlen( (string) PHP_INT_MAX )
				? PHP_INT_MAX
				: (int) trim( $threshold );
		} else {
			$normalized_threshold = self::STATIC_DOWNLOAD_THRESHOLD;
		}

		return $filesize >= $normalized_threshold;
	}

	/**
	 * Stream the private debug log through an authenticated REST endpoint.
	 *
	 * @return \WP_Error|void
	 */
	public function download_debug_log() {
		$debug_file = Logger::getFilename();

		if (
			! is_file( $debug_file ) ||
			! is_readable( $debug_file ) ||
			is_link( $debug_file ) ||
			! Helper::is_path_within( $debug_file, Backup::get_storage_path() )
		) {
			return new \WP_Error(
				'debug_log_not_found',
				__( 'The debug log could not be found.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 404 )
			);
		}

		$handle = @fopen( $debug_file, 'rb' );
		$stat   = is_resource( $handle ) ? fstat( $handle ) : false;
		if ( ! is_resource( $handle ) || ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			return new \WP_Error( 'debug_log_unreadable', __( 'The debug log could not be read.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		$filesize = (int) $stat['size'];

		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="sssbm-debug.txt"' );
		header( 'Content-Length: ' . $filesize );
		header( 'X-Content-Type-Options: nosniff' );
		nocache_headers();

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Cap the stream at the size observed above. Logger writes can continue
		// concurrently, but bytes appended after fstat() must not exceed the
		// advertised Content-Length or turn one response into an endless tail.
		$this->stream_file_snapshot( $handle, $filesize );
		fclose( $handle );
		exit;
	}

	/**
	 * Stream exactly the file snapshot advertised by Content-Length.
	 *
	 * Backups commonly exceed the ordinary 30-second PHP execution limit. The
	 * stream is deliberately chunked and does not load the archive into memory;
	 * disabling the PHP timer here allows multi-gigabyte Studio and browser
	 * transfers to finish. Web-server/proxy timeouts remain host concerns.
	 *
	 * @param resource      $handle Open readable file handle.
	 * @param int           $length Maximum bytes to emit.
	 * @param callable|null $heartbeat Optional lease check called before each chunk.
	 * @return int Number of bytes emitted from the opened snapshot.
	 */
	private function stream_file_snapshot( $handle, $length, $heartbeat = null ) {
		$this->disable_php_execution_timeout();

		$remaining = max( 0, (int) $length );
		while ( $remaining > 0 && ! feof( $handle ) ) {
			if ( is_callable( $heartbeat ) && ! $heartbeat() ) {
				break;
			}
			$chunk = @fread( $handle, min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact binary ZIP/plain-text download bytes; HTML escaping corrupts the authenticated file response.
			$remaining -= strlen( $chunk );
		}

		return max( 0, (int) $length ) - $remaining;
	}

	/**
	 * Disable PHP's request execution timer for long streaming transfers.
	 *
	 * Web-server and HTTP API timeouts continue to bound remote requests. Some
	 * hosts disable set_time_limit(), so this operation is guarded and best-effort.
	 *
	 * @return void
	 */
	private function disable_php_execution_timeout() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- Authorized streaming transfers must outlive PHP timers; hosts may reject the best-effort change, and HTTP API timeouts remain bounded.
		}
	}

	/**
	 * Download backup file via a shareable token link.
	 *
	 * Validates a temporary token so the link can be shared
	 * without requiring WordPress authentication.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_Error|\WP_REST_Response|void
	 */
	public function download_backup_shared( $request ) {
		$token       = $request->get_param( 'token' );
		$valid_token = get_transient( 'sssbm_download_token' );

		if ( ! is_string( $token ) || '' === $token || ! is_string( $valid_token ) || '' === $valid_token || ! hash_equals( $valid_token, $token ) ) {
			return new \WP_Error(
				'invalid_token',
				__( 'This download link is invalid or has expired.', 'simply-static-studio-backup-migrate' ),
				[ 'status' => 403 ]
			);
		}

		// Reuse the authenticated download handler.
		return $this->download_backup();
	}

	/**
	 * Supabase API URL.
	 */
	private $supabase_url = 'https://api.static.studio';

	/**
	 * Supabase anon key.
	 */
	private $supabase_anon_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InlpbmdxZGltdmtlcW9udGFlc3hrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Mjc3OTA4MzQsImV4cCI6MjA0MzM2NjgzNH0.wgw5P41QmXkdPkek9sUFBWEKTh_8ZVFLTmBwVGohVGM';

	/**
	 * Send OTP to user's email via Supabase auth.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return array|\WP_Error
	 */
	public function send_otp( $request ) {
		$params    = $request->get_params();
		$raw_email = $params['email'] ?? '';
		$raw_captcha_token = $params['captcha_token'] ?? '';
		$email     = is_string( $raw_email ) && strlen( $raw_email ) <= 254 ? sanitize_email( $raw_email ) : '';
		$captcha_token = is_string( $raw_captcha_token ) && strlen( $raw_captcha_token ) <= 2048 ? trim( $raw_captcha_token ) : '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'A valid email address is required.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}
		if ( '' === $captcha_token ) {
			return new \WP_Error( 'invalid_captcha', __( 'Please complete the security check.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post( $this->supabase_url . '/auth/v1/otp', [
			'headers' => [
				'Content-Type' => 'application/json',
				'apikey'       => $this->supabase_anon_key,
			],
			'body'    => wp_json_encode( [
				'email'                => $email,
				'create_user'          => false,
				'gotrue_meta_security' => [
					'captcha_token' => $captcha_token,
				],
			] ),
			'timeout'             => 30,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_AUTH_RESPONSE_BYTES,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'studio_otp_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 ) {
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = $this->sanitize_remote_error( is_array( $body ) ? ( $body['error_description'] ?? $body['msg'] ?? __( 'Failed to send verification code.', 'simply-static-studio-backup-migrate' ) ) : __( 'Failed to send verification code.', 'simply-static-studio-backup-migrate' ) );
			return new \WP_Error( 'studio_otp_rejected', $error_msg, array( 'status' => $this->normalize_http_error_status( $code ) ) );
		}

		return array(
			'status'  => 200,
			'message' => __( 'Verification code sent to your email.', 'simply-static-studio-backup-migrate' ),
		);
	}

	/**
	 * Verify OTP and connect to Simply Static Studio.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return array|\WP_Error
	 */
	public function verify_otp( $request ) {
		$params    = $request->get_params();
		$raw_email = $params['email'] ?? '';
		$raw_token = $params['token'] ?? '';
		$email     = is_string( $raw_email ) && strlen( $raw_email ) <= 254 ? sanitize_email( $raw_email ) : '';
		$token     = is_string( $raw_token ) && strlen( $raw_token ) <= 255 ? sanitize_text_field( $raw_token ) : '';

		if ( empty( $email ) || ! is_email( $email ) || empty( $token ) || strlen( $token ) > 255 ) {
			return new \WP_Error( 'invalid_otp', __( 'Email and a valid verification code are required.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}

		$lock_token    = $this->acquire_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, 2 * MINUTE_IN_SECONDS );
		if ( ! $lock_token ) {
			return new \WP_Error( 'studio_auth_busy', __( 'Studio credentials are being updated by another request. Please try again.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		try {
			$response = wp_remote_post( $this->supabase_url . '/auth/v1/verify', [
				'headers' => [
					'Content-Type' => 'application/json',
					'apikey'       => $this->supabase_anon_key,
				],
				'body'    => wp_json_encode( [
					'email' => $email,
					'token' => $token,
					'type'  => 'email',
				] ),
				'timeout'             => 30,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_AUTH_RESPONSE_BYTES,
			] );

			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'studio_verify_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			$access_token  = is_array( $body ) ? $this->normalize_remote_token( $body['access_token'] ?? null ) : false;
			$refresh_token = is_array( $body ) ? $this->normalize_remote_token( $body['refresh_token'] ?? null ) : false;
			$user_id       = is_array( $body ) && isset( $body['user'] ) && is_array( $body['user'] ) && isset( $body['user']['id'] ) && is_scalar( $body['user']['id'] )
				? substr( sanitize_text_field( (string) $body['user']['id'] ), 0, 255 )
				: '';

			if ( 200 !== $code || ! is_array( $body ) || false === $access_token || false === $refresh_token || '' === $user_id ) {
				$error_msg = $this->sanitize_remote_error( is_array( $body ) ? ( $body['error_description'] ?? $body['msg'] ?? __( 'Invalid verification code.', 'simply-static-studio-backup-migrate' ) ) : __( 'Invalid verification code.', 'simply-static-studio-backup-migrate' ) );
				return new \WP_Error( 'studio_verify_rejected', $error_msg, array( 'status' => 401 ) );
			}

			$expires_in = $this->normalize_remote_expiry( $body['expires_in'] ?? HOUR_IN_SECONDS );
			$expires_at = time() + $expires_in;

			if ( ! $this->publish_studio_credentials( $access_token, $refresh_token, $email, $user_id, $expires_at ) ) {
				$this->clear_studio_credentials();

				return new \WP_Error(
					'studio_credentials_not_saved',
					__( 'The Studio connection could not be saved. Please check the WordPress database and try again.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 500 )
				);
			}

			$hardypress_recognized = $this->recognize_hardypress_customer( $access_token, $user_id );

			return array(
				'status'                 => 200,
				'connected'              => true,
				'email'                  => $email,
				'hardypress_recognized' => $hardypress_recognized,
			);
		} finally {
			$this->release_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Publish one complete Studio credential generation.
	 *
	 * The previous access token may already be expired or revoked. Publishing a
	 * future expiry before replacing it would temporarily make that stale token
	 * usable again. Invalidate expiry first so token readers must contend on the
	 * auth lock, write the replacement fields, and publish the new expiry last.
	 *
	 * @param string $access_token  New access token.
	 * @param string $refresh_token New refresh token.
	 * @param string $email         Connected user email.
	 * @param string $user_id       Connected user ID.
	 * @param int    $expires_at    Absolute access-token expiry.
	 * @return bool Whether the complete generation was persisted exactly.
	 */
	private function publish_studio_credentials( $access_token, $refresh_token, $email, $user_id, $expires_at ) {
		update_option( 'sssbm_studio_token_expires', 0, false );
		if ( 0 !== $this->get_studio_token_expiry() ) {
			return false;
		}
		do_action( 'sssbm_studio_credentials_write_step', 'expiry_invalidated' );

		$credential_fields = array(
			'sssbm_studio_refresh_token' => array( $refresh_token, 'refresh_token' ),
			'sssbm_studio_user_email'    => array( $email, 'user_email' ),
			'sssbm_studio_user_id'       => array( $user_id, 'user_id' ),
			'sssbm_studio_access_token'  => array( $access_token, 'access_token' ),
		);

		foreach ( $credential_fields as $option_name => $field ) {
			update_option( $option_name, $field[0], false );
			if ( get_option( $option_name, '' ) !== $field[0] ) {
				return false;
			}
			do_action( 'sssbm_studio_credentials_write_step', $field[1] );
		}

		// Expiry is the commit marker. Readers cannot use the new access token
		// until every field above has been stored successfully.
		update_option( 'sssbm_studio_token_expires', $expires_at, false );
		if ( get_option( 'sssbm_studio_token_expires', 0 ) !== $expires_at ) {
			return false;
		}
		do_action( 'sssbm_studio_credentials_write_step', 'expiry_published' );

		return get_option( 'sssbm_studio_refresh_token', '' ) === $refresh_token
			&& get_option( 'sssbm_studio_user_email', '' ) === $email
			&& get_option( 'sssbm_studio_user_id', '' ) === $user_id
			&& get_option( 'sssbm_studio_access_token', '' ) === $access_token
			&& get_option( 'sssbm_studio_token_expires', 0 ) === $expires_at;
	}

	/**
	 * Disconnect from Simply Static Studio.
	 *
	 * @return array|\WP_Error
	 */
	public function disconnect_from_studio() {
		$maintenance_token = Backup::acquire_operation_lock( 'maintenance', 2 * MINUTE_IN_SECONDS );
		if ( ! $maintenance_token ) {
			return new \WP_Error( 'studio_operation_in_progress', __( 'Wait for the active backup or Studio transfer to finish before disconnecting.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		try {
			if ( Backup::has_active_studio_push() ) {
				return new \WP_Error( 'studio_push_in_progress', __( 'Wait for the active Studio transfer to finish before disconnecting.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
			}

			$auth_token = $this->acquire_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, 2 * MINUTE_IN_SECONDS );
			if ( ! $auth_token ) {
				return new \WP_Error( 'studio_auth_busy', __( 'Studio credentials are being updated by another request. Please try again.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
			}

			try {
				// Test/integration hook runs while both operation domains are fenced.
				do_action( 'sssbm_before_disconnect_credentials' );
				delete_transient( 'sssbm_download_token' );
				$this->clear_studio_credentials();
			} finally {
				$this->release_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, $auth_token );
			}
		} finally {
			Backup::release_operation_lock( $maintenance_token );
		}

		return array(
			'status'    => 200,
			'connected' => false,
		);
	}

	/**
	 * Get current connection status.
	 *
	 * @return array
	 */
	public function get_connection_status() {
		$token = get_option( 'sssbm_studio_access_token', '' );
		$email = get_option( 'sssbm_studio_user_email', '' );
		$user_id = get_option( 'sssbm_studio_user_id', '' );

		return array(
			'status'                 => 200,
			'connected'              => false !== $this->normalize_remote_token( $token ),
			'email'                  => is_string( $email ) ? sanitize_email( $email ) : '',
			'hardypress_recognized' => is_string( $user_id ) && $this->is_hardypress_user_recognized( $user_id ),
		);
	}

	/**
	 * Whether a URL belongs to a HardyPress-hosted WordPress subdomain.
	 *
	 * @param mixed $url Candidate WordPress URL.
	 * @return bool
	 */
	public static function is_hardypress_source_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts ) ||
			! isset( $parts['scheme'], $parts['host'] ) ||
			! is_string( $parts['scheme'] ) ||
			! is_string( $parts['host'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] )
		) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( rtrim( $parts['host'], '.' ) );

		return in_array( $scheme, array( 'http', 'https' ), true )
			&& (bool) preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+hardypress\.com\z/D', $host );
	}

	/**
	 * Find the canonical WordPress URL that proves this site is on HardyPress.
	 *
	 * @return string
	 */
	private function get_hardypress_source_url() {
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
			if ( self::is_hardypress_source_url( $url ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Attribute an authenticated HardyPress-hosted site to its Studio account.
	 *
	 * Recognition is intentionally best-effort so a temporary Studio failure
	 * never consumes a valid OTP without connecting the plugin. The subsequent
	 * Studio-site request retries until the account confirms the attribution.
	 *
	 * @param string $access_token Valid Studio access token.
	 * @param string $user_id      Connected Studio user ID, if already known.
	 * @return bool
	 */
	private function recognize_hardypress_customer( $access_token, $user_id = '' ) {
		$source_url = $this->get_hardypress_source_url();
		if ( '' === $source_url ) {
			return false;
		}

		if ( ! is_string( $user_id ) || '' === $user_id ) {
			$stored_user_id = get_option( 'sssbm_studio_user_id', '' );
			$user_id        = is_string( $stored_user_id ) ? $stored_user_id : '';
		}

		if ( '' === $user_id || $this->is_hardypress_user_recognized( $user_id ) ) {
			return '' !== $user_id;
		}

		$result = $this->request_studio_function(
			'start-trial',
			$access_token,
			array( 'source_url' => $source_url ),
			'studio_partner_recognition',
			15
		);
		if ( is_wp_error( $result ) ) {
			Logger::log( 'HardyPress customer recognition will be retried: ' . $result->get_error_message() );
			return false;
		}

		$subscription = isset( $result['subscription'] ) && is_array( $result['subscription'] ) ? $result['subscription'] : array();
		$recognized   = true === ( $result['partner_recognized'] ?? false )
			|| 'hardypress' === ( $subscription['partner_id'] ?? null );

		if ( ! $recognized ) {
			return false;
		}

		update_option( self::STUDIO_HARDYPRESS_USER_OPTION, $user_id, false );

		return $this->is_hardypress_user_recognized( $user_id );
	}

	/**
	 * Whether Studio confirmed HardyPress attribution for the connected user.
	 *
	 * @param string $user_id Connected Studio user ID.
	 * @return bool
	 */
	private function is_hardypress_user_recognized( $user_id ) {
		$recognized_user_id = get_option( self::STUDIO_HARDYPRESS_USER_OPTION, '' );

		return '' !== $this->get_hardypress_source_url()
			&& is_string( $recognized_user_id )
			&& '' !== $recognized_user_id
			&& is_string( $user_id )
			&& '' !== $user_id
			&& hash_equals( $recognized_user_id, $user_id );
	}

	/**
	 * Get the Studio sites available to the connected user.
	 *
	 * @return array|\WP_Error
	 */
	public function get_studio_sites() {
		$access_token = $this->refresh_studio_token();

		if ( ! $access_token ) {
			$is_transient = 503 === $this->studio_refresh_failure_status;
			return new \WP_Error(
				$is_transient ? 'studio_temporarily_unavailable' : 'studio_not_connected',
				$is_transient
					? __( 'Simply Static Studio is temporarily unavailable. Please try again.', 'simply-static-studio-backup-migrate' )
					: __( 'Not connected to Simply Static Studio. Please connect first.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => $is_transient ? 503 : 401 )
			);
		}

		$refresh_token = get_option( 'sssbm_studio_refresh_token', '' );
		$refresh_token = is_string( $refresh_token ) ? $refresh_token : '';
		$hardypress_recognized = $this->recognize_hardypress_customer( $access_token );
		$sites = $this->fetch_studio_sites( $access_token );

		if ( is_wp_error( $sites ) ) {
			$error_data = $sites->get_error_data();
			$status     = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 502 ) : 502;

			if ( in_array( $status, array( 401, 403 ), true ) ) {
				// The response applies only to the exact credentials used for this
				// request. A concurrent OTP login must never be cleared or reported
				// to the browser as disconnected because a stale token was rejected.
				$status = $this->clear_matching_studio_credentials( $access_token, $refresh_token ) ? 401 : 503;
			}

			return new \WP_Error(
				$sites->get_error_code(),
				$sites->get_error_message(),
				array( 'status' => $this->normalize_http_error_status( $status ) )
			);
		}

		return array(
			'status'                 => 200,
			'sites'                  => $sites,
			'linked_site_id'         => $this->get_available_linked_studio_site_id( $sites ),
			'hardypress_recognized' => $hardypress_recognized,
		);
	}

	/**
	 * Return the remembered Studio site when it still belongs to this account.
	 *
	 * The account binding prevents a site selected while connected as one Studio
	 * user from becoming the implicit deployment target after another user signs
	 * in on the same WordPress installation.
	 *
	 * @param array $sites Sanitized Studio sites available to the current user.
	 * @return string
	 */
	private function get_available_linked_studio_site_id( $sites ) {
		$link    = get_option( self::STUDIO_SITE_LINK_OPTION, array() );
		$user_id = get_option( 'sssbm_studio_user_id', '' );

		if (
			! is_array( $link ) ||
			! is_string( $user_id ) ||
			'' === $user_id ||
			! isset( $link['user_id'], $link['site_id'] ) ||
			! is_string( $link['user_id'] ) ||
			! is_string( $link['site_id'] ) ||
			'' === $link['site_id'] ||
			strlen( $link['site_id'] ) > 128 ||
			! hash_equals( $user_id, $link['user_id'] )
		) {
			return '';
		}

		foreach ( $sites as $site ) {
			if (
				is_array( $site ) &&
				self::STUDIO_DOCKER_HOSTING_PROVIDER === ( $site['hosting_provider'] ?? null ) &&
				isset( $site['id'] ) &&
				(string) $site['id'] === $link['site_id']
			) {
				return $link['site_id'];
			}
		}

		return '';
	}

	/**
	 * Remember the deployment target for future pushes from this installation.
	 *
	 * @param string $site_id Studio site ID that was created or redeployed.
	 * @return bool Whether the exact account-scoped link was persisted.
	 */
	private function remember_studio_site( $site_id ) {
		$user_id = get_option( 'sssbm_studio_user_id', '' );
		$site_id = is_string( $site_id ) ? trim( $site_id ) : '';

		if ( ! is_string( $user_id ) || '' === $user_id || '' === $site_id || strlen( $site_id ) > 128 ) {
			return false;
		}

		$link = array(
			'user_id' => $user_id,
			'site_id' => $site_id,
		);
		update_option( self::STUDIO_SITE_LINK_OPTION, $link, false );

		return get_option( self::STUDIO_SITE_LINK_OPTION, array() ) === $link;
	}

	/**
	 * Fetch Studio sites for the connected user from Supabase.
	 *
	 * @param string $access_token A valid Studio access token.
	 *
	 * @return array|\WP_Error
	 */
	private function fetch_studio_sites( $access_token ) {
		$user_id = get_option( 'sssbm_studio_user_id', '' );

		if ( ! is_string( $access_token ) || '' === $access_token || ! is_string( $user_id ) || '' === $user_id ) {
			return new \WP_Error(
				'studio_user_missing',
				__( 'The connected Studio user could not be identified. Please disconnect and connect again.', 'simply-static-studio-backup-migrate' )
			);
		}

		$url = add_query_arg( [
			'select'            => 'id,name,url,status,hosting_provider,user_site!inner(user_id)',
			'user_site.user_id' => 'eq.' . $user_id,
			'order'             => 'name.asc',
		], $this->supabase_url . '/rest/v1/site' );

		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'apikey'        => $this->supabase_anon_key,
			],
			'timeout'             => 30,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_API_RESPONSE_BYTES,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'studio_sites_request_failed',
				sprintf(
					/* translators: %s: Studio request error message. */
					__( 'Could not load Studio sites: %s', 'simply-static-studio-backup-migrate' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $response_code || ! is_array( $response_body ) ) {
			$error_message = is_array( $response_body )
				? ( $response_body['message'] ?? $response_body['error'] ?? __( 'Studio returned an invalid response.', 'simply-static-studio-backup-migrate' ) )
				: __( 'Studio returned an invalid response.', 'simply-static-studio-backup-migrate' );

			return new \WP_Error(
				'studio_sites_request_failed',
				sprintf(
					/* translators: %s: Studio request error message. */
					__( 'Could not load Studio sites: %s', 'simply-static-studio-backup-migrate' ),
					$this->sanitize_remote_error( $error_message )
				),
				array( 'status' => $this->normalize_http_error_status( $response_code ) )
			);
		}

		$sites = array();
		foreach ( $response_body as $site ) {
			if ( ! is_array( $site ) || empty( $site['id'] ) || ! is_scalar( $site['id'] ) ) {
				continue;
			}

			$sites[] = [
				'id'               => sanitize_text_field( (string) $site['id'] ),
				'name'             => sanitize_text_field( is_scalar( $site['name'] ?? '' ) ? (string) $site['name'] : '' ),
				'url'              => esc_url_raw( is_scalar( $site['url'] ?? '' ) ? (string) $site['url'] : '' ),
				'status'           => sanitize_text_field( is_scalar( $site['status'] ?? '' ) ? (string) $site['status'] : '' ),
				'hosting_provider' => $this->normalize_studio_hosting_provider( $site['hosting_provider'] ?? null ),
			];
		}

		return $sites;
	}

	/**
	 * Normalize mixed-version Studio rows without upgrading them to Docker.
	 *
	 * Only the exact persisted Docker provider is special. Missing and unknown
	 * values retain the legacy behavior used before the provider column existed.
	 *
	 * @param mixed $provider Stored provider value.
	 * @return string
	 */
	private function normalize_studio_hosting_provider( $provider ) {
		return is_string( $provider ) && self::STUDIO_DOCKER_HOSTING_PROVIDER === $provider
			? self::STUDIO_DOCKER_HOSTING_PROVIDER
			: self::STUDIO_LEGACY_HOSTING_PROVIDER;
	}

	/**
	 * Refresh the Supabase access token if expired.
	 *
	 * @return string|false The access token or false on failure.
	 */
	private function refresh_studio_token() {
		$this->studio_refresh_failure_status = 401;
		$access_token = $this->normalize_remote_token( get_option( 'sssbm_studio_access_token', '' ) );
		$expires      = $this->get_studio_token_expiry();

		if ( false === $access_token ) {
			return false;
		}

		// If token is still valid (with 60s buffer), return it.
		if ( $expires > time() + 60 ) {
			return $access_token;
		}

		$lock_token = $this->acquire_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, 2 * MINUTE_IN_SECONDS );
		if ( ! $lock_token ) {
			// Another request may be completing a refresh. Never overwrite it;
			// return only a token that has already become usable.
			$current_valid_token = $this->get_current_valid_studio_access_token();
			if ( ! $current_valid_token ) {
				$this->studio_refresh_failure_status = 503;
			}

			return $current_valid_token;
		}

		try {
			// Re-read after locking because another request may have refreshed
			// between the initial expiry check and lock acquisition.
			$current_valid_token = $this->get_current_valid_studio_access_token();
			if ( $current_valid_token ) {
				return $current_valid_token;
			}

			$access_token  = $this->normalize_remote_token( get_option( 'sssbm_studio_access_token', '' ) );
			$refresh_token = $this->normalize_remote_token( get_option( 'sssbm_studio_refresh_token', '' ) );
			if ( false === $access_token || false === $refresh_token ) {
				$this->clear_studio_credentials();
				return false;
			}

			$response = wp_remote_post( $this->supabase_url . '/auth/v1/token?grant_type=refresh_token', [
				'headers' => [
					'Content-Type' => 'application/json',
					'apikey'       => $this->supabase_anon_key,
				],
				'body'    => wp_json_encode( [
					'refresh_token' => $refresh_token,
				] ),
				'timeout'             => 30,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_AUTH_RESPONSE_BYTES,
			] );

			if ( is_wp_error( $response ) ) {
				if ( ! $this->studio_credentials_match( $access_token, $refresh_token ) ) {
					return $this->get_current_valid_studio_access_token();
				}

				$this->studio_refresh_failure_status = 503;
				return false;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$new_access  = is_array( $body ) ? $this->normalize_remote_token( $body['access_token'] ?? null ) : false;
			$new_refresh = is_array( $body ) && array_key_exists( 'refresh_token', $body )
				? $this->normalize_remote_token( $body['refresh_token'] )
				: $refresh_token;
			$tokens_are_valid = false !== $new_access && false !== $new_refresh;

			if ( 200 !== $code || ! is_array( $body ) || ! $tokens_are_valid ) {
				$credentials_match = $this->studio_credentials_match( $access_token, $refresh_token );
				if ( $credentials_match && in_array( $code, array( 400, 401, 403 ), true ) ) {
					$this->clear_studio_credentials();
					return false;
				}

				if ( ! $credentials_match ) {
					return $this->get_current_valid_studio_access_token();
				}

				// Transport success does not make an unusable or malformed response
				// definitive. Preserve the refresh token so an upstream outage, rate
				// limit, or broken response can recover on a later request.
				$this->studio_refresh_failure_status = 503;
				return false;
			}

			// Option writes outside this class (or another credential workflow in
			// older plugin code) must not be overwritten by this stale response.
			if ( ! $this->studio_credentials_match( $access_token, $refresh_token ) ) {
				return $this->get_current_valid_studio_access_token();
			}

			$expires_in = $this->normalize_remote_expiry( $body['expires_in'] ?? HOUR_IN_SECONDS );

			// Publish the expiry last. Until it commits, readers continue treating
			// both the old and newly written token pair as expired and retryable.
			$expires_at = time() + $expires_in;
			update_option( 'sssbm_studio_refresh_token', $new_refresh, false );
			update_option( 'sssbm_studio_access_token', $new_access, false );
			update_option( 'sssbm_studio_token_expires', $expires_at, false );
			if (
				get_option( 'sssbm_studio_refresh_token', '' ) !== $new_refresh ||
				get_option( 'sssbm_studio_access_token', '' ) !== $new_access ||
				get_option( 'sssbm_studio_token_expires', 0 ) !== $expires_at
			) {
				$this->clear_studio_credentials();
				$this->studio_refresh_failure_status = 503;

				return false;
			}

			return $new_access;
		} finally {
			$this->release_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Return the current access token only when it is usable beyond the buffer.
	 *
	 * @return string|false
	 */
	private function get_current_valid_studio_access_token() {
		$access_token = $this->normalize_remote_token( get_option( 'sssbm_studio_access_token', '' ) );
		$expires      = $this->get_studio_token_expiry();

		return false !== $access_token && $expires > time() + 60 ? $access_token : false;
	}

	/**
	 * Read a persisted Studio expiry without coercing arrays or objects.
	 *
	 * @return int
	 */
	private function get_studio_token_expiry() {
		$expires = get_option( 'sssbm_studio_token_expires', 0 );

		return is_int( $expires ) || ( is_string( $expires ) && preg_match( '/^[0-9]+$/D', $expires ) ) ? (int) $expires : 0;
	}

	/**
	 * Compare the exact credential pair observed before a remote request.
	 *
	 * @param string $access_token Observed access token.
	 * @param string $refresh_token Observed refresh token.
	 * @return bool
	 */
	private function studio_credentials_match( $access_token, $refresh_token ) {
		$current_access  = get_option( 'sssbm_studio_access_token', '' );
		$current_refresh = get_option( 'sssbm_studio_refresh_token', '' );

		return is_string( $current_access ) && is_string( $current_refresh )
			&& hash_equals( $access_token, $current_access )
			&& hash_equals( $refresh_token, $current_refresh );
	}

	/**
	 * Remove every persisted Studio credential field.
	 *
	 * @return void
	 */
	private function clear_studio_credentials() {
		delete_option( 'sssbm_studio_access_token' );
		delete_option( 'sssbm_studio_refresh_token' );
		delete_option( 'sssbm_studio_user_email' );
		delete_option( 'sssbm_studio_user_id' );
		delete_option( 'sssbm_studio_token_expires' );
		delete_option( self::STUDIO_HARDYPRESS_USER_OPTION );
		do_action( 'sssbm_studio_credentials_cleared' );
	}

	/**
	 * Fixed-origin, bounded transport for explicitly approved source observation.
	 * The source capability and dashboard session are never browser response data.
	 */
	public function request_migration_observation( $body, $source_token = null ) {
		$action = is_array( $body ) ? ( $body['action'] ?? null ) : null;
		$source_action = in_array( $action, array( 'observe', 'revoke' ), true );
		if ( ! in_array( $action, array( 'preview', 'confirm', 'observe', 'revoke', 'bind_transfer' ), true ) || ( $source_action && ( ! is_string( $source_token ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $source_token ) ) ) || ( ! $source_action && null !== $source_token ) ) {
			return new \WP_Error( 'observation_invalid_request', __( 'The Static Studio MCP progress request is invalid.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}
		$token = $source_action ? $source_token : $this->refresh_studio_token();
		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error( 'observation_studio_connection_required', __( 'Connect your Static Studio account before enabling MCP progress sharing.', 'simply-static-studio-backup-migrate' ), array( 'status' => $this->studio_refresh_failure_status ) );
		}
		$encoded = wp_json_encode( $body );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 4096 ) {
			return new \WP_Error( 'observation_invalid_request', __( 'The Static Studio MCP progress request is invalid.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}
		$response = wp_remote_post( $this->supabase_url . '/functions/v1/mcp-migration-source', array(
			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token, 'apikey' => $this->supabase_anon_key ),
			'body' => $encoded,
			'timeout' => 10,
			'redirection' => 0,
			'reject_unsafe_urls' => true,
			'limit_response_size' => 16385,
		) );
		$status = is_wp_error( $response ) ? 502 : wp_remote_retrieve_response_code( $response );
		$raw = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		$data = strlen( $raw ) <= 16384 ? json_decode( $raw, true ) : null;
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'observation_unavailable', __( 'Static Studio MCP could not accept the progress request. No backup action was started.', 'simply-static-studio-backup-migrate' ), array( 'status' => $status >= 400 && $status <= 599 ? $status : 502 ) );
		}
		return $data;
	}

	/**
	 * Clear a credential pair rejected by Studio without deleting a replacement.
	 *
	 * @param string $access_token Access token sent to Studio.
	 * @param string $refresh_token Refresh token paired with that access token.
	 * @return bool Whether the exact rejected pair was cleared.
	 */
	private function clear_matching_studio_credentials( $access_token, $refresh_token ) {
		$lock_token = $this->acquire_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, 2 * MINUTE_IN_SECONDS );
		if ( ! $lock_token ) {
			return false;
		}

		try {
			if ( ! $this->studio_credentials_match( $access_token, $refresh_token ) ) {
				return false;
			}

			$this->clear_studio_credentials();
			return true;
		} finally {
			$this->release_operation_lock( self::STUDIO_AUTH_LOCK_OPTION, $lock_token );
		}
	}

	/**
	 * Bound an expiry duration received from Studio without casting arrays.
	 *
	 * @param mixed $value Remote expires_in value.
	 * @return int
	 */
	private function normalize_remote_expiry( $value ) {
		$expires_in = is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) )
			? (int) $value
			: HOUR_IN_SECONDS;

		return min( WEEK_IN_SECONDS, max( MINUTE_IN_SECONDS, $expires_in ) );
	}

	/**
	 * Validate and normalize a token returned by Studio.
	 *
	 * @param mixed $value Remote token value.
	 * @return string|false
	 */
	private function normalize_remote_token( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( $value );

		// Tokens are opaque credentials, not free text. In particular, never pass
		// embedded whitespace/control bytes to an Authorization header or refresh
		// request, even if corrupt options or a malformed API response contains them.
		return '' !== $value && strlen( $value ) <= 8192 && ! preg_match( '/[\x00-\x20\x7F]/', $value ) ? $value : false;
	}

	/**
	 * Push backup to Simply Static Studio.
	 *
	 * Flow: authorize multipart upload → stream archive → finalize artifact →
	 * create and queue a new site, or atomically replace an existing site.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return array|\WP_Error
	 */
	public function push_to_studio( $request ) {
		$params = $request->get_params();
		foreach ( array( 'site_id', 'site_name', 'php_version' ) as $field ) {
			if ( array_key_exists( $field, $params ) && ! is_string( $params[ $field ] ) ) {
				return new \WP_Error(
					'invalid_studio_parameter',
					sprintf(
						/* translators: %s: invalid request or settings field name. */
						__( 'The value supplied for %s is invalid.', 'simply-static-studio-backup-migrate' ),
						$field
					),
					array( 'status' => 400 )
				);
			}
		}

		if ( Backup::has_active_studio_push() ) {
			return new \WP_Error( 'studio_push_in_progress', __( 'Another Studio push is already in progress.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		$token = Backup::acquire_operation_lock( 'studio_push', self::STUDIO_OPERATION_TTL );

		if ( ! $token ) {
			return new \WP_Error( 'backup_operation_in_progress', __( 'A backup or Studio transfer is already in progress.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		$retain_lease = false;
		try {
			if ( Backup::is_running() || Backup::get_process()->is_active() ) {
				return new \WP_Error( 'backup_active', __( 'Wait for the active backup to finish or cancel it before pushing to Studio.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
			}

			$generation_id = Backup::get_completed_generation_id();
			if ( is_string( $generation_id ) && Backup::is_completed_generation_consumed( $generation_id ) ) {
				return new \WP_Error(
					'backup_consumed',
					__( 'This backup has already been transferred. Create a new backup before pushing to Studio again.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 409 )
				);
			}

			$result = $this->perform_push_to_studio( $request, $token );
			if ( is_array( $result ) && ! empty( $result['_retain_push_lease'] ) ) {
				$retain_lease = true;
				unset( $result['_retain_push_lease'] );
			}
			$result = $this->add_studio_consumption_state( $result, $generation_id, $token, $retain_lease );

			return $result;
		} finally {
			if ( ! $retain_lease ) {
				Backup::release_operation_lock( $token );
			}
		}
	}

	/**
	 * Mark only a synchronously confirmed Studio transfer as consumed.
	 *
	 * A retained lease represents asynchronous work that has merely been accepted.
	 * Its eventual completion needs an explicit confirmation path; treating the
	 * initial 2xx as success would hide a reusable backup when that work later fails.
	 *
	 * @param mixed        $result Studio push result.
	 * @param string|false $generation_id Generation captured before the push.
	 * @param string       $operation_token Active Studio operation lease.
	 * @param bool         $retain_lease Whether asynchronous work still owns the lease.
	 * @return mixed Result with an explicit `backupConsumed` flag when successful.
	 */
	private function add_studio_consumption_state( $result, $generation_id, $operation_token, $retain_lease ) {
		$is_successful_push =
			is_array( $result ) &&
			is_string( $generation_id ) &&
			isset( $result['status'] ) &&
			(int) $result['status'] >= 200 &&
			(int) $result['status'] < 300;
		if ( ! $is_successful_push ) {
			return $result;
		}

		$consumed = ! $retain_lease && Backup::mark_completed_generation_consumed( $generation_id, 'studio', $operation_token );
		$result['backupConsumed'] = $consumed;
		if ( ! $consumed && ! $retain_lease ) {
			// Do not turn an accepted remote deployment into a retryable error:
			// that could duplicate a site or redeploy. The explicit response flag
			// keeps the client from optimistically hiding the still-visible backup.
			Logger::log( 'Studio accepted the backup, but its consumed-generation marker could not be saved.' );
		}

		return $result;
	}

	/** Renew the exact Studio transfer lease before another bounded work unit. */
	private function renew_studio_push_lease( $operation_token ) {
		return Backup::renew_operation_lock( $operation_token, 'studio_push', self::STUDIO_OPERATION_TTL );
	}

	/** Return a stable error when this request loses its Studio transfer fence. */
	private function studio_push_lease_lost_error() {
		return new \WP_Error(
			'studio_push_lease_lost',
			__( 'A backup or Studio transfer is already in progress.', 'simply-static-studio-backup-migrate' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Execute a Studio push while the caller owns the per-site operation lock.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $operation_token Active Studio operation lease.
	 * @return array|\WP_Error
	 */
	private function perform_push_to_studio( $request, $operation_token ) {
		if ( is_multisite() && is_main_site() ) {
			return new \WP_Error( 'multisite_push_unsupported', __( 'Pushing entire multisite networks is not supported.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}

		$access_token = $this->refresh_studio_token();

		if ( ! $access_token ) {
			if ( 503 === $this->studio_refresh_failure_status ) {
				return new \WP_Error( 'studio_temporarily_unavailable', __( 'Simply Static Studio is temporarily unavailable. Please try again.', 'simply-static-studio-backup-migrate' ), array( 'status' => 503 ) );
			}

			return new \WP_Error( 'studio_not_connected', __( 'Not connected to Simply Static Studio. Please connect first.', 'simply-static-studio-backup-migrate' ), array( 'status' => 401 ) );
		}

		$archive_path = Backup::get_existing_backup_path();

		if ( ! $archive_path ) {
			return new \WP_Error( 'backup_not_found', __( 'No backup file found. Please create a backup first.', 'simply-static-studio-backup-migrate' ), array( 'status' => 404 ) );
		}

		if ( ! Backup::is_completed() ) {
			return new \WP_Error( 'backup_incomplete', __( 'Backup is not complete yet. Please wait for the backup to finish before pushing to Studio.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}

		$backup_integrity = Backup::get_backup_integrity_status();
		if ( empty( $backup_integrity['is_pushable'] ) ) {
			$error = ! empty( $backup_integrity['warning'] )
				? $backup_integrity['warning']
				: __( 'This backup cannot be pushed to Simply Static Studio.', 'simply-static-studio-backup-migrate' );

			return new \WP_Error( 'backup_integrity_failed', $error, array( 'status' => 400 ) );
		}

		$params          = $request->get_params();
		$raw_site_id     = $params['site_id'] ?? '';
		$raw_site_name   = $params['site_name'] ?? get_bloginfo( 'name' );
		$raw_php_version = $params['php_version'] ?? self::get_recommended_studio_php_version();
		$site_id         = substr( sanitize_text_field( $raw_site_id ), 0, 128 );
		$site_name       = substr( sanitize_text_field( $raw_site_name ), 0, 200 );
		$php_version     = sanitize_text_field( $raw_php_version );

		if ( '' === $site_name ) {
			$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		}
		if ( ! in_array( $php_version, self::STUDIO_PHP_VERSIONS, true ) ) {
			return new \WP_Error( 'invalid_php_version', __( 'The selected PHP version is invalid.', 'simply-static-studio-backup-migrate' ), array( 'status' => 400 ) );
		}

		$fh = @fopen( $archive_path, 'rb' );
		if ( ! is_resource( $fh ) ) {
			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be opened for upload.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		if ( ! Backup::is_valid_archive_path( $archive_path, true ) ) {
			fclose( $fh );

			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		$file_stat = fstat( $fh );
		if ( ! is_array( $file_stat ) || ! isset( $file_stat['size'] ) || (int) $file_stat['size'] <= 0 ) {
			fclose( $fh );

			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		$file_size = (int) $file_stat['size'];
		fclose( $fh );

		return $this->perform_platform_studio_push( $access_token, $archive_path, $file_size, $site_name, $php_version, $site_id, $operation_token );
	}

	/**
	 * Upload an archive through Studio's persisted Docker artifact contract.
	 *
	 * @param string $access_token Studio access token.
	 * @param string $archive_path Validated backup archive.
	 * @param int    $file_size    Archive size in bytes.
	 * @param string $site_name    Requested Studio site name.
	 * @param string $php_version  Requested PHP runtime.
	 * @param string $site_id      Existing Studio site to replace, or empty.
	 * @param string $operation_token Active Studio operation lease.
	 * @return array|\WP_Error
	 */
	private function perform_platform_studio_push( $access_token, $archive_path, $file_size, $site_name, $php_version, $site_id, $operation_token ) {
		if ( $file_size > self::STUDIO_UPLOAD_MAX_BYTES ) {
			return new \WP_Error( 'studio_backup_too_large', __( 'Studio migration backups cannot exceed 10 GB.', 'simply-static-studio-backup-migrate' ), array( 'status' => 413 ) );
		}
		if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
			return $this->studio_push_lease_lost_error();
		}
		$archive = Studio_Transfer_Checkpoint::fingerprint( $archive_path, $file_size );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}
		$transfer = Studio_Transfer_Checkpoint::prepare( $archive, get_option( 'sssbm_studio_user_id', '' ), array( 'site_id' => $site_id, 'site_name' => $site_name, 'php_version' => $php_version ), $operation_token );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}
		$recovering = 'init' !== $transfer['stage'];
		$site_id = $transfer['target']['site_id'];
		$site_name = $transfer['target']['site_name'];
		$php_version = $transfer['target']['php_version'];
		if ( 'queued' === $transfer['stage'] ) {
			$binding = $this->migration_observation->bind_transfer( $transfer['artifact_id'], $archive['sha256'], $file_size );
			return is_wp_error( $binding ) ? $binding : array( 'status' => 200, 'message' => __( 'Studio already accepted this backup. Its existing destination is being used.', 'simply-static-studio-backup-migrate' ), 'siteUrl' => 'https://' . $transfer['site_identifier'] . '.onstatic.studio', 'siteId' => $transfer['destination_site_id'] );
		}
		// A replacement can be destructive. Without a proven replay receipt, an
		// ambiguous replacement response requires reviewing its existing import.
		if ( '' !== $site_id && in_array( $transfer['stage'], array( 'creating', 'created' ), true ) ) {
			return new \WP_Error( 'studio_replacement_needs_review', __( 'Studio may already have accepted this site replacement. Check its existing migration before retrying; no replacement was started again.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}
		$part_count = (int) ceil( $file_size / self::STUDIO_UPLOAD_PART_SIZE );
		if ( in_array( $transfer['stage'], array( 'init', 'uploading' ), true ) ) {
			$is_resume = is_string( $transfer['artifact_id'] );
			$body = $is_resume
				? array( 'action' => 'resume_platform', 'artifactId' => $transfer['artifact_id'] )
				: array( 'action' => 'init', 'bucket' => 'site_migrations', 'key' => 'public/site-migration-pending.zip', 'contentType' => 'application/zip', 'fileSize' => $file_size, 'partSize' => self::STUDIO_UPLOAD_PART_SIZE, 'purpose' => 'new_site', 'uploadProtocol' => 3, 'platformCanary' => true );
			if ( '' !== $site_id ) {
				$body['siteId'] = $site_id;
			}
			$init = $this->request_studio_function( 'get-upload-credentials', $access_token, $body, 'studio_push_init' );
			if ( is_wp_error( $init ) ) {
				$failure = $init->get_error_data();
				if ( $is_resume && is_array( $failure ) && isset( $failure['remote_code'] ) ) {
					return new \WP_Error( 'studio_transfer_needs_review', __( 'The saved Studio upload is no longer available. Review its existing destination in Studio before resetting the saved transfer or creating another site.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
				}
				return $init;
			}
			$session = $this->validate_studio_upload_session( $init, $part_count, $site_id, $is_resume ? $transfer : null );
			if ( is_wp_error( $session ) ) {
				return $session;
			}
			$next = array_merge( $transfer, array( 'artifact_id' => $session['artifact_id'], 'site_identifier' => $session['site_identifier'], 'stage' => 'uploading' ) );
			if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
				return $this->studio_push_lease_lost_error();
			}
			$transfer = $next;
			$binding = $this->migration_observation->bind_transfer( $transfer['artifact_id'], $archive['sha256'], $file_size );
			if ( is_wp_error( $binding ) ) {
				return $binding;
			}
			if ( is_array( $binding ) && in_array( $binding['status'], array( 'failed', 'aborted', 'expired', 'unavailable' ), true ) ) {
				return new \WP_Error( 'observation_transfer_needs_review', __( 'This saved migration cannot be continued automatically. Review its existing transfer and destination in Studio before resetting it.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
			}
			if ( 'active' === $session['upload_status'] ) {
				$pending_parts = array_values( array_diff( range( 1, $part_count ), $session['uploaded_parts'] ) );
				foreach ( array_chunk( $pending_parts, self::STUDIO_UPLOAD_SIGN_BATCH_SIZE ) as $part_numbers ) {
					if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
						return $this->studio_push_lease_lost_error();
					}
					$signed = $this->request_studio_function( 'get-upload-credentials', $access_token, array( 'action' => 'sign_parts', 'token' => $session['token'], 'partNumbers' => $part_numbers ), 'studio_part_signing' );
					if ( is_wp_error( $signed ) ) {
						return $signed;
					}
					$part_urls = $this->validate_studio_part_urls( $signed['urls'] ?? null, $part_numbers, self::STUDIO_MIGRATION_BUCKET, $session['key'] );
					if ( false === $part_urls ) {
						return new \WP_Error( 'studio_part_signing_invalid', __( 'Studio returned invalid signed upload parts.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
					}
					foreach ( $part_urls as $part_number => $part_url ) {
						if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
							return $this->studio_push_lease_lost_error();
						}
						$uploaded = $this->upload_studio_archive_part( $archive_path, $part_url, ( $part_number - 1 ) * self::STUDIO_UPLOAD_PART_SIZE, min( self::STUDIO_UPLOAD_PART_SIZE, $file_size - ( $part_number - 1 ) * self::STUDIO_UPLOAD_PART_SIZE ), $part_number );
						if ( is_wp_error( $uploaded ) ) {
							return $uploaded;
						}
					}
				}
			}
			$next = array_merge( $transfer, array( 'stage' => 'finalizing' ) );
			if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
				return $this->studio_push_lease_lost_error();
			}
			$transfer = $next;
		}
		if ( 'finalizing' === $transfer['stage'] ) {
			// Re-hash before commitment: never finalize mixed bytes after a local
			// archive replacement, even when the replacement kept the same length.
			$after = Studio_Transfer_Checkpoint::fingerprint( $archive_path, $file_size );
			if ( is_wp_error( $after ) || $after !== $archive ) {
				return Studio_Transfer_Checkpoint::error( 'studio_archive_changed' );
			}
			$completed = $this->complete_studio_artifact( $access_token, $transfer['artifact_id'], $operation_token, $recovering );
			if ( is_wp_error( $completed ) ) {
				return $completed;
			}
			// Finalization computes the canonical remote hash. Recheck association
			// before any destination mutation, rather than discovering substitution
			// only after Studio has already accepted the import.
			$verified_binding = $this->migration_observation->bind_transfer( $transfer['artifact_id'], $archive['sha256'], $file_size );
			if ( is_wp_error( $verified_binding ) ) {
				return $verified_binding;
			}
			if ( is_array( $verified_binding ) && ! in_array( $verified_binding['status'], array( 'ready', 'attached' ), true ) ) {
				return new \WP_Error( 'observation_transfer_not_ready', __( 'Static Studio MCP could not verify the finalized archive. Review the existing transfer before creating a destination.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
			}
			$next = array_merge( $transfer, array( 'stage' => 'creating' ) );
			if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
				return $this->studio_push_lease_lost_error();
			}
			$transfer = $next;
			if ( '' !== $site_id ) {
				$result = $this->replace_platform_studio_site( $access_token, $site_id, $transfer['artifact_id'], $transfer['site_identifier'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$next = array_merge( $transfer, array( 'stage' => 'queued', 'destination_site_id' => $site_id ) );
				if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
					return $this->studio_push_lease_lost_error();
				}
				$binding = $this->migration_observation->bind_transfer( $transfer['artifact_id'], $archive['sha256'], $file_size );
				return is_wp_error( $binding ) ? $binding : $result;
			}
		}
		if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
			return $this->studio_push_lease_lost_error();
		}
		$created = $this->request_studio_function( 'create-site-record', $access_token, array( 'name' => $site_name, 'mount_path' => '', 'platform_canary' => true, 'migration_artifact_id' => $transfer['artifact_id'], 'phpVersion' => $php_version ), 'studio_site_creation' );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$created_site = $created['site'] ?? null;
		$provisioning = $created['provisioning'] ?? null;
		$raw_site_id = is_array( $created_site ) ? ( $created_site['id'] ?? null ) : null;
		$created_id = is_scalar( $raw_site_id ) ? trim( (string) $raw_site_id ) : '';
		$site_url = 'https://' . $transfer['site_identifier'] . '.onstatic.studio';
		if ( ! is_array( $created_site ) || ! is_array( $provisioning ) || ! preg_match( '/^[1-9][0-9]{0,19}$/D', $created_id ) || self::STUDIO_DOCKER_HOSTING_PROVIDER !== ( $created_site['hosting_provider'] ?? null ) || $transfer['site_identifier'] !== ( $provisioning['identifier'] ?? null ) || $site_url !== ( $created_site['url'] ?? null ) || $site_url !== ( $created_site['managed_url'] ?? null ) || $site_url !== ( $provisioning['managed_url'] ?? null ) ) {
			return new \WP_Error( 'studio_site_creation_invalid', __( 'Studio created the migration site but returned an invalid site identity.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}
		$next = array_merge( $transfer, array( 'stage' => 'created', 'destination_site_id' => $created_id ) );
		if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
			return $this->studio_push_lease_lost_error();
		}
		$transfer = $next;
		if ( ! $this->remember_studio_site( $created_id ) ) {
			Logger::log( 'Studio created a site, but its deployment target could not be remembered locally.' );
		}
		if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
			return $this->studio_push_lease_lost_error();
		}
		$already_queued = true === ( $provisioning['queued'] ?? false ) || ( true === ( $created['replayed'] ?? false ) && ( 'pending' !== ( $created_site['status'] ?? null ) || true === ( $created_site['initial_export_done'] ?? false ) ) );
		$queued = $already_queued ? array( 'status' => 'queued' ) : $this->request_studio_function( 'queue-platform-site', $access_token, array( 'site_id' => $created_id, 'apiVersion' => 1, 'phpVersion' => $php_version ), 'studio_site_queue' );
		if ( is_wp_error( $queued ) ) {
			return new \WP_Error( 'studio_site_created_queue_failed', sprintf( /* translators: %s: Studio request error. */ __( 'Studio created the site, but could not queue its deployment: %s', 'simply-static-studio-backup-migrate' ), $queued->get_error_message() ), array( 'status' => $this->error_status( $queued ) ) );
		}
		$result = array( 'status' => 200, 'message' => __( 'Backup pushed to Simply Static Studio successfully. Your site is being deployed.', 'simply-static-studio-backup-migrate' ), 'siteUrl' => $site_url, 'siteId' => $created_id );
		$next = array_merge( $transfer, array( 'stage' => 'queued' ) );
		if ( ! $this->save_studio_transfer( $transfer, $next, $operation_token ) ) {
			return $this->studio_push_lease_lost_error();
		}
		$binding = $this->migration_observation->bind_transfer( $transfer['artifact_id'], $archive['sha256'], $file_size );
		return is_wp_error( $binding ) ? $binding : $result;
	}

	private function save_studio_transfer( $before, $after, $operation_token ) {
		return $this->renew_studio_push_lease( $operation_token ) && Studio_Transfer_Checkpoint::save( $before, $after, $operation_token );
	}

	/** Validate fresh capabilities against the immutable local recovery receipt. */
	private function validate_studio_upload_session( $init, $part_count, $site_id, $transfer = null ) {
		$upload_status = is_array( $transfer ) ? ( $init['uploadStatus'] ?? null ) : 'active';
		$upload_token = $this->normalize_remote_token( $init['token'] ?? null );
		$artifact_id = is_string( $init['artifactId'] ?? null ) ? strtolower( trim( $init['artifactId'] ) ) : '';
		$site_identifier = is_string( $init['siteIdentifier'] ?? null ) ? strtolower( trim( $init['siteIdentifier'] ) ) : '';
		$key = is_string( $init['key'] ?? null ) ? $init['key'] : '';
		$returned_site_id = isset( $init['siteId'] ) && is_scalar( $init['siteId'] ) ? trim( (string) $init['siteId'] ) : '';
		$uploaded_parts = is_array( $transfer ) ? ( $init['uploadedParts'] ?? null ) : array();
		$valid_parts = is_array( $uploaded_parts ) && count( $uploaded_parts ) <= $part_count;
		if ( $valid_parts ) {
			foreach ( $uploaded_parts as $part ) {
				if ( ! is_int( $part ) || $part < 1 || $part > $part_count ) {
					$valid_parts = false;
				}
			}
			$valid_parts = $valid_parts && count( array_unique( $uploaded_parts ) ) === count( $uploaded_parts );
		}
		if ( ! is_array( $init ) || ! in_array( $upload_status, array( 'active', 'completed' ), true ) || ( 'active' === $upload_status && ! $upload_token ) || ! $this->is_studio_artifact_id( $artifact_id ) || ! preg_match( '/^[a-z]{2}[a-z0-9]{13}$/D', $site_identifier ) || $part_count !== ( $init['partCount'] ?? null ) || ( '' !== $site_id ? $returned_site_id !== $site_id : '' !== $returned_site_id ) || self::STUDIO_MIGRATION_BUCKET !== ( $init['bucket'] ?? null ) || $key !== 'private/platform-migrations/' . $site_identifier . '/' . $artifact_id . '/source.zip' || ! $valid_parts || ( is_array( $transfer ) && ( $artifact_id !== $transfer['artifact_id'] || $site_identifier !== $transfer['site_identifier'] ) ) ) {
			return new \WP_Error( 'studio_push_invalid_response', __( 'Studio returned invalid migration upload details.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}
		return array( 'token' => $upload_token, 'artifact_id' => $artifact_id, 'site_identifier' => $site_identifier, 'key' => $key, 'uploaded_parts' => $uploaded_parts, 'upload_status' => $upload_status );
	}

	/**
	 * Send an authenticated JSON request to a Studio Edge Function.
	 *
	 * @param string $function_name Edge Function name.
	 * @param string $access_token  Studio access token.
	 * @param array  $body          JSON request body.
	 * @param string $error_code    Stable local error code.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return array|\WP_Error
	 */
	private function request_studio_function( $function_name, $access_token, $body, $error_code, $timeout = 60 ) {
		$timeout = max( 1, min( 60, (int) $timeout ) );
		$response = wp_remote_post( $this->supabase_url . '/functions/v1/' . $function_name, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
				'apikey'        => $this->supabase_anon_key,
			),
			'body'                => wp_json_encode( $body ),
			'timeout'             => $timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_API_RESPONSE_BYTES,
		) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				$error_code . '_failed',
				sprintf(
					/* translators: %s: Studio HTTP request error message. */
					__( 'Studio request failed: %s', 'simply-static-studio-backup-migrate' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$remote_error = is_array( $data )
				? ( $data['details'] ?? $data['error'] ?? $data['message'] ?? __( 'Studio rejected the request.', 'simply-static-studio-backup-migrate' ) )
				: __( 'Studio rejected the request.', 'simply-static-studio-backup-migrate' );

			$error_data = array( 'status' => $this->normalize_http_error_status( $status ) );
			if ( is_array( $data ) && in_array( $data['code'] ?? null, array( 'platform_migration_upload_expired', 'platform_migration_upload_aborted', 'platform_migration_upload_unavailable' ), true ) ) {
				$error_data['remote_code'] = $data['code'];
			}
			return new \WP_Error( $error_code . '_rejected', $this->sanitize_remote_error( $remote_error ), $error_data );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( $error_code . '_invalid', __( 'Studio returned an invalid response.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}

		return $data;
	}

	/** Best-effort cancellation before multipart completion starts. */
	private function abort_studio_upload( $access_token, $upload_token ) {
		$result = $this->request_studio_function(
			'get-upload-credentials',
			$access_token,
			array( 'action' => 'abort', 'token' => $upload_token ),
			'studio_upload_abort'
		);
		if ( is_wp_error( $result ) ) {
			Logger::log( 'Studio multipart upload cleanup failed: ' . $result->get_error_message() );
		}
	}

	/** Validate signed part URLs and bind them to the canonical object. */
	private function validate_studio_part_urls( $entries, $part_numbers, $bucket, $key ) {
		if ( ! is_array( $entries ) || count( $entries ) !== count( $part_numbers ) ) {
			return false;
		}

		$expected_path = '/storage/v1/s3/' . implode( '/', array_map( 'rawurlencode', explode( '/', $bucket . '/' . $key ) ) );
		$urls          = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! is_int( $entry['partNumber'] ?? null ) ) {
				return false;
			}
			$part_number = $entry['partNumber'];
			$url         = $this->validate_studio_storage_url( $entry['url'] ?? null, $expected_path );
			if ( ! in_array( $part_number, $part_numbers, true ) || isset( $urls[ $part_number ] ) || ! $url ) {
				return false;
			}
			$urls[ $part_number ] = $url;
		}
		ksort( $urls );

		return array_keys( $urls ) === $part_numbers ? $urls : false;
	}

	/** Validate one capability against Studio's exact private storage origin. */
	private function validate_studio_storage_url( $url, $expected_path = null ) {
		$url = $this->validate_remote_https_url( $url );
		if ( ! $url ) {
			return false;
		}
		$parts  = wp_parse_url( $url );
		$origin = 'https://' . strtolower( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		if ( self::STUDIO_STORAGE_ORIGIN !== $origin || ( null !== $expected_path && $expected_path !== ( $parts['path'] ?? '' ) ) ) {
			return false;
		}

		return $url;
	}

	/**
	 * Upload one bounded archive slice through the WordPress HTTP API.
	 *
	 * The request body contains only the current multipart slice (at most 25 MiB).
	 * Keeping the part size stable also preserves resumable transfer numbering.
	 */
	private function upload_studio_archive_part( $archive_path, $url, $offset, $length, $part_number ) {
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $length ) || $length <= 0 || $length > self::STUDIO_UPLOAD_PART_SIZE ) {
			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read for upload.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		if ( ! $this->validate_studio_storage_url( $url ) ) {
			return new \WP_Error( 'studio_upload_failed', __( 'Studio returned an invalid signed upload URL.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}
		// REST requests do not automatically receive the admin memory allowance.
		// Reserve room for the slice, an HTTP transport copy, and request overhead.
		wp_raise_memory_limit( 'admin' );
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_budget = ( 2 * $length ) + ( 8 * 1048576 );
		if ( -1 !== $memory_limit && $memory_limit - memory_get_usage( true ) < $memory_budget ) {
			return new \WP_Error(
				'studio_upload_memory_limit',
				__( 'There is not enough PHP memory available to upload this backup to Studio. Ask your host to increase the PHP memory limit, or download the backup instead.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 500 )
			);
		}
		$fh = @fopen( $archive_path, 'rb' );
		if ( ! is_resource( $fh ) ) {
			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read for upload.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}
		try {
			$body = 0 === fseek( $fh, $offset ) ? stream_get_contents( $fh, $length ) : false;
		} finally {
			fclose( $fh );
		}
		if ( ! is_string( $body ) || strlen( $body ) !== $length ) {
			return new \WP_Error( 'backup_unreadable', __( 'The backup file could not be read for upload.', 'simply-static-studio-backup-migrate' ), array( 'status' => 500 ) );
		}

		$this->disable_php_execution_timeout();
		try {
			$response = wp_remote_request(
				$url,
				array(
					'method'              => 'PUT',
					'timeout'             => 600,
					'redirection'         => 0,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
					'headers'             => array( 'Content-Type' => 'application/zip' ),
					'body'                => $body,
					'data_format'         => 'body',
					'limit_response_size' => self::MAX_UPLOAD_RESPONSE_BYTES + 1,
				)
			);
		} catch ( \Throwable $exception ) {
			$response = new \WP_Error( 'http_request_failed', $exception->getMessage() );
		}
		$status        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		$too_large     = strlen( $response_body ) > self::MAX_UPLOAD_RESPONSE_BYTES;
		if ( is_wp_error( $response ) || $too_large || $status < 200 || $status >= 300 ) {
			$detail = is_wp_error( $response ) ? $response->get_error_message() : $response_body;
			if ( $too_large ) {
				$detail = __( 'Studio returned an upload response that was too large.', 'simply-static-studio-backup-migrate' );
			}
			return new \WP_Error(
				'studio_upload_failed',
				sprintf(
					/* translators: 1: upload part number, 2: HTTP status, 3: error detail. */
					__( 'Failed to upload backup part %1$d (HTTP %2$d): %3$s', 'simply-static-studio-backup-migrate' ),
					$part_number,
					$status,
					$this->sanitize_remote_error( $detail )
				),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/** Complete and wait for Studio's persisted migration artifact. */
	private function complete_studio_artifact( $access_token, $artifact_id, $operation_token, $recovering = false ) {
		if ( $recovering ) {
			$observed = $this->request_studio_function( 'complete-migration-upload', $access_token, array( 'action' => 'artifact_status', 'artifactId' => $artifact_id ), 'studio_artifact_status' );
			if ( is_wp_error( $observed ) ) {
				return $observed;
			}
			if ( $artifact_id !== ( $observed['artifactId'] ?? null ) || ! in_array( $observed['status'] ?? null, array( 'awaiting_completion', 'pending', 'finalizing', 'ready' ), true ) ) {
				return new \WP_Error( 'studio_artifact_status_invalid', __( 'Studio returned an invalid migration artifact state.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
			}
			if ( true === ( $observed['ready'] ?? null ) && 'ready' === $observed['status'] ) {
				return true;
			}
		}
		$attempts   = max( 1, min( 8, (int) apply_filters( 'sssbm_studio_completion_attempts', 8 ) ) );
		$committed  = null;
		$last_error = null;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
				return $this->studio_push_lease_lost_error();
			}
			$state = $this->request_studio_function(
				'complete-migration-upload',
				$access_token,
				array( 'action' => 'complete_platform_direct', 'artifactId' => $artifact_id ),
				'studio_artifact_completion'
			);
			if ( is_wp_error( $state ) ) {
				$last_error = $state;
			} elseif ( $this->is_committed_studio_artifact( $state, $artifact_id ) ) {
				$committed = $state;
				break;
			} else {
				$completion_request = $this->validate_studio_completion_request( $state, $artifact_id );
				if ( false === $completion_request ) {
					return new \WP_Error( 'studio_artifact_completion_invalid', __( 'Studio returned an invalid storage completion request.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
				}
				if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
					return $this->studio_push_lease_lost_error();
				}

				$storage_result = $this->dispatch_studio_completion_request( $completion_request );
				if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
					return $this->studio_push_lease_lost_error();
				}
				$confirmed      = $this->request_studio_function(
					'complete-migration-upload',
					$access_token,
					array( 'action' => 'complete_platform_direct', 'artifactId' => $artifact_id ),
					'studio_artifact_confirmation'
				);
				if ( ! is_wp_error( $confirmed ) && $this->is_committed_studio_artifact( $confirmed, $artifact_id ) ) {
					$committed = $confirmed;
					break;
				}
				$last_error = is_wp_error( $confirmed ) ? $confirmed : $storage_result;
			}

			if ( $attempt < $attempts ) {
				$delay = max( 0, min( 15, (int) apply_filters( 'sssbm_studio_completion_retry_delay', min( 15, 1 << ( $attempt - 1 ) ), $attempt ) ) );
				if ( $delay > 0 ) {
					sleep( $delay );
				}
			}
		}

		if ( ! is_array( $committed ) ) {
			return is_wp_error( $last_error )
				? $last_error
				: new \WP_Error( 'studio_artifact_completion_failed', __( 'Studio could not confirm the completed migration upload.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}
		if ( 'failed' === $committed['artifactStatus'] ) {
			return new \WP_Error( 'studio_artifact_failed', __( 'Studio could not finalize the uploaded migration artifact.', 'simply-static-studio-backup-migrate' ), array( 'status' => 409 ) );
		}
		if ( 'ready' === $committed['artifactStatus'] ) {
			return true;
		}

		$polls    = max( 1, min( 300, (int) apply_filters( 'sssbm_studio_artifact_status_polls', 300 ) ) );
		$interval = max( 0, min( 30, (int) apply_filters( 'sssbm_studio_artifact_status_interval', 2 ) ) );
		for ( $poll = 0; $poll < $polls; $poll++ ) {
			if ( $poll > 0 && $interval > 0 ) {
				sleep( $interval );
			}
			if ( ! $this->renew_studio_push_lease( $operation_token ) ) {
				return $this->studio_push_lease_lost_error();
			}
			$status = $this->request_studio_function(
				'complete-migration-upload',
				$access_token,
				array( 'action' => 'artifact_status', 'artifactId' => $artifact_id ),
				'studio_artifact_status'
			);
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			if ( true === ( $status['ready'] ?? null ) && 'ready' === ( $status['status'] ?? null ) && $artifact_id === ( $status['artifactId'] ?? null ) ) {
				return true;
			}
			if ( $artifact_id !== ( $status['artifactId'] ?? null ) || ! in_array( $status['status'] ?? null, array( 'pending', 'finalizing' ), true ) ) {
				return new \WP_Error( 'studio_artifact_status_invalid', __( 'Studio returned an invalid migration artifact state.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
			}
		}

		return new \WP_Error( 'studio_artifact_status_timeout', __( 'Studio is still finalizing the uploaded backup. Please try again later.', 'simply-static-studio-backup-migrate' ), array( 'status' => 503 ) );
	}

	/** Return whether a value is a canonical migration artifact UUID. */
	private function is_studio_artifact_id( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
	}

	/** Return whether Studio confirmed the exact persisted artifact. */
	private function is_committed_studio_artifact( $state, $artifact_id ) {
		return is_array( $state ) &&
			true === ( $state['committed'] ?? null ) &&
			$artifact_id === ( $state['artifactId'] ?? null ) &&
			in_array( $state['artifactStatus'] ?? null, array( 'pending', 'finalizing', 'ready', 'failed' ), true );
	}

	/** Validate Studio's tightly-bound S3 CompleteMultipartUpload request. */
	private function validate_studio_completion_request( $state, $artifact_id ) {
		$request = is_array( $state ) ? ( $state['completionRequest'] ?? null ) : null;
		$headers = is_array( $request ) ? ( $request['headers'] ?? null ) : null;
		if ( ! is_array( $request ) || ! is_array( $headers ) ) {
			return false;
		}

		$url   = $this->validate_studio_storage_url( $request['url'] ?? null );
		$parts = $url ? wp_parse_url( $url ) : false;
		$query = array();
		parse_str( is_array( $parts ) ? ( $parts['query'] ?? '' ) : '', $query );
		$body          = $request['body'] ?? null;
		$authorization = $headers['Authorization'] ?? null;
		$content_hash  = $headers['X-Amz-Content-Sha256'] ?? null;
		$signed_date   = $headers['X-Amz-Date'] ?? null;
		$expires_at    = $request['expiresAt'] ?? null;
		$signed_at     = $this->studio_aws_timestamp( $signed_date );
		$path_pattern  = '#^/storage/v1/s3/platform_migrations/private/platform-migrations/[a-z]{2}[a-z0-9]{13}/' . preg_quote( $artifact_id, '#' ) . '/source\.zip$#D';

		if (
			false !== ( $state['committed'] ?? null ) ||
			$artifact_id !== ( $state['artifactId'] ?? null ) ||
			'POST' !== ( $request['method'] ?? null ) ||
			'application/xml' !== ( $request['contentType'] ?? null ) ||
			! $url ||
			! is_array( $parts ) ||
			! preg_match( $path_pattern, $parts['path'] ?? '' ) ||
			array_keys( $query ) !== array( 'uploadId' ) ||
			! is_string( $query['uploadId'] ?? null ) ||
			'' === $query['uploadId'] ||
			count( $headers ) !== 3 ||
			! is_string( $authorization ) ||
			! preg_match( '/^AWS4-HMAC-SHA256 Credential=[A-Za-z0-9_-]+\/[0-9]{8}\/us-east-1\/s3\/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$/D', $authorization ) ||
			! is_string( $content_hash ) ||
			! preg_match( '/^[0-9a-f]{64}$/D', $content_hash ) ||
			null === $signed_at ||
			! is_int( $expires_at ) ||
			$expires_at < $signed_at + 900 ||
			$expires_at > $signed_at + 905 ||
			! is_string( $body ) ||
			strlen( $body ) < 100 ||
			strlen( $body ) > 262144 ||
			0 !== strpos( $body, '<?xml version="1.0" encoding="UTF-8"?>' ) ||
			'</CompleteMultipartUpload>' !== substr( $body, -26 ) ||
			preg_match( '/<!DOCTYPE|<!ENTITY/i', $body )
		) {
			return false;
		}

		return array(
			'url'         => $url,
			'contentType' => 'application/xml',
			'headers'     => array(
				'Authorization'        => $authorization,
				'X-Amz-Content-Sha256' => $content_hash,
				'X-Amz-Date'           => $signed_date,
			),
			'body'        => $body,
		);
	}

	/** Convert a canonical AWS timestamp to epoch seconds. */
	private function studio_aws_timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/D', $value, $matches ) ) {
			return null;
		}
		$timestamp = gmmktime( (int) $matches[4], (int) $matches[5], (int) $matches[6], (int) $matches[2], (int) $matches[3], (int) $matches[1] );

		return gmdate( 'Ymd\THis\Z', $timestamp ) === $value ? $timestamp : null;
	}

	/** Dispatch a validated direct storage completion request through WordPress. */
	private function dispatch_studio_completion_request( $request ) {
		$this->disable_php_execution_timeout();
		$response = wp_remote_post( $request['url'], array(
			'headers' => array(
				'Content-Type'         => $request['contentType'],
				'Authorization'        => $request['headers']['Authorization'],
				'X-Amz-Content-Sha256' => $request['headers']['X-Amz-Content-Sha256'],
				'X-Amz-Date'           => $request['headers']['X-Amz-Date'],
			),
			'body'                => $request['body'],
			'timeout'             => 600,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			// Retain one extra byte so a truncated, oversized body is rejected.
			'limit_response_size' => self::MAX_UPLOAD_RESPONSE_BYTES + 1,
		) );

		$status        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		$too_large     = strlen( $response_body ) > self::MAX_UPLOAD_RESPONSE_BYTES;
		if ( is_wp_error( $response ) || $too_large || $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'studio_storage_completion_failed',
				sprintf(
					/* translators: 1: HTTP status, 2: error detail. */
					__( 'Studio storage completion returned HTTP %1$d: %2$s', 'simply-static-studio-backup-migrate' ),
					$status,
					$this->sanitize_remote_error( $too_large ? __( 'Studio returned an upload response that was too large.', 'simply-static-studio-backup-migrate' ) : $response_body )
				),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/** Replace an existing Docker site's content with the completed artifact. */
	private function replace_platform_studio_site( $access_token, $site_id, $artifact_id, $site_identifier ) {
		$replaced = $this->request_studio_function(
			'redeploy-site',
			$access_token,
			array(
				'site_id'               => $site_id,
				'apiVersion'            => 1,
				'migration_artifact_id' => $artifact_id,
			),
			'studio_site_replacement'
		);
		if ( is_wp_error( $replaced ) ) {
			return $replaced;
		}

		$site          = $replaced['site'] ?? null;
		$raw_site_id   = is_array( $site ) ? ( $site['id'] ?? null ) : null;
		$returned_id   = is_scalar( $raw_site_id ) ? trim( (string) $raw_site_id ) : '';
		$expected_url  = 'https://' . $site_identifier . '.onstatic.studio';
		// The public URL is returned only for display/navigation in wp-admin. It may
		// be a freshly configured custom domain whose DNS is not live yet, so do not
		// run the HTTP API's DNS/SSRF validator after Studio has already queued the
		// replacement. The managed URL and all site identity fields remain exact.
		$public_url    = is_array( $site ) ? $this->validate_public_site_url( $site['url'] ?? null ) : false;
		if (
			! is_array( $site ) ||
			$returned_id !== $site_id ||
			self::STUDIO_DOCKER_HOSTING_PROVIDER !== ( $site['hosting_provider'] ?? null ) ||
			$site_identifier !== ( $site['provisioning_id'] ?? null ) ||
			$expected_url !== ( $site['managed_url'] ?? null ) ||
			false === $public_url ||
			'queued' !== ( $replaced['status'] ?? null )
		) {
			return new \WP_Error( 'studio_site_replacement_invalid', __( 'Studio returned an invalid site replacement response.', 'simply-static-studio-backup-migrate' ), array( 'status' => 502 ) );
		}

		if ( ! $this->remember_studio_site( $site_id ) ) {
			Logger::log( 'Studio accepted a site replacement, but its deployment target could not be remembered locally.' );
		}

		return array(
			'status'  => 200,
			'message' => __( 'Backup pushed to Simply Static Studio successfully. Your existing site is being replaced.', 'simply-static-studio-backup-migrate' ),
			'siteUrl' => $public_url,
			'siteId'  => $site_id,
		);
	}


	/** Read a REST status from a WP_Error without assuming its data shape. */
	private function error_status( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;

		return is_array( $data ) ? $this->normalize_http_error_status( $data['status'] ?? 502 ) : 502;
	}

	/**
	 * Atomically acquire a short-lived option-backed operation lock.
	 *
	 * @param string $key Option key.
	 * @param int    $ttl Lock lifetime in seconds.
	 * @return string|false Ownership token or false when already locked.
	 */
	private function acquire_operation_lock( $key, $ttl ) {
		$token   = wp_generate_uuid4();
		$payload = array( 'token' => $token, 'expires' => time() + max( 60, (int) $ttl ) );

		if ( add_option( $key, $payload, '', 'no' ) ) {
			return $token;
		}

		$current = get_option( $key, false );
		if ( is_array( $current ) && isset( $current['expires'] ) && is_numeric( $current['expires'] ) && (int) $current['expires'] > time() ) {
			return false;
		}

		$this->delete_exact_option_value( $key, $current );

		return add_option( $key, $payload, '', 'no' ) ? $token : false;
	}

	/**
	 * Release an operation lock only if this request still owns it.
	 *
	 * @param string $key Option key.
	 * @param string $token Ownership token.
	 * @return void
	 */
	private function release_operation_lock( $key, $token ) {
		$current = get_option( $key, false );
		if ( is_array( $current ) && isset( $current['token'] ) && is_string( $current['token'] ) && hash_equals( $current['token'], $token ) ) {
			$this->delete_exact_option_value( $key, $current );
		}
	}

	/**
	 * Compare-and-delete an option without removing a replacement lock.
	 *
	 * @param string $key Option key.
	 * @param mixed  $value Observed value.
	 * @return bool
	 */
	private function delete_exact_option_value( $key, $value ) {
		global $wpdb;

		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-delete must match the exact observed lock value atomically; the options cache is invalidated after success.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$key,
					maybe_serialize( $value )
				)
			);

			if ( false !== $deleted && $deleted > 0 ) {
				wp_cache_delete( $key, 'options' );
				return true;
			}

			return false;
		}

		return get_option( $key, false ) === $value ? delete_option( $key ) : false;
	}

	/**
	 * Validate an HTTPS URL received from, or sent to, an external integration.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string|false
	 */
	private function validate_remote_https_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 8192 ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts ) ||
			empty( $parts['host'] ) ||
			empty( $parts['scheme'] ) ||
			'https' !== strtolower( $parts['scheme'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['fragment'] )
		) {
			return false;
		}

		$validated = wp_http_validate_url( $url );

		return false === $validated ? false : $url;
	}

	/**
	 * Validate a public site URL without requiring DNS to have propagated.
	 *
	 * Unlike signed storage capabilities, this URL is never requested by the
	 * server. It is returned to the browser as a navigation target, so validate
	 * its HTTPS syntax and public hostname shape without a network lookup.
	 *
	 * @param mixed $url Candidate public site URL.
	 * @return string|false
	 */
	private function validate_public_site_url( $url ) {
		if (
			! is_string( $url )
			|| '' === $url
			|| strlen( $url ) > 2048
			|| preg_match( '/[\x00-\x20\x7F]/', $url )
		) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			return false;
		}

		$raw_host = (string) $parts['host'];
		$host     = strtolower( rtrim( $raw_host, '.' ) );
		if (
			$host !== strtolower( $raw_host )
			|| false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| strlen( $host ) > 253
			|| 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host )
		) {
			return false;
		}

		return $url;
	}

	/**
	 * Bound and strip an error received from a remote service.
	 *
	 * @param mixed $error Raw error.
	 * @return string
	 */
	private function sanitize_remote_error( $error ) {
		$error = is_scalar( $error ) ? (string) $error : '';
		$error = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $error ) ) );

		return '' === $error ? __( 'The remote service returned an unknown error.', 'simply-static-studio-backup-migrate' ) : substr( $error, 0, 500 );
	}

	/**
	 * Keep upstream HTTP codes within the valid REST error range.
	 *
	 * @param mixed $status Upstream status.
	 * @return int
	 */
	private function normalize_http_error_status( $status ) {
		$status = (int) $status;

		return $status >= 400 && $status <= 599 ? $status : 502;
	}

	/**
	 * Delete all backups from the filesystem.
	 *
	 * @return array|\WP_Error
	 */
	public function delete_all_backups() {
		$maintenance_token = Backup::acquire_operation_lock( 'maintenance', 10 * MINUTE_IN_SECONDS );
		if ( ! $maintenance_token ) {
			return new \WP_Error(
				'backup_operation_in_progress',
				__( 'Wait for the active backup or Studio transfer to finish before deleting backup files.', 'simply-static-studio-backup-migrate' ),
				array( 'status' => 409 )
			);
		}

		try {
			// Recheck every mutable state under the shared lease. A backup or push
			// that won the race is left completely untouched.
			if ( Backup::has_active_studio_push() ) {
				return new \WP_Error(
					'studio_push_in_progress',
					__( 'Wait for the active Studio transfer to finish before deleting backup files.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 409 )
				);
			}

			if ( Backup::is_running() || Backup::get_process()->is_active() ) {
				return new \WP_Error(
					'backup_active',
					__( 'Wait for the active backup to finish or cancel it before deleting backup files.', 'simply-static-studio-backup-migrate' ),
					array( 'status' => 409 )
				);
			}

			do_action( 'sssbm_before_delete_all_backups' );

			// Invalidate the shared download token when backups are deleted.
			delete_transient( 'sssbm_download_token' );

			Backup::clear_all_export_files();
			Backup::set_as_not_completed();
			Backup::delete_export_params();
			Backup::clear_backup_integrity_issues();
			Status::clear_messages();
			delete_option( 'sssbm_current_task' );
			delete_option( 'sssbm_task_sequence' );
		} finally {
			Backup::release_operation_lock( $maintenance_token );
		}

		return array(
			'status'  => 200,
			'message' => __( 'All backups deleted successfully.', 'simply-static-studio-backup-migrate' ),
		);
	}
}
