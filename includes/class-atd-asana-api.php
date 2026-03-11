<?php
/**
 * Class xử lý kết nối Asana API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATD_Asana_API {

	private $pat;
	private $base_url = 'https://app.asana.com/api/1.0';

	public function __construct() {
		$this->pat = get_option( 'atd_asana_pat' );
	}

	/**
	 * Gửi request đến Asana API.
	 */
	public function request( $endpoint, $args = array() ) {
		if ( empty( $this->pat ) ) {
			return new WP_Error( 'missing_pat', __( 'Chưa cấu hình Asana PAT.', 'asana-teams-dashboard' ) );
		}

		$url = $this->base_url . $endpoint;

		$defaults = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->pat,
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		);

		if ( isset( $args['headers'] ) ) {
			$args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
		}

		$args     = wp_parse_args( $args, $defaults );
		$response = wp_remote_request( $url, $args );

		mf_atd_log( 'ATD Debug: Request URL: ' . $url );
		mf_atd_log( 'ATD Debug: Request Method: ' . ( isset( $args['method'] ) ? $args['method'] : 'GET' ) );
		if ( ! is_wp_error( $response ) ) {
			mf_atd_log( 'ATD Debug: Response Code: ' . wp_remote_retrieve_response_code( $response ) );
		} else {
			mf_atd_log( 'ATD Debug: Response Error: ' . $response->get_error_message() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		// Trả về http_code kèm theo để caller có thể xử lý lỗi cụ thể.
		if ( 401 === $http_code ) {
			return new WP_Error( 'asana_unauthorized', __( 'Lỗi xác thực Asana: Token (PAT) không hợp lệ hoặc đã hết hạn.', 'asana-teams-dashboard' ), array( 'status' => 401 ) );
		}

		if ( 402 === $http_code ) {
			return new WP_Error( 'premium_required', __( 'Tính năng này yêu cầu Asana Premium.', 'asana-teams-dashboard' ), array( 'status' => 402 ) );
		}

		if ( isset( $data['errors'] ) ) {
			mf_atd_log( 'ATD Debug: Response Body Error: ' . $data['errors'][0]['message'] );
			return new WP_Error( 'asana_api_error', $data['errors'][0]['message'] );
		}

		return $data;
	}

	/**
	 * Lấy thông tin User hiện tại (me).
	 * API-01: Dùng để lấy user_gid, lưu vào options.
	 */
	public function get_me() {
		$response = $this->request( '/users/me' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_data = $response['data'];

		// Lưu user_gid để các method khác dùng lại, tránh gọi API nhiều lần.
		if ( ! empty( $user_data['gid'] ) ) {
			update_option( 'atd_asana_user_gid', sanitize_text_field( $user_data['gid'] ) );
		}

		return $user_data;
	}

	/**
	 * Lấy user_gid từ options, gọi get_me() nếu chưa có.
	 */
	private function get_user_gid() {
		$user_gid = get_option( 'atd_asana_user_gid' );
		if ( ! $user_gid ) {
			$me = $this->get_me();
			if ( is_wp_error( $me ) ) {
				return $me;
			}
			$user_gid = $me['gid'];
		}
		return $user_gid;
	}

	/**
	 * Lấy danh sách task được giao (Assigned to me) với phân trang.
	 * Endpoint: GET /tasks?assignee=me&workspace={gid}&completed_since=now
	 */
	public function get_assigned_tasks( $limit = 50, $offset = '' ) {
		$workspace_id = get_option( 'atd_asana_workspace_id' );
		if ( ! $workspace_id ) {
			return new WP_Error( 'missing_workspace', __( 'Chưa cấu hình Workspace GID.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks?assignee=me&workspace=' . $workspace_id . '&completed_since=now&limit=' . $limit;
		if ( $offset ) {
			$endpoint .= '&offset=' . $offset;
		}
		$endpoint .= '&opt_fields=name,due_on,projects.name,notes,assignee.name,permalink_url,resource_subtype,modified_at,created_at,completed,followers';

		return $this->request( $endpoint );
	}

	/**
	 * API-04: Lấy thông tin chi tiết một task (đầy đủ fields).
	 * Endpoint: GET /tasks/{gid}?opt_fields=...
	 */
	public function get_task_detail( $task_gid ) {
		$task_gid = sanitize_text_field( $task_gid );
		if ( ! $task_gid ) {
			return new WP_Error( 'missing_gid', __( 'Thiếu Task GID.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks/' . $task_gid . '?opt_fields=name,due_on,projects.name,notes,html_notes,assignee.name,permalink_url,created_at,modified_at,completed,followers,num_subtasks';

		return $this->request( $endpoint );
	}

	/**
	 * API-05: Lấy danh sách stories (comments) của một task.
	 * Endpoint: GET /tasks/{gid}/stories
	 * Filter type=comment phía PHP.
	 */
	public function get_task_stories( $task_gid ) {
		$task_gid = sanitize_text_field( $task_gid );
		if ( ! $task_gid ) {
			return new WP_Error( 'missing_gid', __( 'Thiếu Task GID.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks/' . $task_gid . '/stories?opt_fields=type,text,html_text,created_by.name,created_at';
		$response = $this->request( $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Chỉ trả về comment stories, bỏ qua system stories (type != comment).
		$comments = array();
		if ( ! empty( $response['data'] ) ) {
			foreach ( $response['data'] as $story ) {
				if ( isset( $story['type'] ) && 'comment' === $story['type'] ) {
					$comments[] = $story;
				}
			}
		}

		return $comments;
	}

	/**
	 * API-06: Post comment lên một task (tạo story).
	 * Endpoint: POST /tasks/{gid}/stories
	 * Yêu cầu scope: stories:write
	 */
	public function create_story( $task_gid, $text, $is_html = false ) {
		$task_gid = sanitize_text_field( $task_gid );

		if ( ! $task_gid || ! $text ) {
			return new WP_Error( 'missing_params', __( 'Thiếu Task GID hoặc nội dung comment.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks/' . $task_gid . '/stories';

		$body_data = array();
		if ( $is_html ) {
			// Asana requires html_text to be wrapped in <body> tags
			// We don't sanitize_textarea_field here because it might strip HTML tags
			$body_data['html_text'] = '<body>' . $text . '</body>';
		} else {
			$body_data['text'] = sanitize_textarea_field( $text );
		}

		$args = array(
			'method'  => 'POST',
			'body'    => wp_json_encode( array( 'data' => $body_data ) ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * API-07: Cập nhật trạng thái Task.
	 * Endpoint: PUT /tasks/{gid}
	 * Hỗ trợ: completed, due_on, name, notes, assignee
	 */
	public function update_task( $task_gid, $data ) {
		$task_gid = sanitize_text_field( $task_gid );
		if ( ! $task_gid ) {
			return new WP_Error( 'missing_gid', __( 'Thiếu Task GID.', 'asana-teams-dashboard' ) );
		}

		// Chỉ cho phép các field được whitelist để tránh ghi đè ngoài ý muốn.
		$allowed_fields = array( 'completed', 'due_on', 'name', 'notes', 'assignee' );
		$sanitized_data = array();

		foreach ( $allowed_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				if ( 'completed' === $field ) {
					$sanitized_data[ $field ] = (bool) $data[ $field ];
				} elseif ( 'due_on' === $field ) {
					// Validate định dạng Y-m-d hoặc null.
					if ( null === $data[ $field ] || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data[ $field ] ) ) {
						$sanitized_data[ $field ] = $data[ $field ];
					}
				} else {
					$sanitized_data[ $field ] = sanitize_text_field( $data[ $field ] );
				}
			}
		}

		if ( empty( $sanitized_data ) ) {
			return new WP_Error( 'no_valid_fields', __( 'Không có field hợp lệ để cập nhật.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks/' . $task_gid;

		$args = array(
			'method'  => 'PUT',
			'body'    => wp_json_encode( array( 'data' => $sanitized_data ) ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * Tạo task mới trên Asana.
	 * Endpoint: POST /tasks
	 */
	public function create_task( $data ) {
		$workspace_id = get_option( 'atd_asana_workspace_id' );
		if ( ! $workspace_id ) {
			return new WP_Error( 'missing_workspace', __( 'Chưa cấu hình Workspace GID.', 'asana-teams-dashboard' ) );
		}

		$data['workspace'] = $workspace_id;
		$endpoint          = '/tasks';

		$args = array(
			'method'  => 'POST',
			'body'    => wp_json_encode( array( 'data' => $data ) ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		return $this->request( $endpoint, $args );
	}
	
	/**
	 * Lấy danh sách task con (subtasks) của một task.
	 */
	public function get_subtasks( $task_gid ) {
		$task_gid = sanitize_text_field( $task_gid );
		if ( ! $task_gid ) {
			return new WP_Error( 'missing_gid', __( 'Thiếu Task GID.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks/' . $task_gid . '/subtasks?opt_fields=name,assignee.name,completed,due_on';

		return $this->request( $endpoint );
	}

	/**
	 * API-11: Lấy danh sách task từ một project (dùng cho bản Free).
	 * Endpoint: GET /tasks?project={gid}&completed_since=now
	 */
	public function get_project_tasks( $project_gid, $limit = 50, $offset = '' ) {
		$project_gid = sanitize_text_field( $project_gid );
		if ( ! $project_gid ) {
			return new WP_Error( 'missing_gid', __( 'Thiêu Project GID.', 'asana-teams-dashboard' ) );
		}

		$endpoint = '/tasks?project=' . $project_gid . '&completed_since=now&limit=' . $limit;
		if ( $offset ) {
			$endpoint .= '&offset=' . $offset;
		}
		
		// opt_fields: followers.gid để check collaborator, assignee.gid để skip assigned task
		$endpoint .= '&opt_fields=name,due_on,projects.name,notes,assignee.name,assignee.gid,permalink_url,resource_subtype,modified_at,created_at,completed,followers.gid';

		return $this->request( $endpoint );
	}

	/**
	 * Lấy danh sách project trong workspace.
	 */
	public function get_projects() {
		$workspace_id = get_option( 'atd_asana_workspace_id' );
		if ( ! $workspace_id ) {
			return new WP_Error( 'missing_workspace', __( 'Chưa cấu hình Workspace GID.', 'asana-teams-dashboard' ) );
		}
		return $this->request( '/projects?workspace=' . $workspace_id . '&archived=false' );
	}
}
