<?php
/**
 * Class xử lý Cron job, Sync dữ liệu, và AJAX handlers.
 */

if (!defined('ABSPATH')) {
	exit;
}

class ATD_Cron
{

	public function __construct()
	{
		add_action('atd_weekly_report_cron', array($this, 'generate_weekly_report'));
		add_action('atd_sync_data_cron', array($this, 'sync_all_data'));
		add_action('wp_ajax_atd_manual_sync', array($this, 'ajax_manual_sync'));
		add_action('wp_ajax_atd_clear_data', array($this, 'ajax_clear_data'));
		add_action('wp_ajax_atd_complete_task', array($this, 'ajax_complete_task'));
		add_action('wp_ajax_atd_create_task', array($this, 'ajax_create_task'));
		add_action('wp_ajax_atd_ai_set_due_date', array($this, 'ajax_ai_set_due_date'));
		add_action('wp_ajax_atd_fetch_dashboard_table', array($this, 'ajax_fetch_dashboard_table'));
		add_action('wp_ajax_atd_fetch_task_detail', array($this, 'ajax_fetch_task_detail'));
		add_action('wp_ajax_atd_get_debug_logs', array($this, 'ajax_get_debug_logs'));
		add_action('wp_ajax_atd_clear_debug_logs', array($this, 'ajax_clear_debug_logs'));
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * AJAX: Lấy HTML card dashboard (grouped layout).
	 * Action: atd_fetch_dashboard_table
	 */
	public function ajax_fetch_dashboard_table()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		$type_filter = (!empty($_POST['type_filter']) && 'all' !== $_POST['type_filter']) ? sanitize_text_field($_POST['type_filter']) : null;

		// Lấy tất cả task (không phân trang khi render card grouped).
		$cached_response = self::get_cached_data('asana', null, 500, 0, $type_filter);
		$all_tasks = $cached_response['data'];
		$summary = self::build_summary($all_tasks);
		$grouped = self::group_tasks_by_deadline($all_tasks);

		ob_start();
		include ATD_PLUGIN_DIR . 'admin/templates/partials/task-board.php';
		$html = ob_get_clean();

		wp_send_json_success(array(
			'html' => $html,
			'summary' => $summary,
		));
	}


	/**
	 * AJAX: Sync thủ công từ dashboard.
	 * Action: atd_manual_sync
	 */
	public function ajax_manual_sync()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		// Giới hạn sync tối thiểu 15 giây/lần.
		$last_sync = get_transient('atd_last_sync_time');
		if ($last_sync && (time() - $last_sync) < 15) {
			wp_send_json_success(__('Dữ liệu đã được cập nhật gần đây.', 'asana-teams-dashboard'));
		}

		$errors = array();

		// Chỉ sync Assigned tasks cho bản Free.
		try {
			$this->sync_asana_assigned();
		}
		catch (Exception $e) {
			$errors[] = 'Assigned: ' . $e->getMessage();
		}

		set_transient('atd_last_sync_time', time(), 30);

		if (!empty($errors)) {
			wp_send_json_error(implode(' | ', $errors));
		}

		wp_send_json_success(__('Đồng bộ dữ liệu thành công!', 'asana-teams-dashboard'));
	}

	/**
	 * AJAX: Xóa toàn bộ dữ liệu đã sync.
	 * Action: atd_clear_data
	 */
	public function ajax_clear_data()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$result = $wpdb->query("TRUNCATE TABLE $table_name");

		if (false === $result) {
			wp_send_json_error('Database error: ' . $wpdb->last_error);
		}

		wp_send_json_success(__('Đã xóa toàn bộ dữ liệu đồng bộ!', 'asana-teams-dashboard'));
	}

	/**
	 * AJAX: Đánh dấu hoàn thành task.
	 * Action: atd_complete_task
	 */
	public function ajax_complete_task()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		$task_gid = isset($_POST['task_gid']) ? sanitize_text_field($_POST['task_gid']) : '';
		if (!$task_gid) {
			wp_send_json_error('Missing task GID');
		}

		$asana_api = new ATD_Asana_API();
		$response = $asana_api->update_task($task_gid, array('completed' => true));

		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
		}

		// Xóa khỏi DB local sau khi hoàn thành thành công.
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$wpdb->delete($table_name, array('remote_id' => $task_gid));

		wp_send_json_success(__('Task đã được đánh dấu hoàn thành!', 'asana-teams-dashboard'));
	}

	/**
	 * AJAX: AI Đặt lại ngày hết hạn cho task.
	 * Action: atd_ai_set_due_date
	 */
	public function ajax_ai_set_due_date()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options'))
			wp_send_json_error('No permission');

		$task_gid = isset($_POST['task_gid']) ? sanitize_text_field($_POST['task_gid']) : '';
		$due_on = isset($_POST['due_on']) ? sanitize_text_field($_POST['due_on']) : '';

		if (!$task_gid || !$due_on) {
			wp_send_json_error('Thiếu thông tin task_gid hoặc due_on');
		}

		$asana_api = new ATD_Asana_API();
		$response = $asana_api->update_task($task_gid, array('due_on' => $due_on));

		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
		}

		// Xóa transient để ép dashboard render lại
		set_transient('atd_last_sync_time', 0);

		wp_send_json_success('Đã cập nhật ngày hết hạn mới thành công!');
	}

	/**
	 * AJAX: Tạo task mới trên Asana.
	 * Action: atd_create_task
	 */
	public function ajax_create_task()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options'))
			wp_send_json_error('No permission');

		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
		$due = isset($_POST['due_on']) ? sanitize_text_field(wp_unslash($_POST['due_on'])) : '';
		$notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';

		if (empty($name))
			wp_send_json_error('Tên công việc không được để trống.');

		$workspace = get_option('atd_asana_workspace_id');
		if (!$workspace)
			wp_send_json_error('Chưa cấu hình Workspace ID.');

		$data = array(
			'name' => $name,
			'workspace' => $workspace,
			'assignee' => 'me', // Default trỏ vào mình (me)
			'notes' => $notes
		);

		if (!empty($due)) {
			$data['due_on'] = $due;
		}

		$asana_api = new ATD_Asana_API();
		$response = $asana_api->create_task($data);

		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
		}

		// Xoá transient của created tab để trigger update list
		set_transient('atd_last_sync_time', 0); // Cưỡng ép refresh lại lần sau

		wp_send_json_success('Tạo task mới thành công.');
	}

	/**
	 * AJAX: Lấy chi tiết nội dung task và comments để AI phân tích.
	 * Action: atd_fetch_task_detail
	 */
	public function ajax_fetch_task_detail()
	{
		mf_atd_log('ATD Debug: ajax_fetch_task_detail CALLED');
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		$task_gid = isset($_POST['task_gid']) ? sanitize_text_field($_POST['task_gid']) : '';
		if (!$task_gid) {
			wp_send_json_error('Missing task GID');
		}

		mf_atd_log('ATD Debug: Fetching task detail for GID: ' . $task_gid);

		$asana_api = new ATD_Asana_API();

		// Lấy task details (notes)
		$task_response = $asana_api->get_task_detail($task_gid);
		if (is_wp_error($task_response)) {
			mf_atd_log('ATD Debug: get_task_detail ERROR: ' . $task_response->get_error_message());
			wp_send_json_error($task_response->get_error_message());
		}

		mf_atd_log('ATD Debug: get_task_detail OK, fetching stories...');

		// Lấy comments
		$stories_response = $asana_api->get_task_stories($task_gid);
		$stories = is_wp_error($stories_response) ? array() : $stories_response;

		mf_atd_log('ATD Debug: Stories count: ' . count($stories));

		// Lấy task con (Subtasks)
		$subtasks_response = $asana_api->get_subtasks($task_gid);
		$subtasks = is_wp_error($subtasks_response) ? array() : (isset($subtasks_response['data']) ? $subtasks_response['data'] : array());

		mf_atd_log('ATD Debug: Subtasks count: ' . count($subtasks));

		wp_send_json_success(
			array(
			'task' => isset($task_response['data']) ? $task_response['data'] : $task_response,
			'stories' => $stories,
			'subtasks' => $subtasks,
		)
		);
	}

	/**
	 * AJAX: Lấy nội dung debug log.
	 * Action: atd_get_debug_logs
	 */
	public function ajax_get_debug_logs()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		$log_file = WP_CONTENT_DIR . '/debug.log';
		if (!file_exists($log_file)) {
			wp_send_json_success('Chưa có file debug.log.');
		}

		// Chỉ đọc 100 dòng cuối để tránh quá tải.
		$lines = array();
		$fp = new SplFileObject($log_file, 'r');
		$fp->seek(PHP_INT_MAX);
		$total_lines = $fp->key();
		$start_line = max(0, $total_lines - 100);

		$fp->seek($start_line);
		while (!$fp->eof()) {
			$lines[] = rtrim($fp->current());
			$fp->next();
		}

		wp_send_json_success(implode("\n", $lines));
	}

	/**
	 * AJAX: Xóa file debug.log.
	 * Action: atd_clear_debug_logs
	 */
	public function ajax_clear_debug_logs()
	{
		check_ajax_referer('atd_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('No permission');
		}

		$log_file = WP_CONTENT_DIR . '/debug.log';
		if (file_exists($log_file)) {
			file_put_contents($log_file, '');
		}

		wp_send_json_success(__('Đã xóa file debug.log!', 'asana-teams-dashboard'));
	}

	// =========================================================================
	// SYNC METHODS
	// =========================================================================

	/**
	 * CRON-03 & CRON-08: Sync toàn bộ nguồn dữ liệu.
	 */
	public function sync_all_data()
	{
		mf_atd_log('ATD Debug: sync_all_data START');
		$this->sync_asana_assigned();
		$this->sync_mentions();
		mf_atd_log('ATD Debug: sync_all_data FINISHED');
	}

	/**
	 * Sync Asana Assigned Tasks (đã có từ trước).
	 */
	private function sync_asana_assigned()
	{
		$asana_api = new ATD_Asana_API();
		$limit = 50;
		$offset = '';
		$current_sync_gids = array();

		for ($i = 0; $i < 3; $i++) {
			$response = $asana_api->get_assigned_tasks($limit, $offset);

			if (is_wp_error($response)) {
				mf_atd_log('ATD Error: Sync Asana Assigned failed - ' . $response->get_error_message());
				if ($response->get_error_code() === 'asana_unauthorized') {
					update_option('atd_sync_auth_error', 1);
				}
				return; // Dừng sync này nhưng không làm chết script
			}

			// Nếu thành công, xóa lỗi auth cũ (nếu có)
			delete_option('atd_sync_auth_error');

			if (!empty($response['data'])) {
				foreach ($response['data'] as $task) {
					if (isset($task['completed']) && $task['completed']) {
						continue;
					}
					$this->save_to_db('asana', 'assigned', $task['gid'], $task);
					$current_sync_gids[] = $task['gid'];
				}
			}

			if (empty($response['next_page'])) {
				break;
			}
			$offset = $response['next_page']['offset'];
		}

		if (!empty($current_sync_gids)) {
			$this->cleanup_stale_tasks('asana', 'assigned', $current_sync_gids);
		}
	}

	/**
	 * CRON-06 & CRON-08: Sync mentions.
	 * Quét comment của các task đã lưu để tìm mentions chứa user_gid hiện tại.
	 */
	private function sync_mentions()
	{
		$asana_api = new ATD_Asana_API();
		$user_gid = get_option('atd_asana_user_gid');

		if (!$user_gid) {
			return;
		}

		global $wpdb;
		$table_data = $wpdb->prefix . 'atd_sync_data';
		$table_mentions = $wpdb->prefix . 'atd_mentions';

		// Lấy danh sách task_gid và content (để lấy name) từ bảng wp_atd_sync_data (giới hạn 20 task mới cập nhật gần đây)
		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT remote_id, content FROM $table_data WHERE source = %s AND type = %s ORDER BY updated_at DESC LIMIT %d",
				'asana',
				'assigned',
				10
			)
		);

		if (empty($tasks)) {
			return;
		}

		foreach ($tasks as $task_row) {
			$task_gid = $task_row->remote_id;
			$task_raw_content = json_decode($task_row->content, true);
			$task_name = isset($task_raw_content['name']) ? $task_raw_content['name'] : 'Unknown Task';

			$stories = $asana_api->get_task_stories($task_gid);

			if (is_wp_error($stories)) {
				mf_atd_log("ATD Error: Failed to fetch stories for task $task_gid - " . $stories->get_error_message());
				continue;
			}

			foreach ($stories as $story) {
				$story_gid = isset($story['gid']) ? $story['gid'] : '';
				$creator_gid = isset($story['created_by']['gid']) ? $story['created_by']['gid'] : '';
				$creator_name = isset($story['created_by']['name']) ? $story['created_by']['name'] : 'Unknown';
				$html_text = isset($story['html_text']) ? $story['html_text'] : '';
				$plain_text = isset($story['text']) ? $story['text'] : '';
				$created_at = isset($story['created_at']) ? $story['created_at'] : '';

				// Bỏ qua comment do chính mình tạo
				if ($creator_gid === $user_gid) {
					continue;
				}

				// Pattern tìm Asana mention tag chứa user_gid
				$mention_pattern = '/<a data-asana-gid="' . preg_quote($user_gid, '/') . '"(?:\s+data-asana-type="user")?\s*\/?>/';

				if (preg_match($mention_pattern, $html_text)) {
					// Kiểm tra xem mention này đã được lưu chưa
					$exists = $wpdb->get_var($wpdb->prepare(
						"SELECT COUNT(1) FROM $table_mentions WHERE story_gid = %s",
						$story_gid
					));

					if (!$exists) {
						$created_at_formatted = '0000-00-00 00:00:00';
						if (!empty($created_at)) {
							$created_at_formatted = date('Y-m-d H:i:s', strtotime($created_at));
						}

						// Lưu mention mới vào DB
						$wpdb->insert(
							$table_mentions,
							array(
								'task_gid'     => $task_gid,
								'task_name'    => $task_name,
								'story_gid'    => $story_gid,
								'mentioned_by' => $creator_name,
								'comment_text' => $plain_text,
								'is_read'      => 0,
								'created_at'   => $created_at_formatted,
								'synced_at'    => current_time('mysql'),
							),
							array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
						);
						
						mf_atd_log("ATD Mention: Found new mention via CRON in task $task_gid from $creator_name");
					}
				}
			}
		}
	}


	// =========================================================================
	// DATA ACCESS
	// =========================================================================

	/**
	 * CRON-05: Lấy dữ liệu từ database, hỗ trợ filter theo type.
	 *
	 * @param string      $source       Nguồn dữ liệu (vd: 'asana').
	 * @param string|null $type         Loại task cũ ('assigned'), để null để lấy tất cả source.
	 * @param int         $limit        Số bản ghi tối đa.
	 * @param int         $offset       Vị trí bắt đầu.
	 * @param string|null $type_filter  Lọc theo type mới: 'assigned'|'created'|'following'|null.
	 * @return array {data: array, total: int}
	 */
	public static function get_cached_data($source, $type = null, $limit = 20, $offset = 0, $type_filter = null)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';

		// Kiểm tra bảng tồn tại.
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
			return array('data' => array(), 'total' => 0);
		}

		// Build WHERE clause linh hoạt.
		$where_parts = array();
		$where_args = array();

		$where_parts[] = 'source = %s';
		$where_args[] = $source;

		if (null !== $type_filter) {
			// Filter theo type_filter mới (ưu tiên hơn $type cũ).
			$where_parts[] = 'type = %s';
			$where_args[] = $type_filter;
		}
		elseif (null !== $type) {
			// Tương thích ngược với cách gọi cũ.
			$where_parts[] = 'type = %s';
			$where_args[] = $type;
		}
		// Nếu cả hai đều null → lấy tất cả types của source đó.

		$where_clause = 'WHERE ' . implode(' AND ', $where_parts);

		// Đếm tổng.
		$count_sql = "SELECT COUNT(*) FROM $table_name $where_clause";
		$total = (int)$wpdb->get_var($wpdb->prepare($count_sql, $where_args));

		// Lấy data.
		$select_args = array_merge($where_args, array($limit, $offset));
		$results_sql = "SELECT content, type FROM $table_name $where_clause ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$results = $wpdb->get_results($wpdb->prepare($results_sql, $select_args), 'ARRAY_A');

		$data = array();
		foreach ($results as $row) {
			$task_data = json_decode($row['content'], true);
			if ($task_data) {
				// Gắn thêm task_type để UI biết phân loại.
				$task_data['task_type'] = $row['type'];
				$data[] = $task_data;
			}
		}

		return array(
			'data' => $data,
			'total' => $total,
		);
	}

	/**
	 * Build summary statistics từ danh sách task.
	 * Trả về: total, overdue, today, upcoming, no_due, by_type.
	 */
	public static function build_summary(array $tasks)
	{
		$today = current_time('Y-m-d');
		$in7days = date('Y-m-d', strtotime('+7 days', strtotime($today)));

		$summary = array(
			'total' => count($tasks),
			'overdue' => 0,
			'today' => 0,
			'upcoming' => 0,
			'no_due' => 0,
			'by_type' => array(
				'assigned' => 0,
				'created' => 0,
				'following' => 0,
			),
		);

		foreach ($tasks as $task) {
			$due_on = isset($task['due_on']) ? $task['due_on'] : null;
			$task_type = isset($task['task_type']) ? $task['task_type'] : 'assigned';

			if (isset($summary['by_type'][$task_type])) {
				$summary['by_type'][$task_type]++;
			}

			if (empty($due_on)) {
				$summary['no_due']++;
			}
			elseif ($due_on < $today) {
				$summary['overdue']++;
			}
			elseif ($due_on === $today) {
				$summary['today']++;
			}
			elseif ($due_on <= $in7days) {
				$summary['upcoming']++;
			}
		}

		return $summary;
	}

	/**
	 * Nhóm task theo deadline để render UI.
	 * Trả về: ['overdue' => [], 'today' => [], 'upcoming' => [], 'no_due' => []]
	 */
	public static function group_tasks_by_deadline(array $tasks)
	{
		$today = current_time('Y-m-d');
		$in7days = date('Y-m-d', strtotime('+7 days', strtotime($today)));

		$groups = array(
			'overdue' => array(),
			'today' => array(),
			'upcoming' => array(),
			'no_due' => array(),
		);

		foreach ($tasks as $task) {
			$due_on = isset($task['due_on']) ? $task['due_on'] : null;

			if (empty($due_on)) {
				$groups['no_due'][] = $task;
			}
			elseif ($due_on < $today) {
				$groups['overdue'][] = $task;
			}
			elseif ($due_on === $today) {
				$groups['today'][] = $task;
			}
			elseif ($due_on <= $in7days) {
				$groups['upcoming'][] = $task;
			}
			else {
				// Due sau 7 ngày: gộp vào no_due để không bị ẩn.
				$groups['no_due'][] = $task;
			}
		}

		// Sắp xếp overdue: task trễ lâu nhất lên đầu.
		usort($groups['overdue'], function ($a, $b) {
			return strcmp($a['due_on'], $b['due_on']);
		});

		// Sắp xếp today và upcoming: hạn gần nhất lên đầu.
		usort($groups['today'], function ($a, $b) {
			return strcmp($a['due_on'], $b['due_on']);
		});
		usort($groups['upcoming'], function ($a, $b) {
			return strcmp($a['due_on'], $b['due_on']);
		});

		return $groups;
	}

	// =========================================================================
	// INTERNAL HELPERS
	// =========================================================================

	/**
	 * Lưu dữ liệu task vào custom table.
	 */
	private function save_to_db($source, $type, $remote_id, $data)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';

		$created_at = '0000-00-00 00:00:00';
		if (!empty($data['created_at'])) {
			$created_at = date('Y-m-d H:i:s', strtotime($data['created_at']));
		}

		$result = $wpdb->replace(
			$table_name,
			array(
			'source' => $source,
			'type' => $type,
			'remote_id' => $remote_id,
			'content' => wp_json_encode($data),
			'created_at' => $created_at,
			'updated_at' => current_time('mysql'),
		),
			array('%s', '%s', '%s', '%s', '%s', '%s')
		);

		if (false === $result) {
			throw new Exception('Database error: ' . $wpdb->last_error);
		}
	}

	/**
	 * Xóa các task không còn trong đợt sync hiện tại (đã hoàn thành hoặc bị xóa).
	 */
	private function cleanup_stale_tasks($source, $type, $current_gids)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';

		$db_gids = $wpdb->get_col($wpdb->prepare(
			"SELECT remote_id FROM $table_name WHERE source = %s AND type = %s",
			$source,
			$type
		));

		if (empty($db_gids)) {
			return;
		}

		$stale_gids = array_diff($db_gids, $current_gids);

		if (!empty($stale_gids)) {
			foreach ($stale_gids as $gid) {
				$wpdb->delete(
					$table_name,
					array(
					'source' => $source,
					'type' => $type,
					'remote_id' => $gid,
				)
				);
				mf_atd_log("ATD Debug: Deleted stale task $gid (type: $type) from local DB");
			}
		}
	}

	// =========================================================================
	// WEEKLY REPORT
	// =========================================================================

	/**
	 * Logic tổng hợp và gửi báo cáo tuần.
	 */
	public function generate_weekly_report()
	{
		$asana_api = new ATD_Asana_API();
		$workspace_id = get_option('atd_asana_workspace_id');

		if (!$workspace_id) {
			return;
		}

		$after = date('Y-m-d\T00:00:00\Z', strtotime('-7 days'));
		$endpoint = '/workspaces/' . $workspace_id . '/tasks/search?completed=true&completed_at.after=' . $after . '&opt_fields=name,completed_at,projects.name';
		$response = $asana_api->request($endpoint);

		if (is_wp_error($response) || empty($response['data'])) {
			return;
		}

		$this->send_email_report($response['data']);
	}

	/**
	 * Gửi email báo cáo.
	 */
	private function send_email_report($tasks)
	{
		$to = get_option('admin_email');
		$subject = __('Báo cáo công việc Asana hàng tuần', 'asana-teams-dashboard');

		$message = '<h2>' . __('Danh sách công việc đã hoàn thành trong tuần qua:', 'asana-teams-dashboard') . '</h2>';
		$message .= '<ul>';

		foreach ($tasks as $task) {
			$message .= sprintf(
				'<li><strong>%s</strong> - %s</li>',
				esc_html($task['name']),
				esc_html(isset($task['completed_at']) ? $task['completed_at'] : '')
			);
		}

		$message .= '</ul>';

		$headers = array('Content-Type: text/html; charset=UTF-8');
		wp_mail($to, $subject, $message, $headers);
	}
}
