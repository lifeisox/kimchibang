"""Operating release must block postponed expenses without touching stored records."""
from pathlib import Path
import subprocess,uuid,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,json,os
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_sales_release_'+uuid.uuid4().hex[:10];container=None
base='http://127.0.0.1:18094'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2000:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data).encode() if data else None) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as r:return r.code,r.read().decode()
def check(ok,label):
 assert ok,label
 print('PASS',label,flush=True)
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 check(app('echo query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'expenses\'")->fetchColumn();').strip()=='0','판매 운영 초기화에서 비용 테이블 생성 제외')
 # Emulate data saved while the feature was under development.
 app('putenv("APP_ENABLE_EXPENSES=1");require "database/expenses.php";query("INSERT INTO expense_categories (name) VALUES (?)",["보류자료 확인"]);query("INSERT INTO users (username,password_hash) VALUES (?,?)",["admin",password_hash("fixture-password-123",PASSWORD_DEFAULT)]);')
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 check(app('echo query("SELECT COUNT(*) FROM expense_categories WHERE name=?",["보류자료 확인"])->fetchColumn();').strip()=='1','기존 비용 테이블·보류 자료 삭제 없음')
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18094:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','-e','APP_ENABLE_EXPENSES=0','web']).strip()
 for _ in range(50):
  try:_,html=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 status,html=req(data={'action':'login','csrf':csrf,'username':'admin','password':'fixture-password-123'})
 check(status==200 and '비용·영수증' not in html and 'receipt-reader' not in html and 'wasm-unsafe-eval' not in html,'판매 화면에서 비용 메뉴·읽기 프로그램 제외')
 for page in ['home','orders','customers','new-order','production','material-calculator','production-settings','sales','accounts']:
  status,html=req('/?page='+page);check(status==200 and '화면을 불러오지 못했습니다' not in html,'운영 화면 '+page)
 for page in ['expenses','expense-edit','expense-report','expense-import','bookkeeping-suppliers','bookkeeping-accounts','bookkeeping-supplier','expense-receipt-view']:
  check(req('/?page='+page)[0]==404,'보류 화면 직접 접근 차단 '+page)
 for api in ['expense-receipt','expense-csv']:
  check(req('/?api='+api+'&id=1')[0]==404,'보류 API 차단 '+api)
 check(req(data={'action':'expense_save'})[0]==404,'보류 비용 저장 요청 차단')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
