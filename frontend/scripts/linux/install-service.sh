#!/usr/bin/env bash
# 安裝 systemd 服務，讓 visitor-front 開機自動啟動
# 用法：sudo bash scripts/linux/install-service.sh [--kiosk] [執行身分使用者]
#   --kiosk：同時設定該使用者「登入桌面後自動以 kiosk 模式開啟訪客機畫面」
set -euo pipefail

SERVICE_NAME="visitor-front"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

KIOSK=0
RUN_USER=""
for arg in "$@"; do
  case "$arg" in
    --kiosk) KIOSK=1 ;;
    *) RUN_USER="$arg" ;;
  esac
done
RUN_USER="${RUN_USER:-${SUDO_USER:-$(whoami)}}"
UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

if [[ $EUID -ne 0 ]]; then
  echo "請使用 sudo 執行：sudo bash $0" >&2
  exit 1
fi

# 以執行身分使用者的環境尋找 node（支援 nvm 等安裝方式）
NODE_BIN="$(sudo -u "$RUN_USER" -i bash -c 'command -v node' 2>/dev/null || true)"
NODE_BIN="${NODE_BIN:-$(command -v node || true)}"
if [[ -z "$NODE_BIN" ]]; then
  echo "找不到 node，請先安裝 Node.js" >&2
  exit 1
fi

if [[ ! -f "$PROJECT_DIR/dist/index.html" ]]; then
  echo "找不到 dist/，請先在專案目錄執行：npm install && npm run build" >&2
  exit 1
fi

if [[ ! -f "$PROJECT_DIR/.env" ]]; then
  cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"
  chown "$RUN_USER" "$PROJECT_DIR/.env"
fi

cat > "$UNIT_FILE" <<EOF
[Unit]
Description=Visitor Front (Vue3 web service)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${PROJECT_DIR}
EnvironmentFile=-${PROJECT_DIR}/.env
Environment=NODE_ENV=production
ExecStart=${NODE_BIN} ${PROJECT_DIR}/server/index.js
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"

echo "已安裝並啟動 ${SERVICE_NAME}"
echo "  node:  ${NODE_BIN}"
echo "  user:  ${RUN_USER}"
echo "  dir:   ${PROJECT_DIR}"
echo "查看狀態：systemctl status ${SERVICE_NAME}"
echo "查看日誌：journalctl -u ${SERVICE_NAME} -f"

if [[ $KIOSK -eq 1 ]]; then
  echo ""
  bash "$PROJECT_DIR/scripts/linux/install-kiosk.sh" "$RUN_USER"
fi
