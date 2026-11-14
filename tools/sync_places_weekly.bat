@echo off
rem Weekly place sync: stages new places for review, publishes none.
rem Works on the database named in .env (DB_NAME). Run from anywhere; paths come from this file's location.
setlocal

set "ROOT=%~dp0.."
set "PHP=C:\xampp\php\php.exe"
set "LOGDIR=%ROOT%\storage\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%"

for /f %%d in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "STAMP=%%d"
set "LOG=%LOGDIR%\sync_places_%STAMP%.log"

echo [%date% %time%] sync started >> "%LOG%"
"%PHP%" "%ROOT%\tools\sync_places.php" --all --no-publish >> "%LOG%" 2>&1
set "CODE=%ERRORLEVEL%"
echo [%date% %time%] sync finished, exit code %CODE% >> "%LOG%"
exit /b %CODE%
