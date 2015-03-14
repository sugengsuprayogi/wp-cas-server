<?php
/**
 * Main Cassava plugin file.
 *
 * @version 1.2.0
 * @since   1.2.0
 */

namespace Cassava;

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Plugin version.
	 */
	const VERSION = '1.2.0';

	/**
	 * Plugin slug.
	 */
	const SLUG = 'wp-cas-server';

	/**
	 * Default endpoint slug.
	 */
	const ENDPOINT_SLUG = 'wp-cas';

	/**
	 * Plugin file.
	 */
	const FILE = 'wp-cas-server/wp-cas-server.php';

	/**
	 * Query variable used to pass the requested CAS route.
	 */
	const QUERY_VAR_ROUTE = 'cas_route';

	/**
	 * Transient prefix for ticket reuse validation.
	 */
	const TRANSIENT_PREFIX = 'cas_';

	/**
	 * CAS server instance.
	 * @var \Cassava\CAS\Server
	 */
	protected $server;

	/**
	 * CAS server plugin admin instance.
	 * @var \Cassava\Admin
	 */
	protected $admin;

	/**
	 * WP CAS Server plugin constructor.
	 *
	 * @param CAS\Server $server CAS server instance.
	 *
	 * @uses is_admin()
	 * @uses register_activation_hook()
	 * @uses register_deactivation_hook()
	 * @uses add_action()
	 */
	public function __construct( CAS\Server $server ) {
		$this->server = $server;

		if ( is_admin() ) {
			$this->admin = new Admin();
		}
	}

	/**
	 * Bootstrap plugin.
	 */
	public function ready() {
		register_activation_hook( __FILE__, array( $this, 'activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	/**
	 * Plugin activation callback.
	 *
	 * @param bool $network_wide Plugin is activated for the entire network.
	 *
	 * @uses flush_rewrite_rules()
	 * @uses is_multisite()
	 * @uses restore_current_blog()
	 * @uses switch_to_blog()
	 * @uses wp_get_sites()
	 */
	public function activation( $network_wide ) {

		if ( function_exists( 'is_multisite' ) && is_multisite() && $network_wide ) {
			$sites = wp_get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site['blog_id'] );
				$this->addRewriteRules();
				flush_rewrite_rules();
			}
			restore_current_blog();
			return;
		}

		$this->addRewriteRules();
		flush_rewrite_rules();
		return;
	}

	/**
	 * Plugin deactivation callback to flush rewrite rules.
	 *
	 * @param bool $network_wide Plugin is activated for the entire network.
	 *
	 * @uses flush_rewrite_rules()
	 * @uses is_multisite()
	 * @uses restore_current_blog()
	 * @uses switch_to_blog()
	 * @uses wp_get_sites()
	 *
	 * @SuppressWarnings(CamelCaseParameterName)
	 * @SuppressWarnings(CamelCaseVariableName)
	 */
	public function deactivation( $network_wide ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() && $network_wide ) {
			$sites = wp_get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site['blog_id'] );
				flush_rewrite_rules();
			}
			restore_current_blog();
			return;
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin loading callback.
	 *
	 * @uses add_action()
	 * @uses add_filter()
	 *
	 * @SuppressWarnings(CamelCaseMethodName)
	 */
	public function plugins_loaded() {
		add_action( 'init'                  , array( $this, 'init' ) );
		add_action( 'template_redirect'     , array( $this, 'template_redirect' ), -100 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );
	}

	/**
	 * Plugin initialization callback.
	 *
	 * @global WP $wp
	 *
	 * @uses apply_filters()
	 * @uses load_plugin_textdomain()
	 * @uses load_textdomain()
	 * @uses trailingslashit()
	 */
	public function init() {
		global $wp;

		$domain = static::SLUG;
		$locale = \apply_filters( 'plugin_locale', \get_locale(), 'wp-cas-server' );

		\load_textdomain( $domain, \trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
		\load_plugin_textdomain( $domain, FALSE, basename( \plugin_dir_path( dirname( __FILE__ ) ) ) . '/languages/' );

		if ( ! \get_option( Options::KEY ) ) {
			Options::setDefaults();
		}

		$wp->add_query_var( static::QUERY_VAR_ROUTE );
		$this->addRewriteRules();
	}

	/**
	 * Serve the CAS request and stop.
	 *
	 * @global WP $wp
	 */
	public function template_redirect() {
		global $wp;

		// Abort unless processing a CAS request:
		if ( empty( $wp->query_vars[ static::QUERY_VAR_ROUTE ] ) ) {
			return;
		}

		echo $this->server->handleRequest( $wp->query_vars[ static::QUERY_VAR_ROUTE ] );

		exit;
	}

	/**
	 * Callback to filter the hosts WordPress allows redirecting to.
	 *
	 * @param  array $allowed List of valid redirection target hosts.
	 *
	 * @return array          Filtered list of valid redirection target hosts.
	 *
	 * @SuppressWarnings(CamelCaseMethodName)
	 */
	public function allowed_redirect_hosts( $allowed = array() ) {

		foreach ( (array) Options::get( 'allowed_services' ) as $uri ) {
			// `allowed_redirect_hosts` returns a list of **hosts**, not URIs:
			$host = parse_url( $uri, PHP_URL_HOST );

			if ( ! empty( $host ) ) {
				$allowed[] = $host;
			}
		}

		return $allowed;
	}

	/**
	 * Register new rewrite rules for the CAS server URIs.
	 *
	 * @uses \add_rewrite_endpoint()
	 * @uses \is_ssl()
	 *
	 * @SuppressWarnings(CamelCaseMethodName)
	 */
	private function addRewriteRules() {

		/**
		 * Enforce SSL
		 */
		if ( ! \is_ssl() ) {
			return;
		}

		$path = Options::get( 'endpoint_slug' );

		if ( empty( $path ) ) {
			$path = static::ENDPOINT_SLUG;
		}

		\add_rewrite_endpoint( $path, EP_ROOT, static::QUERY_VAR_ROUTE );
	}

}
