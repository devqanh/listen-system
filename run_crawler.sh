#!/usr/bin/env bash
# Wrapper chạy crawler Python từ bất kỳ thư mục nào (Linux/macOS/Git Bash).
cd "$(dirname "$0")"
PYTHONIOENCODING=utf-8 python -m crawler_py.run "$@"
