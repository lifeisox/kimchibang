"""MariaDB integration: reproduce MySQL-only identifier failure, then prepare/import/restore in an isolated server."""
from pathlib import Path
import subprocess,uuid,json,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re
ROOT=Path(__file__).resolve().parent.parent
server='kimchi-maria-'+uuid.uuid4().hex[:10]
password=uuid.uuid4().hex
container=None
base='http://127.0.0.1:18089'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def command(args,data=None):
 r=subprocess.run(args,cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2400:].replace(password,'[fixture password]'))
 return r.stdout
def compose(args,data=None):return command(['docker','compose',*args],data)
env=['-e','DB_HOST='+server,'-e','DB_NAME=maria_fixture','-e','DB_USER=fixture','-e','DB_PASSWORD='+password]
def app(code):return compose(['exec','-T',*env,'web','php'],'<?php require "app/bootstrap.php";'+code)
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data).encode() if data is not None else None) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as r:return r.code,r.read().decode()
def check(ok,label):
 assert ok,label
 print('PASS',label,flush=True)
try:
 web=compose(['ps','-q','web']).strip()
 network=next(iter(json.loads(command(['docker','inspect',web]))[0]['NetworkSettings']['Networks']))
 command(['docker','run','-d','--name',server,'--network',network,'-e','MARIADB_ROOT_PASSWORD='+password,'-e','MARIADB_DATABASE=maria_fixture','-e','MARIADB_USER=fixture','-e','MARIADB_PASSWORD='+password,'mariadb:11.8'])
 for _ in range(100):
  r=subprocess.run(['docker','exec',server,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True)
  if r.returncode==0:break
  time.sleep(.3)
 else:raise RuntimeError('Isolated MariaDB startup failed')
 check('MariaDB' in app('echo query("SELECT VERSION()")->fetchColumn();'),'실제 MariaDB 11.8 시험 서버 연결')
 check(app('try{query("SELECT @@server_uuid");echo "unexpected";}catch(PDOException $e){echo $e->errorInfo[1];}').strip()=='1193','기존 server_uuid 조회 실패 재현 (1193)')
 compose(['exec','-T',*env,'web','php','scripts/init.php'])
 app('query("INSERT INTO users (username,password_hash) VALUES (?,?)",["admin",password_hash("fixture-password-123",PASSWORD_DEFAULT)]);')
 check(app('require "app/import-backup.php";echo importDatabaseIdentity()===importDatabaseIdentity()?"stable":"changed";').strip()=='stable','MariaDB 식별값 안정성')
 container=compose(['run','--rm','-d','--no-deps','-p','127.0.0.1:18089:8000',*env,'-e','APP_SECURE_COOKIE=0','-e','APP_HIDE_IMPORT_AFTER_COMPLETE=1','web']).strip()
 for _ in range(40):
  try:_,html=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 token=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 _,html=req(data={'action':'login','csrf':token,'username':'admin','password':'fixture-password-123'})
 token=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 status,html=req('/?page=excel-preview',{'action':'import_prepare','csrf':token})
 check(status==200 and '아직 삭제하거나 저장하지 않았습니다' in html,'MariaDB HTTP 저장할 내용 확인하기 성공')
 job=re.search(r'name="job" value="([a-f0-9]+)"',html)[1]
 check(json.loads(app('echo json_encode([query("SELECT COUNT(*) FROM customers")->fetchColumn(),query("SELECT COUNT(*) FROM orders")->fetchColumn()]);'))==[0,0],'준비 단계에서 고객·주문 쓰기 없음')
 status,html=req('/?page=excel-preview',{'action':'import_execute','csrf':token,'job':job,'confirm_replace':'replace','confirm_import':'yes'})
 check(status==200 and '가져오기가 완료되었습니다' in html,'MariaDB HTTP 백업 후 실제 가져오기 성공')
 counts=json.loads(app('echo json_encode([query("SELECT COUNT(*) FROM customers")->fetchColumn(),query("SELECT COUNT(*) FROM orders")->fetchColumn(),query("SELECT SUM(total_cents) FROM orders")->fetchColumn()]);'))
 check(counts[:2]==[193,946] and int(counts[2])==7784700,'193 고객·946 주문·당시 금액 보존')
 check('엑셀 가져오기' not in re.search(r'<nav id="main-menu".*?</nav>',html,re.S)[0],'운영 가져오기 완료 후 메뉴 숨김')
 backup=app('echo query("SELECT backup_name FROM import_batches ORDER BY id DESC LIMIT 1")->fetchColumn();').strip()
 output=compose(['exec','-T',*env,'web','php','scripts/restore-import.php',backup,'RESTORE_CURRENT_DATABASE'])
 check('복원 완료' in output and app('echo query("SELECT COUNT(*) FROM orders")->fetchColumn();').strip()=='0','MariaDB 백업 식별 검증·가져오기 전 상태 복원')
 # Existing MySQL identities must remain compatible with older backups/jobs.
 code='require "app/import-backup.php";$old=hash("sha256",getenv("DB_HOST")."|".query("SELECT DATABASE()")->fetchColumn()."|".query("SELECT @@server_uuid")->fetchColumn());echo hash_equals($old,importDatabaseIdentity())?"same":"changed";'
 check(compose(['exec','-T','web','php'],'<?php require "app/bootstrap.php";'+code).strip()=='same','기존 MySQL DB 식별값 변경 없음')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 subprocess.run(['docker','rm','-f',server],cwd=ROOT,capture_output=True)
