@echo off
REM Run WhatsApp CRM on this Windows computer. Double-click, or: start.bat [port]
REM Opens the web server and browser (installer on first run), then the background worker.
cd /d "%~dp0"
set PORT=%1
if "%PORT%"=="" set PORT=8000

where php >nul 2>nul
if errorlevel 1 (
  echo PHP was not found. Install PHP 8.3+ from https://windows.php.net/download/ and add it to PATH.
  echo See docs\INSTALL.md for step-by-step help.
  pause
  exit /b 1
)
php -r "exit(PHP_VERSION_ID >= 80300 ? 0 : 1);"
if errorlevel 1 (
  echo PHP 8.3 or newer is required.
  pause
  exit /b 1
)
if not exist storage\logs mkdir storage\logs
if not exist storage\sessions mkdir storage\sessions
if not exist storage\mail mkdir storage\mail

start "WhatsApp CRM - web server (close to stop)" php -S localhost:%PORT% -t public
timeout /t 2 /nobreak >nul
start "" http://localhost:%PORT%
echo WhatsApp CRM is running at http://localhost:%PORT%

if exist storage\installed.lock goto worker
findstr /r /b /c:"APP_KEY=." .env >nul 2>nul && goto worker
echo First run: finish the installer in your browser. Waiting...
:wait
timeout /t 2 /nobreak >nul
if not exist storage\installed.lock goto wait
echo Installed.

:worker
start "WhatsApp CRM - worker (keep open)" php bin\console queue:work
start "WhatsApp CRM - scheduler (keep open)" cmd /c "for /l %%i in () do (php bin\console schedule:run >nul & timeout /t 60 /nobreak >nul)"
echo Background worker started. Close the three WhatsApp CRM windows to stop.
pause
