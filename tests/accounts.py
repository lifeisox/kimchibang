"""Two independent authenticated sessions and own password changes, isolated DB/HTTP server."""
from pathlib import Path
import subprocess,uuid,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,json,datetime,zoneinfo
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_accounts_'+uuid.uuid4().hex[:12]
container=None
base='http://127.0.0.1:18085'
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2200:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
def new_client():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(client,path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data).encode() if data is not None else None) as r:return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url
def csrf(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
def login(client,user,password):
 _,html,_=req(client)
 return req(client,data={'action':'login','csrf':csrf(html),'username':user,'password':password})
def check(ok,label):
 assert ok,label
 print('PASS',label)
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 app('query("INSERT INTO users (username,password_hash) VALUES (?,?)",["admin",password_hash("fixture-admin-123",PASSWORD_DEFAULT)]);')
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18085:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','web']).strip()
 one=new_client();two=new_client()
 for _ in range(30):
  try:_,html,_=req(one);break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 _,html,_=login(one,'admin','fixture-admin-123');token=csrf(html)
 payload={'action':'account_create','csrf':token,'new_username':'second_fixture','new_password':'fixture-second-123','password_confirmation':'fixture-second-123'}
 check(req(one,'/?page=accounts',dict(payload,csrf='wrong'))[0]==422,'계정 추가 CSRF 검사')
 status,html,_=req(one,'/?page=accounts',payload);check(status==200 and '새 계정을 추가했습니다' in html,'앱에서 두 번째 계정 추가')
 check(req(one,'/?page=accounts',payload)[0]==422,'중복 아이디 차단')
 status,html,_=login(two,'second_fixture','fixture-second-123');check(status==200 and 'second_fixture 님' in html,'별도 계정 로그인')
 check('admin 님' in req(one)[1] and 'second_fixture 님' in req(two)[1],'두 브라우저 세션 동시 로그인 유지')
 token=csrf(html)
 status,html,url=req(two,'/?page=customer-edit',{'action':'customer','csrf':token,'name':'가상 계정 고객','preferred_fulfillment':'pickup'})
 cid=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0]);pid=app('echo query("SELECT id FROM products WHERE code=\'포5\'")->fetchColumn();').strip()
 today=datetime.datetime.now(zoneinfo.ZoneInfo('America/Toronto')).date().isoformat()
 status,html,url=req(two,'/?page=new-order',{'action':'order','csrf':token,'request_key':uuid.uuid4().hex,'customer_id':cid,'order_date':today,'delivery_date':today,'fulfillment':'pickup','discount':'0',f'qty[{pid}]':'1'})
 oid=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0])
 check(app('echo query("SELECT u.username FROM orders o JOIN users u ON u.id=o.created_by WHERE o.id=?",['+str(oid)+'])->fetchColumn();').strip()=='second_fixture','주문 생성자 아이디 기록')
 check('second_fixture' in req(one,'/?page=order&id='+str(oid))[1],'다른 계정이 같은 주문·작성 이력 확인')
 token=csrf(html)
 bad={'action':'account_password','csrf':token,'current_password':'wrong','new_password':'fixture-new-password-123','password_confirmation':'fixture-new-password-123'}
 check(req(two,'/?page=accounts',bad)[0]==422,'현재 비밀번호 없이는 변경 차단')
 status,html,_=req(two,'/?page=accounts',dict(bad,current_password='fixture-second-123'));check(status==200 and '내 비밀번호를 변경했습니다' in html,'내 비밀번호만 변경')
 three=new_client();check(login(three,'second_fixture','fixture-second-123')[0]==422,'이전 비밀번호 로그인 차단');check(login(three,'second_fixture','fixture-new-password-123')[0]==200,'새 비밀번호 로그인')
 check('admin 님' in req(one)[1],'다른 계정 세션·비밀번호에 영향 없음')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
