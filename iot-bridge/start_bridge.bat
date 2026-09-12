@echo off
setlocal
cd /d "%~dp0"

where python >nul 2>&1
if errorlevel 1 (
    echo Python was not found on PATH.
    echo Install Python and pyserial/requests, then run this launcher again.
    timeout /t 15 /nobreak >nul
    exit /b 1
)

if not exist "%~dp0fingerprint_bridge.py" (
    echo fingerprint_bridge.py was not found in %~dp0
    timeout /t 15 /nobreak >nul
    exit /b 1
)

echo Starting DMD fingerprint bridge...
echo Configuration is loaded from process environment or iot-bridge\.env.
python "%~dp0fingerprint_bridge.py"
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo Fingerprint bridge stopped with error code %EXIT_CODE%.
    echo The launcher will exit so Task Scheduler can apply its restart policy.
    timeout /t 15 /nobreak >nul
)

exit /b %EXIT_CODE%
