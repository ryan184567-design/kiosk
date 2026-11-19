@echo off
REM Start visitor-front production server, auto-restart when the process exits.
REM Output is appended to logs\service.log
REM Usage: start.bat [full path of node.exe]   (default: node in PATH)
setlocal

set "NODE_EXE=%~1"
if "%NODE_EXE%"=="" set "NODE_EXE=node"

cd /d "%~dp0..\.."
if not exist logs mkdir logs

:loop
echo [%date% %time%] starting visitor-front >> logs\service.log
"%NODE_EXE%" server\index.js >> logs\service.log 2>&1
echo [%date% %time%] visitor-front exited with code %errorlevel%, restart in 5s >> logs\service.log
timeout /t 5 /nobreak > nul
goto loop
