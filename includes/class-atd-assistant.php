<?php
/**
 * Class xử lý logic trợ lý ảo Suni Hạ Linh sử dụng OpenAI API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATD_Assistant {

	private $api_key;
	private $user_name;
	private $assistant_name;
	private $model = 'gpt-4o-mini'; // Sử dụng gpt-4o-mini làm bộ não mới.

	public function __construct() {
		$this->api_key        = get_option( 'atd_openai_api_key' );
		$this->user_name      = get_option( 'atd_user_name', 'Hung' );
		$this->assistant_name = get_option( 'atd_assistant_name', 'Suni Hạ Linh' );
		add_action( 'wp_ajax_atd_assistant_chat', array( $this, 'ajax_chat_handler' ) );
		add_action( 'wp_ajax_atd_clear_chat_history', array( $this, 'ajax_clear_history_handler' ) );
		add_action( 'wp_ajax_atd_get_debug_logs', array( $this, 'ajax_get_debug_logs' ) );
		add_action( 'wp_ajax_atd_clear_debug_logs', array( $this, 'ajax_clear_debug_logs' ) );
		add_action( 'wp_ajax_atd_auto_briefing', array( $this, 'ajax_auto_briefing_handler' ) );
		add_action( 'wp_ajax_atd_ai_draft_comment', array( $this, 'ajax_ai_draft_comment' ) );
		add_action( 'wp_ajax_atd_ai_post_comment', array( $this, 'ajax_ai_post_comment' ) );
		add_action( 'wp_ajax_atd_get_mentions', array( $this, 'ajax_get_mentions' ) );
		add_action( 'wp_ajax_atd_reply_mention', array( $this, 'ajax_reply_mention' ) );
	}

	/**
	 * Xử lý tin nhắn chat từ UI.
	 */
	public function ajax_chat_handler() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No permission' );
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( $_POST['message'] ) : '';

		if ( empty( $message ) ) {
			wp_send_json_error( 'Message is empty' );
		}

		if ( empty( $this->api_key ) ) {
			// Fallback về rule-based nếu chưa có API Key
			$response = $this->process_message_fallback( $message );
			wp_send_json_success( array(
				'reply' => $response . "\n\n(Lưu ý: Em đang chạy chế độ dự phòng vì chưa có OpenAI API Key anh ơi! 😊)",
				'suni_name' => $this->assistant_name
			) );
			return;
		}

		$response = $this->query_openai( $message );

		// Lưu vào lịch sử chat
		$this->save_chat_history( $message, $response );

		wp_send_json_success( array(
			'reply' => $response,
			'suni_name' => $this->assistant_name
		) );
	}

	/**
	 * Xử lý xóa lịch sử chat.
	 */
	public function ajax_clear_history_handler() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No permission' );
		}

		delete_user_meta( get_current_user_id(), 'atd_chat_history' );
		wp_send_json_success( 'Đã xóa lịch sử chat thành công!' );
	}

	/**
	 * Lưu lịch sử chat vào user meta (định dạng OpenAI).
	 */
	private function save_chat_history( $user_msg, $ai_msg ) {
		$user_id = get_current_user_id();
		$history = get_user_meta( $user_id, 'atd_chat_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'role' => 'user',
			'content' => $user_msg
		);
		$history[] = array(
			'role' => 'assistant',
			'content' => $ai_msg
		);

		// Giới hạn lịch sử khoảng 20 cặp tin nhắn gần nhất
		if ( count( $history ) > 40 ) {
			$history = array_slice( $history, -40 );
		}

		update_user_meta( $user_id, 'atd_chat_history', $history );
	}

	/**
	 * Gửi request đến OpenAI API.
	 */
	private function query_openai( $message ) {
		$url = "https://api.openai.com/v1/chat/completions";

		// Lấy lịch sử chat
		$history = get_user_meta( get_current_user_id(), 'atd_chat_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$messages = array();
		$messages[] = array(
			'role' => 'system',
			'content' => $this->get_system_instruction()
		);
		
		foreach ( $history as $h ) {
			// Đảm bảo không còn role 'model' từ Gemini cũ
			if ( isset( $h['role'] ) && $h['role'] === 'model' ) {
				$h['role'] = 'assistant';
			}
			
			// Đảm bảo convert content sang format OpenAI nếu nó đang ở format Gemini (parts)
			if ( ! isset( $h['content'] ) && isset( $h['parts'][0]['text'] ) ) {
				$h['content'] = $h['parts'][0]['text'];
				unset( $h['parts'] );
			}
			
			// Chỉ thêm tin nhắn có content hợp lệ
			if ( isset( $h['role'] ) && isset( $h['content'] ) && ! empty( $h['content'] ) ) {
				$messages[] = $h;
			}
		}

		$messages[] = array(
			'role' => 'user',
			'content' => $message
		);
		
		$body = array(
			'model' => $this->model,
			'messages' => $messages,
			'tools' => $this->get_function_declarations(),
			'tool_choice' => 'auto'
		);

		$response = wp_remote_post( $url, array(
			'body'    => json_encode( $body ),
			'headers' => array( 
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key
			),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return "Lỗi kết nối OpenAI rồi anh ơi: " . $response->get_error_message();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( isset( $data['error'] ) ) {
			return "OpenAI báo lỗi nè anh: " . $data['error']['message'];
		}

		// Xử lý Tool Calls (Function Calling)
		return $this->handle_openai_response( $data, $messages );
	}

	/**
	 * Xử lý response từ OpenAI, hỗ trợ Tool Calls.
	 */
	private function handle_openai_response( $data, $messages ) {
		mf_atd_log( 'ATD Debug: OpenAI response raw: ' . wp_json_encode( $data ) );
		
		if ( ! isset( $data['choices'][0]['message'] ) ) {
			return "Em không nhận được phản hồi hợp lệ từ OpenAI anh ơi... 😅";
		}
		
		$message_obj = $data['choices'][0]['message'];
		
		if ( empty( $message_obj['tool_calls'] ) ) {
			return isset( $message_obj['content'] ) ? $message_obj['content'] : "Em không biết trả lời sao nữa anh ơi... 😅";
		}

		// Có tool calls
		$tool_calls = $message_obj['tool_calls'];
		$messages[] = $message_obj; // Lưu tin nhắn chứa tool_calls vào context

		foreach ( $tool_calls as $tool_call ) {
			$func_name = $tool_call['function']['name'];
			$args = json_decode( $tool_call['function']['arguments'], true );
			
			mf_atd_log( "ATD Debug: OpenAI AI deciding to call tool: $func_name with args: " . print_r( $args, true ) );
			
			$result = $this->call_local_tool( $func_name, $args );
			
			mf_atd_log( "ATD Debug: Tool $func_name result: " . ( is_array( $result ) ? 'Array(' . count( $result ) . ')' : $result ) );
			// Nếu gặp tool nhạy cảm cần xác nhận
			if ( is_array( $result ) && isset( $result['status'] ) && $result['status'] === 'action_required' ) {
				// Dừng loop gọi lại OpenAI, ném luôn Action Obj này về cho Frontend
				return wp_json_encode( $result );
			}
			
			$messages[] = array(
				'role' => 'tool',
				'tool_call_id' => $tool_call['id'],
				'name' => $func_name,
				'content' => is_array( $result ) ? wp_json_encode( $result ) : (string)$result
			);
		}

		// Gửi lại cho OpenAI để lấy câu trả lời cuối cùng hoặc gọi tiếp tool khác (nếu cần)
		$url = "https://api.openai.com/v1/chat/completions";
		$body = array(
			'model' => $this->model,
			'messages' => $messages,
			'tools' => $this->get_function_declarations(),
			'tool_choice' => 'auto'
		);

		mf_atd_log( 'ATD Debug: Sending back tool results to OpenAI...' );

		$response = wp_remote_post( $url, array(
			'body'    => json_encode( $body ),
			'headers' => array( 
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key
			),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return "Lỗi khi OpenAI xử lý dữ liệu rồi anh: " . $response->get_error_message();
		}

		$data_final = json_decode( wp_remote_retrieve_body( $response ), true );
		mf_atd_log( 'ATD Debug: OpenAI response (round 2): ' . wp_json_encode( $data_final ) );

		if ( isset( $data_final['error'] ) ) {
			return "OpenAI báo lỗi rồi anh ơi: " . $data_final['error']['message'];
		}

		// Nếu OpenAI vẫn muốn gọi thêm tool nữa (ví dụ: sau khi find xong muốn complete)
		if ( ! empty( $data_final['choices'][0]['message']['tool_calls'] ) ) {
			return $this->handle_openai_response( $data_final, $messages );
		}

		// Nếu trả lời dạng Action JSON -> bypass
		$final_content = isset( $data_final['choices'][0]['message']['content'] ) ? $data_final['choices'][0]['message']['content'] : "";
		$decoded_check = json_decode( $final_content, true );
		if ( $decoded_check && isset( $decoded_check['status'] ) && $decoded_check['status'] === 'action_required' ) {
			return $final_content; // Pass string JSON thẳng về
		}

		if ( empty( $final_content ) ) {
			// Nếu OpenAI trả về tool_calls rỗng và content cũng rỗng ở round 2, có thể nó đã xong việc
			return "Em đã xử lý xong yêu cầu của anh rồi ạ! 😊";
		}

		return $final_content;
	}

	/**
	 * Gọi các hàm tool nội bộ.
	 */
	private function call_local_tool( $name, $args ) {
		switch ( $name ) {
			case 'get_upcoming_tasks':
				$days = isset( $args['days_ahead'] ) ? intval( $args['days_ahead'] ) : 7;
				return $this->get_upcoming_tasks( $days );
			case 'get_today_tasks':
				return $this->get_today_tasks();
			case 'get_task_by_keyword':
				return $this->get_task_by_keyword( $args['keyword'] );
			case 'get_weekly_report':
				return $this->get_weekly_report_data();
			case 'create_task':
				return $this->create_asana_task( $args );
			case 'get_task_details':
				return $this->get_task_details( $args['task_id'] );
			case 'query_tasks_filter':
				return $this->query_tasks_for_ai( $args );
			
			// Các Tools nhạy cảm cần xác nhận user thay vì thực thi luôn
			case 'complete_task':
			case 'set_due_date':
			case 'reply_mention':
				return array(
					'status'          => 'action_required',
					'action_type'     => $name,
					'action_args'     => $args,
					'message_to_user' => 'Em cần anh duyệt lệnh này trước khi em gửi lên Asana ạ.'
				);
				
			default:
				return "Không tìm thấy công cụ này anh ơi.";
		}
	}

	/**
	 * Khai báo các function cho OpenAI (chuẩn Tool).
	 */
	private function get_function_declarations() {
		return array(
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_upcoming_tasks',
					'description' => 'Lấy danh sách các task sắp tới hoặc quá hạn từ Asana.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'days_ahead' => array(
								'type' => 'number',
								'description' => 'Số ngày sắp tới muốn xem (mặc định là 7).'
							)
						)
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_today_tasks',
					'description' => 'Lấy danh sách các task cần hoàn thành trong hôm nay.',
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_task_by_keyword',
					'description' => 'Tìm kiếm task theo từ khóa.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'keyword' => array(
								'type' => 'string',
								'description' => 'Từ khóa tìm kiếm.'
							)
						),
						'required' => array( 'keyword' )
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_weekly_report',
					'description' => 'Lấy dữ liệu tóm tắt công việc trong tuần.',
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'create_task',
					'description' => 'Tạo một task mới trên Asana.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'title' => array( 'type' => 'string', 'description' => 'Tiêu đề công việc.' ),
							'due_on' => array( 'type' => 'string', 'description' => 'Ngày hết hạn (định dạng YYYY-MM-DD).' ),
							'notes' => array( 'type' => 'string', 'description' => 'Mô tả chi tiết.' )
						),
						'required' => array( 'title' )
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'complete_task',
					'description' => 'Đánh dấu hoàn thành một task.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'task_id' => array( 'type' => 'string', 'description' => 'GID của task trên Asana.' )
						),
						'required' => array( 'task_id' )
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_task_details',
					'description' => 'Lấy thông tin chi tiết đầy đủ của một task (bao gồm mô tả đầy đủ).',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'task_id' => array( 'type' => 'string', 'description' => 'GID của task trên Asana.' )
						),
						'required' => array( 'task_id' )
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'set_due_date',
					'description' => 'Đổi ngày hết hạn cho một task hiện có.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'task_id' => array( 'type' => 'string', 'description' => 'GID của task trên Asana.' ),
							'due_on'  => array( 'type' => 'string', 'description' => 'Ngày hết hạn mới (định dạng YYYY-MM-DD).' )
						),
						'required' => array( 'task_id', 'due_on' )
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'query_tasks_filter',
					'description' => 'Truy vấn tuỳ biến: lọc task theo dự án, người phụ trách, trạng thái deadline, hoặc từ khoá. Dùng khi user hỏi thống kê phức tạp cần lọc nhiều điều kiện.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'project_keyword' => array( 'type' => 'string', 'description' => 'Từ khoá dự án (VD: MKT, Design). Tìm LIKE.' ),
							'assignee_keyword' => array( 'type' => 'string', 'description' => 'Từ khoá tên người phụ trách (VD: Hùng, Suni). Tìm LIKE.' ),
							'due_status' => array( 'type' => 'string', 'description' => 'Lọc theo deadline: overdue, today, upcoming, no_due, all. Mặc định: all.' ),
							'keyword' => array( 'type' => 'string', 'description' => 'Từ khoá trong tên task. Tìm LIKE.' ),
							'max_results' => array( 'type' => 'number', 'description' => 'Số kết quả tối đa trả về. Mặc định: 20.' )
						)
					)
				)
			),
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'reply_mention',
					'description' => 'Trả lời một mention trên Asana dựa vào ID của mention đó. KHÔNG gọi nếu chưa có mention_id.',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'mention_id' => array( 'type' => 'number', 'description' => 'ID của mention lấy từ thông báo ban đầu.' ),
							'task_gid' => array( 'type' => 'string', 'description' => 'GID của task.' ),
							'reply_text'  => array( 'type' => 'string', 'description' => 'Nội dung câu trả lời mà người dùng đã đọc cho bạn, bạn hãy trau chuốt lại cho lịch sự.' )
						),
						'required' => array( 'mention_id', 'task_gid', 'reply_text' )
					)
				)
			)
		);
	}

	/**
	 * Phase 3.3 (AI-10): Query DB tuỳ biến theo bộ lọc cho AI.
	 */
	private function query_tasks_for_ai( $filters = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$today      = current_time( 'Y-m-d' );

		$max_results      = isset( $filters['max_results'] ) ? intval( $filters['max_results'] ) : 20;
		$project_keyword  = isset( $filters['project_keyword'] ) ? sanitize_text_field( $filters['project_keyword'] ) : '';
		$assignee_keyword = isset( $filters['assignee_keyword'] ) ? sanitize_text_field( $filters['assignee_keyword'] ) : '';
		$keyword          = isset( $filters['keyword'] ) ? sanitize_text_field( $filters['keyword'] ) : '';
		$due_status       = isset( $filters['due_status'] ) ? sanitize_text_field( $filters['due_status'] ) : 'all';

		$where_clauses = array( $wpdb->prepare( 'source = %s', 'asana' ) );

		if ( ! empty( $keyword ) ) {
			$where_clauses[] = $wpdb->prepare( 'content LIKE %s', '%' . $wpdb->esc_like( $keyword ) . '%' );
		}
		if ( ! empty( $project_keyword ) ) {
			$where_clauses[] = $wpdb->prepare( 'content LIKE %s', '%' . $wpdb->esc_like( $project_keyword ) . '%' );
		}
		if ( ! empty( $assignee_keyword ) ) {
			$where_clauses[] = $wpdb->prepare( 'content LIKE %s', '%' . $wpdb->esc_like( $assignee_keyword ) . '%' );
		}

		$sql = "SELECT content FROM $table_name WHERE " . implode( ' AND ', $where_clauses ) . " LIMIT $max_results";
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$tasks = array();
		foreach ( $rows as $row ) {
			$task = json_decode( $row['content'], true );
			if ( ! $task ) continue;

			$task_due = isset( $task['due_on'] ) ? $task['due_on'] : '';

			// Lọc theo due_status (post-filter vì deadline nằm trong JSON)
			if ( $due_status !== 'all' && ! empty( $due_status ) ) {
				if ( $due_status === 'overdue' && ( ! $task_due || $task_due >= $today ) ) continue;
				if ( $due_status === 'today' && $task_due !== $today ) continue;
				if ( $due_status === 'upcoming' && ( ! $task_due || $task_due <= $today || $task_due > gmdate( 'Y-m-d', strtotime( '+7 days' ) ) ) ) continue;
				if ( $due_status === 'no_due' && ! empty( $task_due ) ) continue;
			}

			$gid      = isset( $task['gid'] ) ? $task['gid'] : '';
			$name     = isset( $task['name'] ) ? $task['name'] : '';
			$assignee = isset( $task['assignee']['name'] ) ? $task['assignee']['name'] : 'N/A';
			$project  = '';
			if ( ! empty( $task['projects'] ) && is_array( $task['projects'] ) ) {
				$project = implode( ', ', array_column( $task['projects'], 'name' ) );
			}

			$tasks[] = array(
				'gid'      => $gid,
				'name'     => $name,
				'due_on'   => $task_due ? $task_due : 'N/A',
				'assignee' => $assignee,
				'project'  => $project ? $project : 'N/A',
			);
		}

		if ( empty( $tasks ) ) {
			return 'Không tìm thấy task nào khớp với bộ lọc.';
		}

		return $tasks;
	}

	/**
	 * Xây dựng ngữ cảnh các task hiện tại để nhúng vào prompt cho AI.
	 * Phase 2.1: Cung cấp danh sách công việc (tối đa 50) từ DB local.
	 */
	private function build_task_context() {
		// Tăng limit lên 2000 để đảm bảo đếm đủ cho briefing
		$cached_response = ATD_Cron::get_cached_data( 'asana', null, 2000, 0 );
		$all_tasks       = $cached_response['data'];
		
		if ( empty( $all_tasks ) ) {
			return "Hiện tại không có task nào đang cần theo dõi.";
		}

		$grouped = ATD_Cron::group_tasks_by_deadline( $all_tasks );
		
		// Sắp xếp Overdue để lấy những cái "sâu" nhất (cũ nhất) lên đầu
		usort( $grouped['overdue'], function( $a, $b ) {
			$due_a = strtotime( $a['due_on'] );
			$due_b = strtotime( $b['due_on'] );
			return $due_a - $due_b;
		} );

		$counts = array(
			'overdue'  => count( $grouped['overdue'] ),
			'today'    => count( $grouped['today'] ),
			'upcoming' => count( $grouped['upcoming'] ),
			'no_due'   => count( $grouped['no_due'] ),
		);

		$context_lines = array();
		$context_lines[] = "[THỐNG KÊ TỔNG QUAN TỪ DATABASE]";
		$context_lines[] = "- Tổng số task quá hạn: " . $counts['overdue'];
		$context_lines[] = "- Tổng số task đến hạn hôm nay: " . $counts['today'];
		$context_lines[] = "- Tổng số task sắp tới (7 ngày): " . $counts['upcoming'];
		$context_lines[] = "- Tổng số task chưa có hạn/xa hơn: " . $counts['no_due'];
		
		$context_lines[] = "\n[CHI TIẾT DANH SÁCH (DÙNG ĐỂ BRIEFING)]";
		$max_tasks = 100; // Tăng limit liệt kê trong context

		$groups_to_process = array(
			'overdue'  => '🔴 QUÁ HẠN (Sắp xếp từ cũ nhất đến mới nhất)',
			'today'    => '⏰ HÔM NAY',
			'upcoming' => '📅 SẮP TỚI',
			'no_due'   => '📭 KHÔNG CÓ HẠN / XA HƠN'
		);

		$listed_count = 0;
		foreach ( $groups_to_process as $key => $label ) {
			if ( empty( $grouped[ $key ] ) ) {
				continue;
			}
			$context_lines[] = "\n--- $label ---";
			foreach ( $grouped[ $key ] as $task ) {
				if ( $listed_count >= $max_tasks ) {
					break 2;
				}
				
				$gid       = isset( $task['gid'] ) ? $task['gid'] : '';
				$name      = isset( $task['name'] ) ? $task['name'] : 'Không tên';
				$due_on    = isset( $task['due_on'] ) ? $task['due_on'] : 'Không có';
				$assignee  = isset( $task['assignee']['name'] ) ? $task['assignee']['name'] : 'N/A';
				$task_type = isset( $task['task_type'] ) ? $task['task_type'] : 'assigned';
				
				$project = '';
				if ( ! empty( $task['projects'] ) && is_array( $task['projects'] ) ) {
					$project = implode( ', ', array_column( $task['projects'], 'name' ) );
				}
				if ( empty( $project ) ) { $project = 'N/A'; }

				$context_lines[] = sprintf( "- [%s] | %s | Proj: %s | Due: %s | Assignee: %s | Type: %s", 
					$gid, $name, $project, $due_on, $assignee, $task_type 
				);
				$listed_count++;
			}
		}

		return implode( "\n", $context_lines );
	}

	private function get_system_instruction() {
		$today = date_i18n( 'l, d/m/Y' );
		$task_context = $this->build_task_context();
		
		return "Bạn là trợ lý ảo cá nhân thông minh, tên là \"{$this->assistant_name}\", bạn xưng là em và gọi người dùng là anh (anh {$this->user_name}).
Nhiệm vụ chính: Nhắc nhở và quản lý toàn diện công việc của anh {$this->user_name} một cách chủ động, chính xác, lịch sự và ngắn gọn bằng tiếng Việt.
Hôm nay là $today.

[TASK CONTEXT - NGỮ CẢNH CÔNG VIỆC HIỆN TẠI]
Dưới đây là danh sách các task hiện hành của anh {$this->user_name}:
$task_context

[HƯỚNG DẪN NĂNG LỰC CỦA BẠN]
Bạn có thể thao tác với các công việc thông qua các tools:
- Phân tích task chi tiết: GỌI `get_task_details`.
- Đánh dấu hoàn thành task: GỌI `complete_task`.
- Tạo công việc mới: GỌI `create_task`.
- Trả lời mention nhận được: GỌI `reply_mention`. (Luôn truyền \`mention_id\` tương ứng từ CÓ MENTION MỚI CẦN XỬ LÝ).
- Xem các công việc sắp tới: Dữ liệu ĐÃ CÓ trong [TASK CONTEXT], KHÔNG cần gọi tool.

QUY TẮC QUAN TRỌNG:
1. Khi báo cáo tình hình, hãy tóm tắt thông minh, đừng chỉ liệt kê dàn trải, dùng emoji thân thiện hợp lý (✅, ⏰, ⚠️, 🔥, 📅).
2. Khi anh {$this->user_name} yêu cầu hoàn thành task, ĐỪNG BÁO HOÀN THÀNH nếu chưa gọi tool `complete_task`. Nếu cần tìm GID, tìm trong [TASK CONTEXT] trước, nếu không thấy mới gọi `get_task_by_keyword`.
3. Khi anh {$this->user_name} muốn tạo task, hãy hỏi rõ tiêu đề và ngày hết hạn nếu anh chưa cung cấp đủ. Đừng tự ý cho dummy data.
4. Khi anh {$this->user_name} yêu cầu \"Đọc\", \"Tóm tắt\", \"Phân tích\" mô tả của task X, hãy GỌI tool `get_task_details` để có mô tả, sau đó tóm tắt ngắn gọn.";
	}

	// --- Local Tool Implementations ---

	public function get_upcoming_tasks( $days_ahead = 7, $include_overdue = true ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT content FROM $table_name WHERE source = %s AND type = %s", 'asana', 'assigned' ), 'ARRAY_A' );

		mf_atd_log( 'ATD Debug: get_upcoming_tasks - Raw records count: ' . count( $results ) );

		$tasks_raw = array();
		foreach ( $results as $row ) {
			$task = json_decode( $row['content'], true );
			if ( $task ) { $tasks_raw[] = $task; }
		}

		$tasks = array();
		$today = strtotime( 'today' );
		$limit_date = strtotime( "+$days_ahead days", $today );

		foreach ( $tasks_raw as $task ) {
			// Đảm bảo task có remote_id (gid) để AI sử dụng
			if ( ! isset( $task['task_id'] ) && isset( $task['gid'] ) ) {
				$task['task_id'] = $task['gid'];
			}
			$due_date = ! empty( $task['due_on'] ) ? strtotime( $task['due_on'] ) : null;
			if ( $due_date ) {
				if ( $due_date < $today && $include_overdue ) {
					$task['status_tag'] = 'overdue';
					$tasks[] = $task;
				} elseif ( $due_date >= $today && $due_date <= $limit_date ) {
					$task['status_tag'] = 'upcoming';
					$tasks[] = $task;
				}
			} else {
				$task['status_tag'] = 'no_due_date';
				$tasks[] = $task;
			}
		}

		usort( $tasks, function( $a, $b ) {
			if ( $a['status_tag'] === 'overdue' && $b['status_tag'] !== 'overdue' ) return -1;
			if ( $a['status_tag'] !== 'overdue' && $b['status_tag'] === 'overdue' ) return 1;
			$due_a = ! empty( $a['due_on'] ) ? strtotime( $a['due_on'] ) : 9999999999;
			$due_b = ! empty( $b['due_on'] ) ? strtotime( $b['due_on'] ) : 9999999999;
			if ( $due_a !== $due_b ) return $due_a - $due_b;
			return strtotime( $b['created_at'] ) - strtotime( $a['created_at'] );
		} );
		return $tasks;
	}

	public function get_task_by_keyword( $keyword ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$search = '%' . $wpdb->esc_like( $keyword ) . '%';
		
		$sql = $wpdb->prepare( 
			"SELECT content FROM $table_name WHERE source = %s AND type = %s AND content LIKE %s", 
			'asana', 'assigned', $search 
		);
		mf_atd_log( 'ATD Debug: get_task_by_keyword SQL: ' . $sql );

		$results = $wpdb->get_results( $sql, 'ARRAY_A' );
		$tasks = array();
		foreach ( $results as $row ) {
			$task = json_decode( $row['content'], true );
			if ( $task ) {
				if ( ! isset( $task['task_id'] ) && isset( $task['gid'] ) ) {
					$task['task_id'] = $task['gid'];
				}
				$tasks[] = $task;
			}
		}

		if ( empty( $tasks ) ) {
			$all_results = $wpdb->get_results( $wpdb->prepare( 
				"SELECT content FROM $table_name WHERE source = %s AND type = %s", 
				'asana', 'assigned' 
			), 'ARRAY_A' );
			
 		foreach ( $all_results as $row ) {
				$task = json_decode( $row['content'], true );
				if ( $task && ! empty( $task['name'] ) ) {
					if ( mb_strpos( mb_strtolower( $task['name'], 'UTF-8' ), mb_strtolower( $keyword, 'UTF-8' ), 0, 'UTF-8' ) !== false ) {
						// Đảm bảo task có remote_id (gid) để AI sử dụng
						if ( ! isset( $task['task_id'] ) && isset( $task['gid'] ) ) {
							$task['task_id'] = $task['gid'];
						}
						$tasks[] = $task;
					}
				}
			}
		}

		if ( ! empty( $tasks ) ) {
			usort( $tasks, function( $a, $b ) {
				$time_a = ! empty( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
				$time_b = ! empty( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
				return $time_b - $time_a;
			} );
		}

		return $tasks;
	}

	public function get_today_tasks() {
		return $this->get_upcoming_tasks( 0, true );
	}

	private function get_weekly_report_data() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		return array( 'total_pending' => $total, 'message' => "Hiện có $total việc chưa xong." );
	}

	private function create_asana_task( $args ) {
		$asana_api = new ATD_Asana_API();
		$data = array(
			'name' => $args['title'],
			'assignee' => 'me',
			'notes' => isset( $args['notes'] ) ? $args['notes'] : ''
		);
		if ( ! empty( $args['due_on'] ) ) {
			$data['due_on'] = $args['due_on'];
		}
		$response = $asana_api->create_task( $data );
		if ( is_wp_error( $response ) ) return "Lỗi rồi: " . $response->get_error_message();
		wp_schedule_single_event( time() + 2, 'atd_sync_data_cron' );
		return "Đã tạo task thành công!";
	}

	private function complete_asana_task( $task_id ) {
		mf_atd_log( "ATD Debug: complete_asana_task calling for ID: $task_id" );
		$asana_api = new ATD_Asana_API();
		$update_data = array(
			'completed' => true,
			'due_on'    => date( 'Y-m-d' )
		);
		$response = $asana_api->update_task( $task_id, $update_data );
		
		if ( is_wp_error( $response ) ) {
			mf_atd_log( "ATD Debug: complete_asana_task API Error: " . $response->get_error_message() );
			return "Lỗi khi gọi API Asana: " . $response->get_error_message();
		}
		
		mf_atd_log( "ATD Debug: complete_asana_task Success for ID: $task_id" );
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_sync_data';
		$wpdb->delete( $table_name, array( 'remote_id' => $task_id ) );
		
		return "Đã hoàn thành task thành công trên Asana!";
	}

	private function get_task_details( $task_id ) {
		mf_atd_log( "ATD Debug: get_task_details calling for ID: $task_id" );
		$asana_api = new ATD_Asana_API();
		$response = $asana_api->get_task_detail( $task_id );
		
		if ( is_wp_error( $response ) ) {
			return "Lỗi khi lấy chi tiết task từ Asana: " . $response->get_error_message();
		}
		
		return $response['data'];
	}

	public function ajax_get_debug_logs() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
		$log_file = constant( 'WP_CONTENT_DIR' ) . '/debug.log';
		if ( ! file_exists( $log_file ) ) {
			wp_send_json_success( 'Chưa có dữ liệu log (debug.log không tồn tại).' );
		}
		$content = file_get_contents( $log_file );
		$content = mb_substr( $content, -2000 );
		$lines = explode( "\n", $content );
		$atd_logs = array();
		foreach ( $lines as $line ) {
			if ( strpos( $line, 'ATD Debug' ) !== false ) {
				$atd_logs[] = esc_html( $line );
			}
		}
		wp_send_json_success( implode( "\n", $atd_logs ) ?: 'Không có dòng log ATD Debug nào gần đây.' );
	}

	public function ajax_clear_debug_logs() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
		$log_file = constant( 'WP_CONTENT_DIR' ) . '/debug.log';
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}
		wp_send_json_success( 'Đã xóa log debug!' );
	}

	/**
	 * AI-04: Xử lý AJAX Auto-briefing mỗi sáng.
	 * Chỉ thực hiện 1 lần/ngày theo user_meta 'atd_last_briefing_date'.
	 */
	public function ajax_auto_briefing_handler() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

		$force   = isset( $_POST['force'] ) ? rest_sanitize_boolean( $_POST['force'] ) : false;
		$user_id = get_current_user_id();
		$today   = current_time( 'Y-m-d' );
		
		$last_briefing = get_user_meta( $user_id, 'atd_last_briefing_date', true );

		if ( ! $force && $last_briefing === $today ) {
			wp_send_json_success( array( 'status' => 'already_done' ) );
		}

		// Nếu AI chưa cấu hình API Key, dùng fallback
		if ( empty( $this->api_key ) ) {
			$fallback = $this->get_daily_briefing_fallback();
			update_user_meta( $user_id, 'atd_last_briefing_date', $today );
			$this->save_chat_history( 'Briefing hôm nay của tôi là gì?', $fallback );
			
			wp_send_json_success( array(
				'status'    => 'success',
				'reply'     => $fallback,
				'suni_name' => $this->assistant_name
			) );
		}

		// AI-13: Lấy danh sách mention chưa đọc
		$unread_mentions = $this->get_unread_mentions();
		$mention_str = '';
		if ( ! empty( $unread_mentions ) ) {
			$mention_str = "\n[CÓ MENTION MỚI CẦN XỬ LÝ]\nBạn có " . count( $unread_mentions ) . " mention mới chưa đọc:\n";
			foreach ( $unread_mentions as $idx => $m ) {
				$num = $idx + 1;
				$mention_str .= "$num. **{$m['mentioned_by']}** đã nhắc bạn trong task \"{$m['task_name']}\":\n";
				$mention_str .= "   \"{$m['comment_text']}\"\n";
			}
			$mention_str .= "\nHãy tóm tắt các mention này trong phần ĐẦU TIÊN của báo cáo và hỏi xem anh ấy có muốn trả lời không trước khi đi vào báo cáo các task quá hạn.\n";
		}

		// AI-03 & AI-14: Gọi OpenAI để request Auto-briefing
		$prompt = "Hãy tạo báo cáo briefing đầy đủ và chi tiết cho anh {$this->user_name}.
Yêu cầu định dạng báo cáo như sau:
1. Chào ngày mới năng lượng.$mention_str
2. Báo cáo tình hình QUÁ HẠN:
   - Dựa vào con số 'Tổng số task quá hạn' trong [TASK CONTEXT], hãy nêu rõ: 'Hiện tại anh đang còn {X} việc đã quá deadline cần xử lý gấp.'
   - Liệt kê 5 việc có thời gian quá sâu nhất (5 việc đầu tiên trong danh sách QUÁ HẠN của ngữ cảnh).
3. Báo cáo tình hình HÔM NAY:
   - Nêu số lượng task đến hạn hôm nay: 'Có {Y} việc đến hạn ngày hôm nay.'
   - Liệt kê đầy đủ tên của tất cả các việc này.
4. Tóm tắt các mục khác:
   - 'Có {Z} việc sắp tới trong tuần.' (Chỉ nêu số lượng, không liệt kê).
   - 'Có {W} việc cần phải set due date.' (Chỉ nêu số lượng, không liệt kê).
5. Kết luận: Đánh giá nhanh về khối lượng công việc và hỏi xem anh muốn ưu tiên xử lý mục nào trước.

Lưu ý: Dùng emoji phù hợp, trình bày ngắn gọn nhưng phải ĐẦY ĐỦ số lượng như yêu cầu.";

		$response = $this->query_openai( $prompt );

		update_user_meta( $user_id, 'atd_last_briefing_date', $today );
		
		// Ẩn request thực tế, chỉ lưu log là user hỏi briefing để lịch sử tự nhiên
		$this->save_chat_history( 'Tóm tắt công việc đầu ngày cho tôi nhé.', $response );

		wp_send_json_success( array(
			'status'    => 'success',
			'reply'     => $response,
			'suni_name' => $this->assistant_name
		) );
	}

	/**
	 * AI-07: Soạn thảo bình luận nhắc nhở chuyên nghiệp.
	 */
	public function ajax_ai_draft_comment() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

		$task_gid = isset( $_POST['task_gid'] ) ? sanitize_text_field( $_POST['task_gid'] ) : '';
		if ( ! $task_gid ) wp_send_json_error( 'Missing task GID' );

		$asana_api = new ATD_Asana_API();
		$task_res  = $asana_api->get_task_detail( $task_gid );

		if ( is_wp_error( $task_res ) ) {
			wp_send_json_error( $task_res->get_error_message() );
		}

		$task = $task_res['data'];
		$name = isset( $task['name'] ) ? $task['name'] : 'Không tên';
		$assignee = isset( $task['assignee']['name'] ) ? $task['assignee']['name'] : 'Mọi người';
		$due = isset( $task['due_on'] ) ? $task['due_on'] : 'Chưa rõ';

		$prompt = "Hãy đóng vai là {$this->user_name}, một người quản lý chuyên nghiệp và lịch sự.
Soạn MỘT bình luận NGẮN GỌN (tối đa 3-4 câu) bằng tiếng Việt để nhắc nhở người phụ trách làm task này trên Asana.
CHỈ TRẢ VỀ NỘI DUNG BÌNH LUẬN. Không có thêm phần giải thích.
Thông tin task:
- Tên: {$name}
- Người phụ trách: {$assignee}
- Hạn chót: {$due}";

		$draft = $this->query_openai( $prompt );

		wp_send_json_success( array(
			'draft' => trim( $draft )
		) );
	}

	/**
	 * AI-08: Đăng bình luận thật sự lên Asana.
	 */
	public function ajax_ai_post_comment() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

		$task_gid   = isset( $_POST['task_gid'] ) ? sanitize_text_field( $_POST['task_gid'] ) : '';
		$text       = isset( $_POST['comment_text'] ) ? sanitize_textarea_field( $_POST['comment_text'] ) : '';
		$mention_id = isset( $_POST['mention_id'] ) ? intval( $_POST['mention_id'] ) : 0;

		if ( ! $task_gid || empty( $text ) ) wp_send_json_error( 'Missing data' );

		$asana_api = new ATD_Asana_API();
		$response  = $asana_api->create_story( $task_gid, $text );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		// AI-16: Đánh dấu mention là đã đọc nếu là lệnh reply mention
		if ( $mention_id > 0 ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'atd_mentions';
			$wpdb->update( 
				$table_name, 
				array( 'is_read' => 1 ), 
				array( 'id' => $mention_id ), 
				array( '%d' ), 
				array( '%d' ) 
			);
		}

		wp_send_json_success( 'Đã đăng bình luận thành công lên Asana.' );
	}

	private function process_message_fallback( $message ) {
		$message_lower = mb_strtolower( $message, 'UTF-8' );
		if ( strpos( $message_lower, 'chào' ) !== false || strpos( $message_lower, 'hi' ) !== false ) return $this->get_daily_briefing_fallback();
		if ( strpos( $message_lower, 'hôm nay' ) !== false ) return "⏰ Việc hôm nay của anh nè: " . count( $this->get_today_tasks() ) . " việc.";
		return "Em {$this->assistant_name} nghe đây ạ! Anh cấu hình OpenAI API Key để em thông minh hơn nhé! 😊";
	}

	/**
	 * AI-13: Lấy danh sách mention chưa đọc.
	 */
	private function get_unread_mentions() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_mentions';
		
		// Đảm bảo bảng tồn tại trước khi select
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return array();
		}

		$sql = "SELECT id as mention_id, task_gid, task_name, story_gid, mentioned_by, comment_text, created_at 
				FROM $table_name 
				WHERE is_read = 0 
				ORDER BY created_at DESC 
				LIMIT 20";
				
		return $wpdb->get_results( $sql, 'ARRAY_A' );
	}

	/**
	 * AI-15: AJAX API trả về danh sách mentions (dùng cho Frontend Dashboard).
	 */
	public function ajax_get_mentions() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_mentions';
		$status     = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'unread';
		$limit      = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 20;

		$where = '';
		if ( $status === 'unread' ) {
			$where = 'WHERE is_read = 0';
		} elseif ( $status === 'read' ) {
			$where = 'WHERE is_read = 1';
		}

		$sql     = "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT %d";
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), 'ARRAY_A' );

		wp_send_json_success( $results );
	}

	/**
	 * AI-16: Tạo bản nháp trả lời mention bằng AI (trước khi post thật).
	 */
	public function ajax_reply_mention() {
		check_ajax_referer( 'atd_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );

		$mention_id = isset( $_POST['mention_id'] ) ? intval( $_POST['mention_id'] ) : 0;
		$reply_text = isset( $_POST['reply_text'] ) ? sanitize_textarea_field( $_POST['reply_text'] ) : '';

		if ( ! $mention_id || empty( $reply_text ) ) wp_send_json_error( 'Thiếu thông tin mention hoặc nội dung trả lời.' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'atd_mentions';
		$mention = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $mention_id ), ARRAY_A );

		if ( ! $mention ) wp_send_json_error( 'Không tìm thấy mention này trong cơ sở dữ liệu.' );

		$prompt = "Hãy đóng vai là {$this->user_name}, một người chuyên nghiệp và lịch sự.
Đồng nghiệp tên là {$mention['mentioned_by']} vừa nhắc bạn với nội dung: \"{$mention['comment_text']}\".
Ý chính bạn muốn trả lời là: \"{$reply_text}\".
Hãy viết lại câu trả lời trên sao cho tự nhiên, thân thiện và đầy đủ ngữ nghĩa nhất.
CHỈ TRẢ VỀ NỘI DUNG BÌNH LUẬN TRỰC TIẾP để đăng lên Asana. Dùng tag asana mention nếu cần. Không kèm lời giải thích thừa.";

		$draft = $this->query_openai( $prompt );

		wp_send_json_success( array(
			'draft'      => trim( $draft ),
			'task_gid'   => $mention['task_gid'],
			'mention_id' => $mention_id
		) );
	}

	private function get_daily_briefing_fallback() {
		$tasks = $this->get_today_tasks();
		return "Chào anh {$this->user_name}! Hôm nay anh có " . count( $tasks ) . " việc cần làm nè. Anh cần em hỗ trợ gì không?";
	}
}
