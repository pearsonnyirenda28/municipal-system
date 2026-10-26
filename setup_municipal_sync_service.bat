@echo off
REM ============================================================
REM setup_municipal_sync_service.bat
REM
REM Installs cli_sync_engine.php as a persistent, self-restarting
REM Windows service using NSSM, so it runs continuously in the
REM background - no terminal window to leave open, and it comes
REM back on its own if it ever crashes or the machine reboots.
REM
REM BEFORE RUNNING:
REM   1. Download NSSM from https://nssm.cc/download (get the
REM      win64 build unless this machine is 32-bit).
REM   2. Unzip it, find nssm.exe inside the win64 folder, and
REM      place that single file in the SAME folder as this script
REM      (or anywhere already on your PATH).
REM   3. Check the three paths below match your actual install -
REM      edit them if XAMPP or the project live somewhere else.
REM   4. Right-click this file -> "Run as administrator".
REM      (Installing a Windows service requires admin rights -
REM      the script checks for this and tells you plainly if
REM      you forgot.)
REM ============================================================

setlocal

set SERVICE_NAME=MunicipalSync
set PHP_PATH=C:\xampp\php\php.exe
set APP_DIR=C:\xampp\htdocs\municipal-system
set SCRIPT_PATH=%APP_DIR%\cli_sync_engine.php
set LOG_DIR=%APP_DIR%\logs

echo ============================================================
echo  Municipal System - Sync Engine Service Installer
echo ============================================================
echo.

REM --- Must be running as Administrator ---
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: This script must be run as Administrator.
    echo Right-click this file and choose "Run as administrator", then try again.
    pause
    exit /b 1
)

REM --- Find nssm.exe: already on PATH, or sitting next to this script ---
where nssm >nul 2>&1
if %errorlevel% neq 0 (
    if exist "%~dp0nssm.exe" (
        set "PATH=%~dp0;%PATH%"
    ) else (
        echo ERROR: nssm.exe not found.
        echo Download it from https://nssm.cc/download, extract nssm.exe
        echo from the win64 folder, and place it in this same folder:
        echo   %~dp0
        echo Then run this script again.
        pause
        exit /b 1
    )
)

REM --- Confirm PHP actually exists where we expect it ---
if not exist "%PHP_PATH%" (
    echo ERROR: PHP not found at %PHP_PATH%
    echo Edit PHP_PATH near the top of this script to match your XAMPP install, then rerun.
    pause
    exit /b 1
)

REM --- Confirm the sync engine script exists ---
if not exist "%SCRIPT_PATH%" (
    echo ERROR: cli_sync_engine.php not found at %SCRIPT_PATH%
    echo Edit APP_DIR near the top of this script to match where the project lives, then rerun.
    pause
    exit /b 1
)

REM --- Don't blindly reinstall over an existing service ---
sc query %SERVICE_NAME% >nul 2>&1
if %errorlevel% equ 0 (
    echo A service called %SERVICE_NAME% already exists.
    echo To reconfigure it: run "nssm remove %SERVICE_NAME% confirm" first, then rerun this script.
    pause
    exit /b 0
)

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo Installing %SERVICE_NAME% service...
nssm install %SERVICE_NAME% "%PHP_PATH%" "%SCRIPT_PATH%"
nssm set %SERVICE_NAME% AppDirectory "%APP_DIR%"
nssm set %SERVICE_NAME% DisplayName "Municipal System - Sync Engine"
nssm set %SERVICE_NAME% Description "Pushes pending local records to the Workspace Server and pulls master data back, over the municipal LAN."
nssm set %SERVICE_NAME% Start SERVICE_AUTO_START
nssm set %SERVICE_NAME% AppExit Default Restart
nssm set %SERVICE_NAME% AppRestartDelay 5000
REM A background service has no visible console - without this, cli_sync_engine.php's
REM own echo output (and PHP fatal errors, if any) would simply vanish.
nssm set %SERVICE_NAME% AppStdout "%LOG_DIR%\sync-service-out.log"
nssm set %SERVICE_NAME% AppStderr "%LOG_DIR%\sync-service-err.log"
nssm set %SERVICE_NAME% AppRotateFiles 1
nssm set %SERVICE_NAME% AppRotateBytes 1048576

echo.
echo Starting %SERVICE_NAME%...
nssm start %SERVICE_NAME%

timeout /t 3 /nobreak >nul
echo.
echo ============================================================
echo  Service status:
echo ============================================================
sc query %SERVICE_NAME%

echo.
echo ============================================================
echo  Recent log output (first few lines):
echo ============================================================
type "%LOG_DIR%\sync-service-out.log" 2>nul

echo.
echo ------------------------------------------------------------
echo Done. A few things worth doing next:
echo   - Log in to the app as Admin and open Sync Status to confirm
echo     it moves from WORKSPACE_UNAVAILABLE to ONLINE/SYNCHRONIZED.
echo   - If it stays on WORKSPACE_UNAVAILABLE, check that SYNC_API_KEY
echo     in this workstation's .env matches the Workspace Server's
echo     .env EXACTLY, and that CENTRAL_SERVER_URL is reachable from
echo     this machine (try it in a browser).
echo   - Log files if anything looks wrong:
echo       %LOG_DIR%\sync-service-out.log
echo       %LOG_DIR%\sync-service-err.log
echo   - To stop it:    nssm stop %SERVICE_NAME%
echo   - To remove it:  nssm remove %SERVICE_NAME% confirm
echo ------------------------------------------------------------
pause
