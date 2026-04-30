@echo off
REM Wrapper chạy crawler Python từ bất kỳ thư mục nào.
REM Tham số tùy ý sẽ được truyền vào: run_crawler.bat ielts 5
cd /d "%~dp0"
set PYTHONIOENCODING=utf-8
python -m crawler_py.run %*
