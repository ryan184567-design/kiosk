# 建立工作排程：指定使用者「登入後」自動以 kiosk 模式開啟訪客機畫面
# 用法（以系統管理員身分開啟 PowerShell）：
#   powershell -ExecutionPolicy Bypass -File scripts\windows\install-kiosk.ps1 [-User 帳號]
#
# ※ 瀏覽器需在使用者桌面顯示，請搭配 Windows「自動登入」才能開機直接進入訪客機畫面（見 README）
param(
    [string]$TaskName = 'visitor-front-kiosk',
    # 要自動開啟 kiosk 的 Windows 帳號（預設為目前帳號）
    [string]$User = "$env:USERDOMAIN\$env:USERNAME"
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error '請以「系統管理員身分」執行 PowerShell 後再執行此腳本'
    exit 1
}

$kioskScript = Join-Path $PSScriptRoot 'start-kiosk.ps1'
$projectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$kioskScript`"" `
    -WorkingDirectory $projectDir
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $User
$taskPrincipal = New-ScheduledTaskPrincipal -UserId $User -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $taskPrincipal -Settings $settings `
    -Description 'Visitor Front kiosk browser (launch at user logon)' | Out-Null

Write-Host "已建立 kiosk 工作排程：$TaskName"
Write-Host "  使用者：$User（登入後自動開啟）"
Write-Host "  log：   $projectDir\logs\kiosk.log"
Write-Host '  立即測試：Start-ScheduledTask -TaskName' $TaskName
Write-Host '  離開 kiosk 畫面：Alt + F4'
