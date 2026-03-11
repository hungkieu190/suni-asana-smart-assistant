# Hướng dẫn sử dụng Asana Dashboard

Plugin này giúp bạn theo dõi công việc từ Asana ngay trong giao diện quản trị WordPress.

---

## 1. Cài đặt ban đầu
Sau khi kích hoạt plugin, bạn sẽ thấy menu **Asana Dashboard** xuất hiện trong thanh menu bên trái của Dashboard WordPress.
1. Truy cập **Asana Dashboard** > **Settings**.
2. Tại đây có phần cấu hình: **Asana**.

---

## 2. Cấu hình Asana (Kết nối bằng Personal Access Token - PAT)
Để lấy dữ liệu từ Asana, bạn cần cung cấp PAT và Workspace ID:

*   **Bước 1: Lấy PAT**
    *   Đăng nhập vào Asana, nhấn vào ảnh đại diện của bạn ở góc trên bên phải > **My Profile Settings**.
    *   Chọn tab **Apps** > **Developer Apps**.
    *   Nhấn **+ Create New Personal Access Token**.
    *   Đặt tên cho token (ví dụ: "WP Dashboard") và copy mã token hiện ra.
*   **Bước 2: Lấy Workspace GID**
    *   Bạn có thể tìm Workspace GID trong URL khi bạn đang ở trong workspace đó trên trình duyệt (thường là dãy số sau `0/`), hoặc dùng công cụ kiểm tra API của Asana.
*   **Bước 3: Lưu cài đặt**
    *   Dán **PAT** và **Workspace GID** vào trang Settings của plugin và nhấn **Save Changes**.

---

## 3. Sử dụng Dashboard
Truy cập **Asana Dashboard** > **Dashboard** để xem dữ liệu:
*   **Asana Assigned:** Hiển thị các công việc được giao cho bạn chưa hoàn thành, ưu tiên các task mới được tạo/giao gần đây, kèm ngày hết hạn.

---

## 4. Tự động đồng bộ (Auto-sync)
Dashboard được tích hợp cơ chế tự động đồng bộ dữ liệu sau mỗi khoảng thời gian nhất định (mặc định **30 giây**) khi bạn đang mở trang Dashboard. 
*   Bạn có thể thay đổi thời gian này trong trang **Settings**.
*   Bạn sẽ thấy bộ đếm ngược thời gian bên cạnh tiêu đề Dashboard.
*   Sau khi đồng bộ thành công, trang sẽ tự động tải lại để cập nhật danh sách công việc mới nhất.
*   Cơ chế này được tối ưu để tránh làm nghẽn hệ thống bằng cách giới hạn tần suất gọi API tối đa 15 giây/lần.

---

## 5. Báo cáo hàng tuần (Weekly Summary)
*   **Cơ chế:** Plugin sử dụng WP-Cron để tự động chạy vào mỗi cuối tuần.
*   **Nội dung:** Plugin sẽ quét các task Asana bạn đã hoàn thành trong 7 ngày qua.
*   **Gửi báo cáo:** Một email định dạng HTML sẽ được gửi đến email Admin của website vào sáng Chủ Nhật hàng tuần.

---

## 6. Trợ lý ảo Suni Hạ Linh
Dashboard tích hợp trợ lý ảo **Suni Hạ Linh** để giúp bạn quản lý công việc bằng ngôn ngữ tự nhiên:
- **Chào hỏi:** "Chào Suni", "Hi Suni" để nhận tóm tắt công việc trong ngày.
- **Xem việc hôm nay:** "Việc hôm nay", "Hôm nay có gì không em".
- **Xem việc sắp tới/quá hạn:** "Việc sắp tới", "Có việc gì quá hạn không".
- **Tìm kiếm task:** "Tìm [từ khóa]", ví dụ: "Tìm website".
- **Báo cáo:** "Báo cáo tuần", "Tiến độ công việc".
- **Thông minh hơn:** Nếu cấu hình **OpenAI API Key**, Suni có thể hiểu các yêu cầu phức tạp, tự động tạo task (ví dụ: "Tạo task học code vào ngày mai"), hoặc hoàn thành task theo yêu cầu.

---

## 7. Cấu hình OpenAI API (Bộ não của Suni)
Để Suni Hạ Linh trở nên thông minh và có thể thực hiện các thao tác như tạo task, bạn cần:
1. Truy cập [OpenAI API Keys](https://platform.openai.com/api-keys).
2. Tạo một **API Key**.
3. Dán API Key vào mục **OpenAI API Key** trong trang **Settings** của plugin.
4. Sau khi có API Key, Suni sẽ có khả năng đọc dữ liệu thực tế và thực hiện lệnh trực tiếp trên Asana.

---

## 8. Một số lưu ý quan trọng
*   **Bảo mật:** Luôn giữ kín PAT của bạn.
*   **Lỗi dữ liệu:** Nếu Dashboard không hiện dữ liệu, hãy kiểm tra lại Token hoặc Workspace ID xem đã chính xác chưa.
