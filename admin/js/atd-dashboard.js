/**
 * Asana Teams Dashboard — Admin JavaScript
 * File: admin/js/atd-dashboard.js
 */

/* global jQuery, ajaxurl, atdConfig, marked */

jQuery( document ).ready( function ( $ ) {

	var syncIntervalSetting = atdConfig.syncInterval;
	var syncTimer           = syncIntervalSetting;
	var syncIntervalID;
	var isSyncing           = false;
	var isAssistantThinking = false;

	// =========================================================
	// SYNC TIMER
	// =========================================================

	function startTimer() {
		syncIntervalID = setInterval( function () {
			syncTimer--;
			$( '#atd-timer' ).text( syncTimer );
			if ( syncTimer <= 0 ) {
				performSync( true );
			}
		}, 1000 );
	}

	function resetTimer() {
		clearInterval( syncIntervalID );
		syncTimer = syncIntervalSetting;
		$( '#atd-timer' ).text( syncTimer );
		startTimer();
	}

	startTimer();

	// =========================================================
	// SYNC
	// =========================================================

	function performSync( isAuto ) {
		if ( isSyncing ) {
			return;
		}
		isSyncing = true;

		var btn    = $( '#atd-refresh-btn' );
		var status = $( '#atd-sync-status' );

		btn.prop( 'disabled', true ).find( '.dashicons' ).addClass( 'is-active' );
		status.text( atdConfig.i18n.syncing ).show().css( 'color', 'inherit' );

		$.post( ajaxurl, {
			action: 'atd_manual_sync',
			nonce:  atdConfig.nonce
		}, function ( response ) {
			isSyncing = false;
			btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'is-active' );

			if ( response.success ) {
				status.text( response.data ).css( 'color', 'green' );
				refreshTaskBoard();
				if ( isAuto ) {
					resetTimer();
				}
				setTimeout( function () {
					status.fadeOut();
				}, 3000 );
			} else {
				status.text( atdConfig.i18n.errorPrefix + response.data ).css( 'color', 'red' );
				if ( isAuto ) {
					resetTimer();
				}
			}
		} ).fail( function () {
			isSyncing = false;
			btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'is-active' );
			status.text( atdConfig.i18n.networkError ).css( 'color', 'red' );
			if ( isAuto ) {
				resetTimer();
			}
		} );
	}

	$( '#atd-refresh-btn' ).on( 'click', function () {
		performSync( false );
	} );

	// =========================================================
	// TASK BOARD REFRESH
	// =========================================================

	function refreshTaskBoard() {
		var typeFilter = $( '#atd-filter-type' ).val();

		$.post( ajaxurl, {
			action:      'atd_fetch_dashboard_table',
			nonce:       atdConfig.nonce,
			type_filter: typeFilter !== 'all' ? typeFilter : ''
		}, function ( response ) {
			if ( response.success ) {
				$( '#atd-task-board-container' ).html( response.data.html );
				if ( response.data.summary ) {
					updateSummaryBar( response.data.summary );
				}
				restoreCollapseState();
				applyClientFilter();
			}
		} );
	}

	function updateSummaryBar( summary ) {
		$( '#atd-stat-total' ).text( summary.total );
		$( '#atd-stat-overdue' ).text( summary.overdue );
		$( '#atd-stat-today' ).text( summary.today );
		$( '#atd-stat-upcoming' ).text( summary.upcoming );
	}

	// =========================================================
	// COLLAPSE / EXPAND GROUPS
	// =========================================================

	var COLLAPSE_KEY = 'atd_collapse_state';

	function getCollapseState() {
		try {
			return JSON.parse( localStorage.getItem( COLLAPSE_KEY ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function saveCollapseState( group, isCollapsed ) {
		var state      = getCollapseState();
		state[ group ] = isCollapsed;
		localStorage.setItem( COLLAPSE_KEY, JSON.stringify( state ) );
	}

	function restoreCollapseState() {
		var state = getCollapseState();
		$( '.atd-task-group' ).each( function () {
			var $group = $( this );
			var group  = $group.data( 'group' );
			if ( state[ group ] ) {
				$group.addClass( 'is-collapsed' );
			}
		} );
	}

	$( document ).on( 'click', '.atd-group-header', function () {
		var $group     = $( this ).closest( '.atd-task-group' );
		var group      = $group.data( 'group' );
		var collapsed  = $group.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );
		saveCollapseState( group, collapsed );
	} );

	restoreCollapseState();

	// =========================================================
	// USER GUIDE MODAL
	// =========================================================
	$( '#atd-guide-btn' ).on( 'click', function () {
		$( '#atd-guide-modal' ).fadeIn( 200 );
	} );

	$( '.atd-modal-close-guide' ).on( 'click', function () {
		$( '#atd-guide-modal' ).fadeOut( 200 );
	} );

	$( '#atd-guide-modal' ).on( 'click', function ( e ) {
		if ( $( e.target ).is( '#atd-guide-modal' ) ) {
			$( this ).fadeOut( 200 );
		}
	} );

	// =========================================================
	// CLIENT-SIDE FILTER & SEARCH
	// =========================================================

	function applyClientFilter() {
		var search    = $( '#atd-search-input' ).val().toLowerCase().trim();
		var projectFilter = $( '#atd-filter-project' ).val();

		$( '.atd-task-card' ).each( function () {
			var $card    = $( this );
			var name     = $card.data( 'name' ) ? $card.data( 'name' ).toLowerCase() : '';
			var project  = $card.data( 'project' ) || '';

			var matchSearch  = ! search || name.indexOf( search ) !== -1;
			var matchProject = ! projectFilter || projectFilter === 'all' || project === projectFilter;

			if ( matchSearch && matchProject ) {
				$card.show();
			} else {
				$card.hide();
			}
		} );
	}

	$( '#atd-search-input' ).on( 'input', applyClientFilter );
	$( '#atd-filter-project' ).on( 'change', applyClientFilter );
	$( '#atd-filter-type' ).on( 'change', function () {
		var val = $( this ).val();
		if ( val === 'created' || val === 'following' ) {
			alert( 'Dữ liệu này yêu cầu tài khoản Asana Premium (Gói trả phí) để lấy dữ liệu toàn bộ Workspace. Plugin sẽ ưu tiên hiển thị các Task được giao (Assigned) cho anh.' );
			$( this ).val( 'all' );
			return;
		}
		refreshTaskBoard();
	} );

	// =========================================================
	// COMPLETE TASK
	// =========================================================

	$( document ).on( 'click', '.atd-complete-btn', function () {
		var btn = $( this );
		var gid = btn.data( 'gid' );

		if ( ! confirm( atdConfig.i18n.confirmComplete ) ) {
			return;
		}

		btn.prop( 'disabled', true ).text( '...' );

		$.post( ajaxurl, {
			action:   'atd_complete_task',
			task_gid: gid,
			nonce:    atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				$( '#task-card-' + gid ).fadeOut( 300, function () {
					$( this ).remove();
				} );
			} else {
				alert( atdConfig.i18n.errorPrefix + response.data );
				btn.prop( 'disabled', false ).text( atdConfig.i18n.complete );
			}
		} ).fail( function () {
			alert( atdConfig.i18n.networkError );
			btn.prop( 'disabled', false ).text( atdConfig.i18n.complete );
		} );
	} );

	// =========================================================
	// ANALYZE TASK (AI)
	// =========================================================

	$( document ).on( 'click', '.atd-analyze-btn', function () {
		var btn  = $( this );
		var gid  = btn.data( 'gid' );
		var name = btn.closest( '.atd-task-card' ).data( 'name' );

		if ( isAssistantThinking ) {
			return;
		}

		// Simulate user message
		var userRequestText = 'Hãy phân tích chi tiết task: "' + name + '"';
		appendChatMessage( atdConfig.userLabel, userRequestText, 'user' );

		// Disable form
		isAssistantThinking = true;
		var $input = $( '#atd-chat-input-field' );
		var $sendBtn = $( '#atd-chat-send-btn' );
		$input.prop( 'disabled', true );
		$sendBtn.prop( 'disabled', true ).text( '...' );

		// Show loading
		var loadingId = 'loading-' + Date.now();
		var html = '<div id="' + loadingId + '" class="atd-msg suni-msg">'
			+ '<div class="atd-msg-bubble">'
			+ '<span class="atd-msg-sender">' + atdConfig.assistantName + '</span>'
			+ '<div class="atd-msg-content"><span class="dashicons dashicons-ellipsis"></span> Đang tổng hợp dữ liệu...</div>'
			+ '</div></div>';
		$( '#atd-chat-messages' ).append( html ).scrollTop( $( '#atd-chat-messages' )[ 0 ].scrollHeight );

		// Get task details
		$.post( ajaxurl, {
			action:   'atd_fetch_task_detail',
			task_gid: gid,
			nonce:    atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				var data = response.data;
				var taskDesc = data.task.notes || 'Không có mô tả.';
				var commentsStr = '';
				
				if ( data.stories && data.stories.length > 0 ) {
					var count = 1;
					data.stories.forEach( function( st ) {
						if ( st.type === 'comment' && st.text ) {
							var author = st.created_by ? st.created_by.name : 'Unknown';
							commentsStr += '\n[' + count + '] ' + author + ': ' + st.text;
							count++;
						}
					} );
				}
				if ( commentsStr === '' ) {
					commentsStr = 'Không có bình luận nào.';
				}

				// Phân tích Subtasks
				var subtasksStr = '';
				if ( data.subtasks && data.subtasks.length > 0 ) {
					data.subtasks.forEach( function( sub, idx ) {
						var subAssignee = sub.assignee ? sub.assignee.name : 'Chưa giao';
						var subStatus = sub.completed ? '✅ Hoàn thành' : '⏳ Đang làm';
						subtasksStr += '\n- ' + (idx + 1) + '. ' + sub.name + ' (Người làm: ' + subAssignee + ') [' + subStatus + ']';
					} );
				}
				if ( subtasksStr === '' ) {
					subtasksStr = 'Không có task con.';
				}

				// Build system prompt for AI
				var aiPrompt = "Dưới đây là DỮ LIỆU ĐẦY ĐỦ của Task mà anh " + atdConfig.userLabel + " vừa yêu cầu phân tích.\n"
					+ "Tên Task: " + name + "\n"
					+ "Mô tả gốc:\n" + taskDesc + "\n\n"
					+ "Các task con (Subtasks) và người phụ trách:\n" + subtasksStr + "\n\n"
					+ "Các bình luận/trao đổi:\n" + commentsStr + "\n\n"
					+ "Hãy đọc tất cả và tóm tắt theo sườn: 1. Mục tiêu task 2. Các mảng việc đang triển khai (dựa trên subtasks, nêu rõ Dev nào đang phụ trách phần nào) 3. Tình trạng tiến độ hiện tại 4. Khó khăn/Rủi ro nếu có. Trình bày ngắn gọn, dễ hiểu, có emoji.";

				// Send to AI (hide prompt from UI, only send to backend)
				$.ajax( {
					url:  ajaxurl,
					type: 'POST',
					data: {
						action:  'atd_assistant_chat',
						message: aiPrompt,
						nonce:   atdConfig.nonce
					},
					success: function ( aiResponse ) {
						$( '#' + loadingId ).remove();
						isAssistantThinking = false;
						$input.prop( 'disabled', false ).focus();
						$sendBtn.prop( 'disabled', false ).text( atdConfig.i18n.send );

						if ( aiResponse.success ) {
							appendChatMessage( aiResponse.data.suni_name, aiResponse.data.reply, 'suni' );
							fetchDebugLogs();
						} else {
							appendChatMessage( atdConfig.assistantName, atdConfig.i18n.chatError + aiResponse.data, 'suni' );
						}
					},
					error: function () {
						$( '#' + loadingId ).remove();
						isAssistantThinking = false;
						$input.prop( 'disabled', false ).focus();
						$sendBtn.prop( 'disabled', false ).text( atdConfig.i18n.send );
						appendChatMessage( atdConfig.assistantName, atdConfig.i18n.networkError, 'suni' );
					}
				} );

			} else {
				$( '#' + loadingId ).remove();
				isAssistantThinking = false;
				$input.prop( 'disabled', false ).focus();
				$sendBtn.prop( 'disabled', false ).text( atdConfig.i18n.send );
				appendChatMessage( atdConfig.assistantName, "Lỗi khi fetching dữ liệu Task từ Asana: " + response.data, 'suni' );
			}
		} ).fail( function () {
			$( '#' + loadingId ).remove();
			isAssistantThinking = false;
			$input.prop( 'disabled', false ).focus();
			$sendBtn.prop( 'disabled', false ).text( atdConfig.i18n.send );
			appendChatMessage( atdConfig.assistantName, atdConfig.i18n.networkError, 'suni' );
		} );
	} );

	// =========================================================
	// AI DRAFT & POST COMMENT ASANA
	// =========================================================

	$( document ).on( 'click', '.atd-draft-comment-btn', function () {
		var btn  = $( this );
		var gid  = btn.data( 'gid' );
		var name = btn.closest( '.atd-task-card' ).data( 'name' );

		if ( btn.prop( 'disabled' ) ) return;

		btn.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update is-active"></span> Đang soạn...' );

		$.post( ajaxurl, {
			action:   'atd_ai_draft_comment',
			task_gid: gid,
			nonce:    atdConfig.nonce
		}, function ( response ) {
			btn.prop( 'disabled', false ).html( '🤖 Nhắc nhở' );
			if ( response.success ) {
				showCommentEditorModal( gid, name, response.data.draft );
			} else {
				alert( 'Lỗi AI: ' + response.data );
			}
		} ).fail( function () {
			btn.prop( 'disabled', false ).html( '🤖 Nhắc nhở' );
			alert( atdConfig.i18n.networkError );
		} );
	} );

	function showCommentEditorModal( gid, taskName, draftText ) {
		$( '.atd-modal-overlay' ).remove();

		var html = '<div class="atd-modal-overlay">'
			+ '<div class="atd-modal">'
			+ '<h3>Duyệt bình luận cho task: ' + taskName + '</h3>'
			+ '<textarea id="atd-comment-editor">' + draftText + '</textarea>'
			+ '<div class="atd-modal-actions">'
			+ '<button class="button atd-modal-cancel">Hủy bỏ</button>'
			+ '<button class="button button-primary atd-modal-post" data-gid="' + gid + '">Đăng lên Asana</button>'
			+ '</div>'
			+ '</div>'
			+ '</div>';

		$( 'body' ).append( html );
	}

	$( document ).on( 'click', '.atd-modal-cancel', function () {
		$( '.atd-modal-overlay' ).remove();
	} );

	$( document ).on( 'click', '.atd-modal-post', function () {
		var btn  = $( this );
		var gid  = btn.data( 'gid' );
		var text = $( '#atd-comment-editor' ).val().trim();

		if ( ! text ) {
			alert( 'Vui lòng nhập nội dung bình luận.' );
			return;
		}

		btn.prop( 'disabled', true ).text( 'Đang đăng...' );

		$.post( ajaxurl, {
			action:       'atd_ai_post_comment',
			task_gid:     gid,
			comment_text: text,
			nonce:        atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				alert( response.data );
				$( '.atd-modal-overlay' ).remove();
				// Báo tin vui trên khung chat luôn
				appendChatMessage( atdConfig.assistantName, 'Em đã thay mặt anh đăng bình luận lên Asana thành công rồi ạ! ✅', 'suni' );
			} else {
				alert( 'Lỗi khi đăng bình luận: ' + response.data );
				btn.prop( 'disabled', false ).text( 'Đăng lên Asana' );
			}
		} ).fail( function () {
			alert( atdConfig.i18n.networkError );
			btn.prop( 'disabled', false ).text( 'Đăng lên Asana' );
		} );
	} );

	// =========================================================
	// QUICK ADD TASK
	// =========================================================

	$( '#atd-quick-add-form' ).on( 'submit', function( e ) {
		e.preventDefault();

		var btn   = $( '#atd-submit-new-task-btn' );
		var name  = $( '#atd-new-task-name' ).val().trim();
		var due   = $( '#atd-new-task-due' ).val().trim();
		var notes = $( '#atd-new-task-notes' ).val().trim();

		if ( ! name || ! due ) {
			alert( 'Vui lòng nhập tên công việc và ngày hết hạn.' );
			return;
		}

		btn.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update is-active"></span> Đang tạo...' );

		$.post( ajaxurl, {
			action: 'atd_create_task',
			nonce:  atdConfig.nonce,
			name:   name,
			due_on: due,
			notes:  notes
		}, function( response ) {
			if ( response.success ) {
				// Xóa form
				$( '#atd-new-task-name' ).val( '' );
				$( '#atd-new-task-due' ).val( '' );
				$( '#atd-new-task-notes' ).val( '' );
				btn.prop( 'disabled', false ).html( '<span class="dashicons dashicons-plus-alt2"></span> Tạo Task' );

				// Bật đồng bộ để load task mới về list
				performSync( false );
				appendChatMessage( atdConfig.assistantName, 'Em đã tạo task "' + name + '" trên Asana thành công rồi nha! ✅', 'suni' );
			} else {
				alert( response.data );
				btn.prop( 'disabled', false ).html( '<span class="dashicons dashicons-plus-alt2"></span> Tạo Task' );
			}
		} ).fail( function() {
			alert( atdConfig.i18n.networkError );
			btn.prop( 'disabled', false ).html( '<span class="dashicons dashicons-plus-alt2"></span> Tạo Task' );
		} );
	} );

	// =========================================================
	// CLEAR DATA
	// =========================================================

	$( '#atd-clear-btn' ).on( 'click', function () {
		if ( ! confirm( atdConfig.i18n.confirmClear ) ) {
			return;
		}

		var btn    = $( this );
		var status = $( '#atd-sync-status' );

		btn.prop( 'disabled', true );
		status.text( atdConfig.i18n.clearing ).show();

		$.post( ajaxurl, {
			action: 'atd_clear_data',
			nonce:  atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				status.text( response.data ).css( 'color', 'green' );
				setTimeout( function () {
					location.reload();
				}, 1000 );
			} else {
				status.text( atdConfig.i18n.errorPrefix + response.data ).css( 'color', 'red' );
				btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			status.text( atdConfig.i18n.networkError ).css( 'color', 'red' );
			btn.prop( 'disabled', false );
		} );
	} );

	// =========================================================
	// CHAT ASSISTANT
	// =========================================================

	function appendChatMessage( sender, text, type ) {
		var $chatBox = $( '#atd-chat-messages' );
		var processed;

		if ( type === 'suni' && typeof marked !== 'undefined' ) {
			processed = marked.parse( text );
		} else {
			processed = $( '<div/>' ).text( text ).html().replace( /\n/g, '<br>' );
		}

		var align       = type === 'user' ? 'right' : 'left';
		var bubbleClass = type === 'user' ? 'user-msg' : 'suni-msg';

		var html = '<div class="atd-msg ' + bubbleClass + '">'
			+ '<div class="atd-msg-bubble">'
			+ '<span class="atd-msg-sender">' + sender + '</span>'
			+ '<div class="atd-msg-content">' + processed + '</div>'
			+ '</div>'
			+ '</div>';

		$chatBox.append( html );
		$chatBox.scrollTop( $chatBox[ 0 ].scrollHeight );
	}

	function sendToAssistant() {
		var $input  = $( '#atd-chat-input-field' );
		var $btn    = $( '#atd-chat-send-btn' );
		var message = $input.val().trim();

		if ( ! message || isAssistantThinking ) {
			return;
		}

		isAssistantThinking = true;
		$input.val( '' ).prop( 'disabled', true );
		$btn.prop( 'disabled', true ).text( '...' );

		appendChatMessage( atdConfig.userLabel, message, 'user' );

		$.ajax( {
			url:  ajaxurl,
			type: 'POST',
			data: {
				action:  'atd_assistant_chat',
				message: message,
				nonce:   atdConfig.nonce
			},
			success: function ( response ) {
				isAssistantThinking = false;
				$input.prop( 'disabled', false ).focus();
				$btn.prop( 'disabled', false ).text( atdConfig.i18n.send );

				if ( response.success ) {
					// Thử parse xem có phải là Action Object không
					try {
						var parsedReply = JSON.parse( response.data.reply );
						if ( parsedReply && parsedReply.status === 'action_required' ) {
							var assistantMsg = parsedReply.message_to_user || 'Em cần anh duyệt lệnh này ạ.';
							appendChatMessage( response.data.suni_name, assistantMsg, 'suni' );
							showActionConfirmModal( parsedReply );
							fetchDebugLogs();
							return;
						}
					} catch ( e ) {
						// Bỏ qua lỗi parse, xử lý tiếp như text bình thường
					}

					appendChatMessage( response.data.suni_name, response.data.reply, 'suni' );
					fetchDebugLogs();
				} else {
					appendChatMessage( atdConfig.assistantName, atdConfig.i18n.chatError + response.data, 'suni' );
				}
			},
			error: function () {
				isAssistantThinking = false;
				$input.prop( 'disabled', false ).focus();
				$btn.prop( 'disabled', false ).text( atdConfig.i18n.send );
				appendChatMessage( atdConfig.assistantName, atdConfig.i18n.networkError, 'suni' );
			}
		} );
	}

	$( '#atd-chat-send-btn' ).on( 'click', sendToAssistant );
	$( '#atd-chat-input-field' ).on( 'keypress', function ( e ) {
		if ( 13 === e.which ) {
			sendToAssistant();
			return false;
		}
	} );

	$( '#atd-clear-chat-btn' ).on( 'click', function () {
		if ( ! confirm( atdConfig.i18n.confirmClearChat ) ) {
			return;
		}

		$.post( ajaxurl, {
			action: 'atd_clear_chat_history',
			nonce:  atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				$( '#atd-chat-messages' ).html(
					'<div class="atd-msg suni-msg">'
					+ '<div class="atd-msg-bubble">'
					+ '<span class="atd-msg-sender">' + atdConfig.assistantName + '</span>'
					+ '<div class="atd-msg-content">' + atdConfig.i18n.chatCleared + '</div>'
					+ '</div>'
					+ '</div>'
				);
			}
		} );
	} );

	// Quick action buttons.
	$( '.atd-quick-action' ).on( 'click', function () {
		var action = $( this ).data( 'action' );
		var prefill = $( this ).data( 'prefill' );
		
		if ( action === 'auto_briefing' ) {
			// Trigger thủ công auto briefing
			triggerAutoBriefing( true );
		} else if ( prefill ) {
			$( '#atd-chat-input-field' ).val( prefill ).focus();
			sendToAssistant(); // Tự động gửi luôn khi click quick action
		}
	} );

	// =========================================================
	// AUTO BRIEFING LÚC LOAD TRANG
	// =========================================================
	
	function triggerAutoBriefing( force ) {
		// Gọi AJAX briefing mởi
		var $chatBox = $( '#atd-chat-messages' );
		
		// Hiện mốc loading
		var loadingId = 'loading-' + Date.now();
		var html = '<div id="' + loadingId + '" class="atd-msg suni-msg">'
			+ '<div class="atd-msg-bubble">'
			+ '<span class="atd-msg-sender">' + atdConfig.assistantName + '</span>'
			+ '<div class="atd-msg-content"><span class="dashicons dashicons-ellipsis"></span> Đang xem xét lịch trình...</div>'
			+ '</div></div>';
		$chatBox.append( html );
		$chatBox.scrollTop( $chatBox[ 0 ].scrollHeight );

		$.post( ajaxurl, {
			action: 'atd_auto_briefing',
			nonce:  atdConfig.nonce,
			force:  force ? 1 : 0
		}, function ( response ) {
			$( '#' + loadingId ).remove();
			
			if ( response.success && response.data.status !== 'already_done' ) {
				appendChatMessage( response.data.suni_name, response.data.reply, 'suni' );
				fetchDebugLogs();
			} else if ( response.success && response.data.status === 'already_done' && force ) {
				appendChatMessage( atdConfig.assistantName, 'Hôm nay em đã briefing cho anh rồi đó. Anh cần em kiểm tra lại lầu nữa hông?', 'suni' );
			}
		} ).fail( function () {
			$( '#' + loadingId ).remove();
		} );
	}

	// Tự động gọi briefing khi load trang, nếu chưa briefing hôm nay
	setTimeout( function() {
		triggerAutoBriefing( false );
	}, 1500 ); // Delay 1.5s cho UI render mượt mà trước khi gọi AI

	// =========================================================
	// DEBUG LOGS
	// =========================================================

	function fetchDebugLogs() {
		if ( ! atdConfig.enableDebugLog ) {
			return;
		}
		$.post( ajaxurl, {
			action: 'atd_get_debug_logs',
			nonce:  atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				var $logBox = $( '#atd-debug-logs' );
				$logBox.text( response.data );
				$logBox.scrollTop( $logBox[ 0 ].scrollHeight );
			}
		} );
	}

	$( '#atd-refresh-logs-btn' ).on( 'click', function () {
		var $icon = $( this ).find( '.dashicons' );
		$icon.addClass( 'is-active' );
		fetchDebugLogs();
		setTimeout( function () {
			$icon.removeClass( 'is-active' );
		}, 600 );
	} );

	$( '#atd-clear-logs-btn' ).on( 'click', function () {
		if ( ! confirm( atdConfig.i18n.confirmClearLogs ) ) {
			return;
		}
		$.post( ajaxurl, {
			action: 'atd_clear_debug_logs',
			nonce:  atdConfig.nonce
		}, function ( response ) {
			if ( response.success ) {
				$( '#atd-debug-logs' ).text( atdConfig.i18n.logsCleared );
			}
		} );
	} );

	// Tải log lần đầu khi page load.
	fetchDebugLogs();

	// Parse Markdown cho tin nhắn AI cũ trong lịch sử.
	if ( typeof marked !== 'undefined' ) {
		$( '.suni-msg .atd-msg-content[data-raw]' ).each( function () {
			var raw = $( this ).data( 'raw' );
			if ( raw ) {
				$( this ).html( marked.parse( raw ) );
			}
		} );
	}

	// =========================================================
	// ACTION REQUIRED MODAL (AI COMMAND MODE)
	// =========================================================

	function showActionConfirmModal( actionData ) {
		$( '.atd-modal-overlay' ).remove();

		var actionType = actionData.action_type;
		var args       = actionData.action_args;
		var html       = '';
		var title      = 'Xác nhận hành động AI';
		var content    = '';

		if ( actionType === 'complete_task' ) {
			title   = 'Đánh dấu hoàn thành Task';
			content = '<p>Anh đồng ý cho em <strong>đánh dấu hoàn thành</strong> Task Asana này (GID: ' + args.task_id + ') chứ?</p>';
		} else if ( actionType === 'set_due_date' ) {
			title   = 'Đổi Date Hạn Chót';
			content = '<p>Anh đồng ý cho em dời hạn chót của Task (GID: ' + args.task_id + ') sang ngày <strong>' + args.due_on + '</strong> chứ?</p>';
		} else if ( actionType === 'reply_mention' ) {
			title   = 'Trả lời Nhắc đến (Mention)';
			content = '<p>Anh đồng ý đăng bình luận này lên Asana chứ?</p><blockquote style="border-left:4px solid #ddd; padding-left:10px; font-style:italic;">' + args.reply_text + '</blockquote>';
		}

		html = '<div class="atd-modal-overlay">'
			+ '<div class="atd-modal">'
			+ '<h3>🤖 ' + title + '</h3>'
			+ content
			+ '<div class="atd-modal-actions" style="margin-top:20px;">'
			+ '<button class="button atd-modal-action-cancel">Từ chối</button>'
			+ '<button class="button button-primary atd-modal-action-confirm">Đồng ý</button>'
			+ '</div>'
			+ '</div>'
			+ '</div>';

		$( 'body' ).append( html );

		$( '.atd-modal-action-cancel' ).on( 'click', function () {
			$( '.atd-modal-overlay' ).remove();
			appendChatMessage( atdConfig.assistantName, 'Vâng ạ, em đã hủy bỏ thao tác đó rồi nhé.', 'suni' );
		} );

		$( '.atd-modal-action-confirm' ).on( 'click', function () {
			var btn = $( this );
			btn.prop( 'disabled', true ).text( 'Đang thực hiện...' );

			var ajaxAction = '';
			var ajaxData   = { nonce: atdConfig.nonce, task_gid: args.task_id };

			if ( actionType === 'complete_task' ) {
				ajaxAction = 'atd_complete_task';
			} else if ( actionType === 'set_due_date' ) {
				ajaxAction = 'atd_ai_set_due_date';
				ajaxData.due_on = args.due_on;
			} else if (actionType === 'reply_mention') {
				ajaxAction = 'atd_ai_post_comment';
				ajaxData.comment_text = args.reply_text;
				ajaxData.mention_id = args.mention_id;
			}

			ajaxData.action = ajaxAction;

			$.post( ajaxurl, ajaxData, function ( response ) {
				$( '.atd-modal-overlay' ).remove();
				if ( response.success ) {
					appendChatMessage( atdConfig.assistantName, 'Đã làm theo lệnh của anh trên Asana thành công! ✅', 'suni' );
					performSync( false ); // Đồng bộ lại list task
					if ( actionType === 'reply_mention' ) {
						loadMentions( 'unread' ); // Cập nhật lại số lượng mention
					}
				} else {
					appendChatMessage( atdConfig.assistantName, 'Hix, có lỗi xảy ra rồi: ' + response.data, 'suni' );
				}
			} ).fail( function () {
				$( '.atd-modal-overlay' ).remove();
				appendChatMessage( atdConfig.assistantName, atdConfig.i18n.networkError, 'suni' );
			} );
		} );
	}

	// =========================================================
	// MENTIONS NOTIFICATION SYSTEM (Phase 7)
	// =========================================================

	var currentMentionTab = 'unread';

	function loadMentions( status ) {
		var $list = $( '#atd-mentions-list' );
		var $badge = $( '#atd-mention-count-badge' );

		if ( ! $list.length ) return;

		$list.html( '<p style="text-align:center; color:#666;"><span class="spinner is-active" style="float:none;"></span> Đang tải...</p>' );

		$.post( ajaxurl, {
			action: 'atd_get_mentions',
			nonce:  atdConfig.nonce,
			status: status,
			limit:  20
		}, function( response ) {
			if ( response.success ) {
				var renderHTML = '';
				var mentions = response.data.mentions || [];
				
				// Cập nhật badge (chỉ khi load tab unread hoặc gọi chung)
				if ( status === 'unread' || response.data.unread_count !== undefined ) {
					var badgeCount = response.data.unread_count || (status === 'unread' ? mentions.length : 0);
					if ( badgeCount > 0 ) {
						$badge.text( badgeCount ).show();
					} else {
						$badge.hide();
					}
				}

				if ( mentions.length === 0 ) {
					$list.html( '<p style="padding: 20px; text-align: center; color: #888;">Không có thông báo nào ở mục này.</p>' );
					return;
				}

				mentions.forEach( function( m ) {
					var cardClass = m.is_read == '1' ? 'atd-mention-card read' : 'atd-mention-card unread';
					var timeStr   = m.created_at; // Tạm dùng yyyy-mm-dd
					
					var stripHtmlText = $( '<div/>' ).html( m.comment_text ).text();
					if ( stripHtmlText.length > 150 ) {
						stripHtmlText = stripHtmlText.substring( 0, 150 ) + '...';
					}

					renderHTML += '<div class="' + cardClass + '" style="background:#fff; border:1px solid #ddd; border-left:4px solid ' + (m.is_read == '1' ? '#aaa' : '#2271b1') + '; border-radius:4px; padding:12px; margin: 0 15px 15px 15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">'
						+ '<div style="display:flex; justify-content:space-between; margin-bottom: 8px;">'
						+ '<strong style="color:#2271b1;">' + m.mentioned_by + '</strong>'
						+ '<span style="font-size:11px; color:#999;">' + timeStr + '</span>'
						+ '</div>'
						+ '<div style="font-size:12px; margin-bottom: 8px;"><strong>Task:</strong> ' + m.task_name + '</div>'
						+ '<div style="background:#f5f5f5; padding:8px; border-radius:4px; font-style:italic; font-size:13px; margin-bottom: 10px;">"' + stripHtmlText + '"</div>'
						+ '<div style="display:flex; gap:8px;">'
						+ '<button class="button button-small atd-mention-reply-btn" data-id="' + m.mention_id + '" data-task="' + m.task_name + '" data-author="' + m.mentioned_by + '" ' + (m.is_read == '1' ? 'disabled' : '') + '>💬 Trả lời</button>'
						+ '</div>'
						+ '</div>';
				} );

				$list.html( renderHTML );
			} else {
				$list.html( '<p style="color:red; text-align:center;">Lỗi tải mentions: ' + response.data + '</p>' );
			}
		} ).fail( function() {
			$list.html( '<p style="color:red; text-align:center;">Lỗi kết nối.</p>' );
		} );
	}

	// Trigger load lúc vào dashboard
	setTimeout( function() {
		loadMentions( 'unread' );
	}, 1000 );

	// UI Events
	$( '#atd-mention-bell-btn' ).on( 'click', function() {
		$( '#atd-mentions-modal' ).fadeIn( 200 );
		if ( $( '#atd-mentions-list' ).find( '.atd-mention-card' ).length === 0 ) {
			loadMentions( currentMentionTab );
		}
	} );

	$( '.atd-modal-close-mentions' ).on( 'click', function() {
		$( '#atd-mentions-modal' ).fadeOut( 200 );
	} );

	$( '.atd-mention-tab' ).on( 'click', function() {
		$( '.atd-mention-tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		currentMentionTab = $( this ).data( 'status' );
		loadMentions( currentMentionTab );
	} );

	// Nút Trả lời mention (đẩy input vào Chat)
	$( document ).on( 'click', '.atd-mention-reply-btn', function() {
		var author = $( this ).data( 'author' );
		var taskId = $( this ).data( 'id' ); // Lưu mention id tạm vào đây để chat bot nhận diện nếu cần
		
		var chatInput = $( '#atd-chat-input-field' );
		var prefillText = 'Trả lời anh/chị ' + author + ' ở thông báo mention số [' + taskId + ']: "Oki..."';
		
		$( '#atd-mentions-modal' ).fadeOut( 100 );
		chatInput.val( prefillText ).focus();
		
		// Đổi style input để user chú ý
		chatInput.css( 'box-shadow', '0 0 5px #2271b1' );
		setTimeout( function() { chatInput.css( 'box-shadow', 'none' ); }, 1500 );
	} );

} );
