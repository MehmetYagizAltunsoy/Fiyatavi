@echo off
cd /d "%~dp0"
:d
"%USERPROFILE%\php\php.exe" cron.php
timeout /t 15 >nul
goto d
