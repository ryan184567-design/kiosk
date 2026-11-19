# 建立 Windows 工作排程，讓 visitor-front 開機自動啟動（不需登入）
# 用法（以系統管理員身分開啟 PowerShell）：
#   powershell -ExecutionPolicy Bypass -File scripts\windows\install-task.ps1
#   加上 -Kiosk：同時設定「使用者登入後自動以 kiosk 模式開啟訪客機畫面」
#   加上 -KioskUser 帳號：指定 kiosk 使用者（預設為目前帳號）
param(
    [string]$TaskName = 'visitor-front',
    [switch]$Kiosk,
    [string]$KioskUser = "$env:USERDOMAIN\$env:USERNAME"
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error '請以「系統管理員身分」執行 PowerShell 後再執行此腳本'
    exit 1
}

$projectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$startBat = Join-Path $PSScriptRoot 'start.bat'

$nodeCmd = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeCmd) {
    Write-Error '找不到 node，請先安裝 Node.js'
    exit 1
}
$nodeExe = $nodeCmd.Source

if (-not (Test-Path (Join-Path $projectDir 'dist\index.html'))) {
    Write-Error '找不到 dist\，請先在專案目錄執行：npm install; npm run build'
    exit 1
}

$envFile = Join-Path $projectDir '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $projectDir '.env.example') $envFile
}

# 讀取 .env 的 PORT 以設定防火牆
$port = 3000
$portLine = Get-Content $envFile | Where-Object { $_ -match '^\s*PORT\s*=\s*(\d+)' } | Select-Object -First 1
if ($portLine -and ($portLine -match '(\d+)')) { $port = [int]$Matches[1] }

# 若已存在則先移除
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$action = New-ScheduledTaskAction -Execute 'cmd.exe' `
    -Argument "/c `"`"$startBat`" `"$nodeExe`"`"" `
    -WorkingDirectory $projectDir
$trigger = New-ScheduledTaskTrigger -AtStartup
$taskPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $taskPrincipal -Settings $settings `
    -Description 'Visitor Front (Vue3 web service) auto start' | Out-Null

# 防火牆開放連入
$ruleName = "$TaskName (TCP $port)"
if (-not (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP `
        -LocalPort $port -Action Allow | Out-Null
}

Start-ScheduledTask -TaskName $TaskName

Write-Host "已建立並啟動工作排程：$TaskName"
Write-Host "  node: $nodeExe"
Write-Host "  dir:  $projectDir"
Write-Host "  port: $port（已開放防火牆）"
Write-Host "  log:  $projectDir\logs\service.log"

if ($Kiosk) {
    Write-Host ''
    & (Join-Path $PSScriptRoot 'install-kiosk.ps1') -TaskName "$TaskName-kiosk" -User $KioskUser
}
