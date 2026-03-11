# PLAN: Asana Manager Dashboard — WP Admin Edition

> **Phiên bản**: 2.2 — Cập nhật ngày 2026-03-11  
> **Vai trò người dùng**: Quản lý dự án / BA  
> **Nền tảng**: WordPress Admin (backend only)  
> **Trạng thái**: 🟡 Đang phát triển (Phase 11)

---

## 🎯 Bài toán

Anh là người quản lý cần:
1. **Xem tất cả task liên quan** — task được assign, do anh tạo, anh là collaborator
2. **Theo dõi tiến độ** — quá hạn / hôm nay / sắp tới / chưa có hạn
3. **AI tự báo cáo** mỗi sáng khi mở dashboard
4. **Phân tích task** — AI đọc nội dung, comments, trả lời tiến độ
5. **Nhắc người phụ trách** — AI post comment lên Asana thay anh
6. **Ra lệnh cho AI** — complete, set due date, thống kê theo yêu cầu

---

## 📡 Asana API Reference

> Tài liệu đầy đủ: https://developers.asana.com/reference/rest-api-reference

| Endpoint | Method | Dùng để | Scope cần |
|----------|--------|---------|-----------|
| `/tasks` | GET | Lấy task theo assignee/workspace | `tasks:read` |
| `/workspaces/{gid}/tasks/search` | GET | Search task theo follower, created_by | `tasks:read` ⚠️ Premium |
| `/tasks/{gid}` | GET | Chi tiết 1 task (notes, assignee...) | `tasks:read` |
| `/tasks/{gid}` | PUT | Cập nhật task (complete, due_on...) | `tasks:write` |
| `/tasks` | POST | Tạo task mới | `tasks:write` |
| `/tasks/{gid}/stories` | GET | Lấy comments của task | `stories:read` |
| `/tasks/{gid}/stories` | POST | Post comment lên task | `stories:write` |
| `/users/me` | GET | Lấy user_gid của người dùng | `users:read` |

### ⚠️ Constraints & API Limitations

- **Search API (Premium only)** — Các filter `followers.any` và `created_by` yêu cầu Asana Premium (lỗi 402 hoặc 400).
- **Workaround cho bản Free**: Đồng bộ theo từng Project (Phase 4).
- **Search API không stable pagination** — không hỗ trợ phân trang truyền thống, max 100/lần.
- **Rate limit**: 1500 requests/phút — cần throttle khi sync project-based.
- **Update task**: Dùng PUT, chỉ gửi field cần thay đổi.

---

## 🏗️ Kiến trúc hiện tại (đã có)

```
asana-teams-dashboard.php           ← Main file, menu, hooks
includes/
  class-atd-asana-api.php           ← Wrapper API (GET tasks, PUT complete, POST create)
  class-atd-cron.php                ← Sync + AJAX handlers
  class-atd-assistant.php           ← Chat AI (Gemini)
  class-atd-settings.php            ← Settings page
admin/
  templates/dashboard.php           ← Template (561 dòng, all-in-one, có console.log)
  css/                              ← Trống
  js/                               ← Trống
```

### AJAX handlers hiện có

| Action | Handler | Trạng thái |
|--------|---------|------------|
| `atd_manual_sync` | `ATD_Cron::ajax_manual_sync` | ✅ |
| `atd_clear_data` | `ATD_Cron::ajax_clear_data` | ✅ |
| `atd_complete_task` | `ATD_Cron::ajax_complete_task` | ✅ |
| `atd_fetch_dashboard_table` | `ATD_Cron::ajax_fetch_dashboard_table` | ✅ |
| `atd_assistant_chat` | `ATD_Assistant::ajax_chat` | ✅ |
| `atd_clear_chat_history` | `ATD_Assistant::ajax_clear_history` | ✅ |
| `atd_get_debug_logs` | `ATD_Assistant` | ✅ |
| `atd_clear_debug_logs` | `ATD_Assistant` | ✅ |

---

## ✅ ROADMAP CHI TIẾT

---

### PHASE 1 — Data Layer + Dashboard UI
> **Mục tiêu**: Có đủ dữ liệu từ 3 nguồn & UI hiển thị đúng nhu cầu quản lý.

---

#### 1.1 — Mở rộng Data Layer (Sync đủ 3 nguồn task)

**File**: `includes/class-atd-asana-api.php`

- [ ] **API-01** Thêm method `get_me()` đã có — dùng để lấy `user_gid` của tài khoản đang dùng PAT
  - Endpoint: `GET /users/me`
  - Lưu `user_gid` vào `wp_options` sau lần sync đầu tiên (key: `atd_asana_user_gid`)

- [ ] **API-02** Thêm method `get_created_tasks($limit, $offset)`
  - Endpoint: `GET /tasks?assignee=me&workspace={gid}&created_by={user_gid}&completed_since=now`
  - **Chú ý**: `created_by` filter chỉ hoạt động tốt khi kết hợp với `workspace`
  - opt_fields: `name,due_on,projects.name,notes,assignee.name,permalink_url,created_at,completed,followers`

- [ ] **API-03** Thêm method `get_following_tasks($limit, $offset)`
  - Endpoint: `GET /workspaces/{gid}/tasks/search?followed_by.any={user_gid}&is_subtask=false&completed=false`
  - ⚠️ **Yêu cầu Premium** — nếu lỗi 402 thì log lại và skip, không crash
  - Limit max 100/lần (Search API không support offset chuẩn, sort by `created_at`, paginate thủ công)

- [ ] **API-04** Thêm method `get_task_detail($task_gid)`
  - Endpoint: `GET /tasks/{gid}?opt_fields=name,due_on,projects.name,notes,html_notes,assignee.name,permalink_url,created_at,completed,followers`

- [ ] **API-05** Thêm method `get_task_stories($task_gid)`
  - Endpoint: `GET /tasks/{gid}/stories?opt_fields=type,text,created_by.name,created_at`
  - Chỉ lấy `type=comment` (filter phía PHP sau khi nhận)

- [ ] **API-06** Thêm method `create_story($task_gid, $text)`
  - Endpoint: `POST /tasks/{gid}/stories`
  - Body: `{"data": {"text": "..."}}`
  - Scope: `stories:write`

- [ ] **API-07** Mở rộng method `update_task($task_gid, $data)` — đã có, nhưng thêm validate input
  - Hỗ trợ các field: `completed`, `due_on`, `name`, `notes`, `assignee`

**File**: `includes/class-atd-cron.php`

- [ ] **CRON-01** Thêm method `sync_created_tasks()`
  - Gọi `ATD_Asana_API::get_created_tasks()`
  - Lưu với `type = 'created'` vào `wp_atd_sync_data`

- [ ] **CRON-02** Thêm method `sync_following_tasks()`
  - Gọi `ATD_Asana_API::get_following_tasks()`
  - Lưu với `type = 'following'` vào `wp_atd_sync_data`
  - Nếu lỗi 402 (không phải Premium) → log warning, không throw exception

- [ ] **CRON-03** Cập nhật `sync_all_data()` — gọi thêm 2 method mới
  - Thứ tự: `sync_asana_assigned()` → `sync_created_tasks()` → `sync_following_tasks()`

- [ ] **CRON-04** Cập nhật `ajax_manual_sync()` — gọi đủ 3 sync, bắt lỗi từng phần

- [ ] **CRON-05** Cập nhật `get_cached_data()` — thêm param `$type_filter = null`
  - Nếu `$type_filter = null` → lấy tất cả types
  - Nếu `$type_filter = 'assigned'` → chỉ lấy assigned
  - Hỗ trợ array: `$type_filter = ['assigned', 'created']`

---

#### 1.2 — Nâng cấp Dashboard UI

**File**: `admin/css/atd-dashboard.css` ← TẠO MỚI

- [ ] **CSS-01** Tạo file `admin/css/atd-dashboard.css`
  - Extract toàn bộ CSS đang hard-code trong `<style>` của `dashboard.php`
  - Thêm styles cho: summary bar, task card, badge groups, filter bar, collapse/expand button

**File**: `admin/js/atd-dashboard.js` ← TẠO MỚI

- [ ] **JS-01** Tạo file `admin/js/atd-dashboard.js`
  - Extract toàn bộ `<script>` block trong `dashboard.php`
  - **Xóa `console.log`** ở hàm `performSync()` (vi phạm output contract)
  - Thêm logic: collapse/expand group, filter/search client-side

**File**: `asana-teams-dashboard.php`

- [ ] **MAIN-01** Enqueue `atd-dashboard.css` và `atd-dashboard.js` đúng cách
  - Hook: `admin_enqueue_scripts`
  - Chỉ load trên screen `asana-teams-dashboard` và `atd-settings`
  - Dependency JS: `['jquery']`, dependency CSS: `[]`

**File**: `admin/templates/dashboard.php` ← VIẾT LẠI

- [ ] **DASH-01** Summary bar (4 ô thống kê) — tính phía PHP khi render template
  - Tổng task | 🔴 Quá hạn | 🟡 Hôm nay | 🔵 CoLab (following)
  - Data lấy từ `get_cached_data()` đã được mở rộng

- [ ] **DASH-02** Task grouping — thay table phẳng bằng 4 nhóm
  - 🔥 Quá hạn: `due_on < today`
  - ⏰ Hôm nay: `due_on == today`
  - 📅 Sắp tới (7 ngày): `today < due_on <= today+7`
  - 📭 Không có hạn: `due_on == null`
  - Logic tính phía PHP, output HTML với `data-group` attribute

- [ ] **DASH-03** Task card — thay mỗi `<tr>` thành card layout
  - Hiển thị: Tên task (link), Project name, Due date badge màu, Assignee, Notes preview (50 ký tự), badge loại task (assigned/created/following)
  - Nút: [👁 Xem] [✅ Complete] [💬 Nhắc AI] [📋 Phân tích]

- [ ] **DASH-04** Filter bar
  - Dropdown **Loại**: Tất cả / Assigned / Do tôi tạo / Tôi theo dõi
  - Dropdown **Dự án**: Lấy danh sách unique projects từ data đã load
  - Input **Search**: Filter real-time theo tên task (JS client-side)

- [ ] **DASH-05** Collapse/expand group header
  - Click vào header nhóm → ẩn/hiện card trong nhóm
  - Lưu trạng thái collapse vào `localStorage`

- [ ] **DASH-06** Cập nhật AJAX `atd_fetch_dashboard_table` — render ra card layout thay vì table
  - Giữ nguyên nonce và response structure `{success, data: {html}}`

---

### PHASE 2 — AI Context + Auto-briefing + Task Analysis
> **Mục tiêu**: AI biết task data, tự báo cáo sáng, phân tích được từng task.

---

#### 2.1 — Inject Task Context vào AI

**File**: `includes/class-atd-assistant.php`

- [ ] **AI-01** Tạo method `build_task_context()`
  - Lấy tối đa 50 task từ DB (ưu tiên: quá hạn → hôm nay → sắp tới)
  - Format: `[GID] | Tên task | Project | Due: dd/mm/yyyy | Assignee | Type | Status`
  - Dùng trong system prompt, không inject full notes (tránh vượt token limit)

- [ ] **AI-02** Cập nhật system prompt trong `ajax_chat()`
  - Inject kết quả `build_task_context()` vào system prompt
  - Thêm hướng dẫn AI: "Em có thể: phân tích task, complete task, set due date, thống kê"

---

#### 2.2 — Auto-briefing buổi sáng

**File**: `includes/class-atd-assistant.php`

- [ ] **AI-03** Tạo method `build_briefing_prompt()`
  - Tính số lượng: quá hạn, hôm nay, sắp tới 7 ngày
  - Build prompt yêu cầu AI tạo tin nhắn chào buổi sáng bằng tiếng Việt
  - Kèm danh sách task quá hạn (top 5) và task hôm nay

- [ ] **AI-04** Tạo AJAX handler `atd_auto_briefing`
  - Check `user_meta`: `atd_last_briefing_date` (lưu ngày dạng `Y-m-d`)
  - Nếu ngày hôm nay khác với ngày lưu → gọi AI → trả về message
  - Sau khi hoàn thành → cập nhật `atd_last_briefing_date = date('Y-m-d')`
  - Nếu đã briefing hôm nay → return `{success: false, data: 'already_done'}`

- [ ] **JS-02** Khi dashboard load → gọi AJAX `atd_auto_briefing`
  - Nếu response có message → append vào chat box như tin nhắn AI đầu tiên
  - Nếu `already_done` → không làm gì

---

#### 2.3 — AI phân tích task chi tiết

**File**: `includes/class-atd-cron.php` hoặc `class-atd-assistant.php`

- [ ] **AI-05** Tạo AJAX handler `atd_fetch_task_detail`
  - Input: `task_gid`
  - Gọi `ATD_Asana_API::get_task_detail($gid)` → lấy notes
  - Gọi `ATD_Asana_API::get_task_stories($gid)` → lấy comments (chỉ type=comment)
  - Trả về JSON: `{task_data, stories: [{author, text, date}]}`

- [ ] **AI-06** Khi AI nhận request phân tích task (từ chat hoặc nút [📋 Phân tích])
  - Parser nhận diện pattern: "phân tích task [X]" hoặc gid task từ button
  - Gọi `atd_fetch_task_detail` để lấy full data
  - Inject full data vào prompt → AI phân tích và trả lời: nội dung, người làm, tiến độ, rủi ro

- [ ] **DASH-07** Nút **[📋 Phân tích]** trên task card
  - Click → gọi AJAX `atd_fetch_task_detail` → gửi nội dung đến chat AI
  - Chat AI nhận và phân tích, hiển thị kết quả trong chat box

---

#### 2.4 — AI nhắc người phụ trách (comment Asana)

- [ ] **AI-07** Tạo AJAX handler `atd_ai_draft_comment`
  - Input: `task_gid`
  - Lấy task detail (tên, assignee, due_on, notes)
  - AI soạn comment nhắc nhở chuyên nghiệp bằng tiếng Việt
  - Trả về: `{draft: "nội dung comment"}` để user xem trước

- [ ] **AI-08** Tạo AJAX handler `atd_ai_post_comment`
  - Input: `task_gid`, `comment_text`
  - Validate nonce + `current_user_can('manage_options')`
  - Gọi `ATD_Asana_API::create_story($task_gid, $comment_text)`
  - Trả về success/error

- [ ] **DASH-08** Nút **[💬 Nhắc AI]** trên task card
  - Click → gọi `atd_ai_draft_comment` → hiện modal preview
  - Modal có: nội dung soạn sẵn (editable), nút [Huỷ] và [Gửi lên Asana]
  - Confirm → gọi `atd_ai_post_comment` → thông báo thành công/thất bại

---

### PHASE 3 — AI Command Mode + Thống kê nâng cao
> **Mục tiêu**: AI là công cụ điều hành, không chỉ xem.

---

#### 3.1 — Quick Add Task Form

- [ ] **DASH-09** Form nhanh trên dashboard
  - Fields: Tên task (text) + Due date (date picker) + Ghi chú (textarea, optional)
  - Submit → gọi AJAX `atd_create_task` → gọi `ATD_Asana_API::create_task()`
  - Sau khi tạo xong → refresh task board (gọi `atd_fetch_dashboard_table`)

- [ ] **AJAX-01** Tạo AJAX handler `atd_create_task`
  - Input: `name`, `due_on`, `notes`
  - Validate: name không rỗng
  - Gọi `ATD_Asana_API::create_task()`
  - Response: success + task gid mới

---

#### 3.2 — AI Command Mode (Ra lệnh qua chat)

- [ ] **AI-09** Intent parser trong `ajax_chat()`
  - Nhận diện pattern từ message của user:
    - `"complete task [tên/gid]"` → trigger `atd_complete_task`
    - `"set due date task [X] ngày [Y]"` → trigger `atd_ai_set_due_date`
    - `"nhắc task [X]"` → trigger `atd_ai_draft_comment`
  - AI luôn **xác nhận lại** trước khi trả về action: `{confirm: true, action: '...', params: {...}}`

- [ ] **AJAX-02** Tạo AJAX handler `atd_ai_set_due_date`
  - Input: `task_gid`, `due_on` (format `Y-m-d`)
  - Validate + gọi `ATD_Asana_API::update_task($gid, ['due_on' => $due_on])`

- [ ] **JS-03** Xử lý response có `confirm: true` từ AI
  - Hiển thị confirmation dialog: "Em sẽ [hành động]. Anh xác nhận không?"
  - User click OK → gọi AJAX tương ứng → AI thông báo kết quả

---

#### 3.3 — AI Thống kê nâng cao từ DB

- [ ] **AI-10** Tạo method `query_tasks_for_ai($filters)`
  - Nhận filter: `overdue_days`, `project_name`, `assignee_name`, `no_due_date`, `type`
  - Query từ `wp_atd_sync_data` (không gọi API)
  - Trả về array task phù hợp

- [ ] **AI-11** Khi AI nhận câu hỏi thống kê → gọi `query_tasks_for_ai()` → inject vào prompt
  - VD: "task quá hạn hơn 30 ngày" → filter `overdue_days > 30` → AI nhận data → trả lời

- [ ] **DASH-10** Quick action buttons bên chat input
  - [🔔 Briefing hôm nay] → trigger auto-briefing thủ công
  - [📊 Thống kê quá hạn] → pre-fill câu hỏi vào chat input
  - [📋 Task hôm nay] → pre-fill câu hỏi task due today

---

## 📁 Danh sách file thay đổi tổng hợp

| File | Loại | Thay đổi |
|------|------|---------|
| `includes/class-atd-asana-api.php` | Sửa | Thêm: API-01 → API-07 |
| `includes/class-atd-cron.php` | Sửa | Thêm: CRON-01 → CRON-05 |
| `includes/class-atd-assistant.php` | Sửa | Thêm: AI-01 → AI-11 |
| `admin/css/atd-dashboard.css` | TẠO | CSS-01 |
| `admin/js/atd-dashboard.js` | TẠO | JS-01, JS-02, JS-03 |
| `admin/templates/dashboard.php` | Sửa lớn | DASH-01 → DASH-10 |
| `asana-teams-dashboard.php` | Sửa nhỏ | MAIN-01 (enqueue) |

---

## 📊 Tracking

### Phase 1.1 — API & Sync (12 items)
- [x] API-01: `get_me()` + lưu `atd_asana_user_gid`
- [x] API-02: `get_created_tasks($limit, $offset)`
- [x] API-03: `get_following_tasks()` — bắt lỗi 402 Premium
- [x] API-04: `get_task_detail($task_gid)` — full opt_fields
- [x] API-05: `get_task_stories($task_gid)` — filter type=comment phía PHP
- [x] API-06: `create_story($task_gid, $text)` — POST comment lên Asana
- [x] API-07: validate whitelist trong `update_task()`
- [x] CRON-01: `sync_created_tasks()` — lưu type='created'
- [x] CRON-02: `sync_following_tasks()` — lưu type='following', bắt 402
- [x] CRON-03: `sync_all_data()` gọi đủ 3 nguồn
- [x] CRON-04: `ajax_manual_sync()` sync đủ 3, bắt lỗi từng phần
- [x] CRON-05: `get_cached_data()` thêm `$type_filter` + `build_summary()` + `group_tasks_by_deadline()`

### Phase 1.2 — Dashboard UI (8 items)
- [x] CSS-01: Tạo `admin/css/atd-dashboard.css`
- [x] JS-01: Tạo `admin/js/atd-dashboard.js` — không có console.log, có collapse/filter
- [x] MAIN-01: Enqueue CSS/JS đúng screen + `wp_localize_script` với `atdConfig`
- [x] DASH-01: Summary bar — 4 ô thống kê (Tổng / Quá hạn / Hôm nay / Sắp tới)
- [x] DASH-02: Task grouping — 4 nhóm collapse/expand (PHP-side)
- [x] DASH-03: Task card — đủ fields (project, due badge, assignee, notes, type badge) + 3 nút
- [x] DASH-04: Filter bar — Loại task + Dự án + Search real-time
- [x] DASH-05/06: Collapse/expand + localStorage + AJAX render card layout

### Phase 2.1 — AI Context (2 items)
- [x] AI-01: `build_task_context()` — inject tóm tắt task vào system prompt
- [x] AI-02: Cập nhật system prompt `ajax_chat()` có context + hướng dẫn năng lực AI

### Phase 2.2 — Auto-briefing (4 items)
- [x] AI-03: `build_briefing_prompt()` — top 5 quá hạn + task hôm nay
- [x] AI-04: AJAX `atd_auto_briefing` — check `user_meta` 1 lần/ngày
- [x] JS-02: Khi load dashboard → gọi `atd_auto_briefing` → append vào chat
- [x] DASH-10: Quick action buttons: [🔥 Quá hạn] [⏰ Hôm nay] [📊 Tổng quan]

### Phase 2.3 — AI Phân tích Task (3 items)
- [x] AI-05: AJAX `atd_fetch_task_detail` — notes + stories (handler sẵn, cần nối AI)
- [x] AI-06: AI nhận gid từ nút [📋 Phân tích] → fetch → inject → phân tích
- [x] DASH-07: Nút [📋 Phân tích] trên card → đẩy vào chat AI

### Phase 2.4 — AI Comment Asana (4 items)
- [x] AI-07: AJAX `atd_ai_draft_comment` — AI soạn comment nhắc người phụ trách
- [x] AI-08: AJAX `atd_ai_post_comment` — POST comment lên Asana sau xác nhận
- [x] DASH-08: Nút [💬 Nhắc AI] → modal preview (editable) → xác nhận → gửi
- [x] Modal CSS + JS cho comment preview

### Phase 3.1 — Quick Add Task (2 items)
- [x] DASH-09: Form nhanh trên dashboard (tên + due date + ghi chú)
- [x] AJAX-01: `atd_create_task` — validate + gọi API + refresh board

### Phase 3.2 — AI Command Mode (3 items)
- [x] AI-09: Intent parser trong `ajax_chat()` — nhận diện: complete / set due / nhắc
- [x] AJAX-02: `atd_ai_set_due_date` — update due_on qua Asana API
- [x] JS-03: Xử lý `confirm: true` từ AI → dialog xác nhận → thực thi

### Phase 3.3 — AI Thống kê nâng cao (3 items)
- [x] AI-10: `query_tasks_for_ai($filters)` — query DB theo overdue_days, project, assignee
- [x] AI-11: AI nhận câu hỏi thống kê → gọi `query_tasks_for_ai()` → inject → trả lời
- [x] AI-12: Quick stat buttons (pre-fill câu hỏi vào chat input)

---

### PHASE 4 — Handle Asana Free Limitations (Final)
> **Mục tiêu**: Loại bỏ các yêu cầu Premium gây lỗi và thông báo cho người dùng.

- [x] **API-08/02/03** Xoá các method yêu cầu Premium (`get_created_tasks`, `get_following_tasks`).
- [x] **CRON-01/02** Loại bỏ logic sync task "Do tôi tạo" và "Tôi theo dõi" để tránh lỗi 400/402.
- [x] **JS-05** Thêm thông báo Premium khi người dùng cố gắng lọc theo các tiêu chí này.
- [x] **BUG-01** Sửa lỗi filter "Tất cả loại" bị trắng trang do lọc sai type trống trong PHP.

---

### PHASE 6 — Subtask Analysis (Dev assignment)
> **Mục tiêu**: AI phân tích được ai đang làm gì ở các task con.

- [x] **API-09** Thêm method `get_subtasks($task_gid)`
- [x] **AJAX-03** Update `atd_fetch_task_detail` trả về subtasks
- [x] **JS-04** Cập nhật AI Prompt để nhận diện Dev phụ trách subtasks

### PHASE 7 — Mention Detection & AI Auto-Reply
> **Mục tiêu**: AI phát hiện khi ai đó mention (nhắc đến) mình trong comment Asana, thông báo nội dung, và hỗ trợ trả lời trực tiếp.

---

#### 7.1 — API: Lấy stories kèm html_text để phát hiện mentions

**File**: `includes/class-atd-asana-api.php`

- [x] **API-10** Cập nhật method `get_task_stories($task_gid)` — thêm `html_text` vào `opt_fields`
  - Endpoint hiện tại: `GET /tasks/{gid}/stories?opt_fields=type,text,created_by.name,created_at`
  - Endpoint mới: thêm `html_text` vào opt_fields
  - `html_text` chứa tag `<a data-asana-gid="USER_GID"/>` khi có mention
  - Giữ nguyên filter `type=comment` phía PHP

---

#### 7.2 — Sync & Lưu trữ Mentions

**File**: `includes/class-atd-cron.php`

- [x] **CRON-06** Tạo method `sync_mentions()`
  - Lấy danh sách tất cả task đã sync từ `wp_atd_sync_data` (type = 'assigned')
  - Với mỗi task, gọi `get_task_stories()` để lấy comments
  - Parse `html_text` của mỗi comment: tìm `<a data-asana-gid="{user_gid}"/>`
  - So sánh `data-asana-gid` với `atd_asana_user_gid` (option đã lưu sẵn)
  - Lọc bỏ comment do chính mình viết (`created_by.gid == user_gid`)
  - Lưu mention mới vào bảng `wp_atd_mentions` (hoặc `wp_atd_sync_data` với type = 'mention')
  - **Rate limit**: Throttle tối đa 20 task/lần sync, dùng `last_mention_check` timestamp để chỉ check task có `modified_at` mới hơn lần check trước

- [x] **CRON-07** Tạo bảng `wp_atd_mentions` (hoặc dùng `wp_atd_sync_data` với type mới)
  - Schema:
    ```
    id            BIGINT AUTO_INCREMENT
    task_gid      VARCHAR(50)        — GID của task chứa mention
    task_name     VARCHAR(255)       — Tên task
    story_gid     VARCHAR(50)        — GID của comment chứa mention
    mentioned_by  VARCHAR(255)       — Tên người mention (created_by.name)
    comment_text  TEXT               — Nội dung comment gốc (text)
    is_read       TINYINT DEFAULT 0  — Đã đọc/xử lý chưa
    created_at    DATETIME           — Thời điểm comment được tạo
    synced_at     DATETIME           — Thời điểm sync phát hiện
    ```

- [x] **CRON-08** Cập nhật `sync_all_data()` — gọi thêm `sync_mentions()` sau `sync_asana_assigned()`
  - Thứ tự: `sync_asana_assigned()` → `sync_mentions()`
  - Bắt lỗi riêng cho mentions, không ảnh hưởng sync chính

---

#### 7.3 — AI Thông báo Mention khi mở Dashboard

**File**: `includes/class-atd-assistant.php`

- [x] **AI-13** Tạo method `get_unread_mentions()`
  - Query `wp_atd_mentions` WHERE `is_read = 0`
  - Trả về array: `[{task_name, task_gid, mentioned_by, comment_text, created_at}]`

- [x] **AI-14** Cập nhật `build_briefing_prompt()` — inject danh sách mention chưa đọc
  - Khi có mention mới → AI tự thông báo dạng:
    ```
    📩 Bạn có [N] mention mới:
    1. **[Tên người]** đã nhắc đến bạn trong task "[Tên task]":
       "[Nội dung comment preview 100 ký tự...]"
       → Bạn muốn trả lời gì không?
    ```
  - Nếu không có mention mới → không hiện gì thêm

- [x] **AI-15** Tạo AJAX handler `atd_get_mentions`
  - Input: `status` (all / unread / read), `limit` (default 20)
  - Trả về JSON danh sách mentions
  - Nonce + `current_user_can('manage_options')`

---

#### 7.4 — AI Trả lời Mention (Reply Comment)

**File**: `includes/class-atd-assistant.php`

- [x] **AI-16** Tạo AJAX handler `atd_reply_mention`
  - Input: `mention_id`, `reply_text`
  - Flow:
    1. Lấy `task_gid` từ mention record
    2. AI soạn comment trả lời chuyên nghiệp (kết hợp context mention + reply_text)
    3. Trả về `{draft: "nội dung reply soạn sẵn", task_gid, mention_id}` để user preview
  - User xác nhận → gọi `atd_ai_post_comment` (đã có) để post lên Asana
  - Sau khi post thành công → update `is_read = 1` trong `wp_atd_mentions`

- [x] **AI-17** Cập nhật AI system prompt — thêm tool `reply_mention`
  - Khi user nhập câu trả lời cho mention trong chat → AI nhận diện intent
  - Pattern: "trả lời mention [X]" hoặc "reply [nội dung]" sau khi AI vừa thông báo mention
  - AI tự động gọi `atd_reply_mention` → preview → confirm → post

---

      - PHP: AJAX return projects
      - JS: Rebuild dropdown + update live counts

---

### PHASE 8 — Collaborator Tasks (Asana Free Workaround)
> **Mục tiêu**: Lấy các task anh đang theo dõi (followers) mà không cần Premium.

- [ ] **API-11** Thêm method `get_project_tasks($project_gid)` — Lấy task theo project.
- [ ] **CRON-09** Method `sync_collaborator_tasks()` — Chạy vòng lặp qua các project để tìm task user đang follow.
- [ ] **DASH-13** Thêm stat card "🤝 Collaborator" và cập nhật filter label.
- [ ] **AI-18** Inject bối cảnh collaborator vào prompt AI.

---

### PHASE 9 — Dynamic Filters & Live Counts
> **Mục tiêu**: Bộ lọc và số liệu nhảy "tưng bừng" theo thao tác người dùng.

- [ ] **PHP-11** Update `ajax_fetch_dashboard_table` trả về danh sách projects tương ứng với data đang load.
- [ ] **JS-07** Method `updateProjectDropdown()` — Tự động cập nhật dropdown project khi đổi loại filter.
- [ ] **JS-08** Live group counts: Tự động đếm task đang hiển thị và cập nhật vào tiêu đề nhóm (Overdue, Today...).

---

### PHASE 10 — Smart Due Date Intelligence
> **Mục tiêu**: Suni nhận thức được thời gian, biết "đòi nợ" thông minh.

- [ ] **JS-09** Analysis prompt: Tính toán số ngày trễ hạn/còn lại và đẩy vào prompt phân tích.
- [ ] **AI-20** Reminder prompt: AI biết ngày hiện tại, tính được độ trễ cực lâu (ví dụ từ 2023) để yêu cầu cập nhật due date mới.

---

#### 7.5 — Dashboard UI: Mention Notification Panel

**File**: `admin/templates/dashboard.php` + `admin/js/atd-dashboard.js` + `admin/css/atd-dashboard.css`

- [x] **DASH-11** Mention badge trên header Dashboard
  - Badge đỏ hiển thị số mention chưa đọc (cạnh tiêu đề "Asana Dashboard")
  - Click badge → scroll đến panel mentions hoặc mở modal

- [x] **DASH-12** Mention panel (hoặc tab riêng) trên Dashboard
  - Danh sách mention cards:
    - Avatar/tên người mention
    - Tên task (link tới Asana)
    - Nội dung comment (preview 150 ký tự, expand full)
    - Thời gian (relative: "2 giờ trước")
    - Nút **[💬 Trả lời]** → mở chat AI với context mention
    - Nút **[✓ Đã đọc]** → mark as read
  - Filter: Tất cả / Chưa đọc / Đã đọc

- [x] **JS-06** Khi dashboard load → gọi AJAX `atd_get_mentions` (unread)
  - Cập nhật badge count
  - Render mention cards vào panel
  - Nút [💬 Trả lời] → inject context vào chat input:
    `"Trả lời mention từ [Tên người] trên task [Tên task]: [nội dung]"`
  - Sau đó user gõ câu trả lời → AI xử lý reply

---

## 📡 Asana API Reference (cập nhật)

| Endpoint | Method | Dùng để | Scope cần |
|----------|--------|---------|-----------|
| `/tasks/{gid}/stories` | GET | Lấy comments + **html_text** chứa mention tags | `stories:read` |

### Mention Detection Logic

```
html_text chứa: <a data-asana-gid="USER_GID"/>
→ So sánh USER_GID với atd_asana_user_gid (option)
→ Nếu match + created_by !== user_gid → Đây là mention mới
```

---

### Phase 7 Tracking (10 items)
- [x] API-10: Cập nhật `get_task_stories()` thêm `html_text` vào opt_fields
- [x] CRON-06: `sync_mentions()` — parse html_text, phát hiện mention, lưu DB
- [x] CRON-07: Tạo bảng `wp_atd_mentions` (schema mention records)
- [x] CRON-08: Cập nhật `sync_all_data()` gọi `sync_mentions()`
- [x] AI-13: `get_unread_mentions()` — query mentions chưa đọc
- [x] AI-14: Cập nhật briefing prompt inject mention mới
- [x] AI-15: AJAX `atd_get_mentions` — API lấy danh sách mentions
- [x] AI-16: AJAX `atd_reply_mention` — AI soạn reply + post comment
- [x] AI-17: Cập nhật AI system prompt — tool reply_mention
- [x] DASH-11: Badge đỏ + mention count trên header
- [x] DASH-12: Mention panel với cards + filter
- [x] JS-06: Load mentions on dashboard + inject reply vào chat

---

### Phase 8 — Collaborator Tasks (4 items)
- [x] API-11: `get_project_tasks()`
- [x] CRON-09: `sync_collaborator_tasks()` (batch sync via cron)
- [x] DASH-13: UI "🤝 Collaborator" + Filter renaming
- [x] AI-18: Collaborator context in AI Assistant

### Phase 9 — Dynamic Filters & Counts (3 items)
- [x] PHP-11: AJAX handler returns unique projects
- [x] JS-07: `updateProjectDropdown()` logic
- [x] JS-08: Live group count updates (search/project filter)

### Phase 10 — Smart Due Date Intelligence (3 items)
- [x] JS-09: Deadline context in Analyze prompt
- [x] AI-20: Overdue awareness in Reminder prompt
- [x] AI-21: AI demands new due date for severely late tasks

---

**Tổng tiến độ**: 57/72 items hoàn thành 🟡

---

*Plan được soạn thảo bởi Trợ lý Antigravity — cập nhật `[x]` khi AI hoàn thành từng item.* 🚀
