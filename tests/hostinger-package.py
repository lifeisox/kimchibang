"""Check release allowlist, non-public settings, entry point and env precedence without a DB write."""
from pathlib import Path
import subprocess,tempfile,zipfile,shutil,json
root=Path(__file__).resolve().parent.parent
release=root/'dist/kimchibang-hostinger.zip'
subprocess.run(['python','scripts/package-hostinger.py'],cwd=root,check=True)
work=Path(tempfile.mkdtemp(prefix='hostinger-test-',dir=root/'.local'))
try:
 with zipfile.ZipFile(release) as z:
  names=z.namelist()
  assert not any(n.endswith(('.xlsx','.env','local.php')) or '/.local/' in n or '/tests/' in n for n in names)
  assert not any(n.startswith('public_html/k/') and not (n in ['public_html/k/index.php','public_html/k/.htaccess','public_html/k/.user.ini','public_html/k/favicon.ico'] or n.startswith('public_html/k/assets/')) for n in names)
  z.extractall(work)
 (work/'kimchibang/config/local.php').write_text("<?php return ['DB_NAME'=>'fixture_only','DB_PASSWORD'=>'fixture-secret','APP_SECURE_COOKIE'=>'1','APP_ALLOW_INITIAL_SETUP'=>'0','APP_HIDE_IMPORT_AFTER_COMPLETE'=>'1'];")
 prefix='/app/'+work.relative_to(root).as_posix()
 def php(code,env=()):
  r=subprocess.run(['docker','compose','exec','-T',*env,'web','php','-r',code],cwd=root,capture_output=True,text=True)
  assert r.returncode==0,r.stderr
  return r.stdout
 # Existing Docker environment takes precedence, local file fills unset settings.
 result=php('require '+json.dumps(prefix+'/kimchibang/app/config.php')+';echo json_encode([getenv("DB_NAME"),getenv("APP_HIDE_IMPORT_AFTER_COMPLETE")]);',['-e','DB_NAME=env_fixture'])
 assert json.loads(result)==['env_fixture','1']
 html=php('$_SERVER["REQUEST_URI"]="/";$_SERVER["REQUEST_METHOD"]="GET";require '+json.dumps(prefix+'/public_html/k/index.php')+';')
 assert '로그인하기' in html and '초기 관리자 설정' not in html
 print('PASS release excludes credentials/customer files; private settings; environment precedence; split public entry renders login')
finally:shutil.rmtree(work)
