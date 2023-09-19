<?php
/**
 * @package koko-analytics
 * @license GPL-3.0+
 * @author Danny van Kooten
 */
namespace KokoAnalytics;

class Admin {

	public function init() {
		global $pagenow;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_actions' ) );
		add_action( 'koko_analytics_install_optimized_endpoint', 'KokoAnalytics\\install_and_test_custom_endpoint' );
		add_action( 'koko_analytics_save_settings', array( $this, 'save_settings' ));
		add_action( 'koko_analytics_reset_statistics', array( $this, 'reset_statistics' ));

		switch ( $pagenow ) {
			case 'index.php':
				// Hooks for main dashboard page
				add_action( 'shutdown', array( $this, 'maybe_run_endpoint_installer' ) );
				add_action( 'init', array( $this, 'maybe_seed' ) );
				break;

			case 'plugins.php':
				// Hooks for plugins overview page
				add_filter( 'plugin_action_links_' . plugin_basename( KOKO_ANALYTICS_PLUGIN_FILE ), array( $this, 'add_plugin_settings_link' ), 10, 2 );
				add_filter( 'plugin_row_meta', array( $this, 'add_plugin_meta_links' ), 10, 2 );
				break;
		}
	}

	public function register_menu() {
		add_submenu_page( 'index.php', esc_html__( 'Koko Analytics', 'koko-analytics' ), esc_html__( 'Analytics', 'koko-analytics' ), 'view_koko_analytics', 'koko-analytics', array( $this, 'show_page' ) );
	}

	public function maybe_run_actions() {
		if (isset($_GET['koko_analytics_action'])) {
			$action = $_GET['koko_analytics_action'];
		} elseif (isset($_POST['koko_analytics_action'])) {
			$action = $_POST['koko_analytics_action'];
		} else {
			return;
		}

		if (! current_user_can( 'manage_koko_analytics')) {
			return;
		}

		do_action( 'koko_analytics_' . $action);
		wp_safe_redirect(remove_query_arg('koko_analytics_action'));
		exit;
	}

	public function enqueue_scripts( $page ) {
		// do not load any scripts if user is missing required capability for viewing
		if ( ! current_user_can( 'view_koko_analytics' ) ) {
			return;
		}

		switch ( $page ) {
			case 'index.php':
				// load scripts for dashboard widget
				wp_enqueue_script( 'koko-analytics-dashboard-widget', plugins_url( '/assets/dist/js/dashboard-widget.js', KOKO_ANALYTICS_PLUGIN_FILE ), array( 'wp-i18n', 'wp-element' ), KOKO_ANALYTICS_VERSION, true );

				if ( function_exists( 'wp_set_script_translations' ) ) {
					wp_set_script_translations( 'koko-analytics-dashboard-widget', 'koko-analytics' );
				}
				wp_localize_script(
					'koko-analytics-dashboard-widget',
					'koko_analytics',
					array(
						'root' => rest_url(),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'colors' => $this->get_colors(),
						'date_format' => get_option( 'date_format' ),
					)
				);
				break;

			case 'dashboard_page_koko-analytics':
				wp_enqueue_style('koko-analytics-admin', plugins_url( 'assets/dist/css/admin.css', KOKO_ANALYTICS_PLUGIN_FILE));

				if (!isset($_GET['tab'])) {
					$settings = get_settings();
					$colors   = $this->get_colors();

					wp_enqueue_script( 'koko-analytics-admin', plugins_url( 'assets/dist/js/admin.js', KOKO_ANALYTICS_PLUGIN_FILE ), array(
						'wp-i18n',
						'wp-element',
					), KOKO_ANALYTICS_VERSION, true );
					if ( function_exists( 'wp_set_script_translations' ) ) {
						wp_set_script_translations( 'koko-analytics-admin', 'koko-analytics' );
					}
					wp_localize_script( 'koko-analytics-admin', 'koko_analytics', array(
						'root'             => rest_url(),
						'nonce'            => wp_create_nonce( 'wp_rest' ),
						'colors'           => $colors,
						'startOfWeek'      => (int) get_option( 'start_of_week' ),
						'defaultDateRange' => $settings['default_view'],
						'dateFormat'       => get_option( 'date_format' ),
					) );
				}
				break;
		}
	}

	private function get_available_roles() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[ $key ] = $role['name'];
		}
		return $roles;
	}

	private function is_cron_event_working() {
		// Always return true on localhost / dev-ish environments
		$site_url = get_site_url();
		if (strpos($site_url, ':') !== false || strpos($site_url, 'localhost') !== false || strpos($site_url, '.local') !== false) {
			return true;
		}

		// detect issues with WP Cron event not running
		// it should run every minute, so if it didn't run in 10 minutes there is most likely something wrong
		$next_scheduled = wp_next_scheduled( 'koko_analytics_aggregate_stats' );
		return $next_scheduled !== false && $next_scheduled > ( time() - HOUR_IN_SECONDS );
	}

	public function show_page() {
		add_action('koko_analytics_show_settings_page', array( $this, 'show_settings_page' ));
		add_action('koko_analytics_show_dashboard_page', array( $this, 'show_dashboard_page' ));

		$tab = $_GET['tab'] ?? 'dashboard';
		do_action("koko_analytics_show_{$tab}_page");

		add_action('admin_footer_text', array($this, 'footer_text'));
	}

	public function show_dashboard_page() {
		// aggregate stats whenever this page is requested
		do_action( 'koko_analytics_aggregate_stats' );

		// determine whether buffer file is writable
		$buffer_filename        = get_buffer_filename();
		$buffer_dirname         = dirname( $buffer_filename );
		$is_buffer_dir_writable = wp_mkdir_p( $buffer_dirname ) && is_writable( $buffer_dirname );

		// determine whether cron event is set up properly and running in-time
		$is_cron_event_working = $this->is_cron_event_working();

		require KOKO_ANALYTICS_PLUGIN_DIR . '/views/dashboard-page.php';
	}

	public function show_settings_page() {
		if (! current_user_can('manage_koko_analytics')) {
			return;
		}

		$settings           = get_settings();
		$endpoint_installer = new Endpoint_Installer();
		$custom_endpoint    = array(
			'enabled' => using_custom_endpoint(),
			'file_contents' => $endpoint_installer->get_file_contents(),
			'filename' => rtrim( ABSPATH, '/' ) . '/koko-analytics-collect.php',
		);
		$database_size      = $this->get_database_size();
		require KOKO_ANALYTICS_PLUGIN_DIR . '/views/settings-page.php';
	}

	public function footer_text() {
		/* translators: %1$s links to the WordPress.org plugin review page, %2$s links to the admin page for creating a new post */
		return sprintf( wp_kses( __( 'If you enjoy using Koko Analytics, please <a href="%1$s">review the plugin on WordPress.org</a> or <a href="%2$s">write about it on your blog</a> to help out.', 'koko-analytics' ), array( 'a' => array( 'href' => array() ) ) ), 'https://wordpress.org/support/view/plugin-reviews/koko-analytics?rate=5#postform', admin_url( 'post-new.php' ) );
	}

	public function maybe_run_endpoint_installer() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'koko-analytics' ) {
			return;
		}

		// do not run if KOKO_ANALYTICS_CUSTOM_ENDPOINT is defined
		if ( defined( 'KOKO_ANALYTICS_CUSTOM_ENDPOINT' ) ) {
			return;
		}

		// do not run if we attempted in the last hour already
		if ( get_transient( 'koko_analytics_install_custom_endpoint_attempt' ) !== false ) {
			return;
		}

		install_and_test_custom_endpoint();

		// set flag to prevent attempting to install again within the next hour
		set_transient( 'koko_analytics_install_custom_endpoint_attempt', 1, HOUR_IN_SECONDS );
	}

	private function get_colors() {
		$color_scheme_name = get_user_option( 'admin_color' );
		global $_wp_admin_css_colors;
		if ( empty( $_wp_admin_css_colors[ $color_scheme_name ] ) ) {
			$color_scheme_name = 'fresh';
		}

		return $_wp_admin_css_colors[ $color_scheme_name ]->colors;
	}

	public function register_dashboard_widget() {
		// only show if user can view stats
		if ( ! current_user_can( 'view_koko_analytics' ) ) {
			return;
		}

		add_meta_box( 'koko-analytics-dashboard-widget', 'Koko Analytics', array( $this, 'dashboard_widget' ), 'dashboard', 'side', 'high' );
	}

	public function dashboard_widget() {
		echo '<div id="koko-analytics-dashboard-widget-mount"></div>';
		echo sprintf( '<p class="help" style="text-align: center;">%s &mdash; <a href="%s">%s</a></p>', esc_html__( 'Showing site visits over last 14 days', 'koko-analytics' ), esc_attr( admin_url( 'index.php?page=koko-analytics' ) ), esc_html__( 'View all statistics', 'koko-analytics' ) );
	}

	/**
	 * Add the settings link to the Plugins overview
	 *
	 * @param array $links
	 * @param       $file
	 *
	 * @return array
	 */
	public function add_plugin_settings_link( $links, $file ) {
		$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'index.php?page=koko-analytics#/settings' ), esc_html__( 'Settings', 'koko-analytics' ) );
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Adds meta links to the plugin in the WP Admin > Plugins screen
	 *
	 * @param array $links
	 * @param string $file
	 *
	 * @return array
	 */
	public function add_plugin_meta_links( $links, $file ) {
		if ( $file !== plugin_basename( KOKO_ANALYTICS_PLUGIN_FILE ) ) {
			return $links;
		}

		$links[] = '<a href="https://www.kokoanalytics.com/">' . esc_html__( 'Visit plugin site', 'koko-analytics' ) . '</a>';
		return $links;
	}

	public function get_database_size() {
		global $wpdb;
		$sql = $wpdb->prepare(
			'
			SELECT ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2)
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
			DB_NAME,
			$wpdb->prefix . 'koko_analytics_%'
		);

		return $wpdb->get_var( $sql );
	}

	public function maybe_seed() {
		global $wpdb;

		if ( ! isset( $_GET['koko_analytics_seed'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$query            = new \WP_Query();
		$posts            = $query->query(
			array(
				'posts_per_page' => 32,
				'post_type' => 'any',
				'post_status' => 'publish',
			)
		);
		$post_count       = count( $posts );
		$referrer_urls    = array();
		$sample_referrers = array(
			'https://www.wordpress.org',
			'https://www.wordpress.org/plugins/koko-analytics',
			'https://www.ibericode.com',
			'https://duckduckgo.com',
			'https://www.mozilla.org',
			'https://www.eff.org',
			'https://letsencrypt.org',
			'https://dannyvankooten.com',
			'https://github.com/ibericode/koko-analytics',
			'https://lobste.rs',
			'https://joinmastodon.org',
			'https://www.php.net',
			'https://mariadb.org',
			'https://referrer-1.com',
			'https://referrer-2.com',
			'https://referrer-3.com',
			'https://referrer-4.com',
			'https://referrer-5.com',
			'https://referrer-6.com',
			'https://referrer-7.com',
			'https://referrer-8.com',
			'https://referrer-9.com',
			'https://referrer-10.com',
			'https://referrer-11.com',
			'https://referrer-12.com',
			'https://referrer-13.com',
			'https://referrer-14.com',
			'https://referrer-15.com',
			'https://referrer-16.com',
			'https://referrer-17.com',
			'https://referrer-18.com',
			'https://referrer-19.com',
			'https://referrer-20.com',
			'https://t.co/IiADWZC13f',
			'https://www.reddit.com/r/Wordpress/comments/e6ycsm/privacy_friendly_analytics_plugin_that_does_not/',
			'android-app://com.stefandekanski.hackernews.free',
		);
		foreach ( $sample_referrers as $url ) {
			$wpdb->insert(
				$wpdb->prefix . 'koko_analytics_referrer_urls',
				array(
					'url' => $url,
				)
			);
			$referrer_urls[ $wpdb->insert_id ] = $url;
		}
		$referrer_count = count( $referrer_urls );

		$n = 3 * 365;
		for ( $i = 0; $i < $n; $i++ ) {
			$progress  = ( $n - $i ) / $n;
			$date      = gmdate( 'Y-m-d', strtotime( sprintf( '-%d days', $i ) ) );
			$pageviews = max( 1, rand( 500, 1000 ) * $progress ^ 2 );
			$visitors  = max( 1, $pageviews * rand( 3, 6 ) / 10 );

			// simulate a huge peak in traffic every 180 days
			if ( rand( 1, 180 ) === 1 ) {
				$pageviews = $pageviews * 10;
				$visitors  = $visitors * 10;
			}

			$wpdb->insert(
				$wpdb->prefix . 'koko_analytics_site_stats',
				array(
					'date' => $date,
					'pageviews' => $pageviews,
					'visitors' => $visitors,
				)
			);

			$values = array();
			foreach ( $posts as $post ) {
				array_push( $values, $date, $post->ID, round( $pageviews / $post_count * rand( 5, 15 ) / 10 ), round( $visitors / $post_count * rand( 5, 15 ) / 10 ) );
			}
			$placeholders = rtrim( str_repeat( '(%s,%d,%d,%d),', count( $posts ) ), ',' );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}koko_analytics_post_stats(date, id, pageviews, visitors) VALUES {$placeholders}", $values ) );

			$values = array();
			foreach ( $referrer_urls as $id => $referrer_url ) {
				array_push( $values, $date, $id, round( $pageviews / $referrer_count * rand( 5, 15 ) / 10 ), round( $visitors / $referrer_count * rand( 5, 15 ) / 10 ) );
			}
			$placeholders = rtrim( str_repeat( '(%s,%d,%d,%d),', count( $referrer_urls ) ), ',' );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}koko_analytics_referrer_stats(date, id, pageviews, visitors) VALUES {$placeholders}", $values ) );
		}
	}

	public function reset_statistics() {
		check_admin_referer('koko_analytics_reset_statistics');
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}koko_analytics_site_stats;" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}koko_analytics_post_stats;" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}koko_analytics_referrer_stats;" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}koko_analytics_referrer_urls;" );
		delete_option( 'koko_analytics_realtime_pageview_count' );
	}

	public function save_settings() {
		check_admin_referer('koko_analytics_save_settings');
		$settings                                = get_settings();
		$new_settings                            = $_POST['koko_analytics_settings'];
		$new_settings['prune_data_after_months'] = abs( (int) $new_settings['prune_data_after_months'] );
		$new_settings['use_cookie']              = (int) $new_settings['use_cookie'];
		$new_settings                            = array_merge( $settings, $new_settings );
		update_option( 'koko_analytics_settings', $new_settings, true );
		wp_safe_redirect(add_query_arg(array( 'settings-updated' => true ), wp_get_referer()));
		exit;
	}
}
