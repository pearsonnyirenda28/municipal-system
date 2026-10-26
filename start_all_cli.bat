@echo off
REM ====================================================================
REM  start_all_cli.bat  -  starts everything in this project that runs
REM  from the command line, in one double-click.
REM
REM  What it starts
REM    1. cli_sync_engine.php  - the background sync engine. It stays open
REM       in its own window, so you can SHOW it working during the demo.
REM       It is started only once: if it already runs as the MunicipalSync
REM       Windows service (setup_municipal_sync_service.bat) or in another
REM       window, it is not started a second time.
REM    2. cli_backup.php       - takes one backup now (the app keeps the
REM       last 30 and deletes older ones by itself).
REM
REM  What it does NOT start
REM    ws\server.php - the WebSocket notification server. It is not
REM    connected to any page in this version (see ws\README.md), so
REM    starting it would do nothing.
REM
REM  Usage
REM    start_all_cli.bat                  start everything
REM    start_all_cli.bat stop             close the sync-engine window
REM    start_all_cli.bat schedule-backup  create a daily 02:00 backup task
REM
REM  Put this file in the project folder (next to cli_sync_engine.php).
REM  Every workstation runs its own copy. The Workspace Server runs NONE
REM  of these - it only receives what the workstations send.
REM ====================================================================
setlocal EnableExtensions

REM ---------- settings: change only if your folders differ ----------
set "XAMPP=C:\xampp"
REM 1 = also start Apache (and MySQL on a Workspace Server) if they are
REM not running yet. Leave 0 if you start them from the XAMPP Control Panel.
set "START_XAMPP=0"
REM 1 = take a backup each time you run this file
set "BACKUP_NOW=1"
REM 1 = open the system in the browser when everything is started
set "OPEN_BROWSER=1"
REM ------------------------------------------------------------------

set "APP_DIR=%~dp0"
if "%APP_DIR:~-1%"=="\" set "APP_DIR=%APP_DIR:~0,-1%"
if not exist "%APP_DIR%\cli_sync_engine.php" set "APP_DIR=%XAMPP%\htdocs\municipal-system"
if not exist "%APP_DIR%\cli_sync_engine.php" goto :noapp

set "PHP="
if exist "%XAMPP%\php\php.exe" set "PHP=%XAMPP%\php\php.exe"
if not defined PHP for %%P in (php.exe) do set "PHP=%%~$PATH:P"
if not defined PHP goto :nophp

if /I "%~1"=="stop" goto :stop
if /I "%~1"=="schedule-backup" goto :schedule

REM ---------- read the three settings this script needs from .env ----------
set "DRIVER=sqlite"
set "CENTRAL_URL="
set "SYNC_KEY="
if not exist "%APP_DIR%\.env" goto :noenv
for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%APP_DIR%\.env") do call :readenv "%%A" "%%B"
goto :envdone
:readenv
if /I "%~1"=="DB_DRIVER" set "DRIVER=%~2"
if /I "%~1"=="CENTRAL_SERVER_URL" set "CENTRAL_URL=%~2"
if /I "%~1"=="SYNC_API_KEY" set "SYNC_KEY=%~2"
goto :eof
:envdone

echo.
echo ===== Municipal System - starting =====
echo Folder: %APP_DIR%

REM ---------- optional: Apache / MySQL ----------
if not "%START_XAMPP%"=="1" goto :xamppdone
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if errorlevel 1 start "Apache" /MIN "%XAMPP%\apache\bin\httpd.exe"
if /I not "%DRIVER%"=="mysql" goto :xamppwait
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 start "MySQL" /MIN "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
:xamppwait
timeout /t 5 /nobreak >nul
:xamppdone
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if errorlevel 1 echo WARNING: Apache is not running. Start it from the XAMPP Control Panel.

REM ---------- backup ----------
if not "%BACKUP_NOW%"=="1" goto :backupdone
echo.
echo Taking a backup...
"%PHP%" "%APP_DIR%\cli_backup.php"
:backupdone

REM ---------- sync engine ----------
echo.
if not defined CENTRAL_URL goto :standalone
if not defined SYNC_KEY goto :standalone
sc query MunicipalSync 2>nul | find "RUNNING" >nul
if not errorlevel 1 goto :engineservice
tasklist /FI "WINDOWTITLE eq Municipal Sync Engine*" 2>nul | find /I "cmd.exe" >nul
if not errorlevel 1 goto :enginewindow
echo Starting the sync engine in its own window...
start "Municipal Sync Engine" /D "%APP_DIR%" cmd /k ""%PHP%" cli_sync_engine.php"
goto :enginedone
:standalone
echo NOTE: CENTRAL_SERVER_URL and/or SYNC_API_KEY are empty in .env.
echo This PC works on its own, but nothing will sync to the Workspace Server,
echo so the sync engine is NOT started. Fill in both settings and run this again.
goto :enginedone
:engineservice
echo The sync engine is already running as the MunicipalSync Windows service.
echo Nothing to start.
goto :enginedone
:enginewindow
echo The sync engine is already running in another window. Not starting a second copy.
:enginedone

if "%OPEN_BROWSER%"=="1" start "" "http://localhost/municipal-system/"
echo.
echo Done. In the system, open Sync Status (Admin menu) to see the engine at work.
echo To close the engine later: start_all_cli.bat stop
echo.
pause
exit /b 0

:stop
taskkill /FI "WINDOWTITLE eq Municipal Sync Engine*" /T /F
echo.
echo If it says no tasks matched, the engine is not running in a window.
echo If you installed it as a service instead, use:  nssm stop MunicipalSync
pause
exit /b 0

:schedule
schtasks /Create /SC DAILY /ST 02:00 /TN "Municipal System Backup" /TR "\"%PHP%\" \"%APP_DIR%\cli_backup.php\"" /F
echo.
echo A backup will now run every day at 02:00 while this PC is on.
pause
exit /b 0

:noapp
echo ERROR: cannot find the Municipal System folder.
echo Put this file inside the project folder, next to cli_sync_engine.php,
echo or change the XAMPP setting at the top of this file.
pause
exit /b 1

:nophp
echo ERROR: PHP was not found at %XAMPP%\php\php.exe or on the PATH.
echo Change the XAMPP setting at the top of this file to your XAMPP folder.
pause
exit /b 1

:noenv
echo ERROR: there is no .env file in %APP_DIR%
echo Copy .env.example to .env and fill it in first.
pause
exit /b 1
