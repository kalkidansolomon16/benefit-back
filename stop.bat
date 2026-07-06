@echo off
cd /d %~dp0
echo Stopping FitAccess Backend...
docker-compose down
echo Done!
pause
