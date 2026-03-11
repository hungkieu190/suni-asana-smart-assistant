<?php
/**
 * Class xử lý Settings cho Plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATD_Settings {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Đăng ký settings và sections.
	 */
	public function register_settings() {
		register_setting( 'atd_settings_group', 'atd_asana_pat' );
		register_setting( 'atd_settings_group', 'atd_asana_workspace_id' );
		register_setting( 'atd_settings_group', 'atd_sync_interval' );
		register_setting( 'atd_settings_group', 'atd_openai_api_key' );
		register_setting( 'atd_settings_group', 'atd_user_name' );
		register_setting( 'atd_settings_group', 'atd_assistant_name' );
		register_setting( 'atd_settings_group', 'atd_enable_debug_log' );

		// Section Asana
		add_settings_section(
			'atd_asana_section',
			__( 'Cấu hình Asana', 'asana-teams-dashboard' ),
			array( $this, 'render_asana_section_info' ),
			'atd-settings'
		);

		add_settings_field(
			'atd_asana_pat',
			__( 'Personal Access Token (PAT)', 'asana-teams-dashboard' ),
			array( $this, 'render_asana_pat_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_asana_workspace_id',
			__( 'Workspace GID', 'asana-teams-dashboard' ),
			array( $this, 'render_asana_workspace_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_sync_interval',
			__( 'Thời gian tự động đồng bộ (giây)', 'asana-teams-dashboard' ),
			array( $this, 'render_sync_interval_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_openai_api_key',
			__( 'OpenAI API Key', 'asana-teams-dashboard' ),
			array( $this, 'render_openai_api_key_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_user_name',
			__( 'Tên của bạn', 'asana-teams-dashboard' ),
			array( $this, 'render_user_name_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_assistant_name',
			__( 'Tên trợ lý', 'asana-teams-dashboard' ),
			array( $this, 'render_assistant_name_field' ),
			'atd-settings',
			'atd_asana_section'
		);

		add_settings_field(
			'atd_enable_debug_log',
			__( 'Bật ghi Debug Log', 'asana-teams-dashboard' ),
			array( $this, 'render_enable_debug_log_field' ),
			'atd-settings',
			'atd_asana_section'
		);
	}

	public function render_asana_section_info() {
		echo '<p>' . esc_html__( 'Nhập thông tin kết nối API của Asana. Bạn có thể tạo PAT trong phần My Profile Settings > Apps > Developer Apps trên Asana.', 'asana-teams-dashboard' ) . '</p>';
	}

	public function render_asana_pat_field() {
		$value = get_option( 'atd_asana_pat', '' );
		echo '<input type="password" name="atd_asana_pat" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public function render_asana_workspace_field() {
		$value = get_option( 'atd_asana_workspace_id', '' );
		echo '<input type="text" name="atd_asana_workspace_id" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public function render_sync_interval_field() {
		$value = get_option( 'atd_sync_interval', '30' );
		echo '<input type="number" name="atd_sync_interval" value="' . esc_attr( $value ) . '" class="small-text"> ' . esc_html__( 'giây', 'asana-teams-dashboard' );
		echo '<p class="description">' . esc_html__( 'Thời gian mặc định là 30 giây. Giá trị thấp có thể ảnh hưởng đến hiệu năng.', 'asana-teams-dashboard' ) . '</p>';
	}

	public function render_openai_api_key_field() {
		$value = get_option( 'atd_openai_api_key', '' );
		echo '<input type="password" name="atd_openai_api_key" value="' . esc_attr( $value ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Nhập API Key từ OpenAI (GPT-4o) để trợ lý trở nên thông minh hơn.', 'asana-teams-dashboard' ) . '</p>';
	}

	public function render_user_name_field() {
		$value = get_option( 'atd_user_name', 'Hung' );
		echo '<input type="text" name="atd_user_name" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public function render_assistant_name_field() {
		$value = get_option( 'atd_assistant_name', 'Suni Hạ Linh' );
		echo '<input type="text" name="atd_assistant_name" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public function render_enable_debug_log_field() {
		$value = get_option( 'atd_enable_debug_log', '0' );
		echo '<input type="checkbox" name="atd_enable_debug_log" value="1" ' . checked( '1', $value, false ) . '> ' . esc_html__( 'Kích hoạt ghi log để hỗ trợ debug lỗi.', 'asana-teams-dashboard' );
		echo '<p class="description">' . esc_html__( 'Nếu tắt, panel Debug Logs trên Dashboard cũng sẽ bị ẩn.', 'asana-teams-dashboard' ) . '</p>';
	}
}
