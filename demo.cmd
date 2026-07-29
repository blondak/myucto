@echo off
setlocal
call "%~dp0production.cmd" demo demo
exit /b %errorlevel%
