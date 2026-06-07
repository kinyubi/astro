@echo off
CD /d "%~dp0pythonScripts"
echo Running regenerate_blurbs.py to regenerate blurb files...
:: Assumes the virtual environment is already set up and activated
:: Usage:
::   .\regenerate_blurbs                     # process all objects
::   .\regenerate_blurbs.py SH2-101          # process one object by DSOKey
::   .\regenerate_blurbs.py --skip-existing  # skip objects that already have a blurb
python regenerate_blurbs.py %*