@echo off
rem Weekly job: place sync, place types, and once a month the public transport stops.
rem Works on the database named in .env (DB_NAME). Run from anywhere; paths come from this file's location.
rem
rem   1. tools\sync_places.php --all --no-publish    stages new places for review, publishes none.
rem   2. tools\classify_places.php --missing          gives new places a type. Every run.
rem   3. tools\sync_transit_stops.php --all           reloads the public transport stops. Stops change
rem      slowly, so it runs on the first run of each month: the month (yyyy-MM) of the last good stop
rem      sync is kept in storage\logs\transit_stops_month.txt, and the job runs when that is not the
rem      current month. A failed run leaves the file alone, so the next run tries again.
rem
rem Tools 2 and 3 refuse a database whose name does not end in _ctest unless --confirm=NAME is given.
rem The name is DB_NAME from the environment when it is set there (that is what the PHP code uses
rem first), else the DB_NAME line of .env. No other line of .env is read or shown. Without a usable
rem name (letters, digits and underscores) the script writes that to the log and runs nothing.
setlocal

set "ROOT=%~dp0.."
set "PHP=C:\xampp\php\php.exe"
set "LOGDIR=%ROOT%\storage\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%"

for /f %%d in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "STAMP=%%d"
for /f %%m in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM"') do set "MONTH=%%m"
set "LOG=%LOGDIR%\sync_places_%STAMP%.log"
set "MARK=%LOGDIR%\transit_stops_month.txt"

rem ---- the database name
set "DBNAME=%DB_NAME%"
if defined DBNAME goto checkname
if not exist "%ROOT%\.env" goto noname
for /f "usebackq tokens=1,* delims==" %%a in (`findstr /b /r /c:"DB_NAME *=" "%ROOT%\.env"`) do set "DBNAME=%%b"
if not defined DBNAME goto noname
rem leading spaces, quotes and trailing spaces
for /f "tokens=* delims= " %%t in ("%DBNAME%") do set "DBNAME=%%t"
if not defined DBNAME goto noname
set "DBNAME=%DBNAME:"=%"
set "DBNAME=%DBNAME:'=%"
if not defined DBNAME goto noname
:trimname
if not defined DBNAME goto noname
if not "%DBNAME:~-1%"==" " goto checkname
set "DBNAME=%DBNAME:~0,-1%"
goto trimname

:checkname
rem a database name is letters, digits and underscores; anything else is not run
echo "%DBNAME%"| findstr /r /x /c:"\"[A-Za-z0-9_][A-Za-z0-9_]*\"" >nul
if errorlevel 1 goto noname
goto havename

:noname
echo [%date% %time%] stopped: no usable DB_NAME in the environment or in .env, so nothing was run >> "%LOG%"
exit /b 1

:havename
echo [%date% %time%] weekly job started, database %DBNAME% >> "%LOG%"

rem ---- 1. place sync
echo [%date% %time%] place sync started >> "%LOG%"
"%PHP%" "%ROOT%\tools\sync_places.php" --all --no-publish >> "%LOG%" 2>&1
set "CODE=%ERRORLEVEL%"
echo [%date% %time%] place sync finished, exit code %CODE% >> "%LOG%"

rem ---- 2. types for new places
echo [%date% %time%] place types started >> "%LOG%"
"%PHP%" "%ROOT%\tools\classify_places.php" --missing "--confirm=%DBNAME%" >> "%LOG%" 2>&1
set "TYPECODE=%ERRORLEVEL%"
echo [%date% %time%] place types finished, exit code %TYPECODE% >> "%LOG%"
if "%CODE%"=="0" set "CODE=%TYPECODE%"

rem ---- 3. public transport stops, once a month
set "LASTMONTH="
if exist "%MARK%" set /p LASTMONTH=<"%MARK%"
if /i "%LASTMONTH%"=="%MONTH%" goto stopsdone
echo [%date% %time%] transport stops started, month %MONTH% >> "%LOG%"
"%PHP%" "%ROOT%\tools\sync_transit_stops.php" --all "--confirm=%DBNAME%" >> "%LOG%" 2>&1
set "STOPCODE=%ERRORLEVEL%"
echo [%date% %time%] transport stops finished, exit code %STOPCODE% >> "%LOG%"
if "%STOPCODE%"=="0" >"%MARK%" echo %MONTH%
if "%CODE%"=="0" set "CODE=%STOPCODE%"
goto finished

:stopsdone
echo [%date% %time%] transport stops skipped, done in %MONTH% already >> "%LOG%"

:finished
echo [%date% %time%] weekly job finished, exit code %CODE% >> "%LOG%"
exit /b %CODE%
