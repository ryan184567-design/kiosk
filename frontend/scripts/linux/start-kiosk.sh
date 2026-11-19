#!/usr/bin/env bash
# 以 kiosk 模式（全螢幕、無網址列）開啟訪客機畫面
# 由 install-kiosk.sh 建立的桌面自動啟動項目在「使用者登入桌面後」執行，也可手動執行：
#   bash scripts/linux/start-kiosk.sh
#
# 設定（.env，皆可省略）：
#   KIOSK_URL              網站根網址，預設 http://localhost:<PORT>
#   KIOSK_BROWSER          auto（預設）| chrome | chromium | edge | 瀏覽器執行檔完整路徑
#   KIOSK_MODE             dual（預設，雙螢幕：訪客 + 櫃台）| single（只開訪客畫面）
#   KIOSK_VISITOR_DISPLAY  訪客畫面所在螢幕編號（由左到右 1、2…，預設 1）
#   KIOSK_STAFF_DISPLAY    櫃台畫面所在螢幕編號（預設 2）
#
# 測試：DRY_RUN=1 bash scripts/linux/start-kiosk.sh（只顯示將執行的指令）
set -u

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
WAIT_SECONDS="${WAIT_SECONDS:-180}"
DRY_RUN="${DRY_RUN:-0}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# 讀取 .env 中的單一設定值
get_env() {
  [[ -f "$ENV_FILE" ]] || return 0
  grep -E "^[[:space:]]*$1[[:space:]]*=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- |
    sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/\r$//'
}

PORT="$(get_env PORT)"
PORT="${PORT:-3000}"
URL="${1:-$(get_env KIOSK_URL)}"
URL="${URL:-http://localhost:$PORT}"
BROWSER="$(get_env KIOSK_BROWSER)"
BROWSER="${BROWSER:-auto}"
MODE="$(get_env KIOSK_MODE)"
MODE="${MODE:-dual}"
VISITOR_DISPLAY="$(get_env KIOSK_VISITOR_DISPLAY)"
VISITOR_DISPLAY="${VISITOR_DISPLAY:-1}"
STAFF_DISPLAY="$(get_env KIOSK_STAFF_DISPLAY)"
STAFF_DISPLAY="${STAFF_DISPLAY:-2}"
BASE="$(echo "$URL" | sed -E 's#^(https?://[^/]+).*#\1#')"

# ── 尋找瀏覽器 ──
case "$BROWSER" in
  chrome) CANDIDATES=(google-chrome google-chrome-stable) ;;
  chromium) CANDIDATES=(chromium chromium-browser) ;;
  edge) CANDIDATES=(microsoft-edge microsoft-edge-stable) ;;
  auto) CANDIDATES=(google-chrome google-chrome-stable chromium chromium-browser microsoft-edge microsoft-edge-stable) ;;
  *) CANDIDATES=("$BROWSER") ;;
esac
BIN=""
for b in "${CANDIDATES[@]}"; do
  if command -v "$b" >/dev/null 2>&1; then BIN="$(command -v "$b")"; break; fi
  if [[ -x "$b" ]]; then BIN="$b"; break; fi
done
if [[ -z "$BIN" ]]; then
  log "找不到瀏覽器（KIOSK_BROWSER=$BROWSER），請安裝 Google Chrome 或 Chromium"
  exit 1
fi

# ── 取得螢幕位置（X11：xrandr；由左到右排序），每行格式 "X Y W H" ──
SCREENS=()
if command -v xrandr >/dev/null 2>&1 && [[ -n "${DISPLAY:-}" ]]; then
  while read -r line; do SCREENS+=("$line"); done < <(
    xrandr --listmonitors 2>/dev/null |
      sed -nE 's/.* ([0-9]+)\/[0-9]+x([0-9]+)\/[0-9]+\+([0-9]+)\+([0-9]+).*/\3 \4 \1 \2/p' |
      sort -n -k1,1 -k2,2
  )
fi
for i in "${!SCREENS[@]}"; do
  read -r sx sy sw sh <<<"${SCREENS[$i]}"
  log "螢幕 $((i + 1))：${sw}x${sh}，位置 (${sx},${sy})"
done
# 取得第 N 個螢幕的 "X Y W H"（找不到時回傳空字串）
screen_of() {
  local n="$1"
  if [[ "$n" -ge 1 && "$n" -le "${#SCREENS[@]}" ]]; then echo "${SCREENS[$((n - 1))]}"; fi
}

# ── 等待網頁服務啟動（開機時服務可能尚未就緒） ──
log "等待網頁服務就緒：$BASE/health"
for ((i = 0; i < WAIT_SECONDS; i += 2)); do
  if command -v curl >/dev/null 2>&1; then
    curl -fsS -m 3 "$BASE/health" >/dev/null 2>&1 && break
  elif command -v wget >/dev/null 2>&1; then
    wget -q -T 3 -O /dev/null "$BASE/health" && break
  else
    sleep 5 && break
  fi
  sleep 2
done

# ── 關閉螢幕保護與自動關閉螢幕（X11） ──
if [[ -n "${DISPLAY:-}" ]] && command -v xset >/dev/null 2>&1 && [[ "$DRY_RUN" != "1" ]]; then
  xset s off -dpms s noblank 2>/dev/null || true
fi

# ── 以 kiosk 模式開啟 ──
# 每個視窗使用獨立的瀏覽器設定檔：避免第二個視窗併入第一個而忽略位置，也避免「還原分頁」等提示
start_window() {
  local name="$1" page_url="$2" geometry="$3"
  local profile="${XDG_CONFIG_HOME:-$HOME/.config}/visitor-front-kiosk-$name"
  local args=(
    --kiosk "$page_url"
    --user-data-dir="$profile"
    --no-first-run
    --no-default-browser-check
    --disable-session-crashed-bubble
    --disable-infobars
    --noerrdialogs
    --disable-translate
    --disable-features=Translate
    --overscroll-history-navigation=0
    --disable-pinch
    --password-store=basic
  )
  if [[ -n "$geometry" ]]; then
    local x y w h
    read -r x y w h <<<"$geometry"
    args+=(--window-position="$x,$y" --window-size="$w,$h")
  fi
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "[DryRun] $name：$BIN ${args[*]}"
    return
  fi
  log "開啟 kiosk（$name）：$page_url"
  "$BIN" "${args[@]}" >/dev/null 2>&1 &
}

if [[ "$MODE" == "single" ]]; then
  start_window visitor "$BASE/" "$(screen_of "$VISITOR_DISPLAY")"
else
  start_window visitor "$BASE/" "$(screen_of "$VISITOR_DISPLAY")"
  STAFF_GEOMETRY="$(screen_of "$STAFF_DISPLAY")"
  if [[ -n "$STAFF_GEOMETRY" ]]; then
    sleep 1
    start_window staff "$BASE/staff" "$STAFF_GEOMETRY"
  else
    log "偵測到 ${#SCREENS[@]} 個螢幕，找不到櫃台螢幕 $STAFF_DISPLAY，未開啟櫃台畫面（可手動開啟 $BASE/staff）"
  fi
fi

# 保持執行直到瀏覽器關閉（桌面自動啟動項目可正確追蹤）
[[ "$DRY_RUN" == "1" ]] || wait
