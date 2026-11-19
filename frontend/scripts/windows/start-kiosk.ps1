# 以 kiosk 模式（全螢幕、無網址列）開啟訪客機畫面
# 由 install-kiosk.ps1 建立的工作排程在「使用者登入後」自動執行，也可手動執行：
#   powershell -ExecutionPolicy Bypass -File scripts\windows\start-kiosk.ps1
#
# 設定（.env，皆可省略）：
#   KIOSK_URL              網站根網址，預設 http://localhost:<PORT>
#   KIOSK_BROWSER          auto（預設，Chrome 優先）| chrome | edge | 瀏覽器 exe 完整路徑
#   KIOSK_MODE             dual（預設，雙螢幕：訪客 + 櫃台）| single（只開訪客畫面）
#   KIOSK_VISITOR_DISPLAY  訪客畫面所在螢幕編號（由左到右 1、2…，預設 1）
#   KIOSK_STAFF_DISPLAY    櫃台畫面所在螢幕編號（預設 2）
param(
    [string]$Url,
    [string]$Browser,
    [string]$Mode,
    [int]$WaitSeconds = 180,
    # 只顯示將執行的指令，不實際開啟瀏覽器（測試用）
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$projectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$logDir = Join-Path $projectDir 'logs'
$logFile = Join-Path $logDir 'kiosk.log'

function Write-Log([string]$msg) {
    $line = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Write-Host $line
    try {
        if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }
        Add-Content -Path $logFile -Value $line -Encoding UTF8
    } catch {}
}

# ── 讀取 .env ──
$envMap = @{}
$envFile = Join-Path $projectDir '.env'
if (Test-Path $envFile) {
    Get-Content $envFile -Encoding UTF8 | ForEach-Object {
        if ($_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') { $envMap[$Matches[1]] = $Matches[2] }
    }
}
function Get-Setting([string]$key, [string]$default) {
    if ($envMap[$key]) { return $envMap[$key] } else { return $default }
}
$port = Get-Setting 'PORT' '3000'
if (-not $Url) { $Url = Get-Setting 'KIOSK_URL' "http://localhost:$port" }
if (-not $Browser) { $Browser = Get-Setting 'KIOSK_BROWSER' 'auto' }
if (-not $Mode) { $Mode = Get-Setting 'KIOSK_MODE' 'dual' }
$visitorDisplay = [int](Get-Setting 'KIOSK_VISITOR_DISPLAY' '1')
$staffDisplay = [int](Get-Setting 'KIOSK_STAFF_DISPLAY' '2')

$uri = [Uri]$Url
$baseUrl = '{0}://{1}' -f $uri.Scheme, $uri.Authority

# ── 尋找瀏覽器 ──
$chromePaths = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
)
$edgePaths = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
)
switch ($Browser.ToLower()) {
    'chrome' { $candidates = $chromePaths }
    'edge'   { $candidates = $edgePaths }
    'auto'   { $candidates = $chromePaths + $edgePaths }
    default  { $candidates = @($Browser) }
}
$exe = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if (-not $exe) {
    Write-Log "找不到瀏覽器（KIOSK_BROWSER=$Browser），請安裝 Google Chrome 或 Microsoft Edge"
    exit 1
}

# ── 取得螢幕位置（由左到右、由上到下排序） ──
Add-Type -AssemblyName System.Windows.Forms
$screens = @([System.Windows.Forms.Screen]::AllScreens | Sort-Object { $_.Bounds.X }, { $_.Bounds.Y })
for ($i = 0; $i -lt $screens.Count; $i++) {
    $b = $screens[$i].Bounds
    Write-Log ("螢幕 {0}：{1}x{2}，位置 ({3},{4}){5}" -f ($i + 1), $b.Width, $b.Height, $b.X, $b.Y, $(if ($screens[$i].Primary) { '，主螢幕' } else { '' }))
}
function Get-ScreenBounds([int]$no) {
    if ($no -ge 1 -and $no -le $screens.Count) { return $screens[$no - 1].Bounds }
    return $null
}

# ── 等待網頁服務啟動（開機時服務可能尚未就緒） ──
$healthUrl = "$baseUrl/health"
Write-Log "等待網頁服務就緒：$healthUrl"
$deadline = (Get-Date).AddSeconds($WaitSeconds)
$ready = $false
while ((Get-Date) -lt $deadline) {
    try {
        Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 3 | Out-Null
        $ready = $true
        break
    } catch {
        Start-Sleep -Seconds 2
    }
}
if (-not $ready) { Write-Log "等待逾時（$WaitSeconds 秒），仍嘗試開啟瀏覽器" }

# ── 以 kiosk 模式開啟 ──
# 每個視窗使用獨立的瀏覽器設定檔：避免第二個視窗併入第一個而忽略位置，也避免「還原分頁」等提示
function Start-KioskWindow([string]$name, [string]$pageUrl, $bounds) {
    $profileDir = Join-Path $env:LOCALAPPDATA "visitor-front\kiosk-profile-$name"
    $browserArgs = @(
        '--kiosk', $pageUrl,
        "--user-data-dir=`"$profileDir`"",
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-session-crashed-bubble',
        '--disable-infobars',
        '--noerrdialogs',
        '--disable-translate',
        '--disable-features=Translate',
        '--overscroll-history-navigation=0',
        '--disable-pinch'
    )
    if ($bounds) {
        $browserArgs += "--window-position=$($bounds.X),$($bounds.Y)"
        $browserArgs += "--window-size=$($bounds.Width),$($bounds.Height)"
    }
    if ($exe -like '*msedge.exe') { $browserArgs += '--edge-kiosk-type=fullscreen' }

    if ($DryRun) {
        Write-Host "[DryRun] $name：`"$exe`" $($browserArgs -join ' ')"
        return
    }
    Write-Log "開啟 kiosk（$name）：$pageUrl"
    Start-Process -FilePath $exe -ArgumentList $browserArgs
}

if ($Mode.ToLower() -eq 'single') {
    Start-KioskWindow 'visitor' "$baseUrl/" (Get-ScreenBounds $visitorDisplay)
} else {
    $visitorBounds = Get-ScreenBounds $visitorDisplay
    $staffBounds = Get-ScreenBounds $staffDisplay
    if (-not $visitorBounds) { Write-Log "找不到訪客螢幕 $visitorDisplay，改用主螢幕" }
    Start-KioskWindow 'visitor' "$baseUrl/" $visitorBounds
    if ($staffBounds) {
        Start-Sleep -Seconds 1
        Start-KioskWindow 'staff' "$baseUrl/staff" $staffBounds
    } else {
        Write-Log "只偵測到 $($screens.Count) 個螢幕，找不到櫃台螢幕 $staffDisplay，未開啟櫃台畫面（可手動開啟 $baseUrl/staff）"
    }
}
