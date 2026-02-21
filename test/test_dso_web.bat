@echo off
REM Test script for DSO Visibility Web Application
REM This tests the Python script directly before trying the web interface

echo ================================================
echo DSO Visibility Web Application - Test Script
echo ================================================
echo.

REM Set paths
set VENV_PYTHON=C:\Astronomy\Apps\pythonScripts\venv\Scripts\python.exe
set SCRIPT_PATH=C:\laragon7\www\astro\pythonscripts\todays_dsos_web.py

echo Checking if Python virtual environment exists...
if not exist "%VENV_PYTHON%" (
    echo ERROR: Python virtual environment not found at:
    echo %VENV_PYTHON%
    echo.
    echo Please create the virtual environment first:
    echo   cd C:\Astronomy\Apps\pythonScripts
    echo   python -m venv venv
    echo   venv\Scripts\activate
    echo   pip install numpy pandas skyfield astropy astroquery
    pause
    exit /b 1
)
echo ✓ Virtual environment found
echo.

echo Checking if Python script exists...
if not exist "%SCRIPT_PATH%" (
    echo ERROR: Python script not found at:
    echo %SCRIPT_PATH%
    pause
    exit /b 1
)
echo ✓ Python script found
echo.

echo Running Python script for today's date...
echo (This may take 30-60 seconds...)
echo.
"%VENV_PYTHON%" "%SCRIPT_PATH%" > test_output.html 2>&1

if %ERRORLEVEL% neq 0 (
    echo.
    echo ERROR: Python script failed!
    echo Check test_output.html for error details
    pause
    exit /b 1
)

echo ✓ Python script executed successfully!
echo.
echo Output saved to: test_output.html
echo.
echo Opening in browser...
start test_output.html

echo.
echo ================================================
echo Next steps:
echo 1. Check the HTML output in your browser
echo 2. If it looks good, test the web interface at:
echo    http://localhost/astro/vis
echo 3. Try with a specific date:
echo    http://localhost/astro/vis?date=2025-11-21
echo ================================================
echo.
pause
