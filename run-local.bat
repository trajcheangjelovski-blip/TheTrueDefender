@echo off
REM ── TheTrueDefender — start the site locally ──
REM Double-click this file to launch the web server, background worker,
REM the scheduler, and open the site in your browser.

set "PHP=C:\Users\Angjelovski-PC\php\php.exe"
cd /d "%~dp0pulse"

echo Starting TheTrueDefender...

start "TheTrueDefender - Web"       cmd /k ""%PHP%" artisan serve --host=127.0.0.1 --port=8010"
start "TheTrueDefender - Queue"     cmd /k ""%PHP%" artisan queue:work"
start "TheTrueDefender - Scheduler" cmd /k ""%PHP%" artisan schedule:work"

timeout /t 3 >nul
start "" http://127.0.0.1:8010

echo.
echo Site:  http://127.0.0.1:8010
echo Admin: http://127.0.0.1:8010/admin
echo.
echo Three terminal windows opened (Web, Queue, Scheduler).
echo Close those windows to stop the site.
