#!/usr/bin/env bash
# 設定指定使用者「登入桌面後」自動以 kiosk 模式開啟訪客機畫面（XDG autostart）
# 用法：
#   bash scripts/linux/install-kiosk.sh              # 以目前使用者設定
#   sudo bash scripts/linux/install-kiosk.sh 使用者    # 由 root 替指定使用者設定
#
# ※ 瀏覽器需在桌面顯示，請搭配桌面環境「自動登入」才能開機直接進入訪客機畫面（見 README）
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
KIOSK_USER="${1:-${SUDO_USER:-$(whoami)}}"
USER_HOME="$(getent passwd "$KIOSK_USER" | cut -d: -f6)"

if [[ -z "$USER_HOME" ]]; then
  echo "找不到使用者：$KIOSK_USER" >&2
  exit 1
fi

AUTOSTART_DIR="$USER_HOME/.config/autostart"
DESKTOP_FILE="$AUTOSTART_DIR/visitor-front-kiosk.desktop"

mkdir -p "$AUTOSTART_DIR"
cat > "$DESKTOP_FILE" <<EOF
[Desktop Entry]
Type=Application
Name=Visitor Front Kiosk
Comment=訪客機 kiosk 畫面
Exec=bash "$PROJECT_DIR/scripts/linux/start-kiosk.sh"
Terminal=false
X-GNOME-Autostart-enabled=true
X-GNOME-Autostart-Delay=5
EOF

# 由 root 執行時，將檔案擁有者設回該使用者
if [[ $EUID -eq 0 ]]; then
  chown -R "$KIOSK_USER": "$USER_HOME/.config/autostart"
fi

echo "已設定 kiosk 自動啟動"
echo "  使用者：$KIOSK_USER（登入桌面後自動開啟）"
echo "  設定檔：$DESKTOP_FILE"
echo "  立即測試：bash $PROJECT_DIR/scripts/linux/start-kiosk.sh"
echo "  離開 kiosk 畫面：Alt + F4"
