#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
python3 scripts/configure.py
docker compose up -d --build
docker compose exec -T web php scripts/init.php
if ! docker compose exec -T web php -r 'require "app/bootstrap.php"; exit(query("SELECT id FROM users WHERE username=?",["admin"])->fetch()?0:1);'; then
  python3 - <<'PY'
from pathlib import Path
import secrets
p=Path('.local/admin-password.txt'); p.parent.mkdir(exist_ok=True)
p.write_text(secrets.token_urlsafe(20)+'\n'); p.chmod(0o600)
PY
  docker compose exec -T web php scripts/create-user.php admin < .local/admin-password.txt
fi
printf '%s\n' '개발 서버 준비 완료. 아이디: admin / 비밀번호: .local/admin-password.txt' '개발 주소: http://127.0.0.1:8080 (작업 환경 내부)'
