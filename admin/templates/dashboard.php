<?php
/**
 * Template Dashboard chính — WP Admin.
 * Logic: PHP. UI: template partials. JS/CSS: enqueue riêng.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Lấy tất cả task để render lần đầu (không phân trang, grouped).
$all_tasks_response = ATD_Cron::get_cached_data( 'asana', null, 500, 0 );
$all_tasks          = $all_tasks_response['data'];
$summary            = ATD_Cron::build_summary( $all_tasks );
$grouped            = ATD_Cron::group_tasks_by_deadline( $all_tasks );
$sync_interval      = max( 5, intval( get_option( 'atd_sync_interval', 30 ) ) );
$user_name          = get_option( 'atd_user_name', 'Hung' );
$assistant_name     = get_option( 'atd_assistant_name', 'Suni Hạ Linh' );

// Lấy danh sách project unique để làm dropdown filter.
$projects = array();
foreach ( $all_tasks as $task ) {
	if ( ! empty( $task['projects'] ) && is_array( $task['projects'] ) ) {
		foreach ( $task['projects'] as $proj ) {
			if ( ! empty( $proj['name'] ) && ! in_array( $proj['name'], $projects, true ) ) {
				$projects[] = $proj['name'];
			}
		}
	}
}
sort( $projects );

// Lịch sử chat.
$history = get_user_meta( get_current_user_id(), 'atd_chat_history', true );
if ( empty( $history ) || ! is_array( $history ) ) {
	$history = array();
}
?>

<div class="wrap atd-dashboard">

	<!-- Header -->
	<div class="atd-header">
		<h1><?php esc_html_e( 'Asana Manager Dashboard', 'asana-teams-dashboard' ); ?></h1>
		<div class="atd-actions">
			<!-- DASH-11: Mention Bell -->
			<button id="atd-mention-bell-btn" class="button button-secondary" style="position:relative; margin-right: 10px;">
				<span class="dashicons dashicons-bell"></span>
				<span id="atd-mention-count-badge" style="display:none; position:absolute; top:-8px; right:-8px; background:#d63638; color:#fff; border-radius:10px; padding:2px 6px; font-size:10px; font-weight:bold; line-height:1;">0</span>
			</button>

			<button id="atd-guide-btn" class="button button-primary" style="background:#2271b1;">
				<span class="dashicons dashicons-editor-help"></span>
				<?php esc_html_e( 'Hướng dẫn sử dụng', 'asana-teams-dashboard' ); ?>
			</button>
			<button id="atd-refresh-btn" class="button button-secondary">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Đồng bộ ngay', 'asana-teams-dashboard' ); ?>
			</button>
			<button id="atd-clear-btn" class="button button-link-delete">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Xóa dữ liệu', 'asana-teams-dashboard' ); ?>
			</button>
			<span id="atd-sync-status" style="display:none;"></span>
			<span id="atd-auto-sync-info">
				<?php esc_html_e( 'Tự động đồng bộ sau: ', 'asana-teams-dashboard' ); ?>
				<span id="atd-timer"><?php echo esc_html( $sync_interval ); ?></span>s
			</span>
		</div>
	</div>

	<!-- Summary Bar -->
	<div class="atd-summary-bar">
		<div class="atd-stat-card is-total">
			<span class="atd-stat-number" id="atd-stat-total"><?php echo esc_html( $summary['total'] ); ?></span>
			<span class="atd-stat-label"><?php esc_html_e( 'Tổng task', 'asana-teams-dashboard' ); ?></span>
		</div>
		<div class="atd-stat-card is-overdue">
			<span class="atd-stat-number" id="atd-stat-overdue"><?php echo esc_html( $summary['overdue'] ); ?></span>
			<span class="atd-stat-label"><?php esc_html_e( '🔥 Quá hạn', 'asana-teams-dashboard' ); ?></span>
		</div>
		<div class="atd-stat-card is-today">
			<span class="atd-stat-number" id="atd-stat-today"><?php echo esc_html( $summary['today'] ); ?></span>
			<span class="atd-stat-label"><?php esc_html_e( '⏰ Hôm nay', 'asana-teams-dashboard' ); ?></span>
		</div>
		<div class="atd-stat-card is-upcoming">
			<span class="atd-stat-number" id="atd-stat-upcoming"><?php echo esc_html( $summary['upcoming'] ); ?></span>
			<span class="atd-stat-label"><?php esc_html_e( '📅 Sắp tới', 'asana-teams-dashboard' ); ?></span>
		</div>
	</div>

	<!-- Marked.js cho render Markdown trong chat -->
	<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

	<!-- Main layout -->
	<div class="atd-main-content">

		<!-- Task Board -->
		<div class="atd-task-board">

			<!-- Quick Add Task -->
			<div class="atd-quick-add-wrap">
				<form id="atd-quick-add-form">
					<input type="text" id="atd-new-task-name" placeholder="<?php esc_attr_e( 'Tên công việc mới...', 'asana-teams-dashboard' ); ?>" required>
					<input type="date" id="atd-new-task-due" title="<?php esc_attr_e( 'Hạn chót', 'asana-teams-dashboard' ); ?>" required>
					<input type="text" id="atd-new-task-notes" placeholder="<?php esc_attr_e( 'Ghi chú (không bắt buộc)', 'asana-teams-dashboard' ); ?>">
					<button type="submit" class="button button-primary" id="atd-submit-new-task-btn">
						<span class="dashicons dashicons-plus-alt2" style="position:relative;top:2px;left:-2px;"></span>
						<?php esc_html_e( 'Tạo Task', 'asana-teams-dashboard' ); ?>
					</button>
				</form>
			</div>

			<!-- Filter Bar -->
			<div class="atd-filter-bar">
				<select id="atd-filter-type">
					<option value="all"><?php esc_html_e( 'Tất cả loại', 'asana-teams-dashboard' ); ?></option>
					<option value="assigned"><?php esc_html_e( 'Assigned to me', 'asana-teams-dashboard' ); ?></option>
					<option value="created"><?php esc_html_e( 'Do tôi tạo', 'asana-teams-dashboard' ); ?></option>
					<option value="following"><?php esc_html_e( 'Tôi theo dõi', 'asana-teams-dashboard' ); ?></option>
				</select>

				<select id="atd-filter-project">
					<option value="all"><?php esc_html_e( 'Tất cả dự án', 'asana-teams-dashboard' ); ?></option>
					<?php foreach ( $projects as $proj ) : ?>
						<option value="<?php echo esc_attr( $proj ); ?>"><?php echo esc_html( $proj ); ?></option>
					<?php endforeach; ?>
				</select>

				<div class="atd-search-wrap">
					<span class="dashicons dashicons-search"></span>
					<input type="text" id="atd-search-input"
						placeholder="<?php esc_attr_e( 'Tìm task...', 'asana-teams-dashboard' ); ?>">
				</div>
			</div>

			<!-- Task Board Container (được AJAX refresh) -->
			<div id="atd-task-board-container">
				<?php include ATD_PLUGIN_DIR . 'admin/templates/partials/task-board.php'; ?>
			</div>

		</div><!-- .atd-task-board -->

		<!-- Chat Assistant -->
		<div class="atd-assistant-sidebar">

			<div class="atd-sidebar-header">
				<h2 style="margin:0;">
					<span class="dashicons dashicons-admin-users"></span>
					<?php printf( esc_html__( 'Trợ lý %s', 'asana-teams-dashboard' ), esc_html( $assistant_name ) ); ?>
				</h2>
				<button id="atd-clear-chat-btn" class="button button-small"
					title="<?php esc_attr_e( 'Xóa lịch sử chat', 'asana-teams-dashboard' ); ?>">
					<span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
				</button>
			</div>

			<div class="atd-chat-container">
				<div id="atd-chat-messages">
					<?php if ( empty( $history ) ) : ?>
						<div class="atd-msg suni-msg">
							<div class="atd-msg-bubble">
								<span class="atd-msg-sender"><?php echo esc_html( $assistant_name ); ?></span>
								<div class="atd-msg-content">
									<?php printf(
										esc_html__( 'Chào anh %1$s! Em là %2$s đây. Anh muốn em hỗ trợ gì về công việc hôm nay không ạ? 😊', 'asana-teams-dashboard' ),
										esc_html( $user_name ),
										esc_html( $assistant_name )
									); ?>
								</div>
							</div>
						</div>
					<?php else : ?>
						<?php foreach ( $history as $entry ) :
							$role   = isset( $entry['role'] ) ? $entry['role'] : 'model';
							$is_user = ( 'user' === $role );
							$sender = $is_user ? 'Anh ' . $user_name : $assistant_name;

							$text = '';
							if ( isset( $entry['content'] ) && ! empty( $entry['content'] ) ) {
								$text = $entry['content'];
							} elseif ( isset( $entry['parts'][0]['text'] ) ) {
								$text = $entry['parts'][0]['text'];
							}

							if ( empty( $text ) ) {
								continue;
							}
							?>
							<div class="atd-msg <?php echo $is_user ? 'user-msg' : 'suni-msg'; ?>">
								<div class="atd-msg-bubble">
									<span class="atd-msg-sender"><?php echo esc_html( $sender ); ?></span>
									<div class="atd-msg-content"
										<?php if ( ! $is_user ) : ?>
										data-raw="<?php echo esc_attr( $text ); ?>"
										<?php endif; ?>>
										<?php echo nl2br( esc_html( $text ) ); ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div><!-- #atd-chat-messages -->

				<div class="atd-chat-input-area">
					<div class="atd-chat-input-row">
						<input type="text" id="atd-chat-input-field"
							placeholder="<?php esc_attr_e( 'Nhập câu hỏi hoặc lệnh...', 'asana-teams-dashboard' ); ?>">
						<button id="atd-chat-send-btn" class="button button-primary">
							<?php esc_html_e( 'Gửi', 'asana-teams-dashboard' ); ?>
						</button>
					</div>
					<div class="atd-quick-actions">
						<button class="button button-small atd-quick-action"
							data-action="auto_briefing">
							🔔 <?php esc_html_e( 'Briefing hôm nay', 'asana-teams-dashboard' ); ?>
						</button>
						<button class="button button-small atd-quick-action"
							data-prefill="<?php esc_attr_e( 'Liệt kê tất cả task quá hạn cho tôi', 'asana-teams-dashboard' ); ?>">
							🔥 <?php esc_html_e( 'Quá hạn', 'asana-teams-dashboard' ); ?>
						</button>
						<button class="button button-small atd-quick-action"
							data-prefill="<?php esc_attr_e( 'Task hôm nay tôi cần làm là gì?', 'asana-teams-dashboard' ); ?>">
							⏰ <?php esc_html_e( 'Hôm nay', 'asana-teams-dashboard' ); ?>
						</button>
						<button class="button button-small atd-quick-action"
							data-prefill="<?php esc_attr_e( 'Tóm tắt tình hình công việc hiện tại cho tôi', 'asana-teams-dashboard' ); ?>">
							📊 <?php esc_html_e( 'Tổng quan', 'asana-teams-dashboard' ); ?>
						</button>
					<button class="button button-small atd-quick-action"
						data-prefill="<?php esc_attr_e( 'Báo cáo tiến độ công việc tuần này cho tôi', 'asana-teams-dashboard' ); ?>">
						📈 <?php esc_html_e( 'Tiến độ tuần', 'asana-teams-dashboard' ); ?>
					</button>
					<button class="button button-small atd-quick-action"
						data-prefill="<?php esc_attr_e( 'Liệt kê những task do người khác giao cho tôi', 'asana-teams-dashboard' ); ?>">
						👤 <?php esc_html_e( 'Việc được giao', 'asana-teams-dashboard' ); ?>
					</button>
					<button class="button button-small atd-quick-action"
						data-prefill="<?php esc_attr_e( 'Task nào sắp đến hạn trong 3 ngày tới?', 'asana-teams-dashboard' ); ?>">
						⏳ <?php esc_html_e( 'Sắp tới', 'asana-teams-dashboard' ); ?>
					</button>
				</div>
				</div>
			</div><!-- .atd-chat-container -->

			<!-- Debug Panel -->
			<?php if ( get_option( 'atd_enable_debug_log', '0' ) === '1' ) : ?>
			<div class="atd-debug-panel">
				<div class="atd-debug-header">
					<h3>
						<span class="dashicons dashicons-media-text" style="font-size:14px;width:14px;height:14px;"></span>
						<?php esc_html_e( 'Debug Logs', 'asana-teams-dashboard' ); ?>
					</h3>
					<div>
						<button id="atd-refresh-logs-btn" class="button button-small"
							title="<?php esc_attr_e( 'Làm mới log', 'asana-teams-dashboard' ); ?>">
							<span class="dashicons dashicons-update"></span>
						</button>
						<button id="atd-clear-logs-btn" class="button button-small"
							style="color:#d63638;"
							title="<?php esc_attr_e( 'Xóa log', 'asana-teams-dashboard' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</div>
				</div>
				<pre id="atd-debug-logs"><?php esc_html_e( 'Đang tải log...', 'asana-teams-dashboard' ); ?></pre>
			</div>
			<?php endif; ?>

		</div><!-- .atd-assistant-sidebar -->

	</div><!-- .atd-main-content -->

</div><!-- .atd-dashboard -->

<!-- Modal Hướng dẫn sử dụng -->
<div id="atd-guide-modal" class="atd-modal-overlay" style="display:none;">
	<div class="atd-modal" style="max-width: 700px; width: 90%; max-height: 85vh; overflow-y: auto;">
		<div class="atd-modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 15px;">
			<h2 style="margin:0;">📖 <?php esc_html_e( 'Hướng dẫn sử dụng Asana Dashboard', 'asana-teams-dashboard' ); ?></h2>
			<button class="atd-modal-close-guide" style="background:none; border:none; cursor:pointer; font-size:24px;">&times;</button>
		</div>
		
		<div class="atd-guide-content" style="line-height: 1.6;">
			<section style="margin-bottom: 20px;">
				<h3 style="color:#2271b1;"><span class="dashicons dashicons-welcome-view-site"></span> 1. Tổng quan Dashboard</h3>
				<p>Dashboard giúp anh quản lý toàn bộ task Asana ngay tại WordPress. Dữ liệu được chia thành 4 nhóm thời gian: **Quá hạn**, **Hôm nay**, **Sắp tới (7 ngày)** và **Không có hạn**.</p>
			</section>

			<section style="margin-bottom: 20px;">
				<h3 style="color:#2271b1;"><span class="dashicons dashicons-admin-users"></span> 2. Trợ lý ảo (AI Assistant)</h3>
				<ul>
					<li>🚀 **Chào buổi sáng (Daily Briefing):** Mỗi sáng lần đầu mở dashboard, em sẽ tự động tóm tắt những việc quan trọng nhất cần làm trong ngày.</li>
					<li>🔔 **Nhắc đến (Mentions):** AI tự động theo dõi khi anh bị nhắc tên (`@mention`) trong các bình luận trên Asana. Bấm vào biểu tượng **Chuông thông báo (màu đỏ)** trên cùng để xem danh sách. Em có thể soạn sẵn tin nhắn phản hồi nhanh chóng cho anh.</li>
					<li>📋 **Phân tích Task:** Click nút **[Phân tích]** trên mỗi task để em đọc chi tiết nội dung, các bình luận (comments) và **toàn bộ task con (subtasks)**. Em sẽ báo cáo rõ mảng việc nào đang do **Dev nào phụ trách** và tiến độ ra sao giúp anh.</li>
					<li>💬 **Nhắc nhở (Remind AI):** Click nút **[Nhắc AI]**, em sẽ soạn sẵn một lời nhắc nhở chuyên nghiệp để anh gửi lên Asana cho người phụ trách.</li>
				</ul>
			</section>

			<section style="margin-bottom: 20px;">
				<h3 style="color:#2271b1;"><span class="dashicons dashicons-editor-command"></span> 3. Ra lệnh qua Chat</h3>
				<p>Anh có thể gõ trực tiếp yêu cầu vào khung chat:</p>
				<ul>
					<li>- *"Dời hạn chót task [Tên task] sang ngày mai"*</li>
					<li>- *"Đánh dấu hoàn thành task [Tên task]"*</li>
					<li>- *"Thống kê task quá hạn của project [Tên project]"*</li>
					<li>- *"Trả lời anh Hiếu ở mention số [X] là em đang làm nhé"*</li>
				</ul>
				<p><em>*Em sẽ luôn hỏi xác nhận trước khi thực hiện các thao tác thay đổi dữ liệu.*</em></p>
			</section>

			<section style="margin-bottom: 20px;">
				<h3 style="color:#d63638;"><span class="dashicons dashicons-warning"></span> 4. Lưu ý về bản Asana Free</h3>
				<p>Nếu workspace của anh là bản miễn phí (Basic):</p>
				<ul>
					<li>- Tính năng **"Do tôi tạo"** và **"Tôi theo dõi"** sẽ bị giới hạn bởi API Asana (yêu cầu Premium).</li>
					<li>- Plugin sẽ ưu tiên hiển thị các task **được giao cho anh (Assigned to me)**.</li>
				</ul>
			</section>
		</div>

		<div class="atd-modal-footer" style="margin-top:20px; text-align:right; border-top:1px solid #ddd; padding-top:15px;">
			<button class="button button-primary atd-modal-close-guide"><?php esc_html_e( 'Đã hiểu, đóng lại', 'asana-teams-dashboard' ); ?></button>
		</div>
	</div>
</div>

<!-- DASH-12: Modal Danh sách Mention -->
<div id="atd-mentions-modal" class="atd-modal-overlay" style="display:none;">
	<div class="atd-modal" style="max-width: 600px; width: 90%; max-height: 80vh; display: flex; flex-direction: column;">
		<div class="atd-modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 15px; margin-bottom: 0;">
			<h2 style="margin:0;">🔔 <?php esc_html_e( 'Thông báo Nhắc đến (Mentions)', 'asana-teams-dashboard' ); ?></h2>
			<button class="atd-modal-close-mentions" style="background:none; border:none; cursor:pointer; font-size:24px; color:#555;">&times;</button>
		</div>
		
		<div class="atd-mentions-tabs" style="display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
			<button class="button atd-mention-tab active" data-status="unread"><?php esc_html_e( 'Chưa đọc', 'asana-teams-dashboard' ); ?></button>
			<button class="button atd-mention-tab" data-status="read"><?php esc_html_e( 'Đã đọc', 'asana-teams-dashboard' ); ?></button>
		</div>

		<div class="atd-mentions-content" style="flex: 1; overflow-y: auto; padding: 15px 0; background: #f9f9f9;">
			<div id="atd-mentions-list">
				<p style="text-align:center; color:#666;"><span class="spinner is-active" style="float:none;"></span> Đang tải...</p>
			</div>
		</div>
	</div>
</div>
