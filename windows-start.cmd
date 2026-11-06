@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\windows-start.ps1"
if errorlevel 1 echo Please send the error text to your developer. Do not include passwords.
pause
