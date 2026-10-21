#!/bin/zsh
cd "$(dirname "$0")" || exit 1
docker compose up -d --build || { echo 'Запустіть Docker Desktop і повторіть спробу.'; read; exit 1; }
open 'http://127.0.0.1:8086'
echo 'Програма: http://127.0.0.1:8086'
echo 'Демо пошта: http://127.0.0.1:8026'
