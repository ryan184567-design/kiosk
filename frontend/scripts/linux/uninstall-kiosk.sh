#!/usr/bin/env bash
# 移除登入桌面後自動開啟 kiosk 的設定
# 用法：
#   bash scripts/linux/uninstall-kiosk.sh              # 目前使用者
#   sudo bash scripts/linux/uninstall-kiosk.sh 使用者    # 由 root 替指定使用者移除
set -euo pipefail

KIOSK_USER="${1:-${SUDO_USER:-$(whoami)}}"
USER_HOME="$(getent passwd "$KIOSK_USER" | cut -d: -f6)"
DESKTOP_FILE="$USER_HOME/.config/autostart/visitor-front-kiosk.desktop"

if [[ -f "$DESKTOP_FILE" ]]; then
  rm -f "$DESKTOP_FILE"
  echo "已移除 kiosk 自動啟動：$DESKTOP_FILE"
else
  echo "未設定 kiosk 自動啟動（$KIOSK_USER）"
fi
