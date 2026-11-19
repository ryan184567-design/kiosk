# 移除 visitor-front 的 Windows 工作排程與防火牆規則，並停止服務
# 用法（以系統管理員身分開啟 PowerShell）：
#   powershell -ExecutionPolicy Bypass -File scripts\windows\uninstall-task.ps1
param(
    [string]$TaskName = 'visitor-front'
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error '請以「系統管理員身分」執行 PowerShell 後再執行此腳本'
    exit 1
}

$projectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "已移除工作排程：$TaskName"
}

# 讀取 .env 的 PORT
$port = 3000
$envFile = Join-Path $projectDir '.env'
if (Test-Path $envFile) {
    $portLine = Get-Content $envFile | Where-Object { $_ -match '^\s*PORT\s*=\s*(\d+)' } | Select-Object -First 1
    if ($portLine -and ($portLine -match '(\d+)')) { $port = [int]$Matches[1] }
}

# 先結束 start.bat 的重啟迴圈（cmd.exe），再結束佔用該埠號的 node 程序
$startBat = Join-Path $projectDir 'scripts\windows\start.bat'
Get-CimInstance Win32_Process -Filter "Name = 'cmd.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains($startBat) } |
    ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch {} }

Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
    Select-Object -ExpandProperty OwningProcess -Unique |
    ForEach-Object {
        $p = Get-Process -Id $_ -ErrorAction SilentlyContinue
        if ($p -and $p.ProcessName -eq 'node') { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }
    }

Get-NetFirewallRule -DisplayName "$TaskName (TCP *" -ErrorAction SilentlyContinue |
    Remove-NetFirewallRule

Write-Host '已停止服務並移除防火牆規則'

# 一併移除 kiosk 工作排程（若有）
if (Get-ScheduledTask -TaskName "$TaskName-kiosk" -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName "$TaskName-kiosk" -Confirm:$false
    Write-Host "已移除 kiosk 工作排程：$TaskName-kiosk"
}
