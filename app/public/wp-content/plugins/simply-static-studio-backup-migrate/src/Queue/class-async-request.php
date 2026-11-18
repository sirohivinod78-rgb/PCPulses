<?php

namespace Simply_Static\Backup\Queue;

use Simply_Static\Backup\Runtime_Limits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract AsyncRequest class.
 *
 * From: https://github.com/deliciousbrains/wp-background-processing
 *
 * @abstract
 */
abstract class Async_Request {

	/**
	 * Prefix
	 *
	 * (default value: 'sssbm')
	 *
	 * @var string
	 * @access protected
	 */
	protected $prefix = 'sssbm';

	/**
	 * Action
	 *
	 * (default value: 'async_request')
	 *
	 * @var string
	 * @access protected
	 */
	protected $action = 'async_request';

	/**
	 * Identifier
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $identifier;

	/**
	 * Data
	 *
	 * (default value: array())
	 *
	 * @var array
	 * @access protected
	 */
	protected $data = array();

	/**
	 * Initiate new async request.
	 */
	public function __construct() {
		$this->identifier = $this->prefix . '_' . $this->action;

		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set data used during the request.
	 *
	 * @param array $data Data.
	 *
	 * @return $this
	 */
	public function data( $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request.
	 *
	 * @return array|\WP_Error|false HTTP Response array, WP_Error on failure, or false if not attempted.
	 */
	public function dispatch() {
		$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		$args = $this->get_post_args();

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Get query args.
	 *
	 * @return array
	 */
	protected function get_query_args() {
		$mandatory = array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);
		if ( property_exists( $this, 'query_args' ) ) {
			$args = $this->query_args;
		} else {
			$args = $mandatory;

			/**
			 * Filters the query arguments used during an async request.
			 *
			 * @param array $args
			 */
			$args = apply_filters( $this->identifier . '_query_args', $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
		}

		// Extensions may add routing hints, but cannot remove the authenticated
		// local AJAX action or turn malformed filter output into a fatal error.
		return array_merge( is_array( $args ) ? $args : array(), $mandatory );
	}

	/**
	 * Get query URL.
	 *
	 * @return string
	 */
	protected function get_query_url() {
		$default = admin_url( 'admin-ajax.php' );
		if ( property_exists( $this, 'query_url' ) ) {
			$url = $this->query_url;
		} else {
			/**
			 * Filters the query URL used during an async request.
			 *
			 * @param string $url
			 */
			$url = apply_filters( $this->identifier . '_query_url', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
		}

		return $this->is_same_origin_http_url( $url, $default ) ? $url : $default;
	}

	/**
	 * Get post args.
	 *
	 * @return array
	 */
	protected function get_post_args() {
		$defaults = array(
			'timeout'   => 5,
			'blocking'  => false,
			'redirection' => 0,
			'body'      => $this->data,
			'cookies'   => isset( $_COOKIE ) && is_array( $_COOKIE ) ? $_COOKIE : array(), // Passing cookies ensures request is performed as initiating user.
			'sslverify' => apply_filters( 'https_local_ssl_verify', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is the established WordPress loopback SSL hook.
		);
		$args = property_exists( $this, 'post_args' ) ? $this->post_args : $defaults;

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $args
		 */
		$args = apply_filters( $this->identifier . '_post_args', $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
		$args = is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;

		// This request carries WordPress login cookies. Keep it non-blocking and
		// non-redirecting so filtered arguments cannot leak them on a redirect or
		// hold an admin request open indefinitely.
		$args['blocking']    = false;
		$args['redirection'] = 0;
		$args['timeout']     = Runtime_Limits::bounded_int( $args['timeout'], 5, 1, 30 );
		$args['cookies']     = is_array( $args['cookies'] ) ? $args['cookies'] : $defaults['cookies'];
		$args['headers']     = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['sslverify']   = is_bool( $args['sslverify'] ) ? $args['sslverify'] : true;

		return $args;
	}

	/**
	 * Allow loopback URL customization only within the WordPress admin origin.
	 *
	 * Sending this request to another origin would disclose logged-in cookies
	 * and a valid AJAX nonce.
	 *
	 * @param mixed  $candidate Filtered URL.
	 * @param string $default Trusted admin URL.
	 * @return bool
	 */
	private function is_same_origin_http_url( $candidate, $default ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			return false;
		}

		$candidate_parts = wp_parse_url( $candidate );
		$default_parts   = wp_parse_url( $default );
		if ( ! is_array( $candidate_parts ) || ! is_array( $default_parts ) ) {
			return false;
		}

		foreach ( array( 'scheme', 'host' ) as $part ) {
			if ( empty( $candidate_parts[ $part ] ) || empty( $default_parts[ $part ] ) || strtolower( $candidate_parts[ $part ] ) !== strtolower( $default_parts[ $part ] ) ) {
				return false;
			}
		}

		if ( ! in_array( strtolower( $candidate_parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $candidate_parts['user'] ) || isset( $candidate_parts['pass'] ) ) {
			return false;
		}

		$default_port   = isset( $default_parts['port'] ) ? (int) $default_parts['port'] : ( 'https' === strtolower( $default_parts['scheme'] ) ? 443 : 80 );
		$candidate_port = isset( $candidate_parts['port'] ) ? (int) $candidate_parts['port'] : ( 'https' === strtolower( $candidate_parts['scheme'] ) ? 443 : 80 );

		return $candidate_port === $default_port;
	}

	/**
	 * Maybe handle a dispatched request.
	 *
	 * Check for correct nonce and pass to handler.
	 *
	 * @return void|mixed
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		check_ajax_referer( $this->identifier, 'nonce' );

		$this->handle();

		return $this->maybe_wp_die();
	}

	/**
	 * Should the process exit with wp_die?
	 *
	 * @param mixed $return What to return if filter says don't die, default is null.
	 *
	 * @return void|mixed
	 */
	protected function maybe_wp_die( $return = null ) {
		/**
		 * Should wp_die be used?
		 *
		 * @return bool
		 */
		if ( apply_filters( $this->identifier . '_wp_die', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Production identifiers are built from the simply_static_ backup-process prefix.
			// Clean any output buffers before wp_die to prevent buffer notices
			while ( ob_get_level() ) {
				ob_end_clean();
			}
			wp_die();
		}

		return $return;
	}

	/**
	 * Handle a dispatched request.
	 *
	 * Override this method to perform any actions required
	 * during the async request.
	 */
	abstract protected function handle();
}
