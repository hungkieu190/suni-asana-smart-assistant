<?php
/**
 * Plugin Name: Asana Dashboard
 * Plugin URI:  https://example.com/asana-dashboard
 * Description: Quản lý công việc Asana tập trung tại WordPress Dashboard.
 * Version:     1.1.0
 * Author:      Junie
 * Author URI:  https://example.com
 * Text Domain: asana-teams-dashboard
 * Domain Path: /languages
 * License:     GPL2
 */

// Ngăn chặn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper function centralized logging.
 * Kiểm tra option atd_enable_debug_log trước khi ghi log.
 */
function mf_atd_log( $message ) {
	if ( get_option( 'atd_enable_debug_log', '0' ) === '1' ) {
		error_log( $message );
	}
}

// Định nghĩa các hằng số.
define( 'ATD_VERSION', '1.1.0' );
define( 'ATD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ATD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Class chính điều khiển Plugin.
 */
class Asana_Teams_Dashboard {

	/**
	 * Instance duy nhất của class.
	 */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Load dependencies.
		$this->includes();

		// Hooks.
		add_action( 'plugins_loaded', array( $this, 'check_db_version' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice_auth_error' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Kiểm tra xem có cần update database schema không so với version hiện tại
	 */
	public function check_db_version() {
		if ( get_option( 'atd_db_version' ) !== ATD_VERSION ) {
			$this->create_tables();
			update_option( 'atd_db_version', ATD_VERSION );
		}
	}

	/**
	 * Bao gồm các file cần thiết.
	 */
	private function includes() {
		require_once ATD_PLUGIN_DIR . 'includes/class-atd-settings.php';
		require_once ATD_PLUGIN_DIR . 'includes/class-atd-asana-api.php';
		require_once ATD_PLUGIN_DIR . 'includes/class-atd-cron.php';
		require_once ATD_PLUGIN_DIR . 'includes/class-atd-assistant.php';
		
		new ATD_Settings();
		new ATD_Cron();
		new ATD_Assistant();
	}

	/**
	 * Tạo menu trong admin.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Asana Dashboard', 'asana-teams-dashboard' ),
			__( 'Asana Dashboard', 'asana-teams-dashboard' ),
			'manage_options',
			'asana-teams-dashboard',
			array( $this, 'render_dashboard_page' ),
			'dashicons-performance',
			25
		);

		add_submenu_page(
			'asana-teams-dashboard',
			__( 'Dashboard', 'asana-teams-dashboard' ),
			__( 'Dashboard', 'asana-teams-dashboard' ),
			'manage_options',
			'asana-teams-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'asana-teams-dashboard',
			__( 'Settings', 'asana-teams-dashboard' ),
			__( 'Settings', 'asana-teams-dashboard' ),
			'manage_options',
			'atd-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue CSS và JS cho các trang admin của plugin.
	 * MAIN-01: Chỉ load trên đúng screen, không load toàn bộ admin.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed_screens = array(
			'toplevel_page_asana-teams-dashboard',
			'asana-dashboard_page_atd-settings',
		);

		if ( ! in_array( $hook_suffix, $allowed_screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'atd-dashboard',
			ATD_PLUGIN_URL . 'admin/css/atd-dashboard.css',
			array(),
			ATD_VERSION
		);

		wp_enqueue_script(
			'atd-dashboard',
			ATD_PLUGIN_URL . 'admin/js/atd-dashboard.js',
			array( 'jquery' ),
			ATD_VERSION,
			true // Load ở footer.
		);

		// Truyền config từ PHP sang JS.
		wp_localize_script(
			'atd-dashboard',
			'atdConfig',
			array(
				'nonce'         => wp_create_nonce( 'atd_dashboard_nonce' ),
				'syncInterval'  => max( 5, intval( get_option( 'atd_sync_interval', 30 ) ) ),
				'userLabel'     => 'Anh ' . get_option( 'atd_user_name', 'Hung' ),
				'assistantName' => get_option( 'atd_assistant_name', 'Suni Hạ Linh' ),
				'enableDebugLog' => get_option( 'atd_enable_debug_log', '0' ) === '1',
				'i18n'          => array(
					'syncing'          => __( 'Đang đồng bộ...', 'asana-teams-dashboard' ),
					'clearing'         => __( 'Đang xóa...', 'asana-teams-dashboard' ),
					'networkError'     => __( 'Lỗi kết nối mạng.', 'asana-teams-dashboard' ),
					'errorPrefix'      => __( 'Lỗi: ', 'asana-teams-dashboard' ),
					'confirmComplete'  => __( 'Bạn có chắc muốn đánh dấu hoàn thành task này?', 'asana-teams-dashboard' ),
					'confirmClear'     => __( 'Xóa toàn bộ dữ liệu đã sync? Cần đồng bộ lại từ đầu.', 'asana-teams-dashboard' ),
					'confirmClearChat' => __( 'Xóa toàn bộ lịch sử trò chuyện?', 'asana-teams-dashboard' ),
					'confirmClearLogs' => __( 'Xóa file debug.log?', 'asana-teams-dashboard' ),
					'chatCleared'      => __( 'Đã xóa lịch sử rồi anh nhé! Em sẵn sàng nghe lệnh mới. 😊', 'asana-teams-dashboard' ),
					'logsCleared'      => __( 'Đã xóa log!', 'asana-teams-dashboard' ),
					'chatError'        => __( 'Lỗi: ', 'asana-teams-dashboard' ),
					'complete'         => __( 'Complete', 'asana-teams-dashboard' ),
					'send'             => __( 'Gửi', 'asana-teams-dashboard' ),
				),
			)
		);
	}

	/**
	 * Render trang Dashboard chính.
	 */
	public function render_dashboard_page() {
		include ATD_PLUGIN_DIR . 'admin/templates/dashboard.php';
	}

	/**
	 * Hiển thị thông báo nếu có lỗi Authorization khi đồng bộ.
	 */
	public function admin_notice_auth_error() {
		if ( get_option( 'atd_sync_auth_error' ) ) {
			$class   = 'notice notice-error is-dismissible';
			$message = __( 'Asana Dashboard: Lỗi xác thực (Unauthorized). Vui lòng kiểm tra lại Personal Access Token (PAT) trong phần Settings.', 'asana-teams-dashboard' );
			$url     = admin_url( 'admin.php?page=atd-settings' );
			
			printf( '<div class="%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>', 
				esc_attr( $class ), 
				esc_html( $message ), 
				esc_url( $url ), 
				esc_html__( 'Đi đến Settings', 'asana-teams-dashboard' ) 
			);
		}
	}

	/**
	 * Render trang Settings.
	 */
	public function render_settings_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Cài đặt Kết nối', 'asana-teams-dashboard' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'atd_settings_group' );
		do_settings_sections( 'atd-settings' );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Kích hoạt plugin.
	 */
	public function activate() {
		// Tạo bảng dữ liệu.
		$this->create_tables();
		update_option( 'atd_db_version', ATD_VERSION );

		// Thử đồng bộ ngay khi kích hoạt (chạy ngầm)
		if ( class_exists( 'ATD_Cron' ) ) {
			$cron = new ATD_Cron();
			wp_schedule_single_event( time() + 5, 'atd_sync_data_cron' );
		}
		if ( ! wp_next_scheduled( 'atd_weekly_report_cron' ) ) {
			wp_schedule_event( time(), 'weekly', 'atd_weekly_report_cron' );
		}

		// Đăng ký cron job sync dữ liệu (mỗi 15 phút).
		if ( ! wp_next_scheduled( 'atd_sync_data_cron' ) ) {
			wp_schedule_event( time(), 'every_15_minutes', 'atd_sync_data_cron' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Tạo các bảng custom database.
	 */
	private function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Bảng lưu data sync Asana
		$table_name      = $wpdb->prefix . 'atd_sync_data';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			source varchar(20) NOT NULL,
			type varchar(50) NOT NULL,
			remote_id varchar(100) NOT NULL,
			content longtext NOT NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00',
			updated_at datetime DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY remote_id (remote_id),
			KEY source_type (source, type),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Bảng lưu Mentions
		$table_mentions = $wpdb->prefix . 'atd_mentions';
		$sql_mentions = "CREATE TABLE $table_mentions (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			task_gid varchar(50) NOT NULL,
			task_name varchar(255) NOT NULL,
			story_gid varchar(50) NOT NULL,
			mentioned_by varchar(255) NOT NULL,
			comment_text text NOT NULL,
			is_read tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT '0000-00-00 00:00:00',
			synced_at datetime DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY story_gid (story_gid),
			KEY task_gid (task_gid),
			KEY is_read (is_read)
		) $charset_collate;";
		dbDelta( $sql_mentions );
	}

	/**
	 * Hủy kích hoạt plugin.
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'atd_weekly_report_cron' );
		wp_clear_scheduled_hook( 'atd_sync_data_cron' );
		flush_rewrite_rules();
	}
}

// Thêm custom cron schedule nếu chưa có.
add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['every_15_minutes'] = array(
		'interval' => 15 * 60,
		'display'  => __( 'Every 15 Minutes', 'asana-teams-dashboard' ),
	);
	return $schedules;
} );

// Khởi tạo plugin.
Asana_Teams_Dashboard::get_instance();
