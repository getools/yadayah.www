@echo off
REM Build a single-file Windows .exe of the Book PDF Generator.
REM Output: dist\YadaYahBookPDFGenerator.exe — copy anywhere
REM (no Python install required on the target machine).

py -m pip install --upgrade pyinstaller PySide6 requests requests-toolbelt pywin32 Pillow

REM Generate icon.ico from icon.png so the .exe shows the Manowrah
REM logo in Explorer. PNG works for the runtime QIcon, but the
REM file-icon embedded in the .exe binary needs Windows .ico format.
py -c "from PIL import Image; Image.open('icon.png').save('icon.ico', sizes=[(16,16),(24,24),(32,32),(48,48),(64,64),(128,128),(256,256)])"

py -m PyInstaller --noconfirm --windowed --onefile ^
    --name "YadaYahBookPDFGenerator" ^
    --icon icon.ico ^
    --add-data "icon.png;." ^
    yada_uploader.py

echo.
echo Built: dist\YadaYahBookPDFGenerator.exe
