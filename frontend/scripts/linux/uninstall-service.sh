#!/usr/bin/env bash
# 移除 visitor-front 的 systemd 服務
# 用法：sudo bash scripts/linux/uninstall-service.sh [kiosk 使用者]
set -euo pipefail

SERVICE_NAME="visitor-front"
UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

if [[ $EUID -ne 0 ]]; then
  echo "請使用 sudo 執行：sudo bash $0" >&2
  exit 1
fi

systemctl disable --now "$SERVICE_NAME" 2>/dev/null || true
rm -f "$UNIT_FILE"
systemctl daemon-reload

echo "已移除 ${SERVICE_NAME} 服務"

# 一併移除 kiosk 自動啟動（若有）
bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/uninstall-kiosk.sh" "${1:-${SUDO_USER:-root}}" || true
