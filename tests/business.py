"""Real HTTP sales/consignment/invoice tests in a disposable database. No emails sent."""
from pathlib import Path
import subprocess,uuid,json,time,re,urllib.request,urllib.parse,urllib.error,http.cookiejar,os
ROOT=Path(__file__).resolve().parent.parent;name='kimchi_biz_'+uuid.uuid4().hex[:10];container=None;base='http://127.0.0.1:18100'
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-3000:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','APP_ENABLE_BUSINESS=1','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data).encode() if data is not None else None) as r:return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url

def check(v,label):
 assert v,label
 print('PASS',label,flush=True)
def post(data):return req('/?page=biz',dict(data,csrf=csrf))
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','APP_ENABLE_BUSINESS=1','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 app("query('INSERT INTO users (username,password_hash) VALUES (?,?)',['admin',password_hash('fixture-password-123',PASSWORD_DEFAULT)]);query(\"UPDATE biz_settings SET address='Fixture address',gst='Fixture GST'\");query('UPDATE biz_products SET retail_cents=499');foreach(query('SELECT id FROM biz_clients')->fetchAll(PDO::FETCH_COLUMN) as $c)foreach(query('SELECT id FROM biz_products')->fetchAll(PDO::FETCH_COLUMN) as $p)query('INSERT INTO biz_prices VALUES (?,?,300)',[$c,$p]);")
 ids=json.loads(app("echo json_encode(['clients'=>array_column(query('SELECT id,code FROM biz_clients')->fetchAll(),'id','code'),'products'=>array_column(query('SELECT id,en,pack FROM biz_products')->fetchAll(),'id','en')]);"))
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18100:8000','-e','APP_ENABLE_BUSINESS=1','-e','APP_ENABLE_EXPENSES=1','-e','APP_SECURE_COOKIE=0','-e','DB_NAME='+name,'web']).strip()
 # This server uses bookkeeping too.
 cmd(['exec','-T','-e','APP_ENABLE_EXPENSES=1','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 for _ in range(40):
  try:_,html,_=req();break
  except OSError:time.sleep(.2)
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 _,html,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':'fixture-password-123'});csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 check(html.index('판매 관리</summary>')<html.index('김치방</summary>')<html.index('기장 (Bookkeeping)</summary>'),'메뉴 순서')
 check(app('echo query("SELECT COUNT(*) FROM biz_products")->fetchColumn();').strip()=='31','한영 병기 제품31종·Cheese 제외')
 def plan(c,p,q,day='2026-11-16'):
  status,h,u=post({'action':'biz_plan','client_id':ids['clients'][c],'day':day,'qty['+str(ids['products'][p])+']':q,'request_key':uuid.uuid4().hex});check(status==200,'계획 '+c);return int(app('echo query("SELECT MAX(id) FROM biz_deliveries")->fetchColumn();'))
 d=plan('OK','Crab Rice Ball',10);check(post({'action':'biz_deliver','id':d,'delivered':10})[0]==200,'위탁 납품')
 check(app('echo query("SELECT COUNT(*) FROM biz_invoices")->fetchColumn();').strip()=='0','위탁 납품은 매출 아님')
 key=uuid.uuid4().hex;data={'action':'biz_result','id':d,'day':'2026-11-16','sold':8,'returned':2,'staff':0,'request_key':key};check(post(data)[0]==200,'실판매·반품');check(post(data)[0]==422,'중복 판매 차단')
 data={'action':'biz_settle','client_id':ids['clients']['OK'],'start':'2026-11-16','end':'2026-11-22'};status,html,url=post(data);check(status==200,'OK 정산')
 inv=json.loads(app('echo query("SELECT snapshot FROM biz_invoices ORDER BY id DESC LIMIT 1")->fetchColumn();'));check(inv['net']==3194 and inv['hst']==415 and inv['total']==3609,'OK 20% 할인 후 HST13%')
 check(post(data)[0]==200 and app('echo query("SELECT COUNT(*) FROM biz_invoices")->fetchColumn();').strip()=='1','같은 기간 이중 발행 방지')
 d=plan('HN','Sliced Kimchi',2);check(post({'action':'biz_deliver','id':d,'delivered':2})[0]==200,'Hungry Ninja 직매출')
 check(app('echo query("SELECT total_cents FROM biz_invoices ORDER BY id DESC LIMIT 1")->fetchColumn();').strip()=='600','김치 HST0')
 d=plan('PT','Sliced Kimchi',2);check(post({'action':'biz_deliver','id':d,'delivered':2})[0]==200,'Popularitea 김치 납품');check(app('echo query("SELECT COUNT(*) FROM biz_invoices")->fetchColumn();').strip()=='2','Popularitea 김치는 월 정산까지 미청구');sale=int(app('echo query("SELECT MAX(id) FROM biz_sales")->fetchColumn();'));check(post({'action':'biz_staff','id':sale,'staff':1})[0]==200,'Popularitea 김치 직원 판매 수량')
 d=plan('PT','Tuna Triangle Kimbap',5);post({'action':'biz_deliver','id':d,'delivered':5});post({'action':'biz_result','id':d,'day':'2026-11-16','sold':5,'staff':2,'returned':0,'request_key':uuid.uuid4().hex});data={'action':'biz_settle','client_id':ids['clients']['PT'],'start':'2026-11-01','end':'2026-11-30'};check(post(data)[0]==422,'직원 할인 미등록 정산 차단');app("query(\"UPDATE biz_clients SET staff_discount_bp=5000 WHERE code='PT'\");");check(post(data)[0]==200,'직원 할인 정산');check(app('echo query("SELECT total_cents FROM biz_invoices ORDER BY id DESC LIMIT 1")->fetchColumn();').strip()=='1806','김치 포함 월 정산·직원 수량만 할인·HST')
 status,_,_=post({'action':'biz_payment','id':1,'amount':'36.09','day':'2026-11-23','method':'etransfer','request_key':uuid.uuid4().hex});check(status==200,'eTransfer 수금');check(post({'action':'biz_payment','id':1,'amount':'1','day':'2026-11-23','method':'cash','request_key':uuid.uuid4().hex})[0]==422,'초과 수금 차단')
 for page in ['biz','biz-plan','biz-production','biz-results','biz-invoices','biz-products','biz-clients','biz-invoice&id=1']:
  status,h,_=req('/?page='+page);check(status==200 and '화면을 불러오지 못했습니다' not in h,'화면 '+page)
 env=dict(os.environ,TEST_BASE=base,NODE_PATH='/workspace/browser-check/node_modules');r=subprocess.run(['node','tests/browser-business.cjs'],cwd=ROOT,env=env,text=True,capture_output=True);print(r.stdout);assert r.returncode==0,r.stderr
 info=subprocess.run(['pdfinfo','/tmp/rodem-business-invoice-max.pdf'],capture_output=True,text=True,check=True).stdout;check(bool(re.search(r'Pages:\s+1\b',info)),'최대 34개 긴 품목 A4 한 페이지')
finally:
 if container:subprocess.run(['docker','rm','-f',container],capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
