# Phân tích Plugin Asana Dashboard

## 1. Tổng quan
Plugin **Asana Dashboard** là một công cụ quản lý công việc tập trung, cho phép người dùng WordPress tương tác với các task trên Asana mà không cần rời khỏi giao diện admin. Điểm nổi bật nhất là sự kết hợp giữa quản lý task truyền thống và trợ lý ảo thông minh (AI Assistant).

## 2. Các tính năng hiện có
- **Quản lý Task Asana**:
  - Hiển thị danh sách công việc được giao (Assigned to Me).
  - Đánh dấu hoàn thành task (đồng bộ ngay lập tức với Asana).
  - Tạo task mới trực tiếp từ dashboard.
  - Phân trang dữ liệu để tối ưu hiệu năng.
- **Trợ lý ảo "Suni Hạ Linh"**:
  - Sử dụng OpenAI GPT-4o-mini.
  - Hỗ trợ **Function Calling** (Tool Calls) để thực hiện hành động: tìm kiếm task, xem chi tiết, tóm tắt nội dung, tạo task, hoàn thành task.
  - Giao diện chat trực quan với khả năng render Markdown.
  - Có chế độ dự phòng (fallback) nếu không có API Key.
- **Đồng bộ hóa (Synchronization)**:
  - Tự động đồng bộ mỗi 15 phút qua WP Cron.
  - Cho phép đồng bộ thủ công hoặc xóa cache dữ liệu local.
  - Cơ chế tự động cleanup các task đã hoàn thành hoặc bị xóa trên Asana khỏi DB local.
- **Báo cáo (Reporting)**:
  - Gửi email báo cáo hàng tuần về các công việc đã hoàn thành trong 7 ngày qua cho Admin.
- **Hệ thống Log & Debug**:
  - Tích hợp bảng theo dõi log ngay trên Dashboard để giám sát hoạt động của AI và API.

## 3. Kiến trúc cơ sở
Plugin được xây dựng theo tiêu chuẩn WordPress hiện đại với các đặc điểm:
- **Mẫu thiết kế (Design Pattern)**: Sử dụng **Singleton** cho class chính (`Asana_Teams_Dashboard`) để quản lý instance duy nhất.
- **Tách biệt mối quan tâm (Separation of Concerns)**:
  - `ATD_Asana_API`: Xử lý giao tiếp REST API với Asana.
  - `ATD_Assistant`: Quản lý logic AI và Tool Calls.
  - `ATD_Cron`: Xử lý các tác vụ chạy ngầm, AJAX và logic database.
  - `ATD_Settings`: Quản lý cấu hình plugin qua WordPress Settings API.
- **Lớp dữ liệu (Data Layer)**: 
  - Sử dụng bảng custom `wp_atd_sync_data` để cache dữ liệu từ Asana.
  - Giúp dashboard load nhanh hơn và giảm thiểu số lượng request đến API Asana (tránh rate limit).
- **Giao diện (UI/UX)**:
  - Sử dụng **AJAX** hoàn toàn cho các tương tác trên dashboard (chat, sync, pagination).
  - Tích hợp `marked.js` để hiển thị Markdown đẹp mắt trong phần chat.

## 4. Cấu trúc thư mục
- `/admin`: Chứa template trang dashboard, CSS và JS.
- `/includes`: Chứa toàn bộ core logic của plugin (API, AI, Cron, Settings).
- `/languages`: Hỗ trợ đa ngôn ngữ.

## 5. Đánh giá bảo mật (Security)
- Kiểm tra `ABSPATH` ở đầu mọi file PHP.
- Sử dụng `nonce` và `current_user_can('manage_options')` cho mọi request AJAX.
- Dữ liệu input được sanitize (`sanitize_text_field`), output được escape (`esc_html`, `esc_url`).
- Sử dụng `$wpdb->prepare` cho tất cả các câu lệnh SQL để chống SQL Injection.

---
*Tài liệu này được soạn thảo bởi Trợ lý Antigravity dành cho pé Kiều Mầm.*
