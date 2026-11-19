# 移除登入後自動開啟 kiosk 的工作排程
# 用法（以系統管理員身分開啟 PowerShell）：
#   powershell -ExecutionPolicy Bypass -File scripts\windows\uninstall-kiosk.ps1
param(
    [string]$TaskName = 'visitor-front-kiosk'
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error '請以「系統管理員身分」執行 PowerShell 後再執行此腳本'
    exit 1
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "已移除 kiosk 工作排程：$TaskName"
} else {
    Write-Host "找不到 kiosk 工作排程：$TaskName"
}
