<?php
/**
 * Partial template: Task Board (grouped card layout).
 * Được include bởi dashboard.php và AJAX handler ajax_fetch_dashboard_table.
 *
 * Biến cần có trong scope:
 *   @var array  $grouped  Output của ATD_Cron::group_tasks_by_deadline()
 *   @var array  $projects Danh sách unique project names (chỉ dùng khi render từ dashboard.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$today = current_time( 'Y-m-d' );

$groups_config = array(
	'overdue'  => array(
		'label'      => __( '🔥 Quá hạn', 'asana-teams-dashboard' ),
		'class'      => 'is-overdue',
		'empty_text' => __( 'Không có task quá hạn. 🎉', 'asana-teams-dashboard' ),
	),
	'today'    => array(
		'label'      => __( '⏰ Hôm nay', 'asana-teams-dashboard' ),
		'class'      => 'is-today',
		'empty_text' => __( 'Không có task đến hạn hôm nay.', 'asana-teams-dashboard' ),
	),
	'upcoming' => array(
		'label'      => __( '📅 Sắp tới (7 ngày)', 'asana-teams-dashboard' ),
		'class'      => 'is-upcoming',
		'empty_text' => __( 'Không có task sắp đến hạn.', 'asana-teams-dashboard' ),
	),
	'no_due'   => array(
		'label'      => __( '📭 Không có hạn / Sau 7 ngày', 'asana-teams-dashboard' ),
		'class'      => 'is-no-due',
		'empty_text' => __( 'Không có task.', 'asana-teams-dashboard' ),
	),
);

$type_labels = array(
	'assigned'  => __( 'Assigned', 'asana-teams-dashboard' ),
	'created'   => __( 'Do tôi tạo', 'asana-teams-dashboard' ),
	'following' => __( 'Theo dõi', 'asana-teams-dashboard' ),
);

if ( empty( $grouped ) ) {
	$grouped = array(
		'overdue'  => array(),
		'today'    => array(),
		'upcoming' => array(),
		'no_due'   => array(),
	);
}

foreach ( $groups_config as $group_key => $group_conf ) :
	$tasks = isset( $grouped[ $group_key ] ) ? $grouped[ $group_key ] : array();
	?>
	<div class="atd-task-group" data-group="<?php echo esc_attr( $group_key ); ?>">
		<div class="atd-group-header <?php echo esc_attr( $group_conf['class'] ); ?>">
			<span class="atd-group-title"><?php echo esc_html( $group_conf['label'] ); ?></span>
			<span class="atd-group-count"><?php echo count( $tasks ); ?></span>
			<span class="dashicons dashicons-arrow-down-alt2 atd-collapse-icon"></span>
		</div>
		<div class="atd-group-body">
			<?php if ( empty( $tasks ) ) : ?>
				<p class="atd-empty-state"><?php echo esc_html( $group_conf['empty_text'] ); ?></p>
			<?php else : ?>
				<?php foreach ( $tasks as $task ) :
					$gid          = isset( $task['gid'] ) ? $task['gid'] : '';
					$name         = isset( $task['name'] ) ? $task['name'] : '';
					$permalink    = isset( $task['permalink_url'] ) ? $task['permalink_url'] : '#';
					$due_on       = isset( $task['due_on'] ) ? $task['due_on'] : '';
					$task_type    = isset( $task['task_type'] ) ? $task['task_type'] : 'assigned';
					$assignee     = isset( $task['assignee']['name'] ) ? $task['assignee']['name'] : '';
					$notes        = isset( $task['notes'] ) ? $task['notes'] : '';
					$notes_short  = $notes ? mb_substr( $notes, 0, 80 ) . ( mb_strlen( $notes ) > 80 ? '...' : '' ) : '';

					// Lấy project name đầu tiên nếu có.
					$project_name = '';
					if ( ! empty( $task['projects'] ) && is_array( $task['projects'] ) ) {
						$project_name = isset( $task['projects'][0]['name'] ) ? $task['projects'][0]['name'] : '';
					}

					// Badge deadline.
					$due_badge_class = 'badge-no-due';
					$due_text        = __( 'Không rõ hạn', 'asana-teams-dashboard' );
					if ( $due_on ) {
						$due_dt   = new DateTime( $due_on );
						$due_text = $due_dt->format( 'd/m/Y' );
						if ( $due_on < $today ) {
							$diff            = ( new DateTime( $today ) )->diff( $due_dt );
							$days_late       = $diff->days;
							$due_badge_class = 'badge-overdue';
							$due_text        = sprintf( _n( 'Trễ %d ngày', 'Trễ %d ngày', $days_late, 'asana-teams-dashboard' ), $days_late );
						} elseif ( $due_on === $today ) {
							$due_badge_class = 'badge-today';
							$due_text        = __( 'Hôm nay', 'asana-teams-dashboard' );
						} else {
							$due_badge_class = 'badge-upcoming';
						}
					}

					$type_badge_class = 'badge-' . $task_type;
					$type_label       = isset( $type_labels[ $task_type ] ) ? $type_labels[ $task_type ] : $task_type;
					?>
					<div class="atd-task-card"
						id="task-card-<?php echo esc_attr( $gid ); ?>"
						data-gid="<?php echo esc_attr( $gid ); ?>"
						data-name="<?php echo esc_attr( $name ); ?>"
						data-project="<?php echo esc_attr( $project_name ); ?>"
						data-type="<?php echo esc_attr( $task_type ); ?>">

						<div class="atd-card-top">
							<a href="<?php echo esc_url( $permalink ); ?>" target="_blank" class="atd-task-name">
								<?php echo esc_html( $name ); ?>
							</a>
						</div>

						<div class="atd-task-meta">
							<?php if ( $due_on ) : ?>
								<span class="atd-badge <?php echo esc_attr( $due_badge_class ); ?>">
									📅 <?php echo esc_html( $due_text ); ?>
								</span>
							<?php endif; ?>

							<?php if ( $project_name ) : ?>
								<span class="atd-badge badge-project">
									📁 <?php echo esc_html( $project_name ); ?>
								</span>
							<?php endif; ?>

							<?php if ( $assignee ) : ?>
								<span class="atd-badge badge-no-due">
									👤 <?php echo esc_html( $assignee ); ?>
								</span>
							<?php endif; ?>

							<span class="atd-badge <?php echo esc_attr( $type_badge_class ); ?>">
								<?php echo esc_html( $type_label ); ?>
							</span>
						</div>

						<?php if ( $notes_short ) : ?>
							<p class="atd-task-notes">
								<?php echo esc_html( $notes_short ); ?>
							</p>
						<?php endif; ?>

						<div class="atd-card-actions">
							<a href="<?php echo esc_url( $permalink ); ?>" target="_blank" class="button button-small">
								<span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
								<?php esc_html_e( 'Xem', 'asana-teams-dashboard' ); ?>
							</a>
							<button class="button button-small button-primary atd-complete-btn"
								data-gid="<?php echo esc_attr( $gid ); ?>">
								<span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>
								<?php esc_html_e( 'Complete', 'asana-teams-dashboard' ); ?>
							</button>
							<button class="button button-small atd-analyze-btn"
								data-gid="<?php echo esc_attr( $gid ); ?>">
								📋 <?php esc_html_e( 'Phân tích', 'asana-teams-dashboard' ); ?>
							</button>
							<button class="button button-small atd-draft-comment-btn"
								data-gid="<?php echo esc_attr( $gid ); ?>"
								title="<?php esc_attr_e( 'AI tự động soạn nhắc nhở công việc', 'asana-teams-dashboard' ); ?>">
								🤖 <?php esc_html_e( 'Nhắc nhở', 'asana-teams-dashboard' ); ?>
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>
