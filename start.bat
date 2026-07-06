@echo off
cd /d %~dp0
echo Starting FitAccess Backend...
docker-compose up -d --build
echo.
echo Done! API running at http://localhost:8000
echo phpMyAdmin running at http://localhost:8081
pause
