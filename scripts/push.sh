#!/bin/bash
# =============================================================
# push.sh — Push code lên GitHub
# Chạy bằng: npm run push
# =============================================================

set -e

# Lấy commit message từ tham số hoặc dùng mặc định
MSG="${1:-update: $(date '+%Y-%m-%d %H:%M')}"

echo ""
echo "📤 Đang push lên GitHub..."
echo "   Message: ${MSG}"
echo "============================================================="

git add -A
git commit -m "${MSG}" 2>/dev/null || echo "   ℹ️  Không có gì thay đổi để commit."
git push origin main

echo ""
echo "✅ Push thành công!"
echo ""
