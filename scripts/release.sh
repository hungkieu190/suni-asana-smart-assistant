#!/bin/bash
# =============================================================
# release.sh — Đóng gói Plugin "Asana Teams Dashboard"
# Chạy bằng: npm run release
# Output: thư mục release/asana-teams-dashboard/
#         và file zip release/asana-teams-dashboard.zip
# =============================================================

set -e

PLUGIN_SLUG="asana-teams-dashboard"
RELEASE_DIR="release"
BUILD_DIR="${RELEASE_DIR}/${PLUGIN_SLUG}"
ROOT_DIR="$(pwd)"

# Lấy version từ file main plugin
VERSION=$(grep "Version:" asana-teams-dashboard.php | head -1 | awk -F: '{print $2}' | tr -d ' ')

echo ""
echo "🚀 Bắt đầu đóng gói plugin: ${PLUGIN_SLUG} v${VERSION}"
echo "============================================================="

# 1. Dọn sạch thư mục cũ
echo "🗑️  Xóa thư mục release cũ..."
rm -rf "${RELEASE_DIR}"
mkdir -p "${BUILD_DIR}"

# 2. Copy các file và thư mục cần thiết (bỏ qua dev files)
echo "📂 Copy files..."

cp asana-teams-dashboard.php "${BUILD_DIR}/"
[ -f README.md ] && cp README.md "${BUILD_DIR}/" || true

# Copy thư mục theo từng phần để lọc bỏ dev junk
cp -r admin "${BUILD_DIR}/admin"
cp -r includes "${BUILD_DIR}/includes"
cp -r languages "${BUILD_DIR}/languages"

# 3. Xóa các file không cần thiết trong build
echo "🧹 Dọn dẹp files không cần thiết..."

# Xóa các file dev
find "${BUILD_DIR}" -name ".DS_Store" -delete
find "${BUILD_DIR}" -name "Thumbs.db" -delete
find "${BUILD_DIR}" -name "*.log" -delete
find "${BUILD_DIR}" -name "*.bak" -delete

# 4. Tạo file zip (từ thư mục gốc, chỉ zip BUILD_DIR)
echo "📦 Tạo file ZIP..."
ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
(cd "${ROOT_DIR}" && zip -rq "${RELEASE_DIR}/${ZIP_NAME}" "${BUILD_DIR}")

echo ""
echo "✅ Đóng gói hoàn tất!"
echo "   📁 Thư mục: ${BUILD_DIR}"
echo "   📦 File ZIP: ${RELEASE_DIR}/${ZIP_NAME}"
echo ""
