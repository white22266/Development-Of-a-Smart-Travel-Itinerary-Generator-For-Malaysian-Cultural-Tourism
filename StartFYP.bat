@echo off
setlocal

set "XAMPP=C:\xampp"
set "URL=http://localhost/Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism/"

echo [INFO] XAMPP path: %XAMPP%
echo [INFO] URL: %URL%
echo.

if not exist "%XAMPP%\xampp_start.exe" (
  echo [ERROR] Cannot find "%XAMPP%\xampp_start.exe"
  pause
  exit /b 1
)

start "" /min "%XAMPP%\xampp_start.exe"
timeout /t 3 /nobreak >nul
start "" "%URL%"

exit /b 0