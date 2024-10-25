@echo off
setlocal enabledelayedexpansion

REM Define the old and new license texts
set "old_license=/*************************************************************************"
set "new_license=/*************************************************************************"

REM Loop through all PHP files in the current directory and subdirectories
for /R %%f in (*.php) do (
    set "file_updated=0"
    set "temp_file=%%~dpnf.tmp"

    REM Initialize a temporary file to store the updated content
    > "!temp_file!" (
        REM Read each line of the current file
        for /F "usebackq delims=" %%a in ("%%f") do (
            set "line=%%a"
            REM Check if the line contains the old license and replace it
            if "!line!"=="%old_license%" (
                echo %new_license%
                set "file_updated=1"
            ) else (
                echo !line!
            )
        )
    )

    REM If the file was updated, replace the original file with the temporary file
    if !file_updated! equ 1 (
        move /Y "!temp_file!" "%%f"
        echo Replaced license in %%f
    ) else (
        del "!temp_file!" 2>nul
    )
)

echo License replacement complete.
endlocal
