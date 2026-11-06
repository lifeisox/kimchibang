"""Create private development configuration without overwriting existing files."""
from pathlib import Path
import secrets
root = Path(__file__).resolve().parent.parent
p = root / '.env'
if not p.exists():
    p.write_text('DB_PASSWORD=' + secrets.token_hex(24) + '\nDB_ROOT_PASSWORD=' + secrets.token_hex(24) + '\n')
    p.chmod(0o600)
print('개발 설정 준비 완료')
