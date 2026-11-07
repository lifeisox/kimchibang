"""Replacement import: atomic delete+insert, backup restore, preferences, accounts/catalog preserved."""
from pathlib import Path
import subprocess,uuid,json,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_replace_'+uuid.uuid4().hex[:12]
container=None
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base='http://127.0.0.1:18084'
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2200:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data).encode() if data is not None else None) as r:return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url
def check(ok,label):
 assert ok,label
 print('PASS',label)
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 print(app('''require 'app/excel-import.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\\n";}
query('INSERT INTO users (username,password_hash) VALUES (?,?),(?,?)',['admin',password_hash('fixture-password-123',PASSWORD_DEFAULT),'second_fixture',password_hash('second-password-123',PASSWORD_DEFAULT)]);
$r=currentExcelPreview();check(($r['customer_preferences_schema']??0)===1,'원본 H 배달여부 읽기');
$first=executeImportPlan(buildImportPlan($r),1);
query("UPDATE products SET price_cents=7777 WHERE code='포5'");
query("INSERT INTO customers (name,notes) VALUES ('삭제할 가상 고객','fixture')");$cid=(int)db()->lastInsertId();
query("INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,total_cents,notes,created_by) VALUES (?,?,?,'가상 주소','2026-01-01','2026-01-02','delivery',5000,5000,'fixture',2)",[str_repeat('a',32),$cid,'삭제할 가상 고객']);$oid=(int)db()->lastInsertId();
query("INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) SELECT ?,id,code,name,weight_g,5000,1 FROM products WHERE code='포5'",[$oid]);
query("INSERT INTO payments (order_id,request_key,amount_cents,method,paid_on,note,created_by) VALUES (?,?,5000,'cash','2026-01-01','fixture',2)",[$oid,str_repeat('b',32)]);allocatePayment((int)db()->lastInsertId(),$oid,5000);refreshOrder($oid);
$p=buildImportPlan($r,true);check($p['delete_counts']['customers']===194 && $p['delete_counts']['orders']===947,'삭제 전 기존 건수와 새 건수를 별도로 준비');
$digest=importDatabaseDigest();$bad=$p;foreach($bad['records'] as &$entry)if($entry['kind']==='orders' && $entry['state']==='ready'){$entry['data']['customer_ref']='missing';break;}unset($entry);unset($bad['plan_sha']);$bad['plan_sha']=importHash($bad);
try{executeImportPlan($bad,1);throw new RuntimeException('rollback missing');}catch(InvalidArgumentException $e){check(hash_equals($digest,importDatabaseDigest()),'삭제 이후 오류 발생시 기존 업무자료 전체 롤백');}
$batch=executeImportPlan($p,1);
check((int)query('SELECT COUNT(*) FROM customers')->fetchColumn()===193 && (int)query('SELECT COUNT(*) FROM orders')->fetchColumn()===946,'기존 고객·주문 삭제 후 엑셀 건수만 저장');
check(!query('SELECT id FROM customers WHERE id=?',[$cid])->fetch() && !query('SELECT id FROM orders WHERE id=?',[$oid])->fetch(),'기존 가상 고객·거래 제거');
check((int)query('SELECT COUNT(*) FROM import_batches')->fetchColumn()===1 && (int)query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'이전 가져오기 이력 초기화 / 로그인 계정2 유지');
check((int)query("SELECT price_cents FROM products WHERE code='포5'")->fetchColumn()===7777,'현재 판매 제품·가격 설정 유지');
check((int)query('SELECT SUM(total_cents) FROM orders')->fetchColumn()===7784700,'과거 주문 금액은 현재 가격으로 바꾸지 않음');
check((int)query("SELECT COUNT(*) FROM customers WHERE preferred_fulfillment NOT IN ('delivery','pickup','church')")->fetchColumn()===0,'수령 기본값 전 고객 저장');
check((int)query("SELECT COUNT(*) FROM customers WHERE area NOT IN ('Barrhaven','Kanata','Stittsville','Nepean','Carlington','Downtown','Vanier','Findlay','East','Etc')")->fetchColumn()===0,'지역 목록으로 전 고객 분류');
check(executeImportPlan($p,1)===$batch,'같은 실행 재전송은 재삭제하지 않음');
$next=buildImportPlan($r,true);query("UPDATE customers SET notes=CONCAT(notes,' ') ORDER BY id LIMIT 1");$after=importDatabaseDigest();
try{executeImportPlan($next,1);throw new RuntimeException('stale');}catch(InvalidArgumentException $e){check(hash_equals($after,importDatabaseDigest()),'준비 후 수정된 자료는 삭제하지 않음');}
$backup=query('SELECT backup_name FROM import_batches WHERE id=?',[$batch])->fetchColumn();
db()->exec(file_get_contents(importBackupDirectory().'/'.$backup));
check((int)query('SELECT COUNT(*) FROM customers')->fetchColumn()===194 && (int)query('SELECT COUNT(*) FROM orders')->fetchColumn()===947,'초기화 직전 백업으로 기존 자료 복원 검증');
'''))
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18084:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','web']).strip()
 for _ in range(30):
  try:_,html,_=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 _,html,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':'fixture-password-123'})
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 status,html,url=req('/?page=excel-preview',{'action':'import_prepare','csrf':csrf})
 check(status==200 and '기존 고객 194명' in html and '주문 947건' in html,'실제 준비 화면에 삭제 범위 표시')
 token=re.search(r'name="job" value="([a-f0-9]+)"',html)[1]
 check(req('/?page=excel-preview',{'action':'import_execute','csrf':csrf,'job':token,'confirm_import':'yes'})[0]==422,'삭제 확인 없으면 기존 자료 삭제 차단')
 status,html,url=req('/?page=excel-preview',{'action':'import_execute','csrf':csrf,'job':token,'confirm_import':'yes','confirm_replace':'replace'})
 check(status==200 and '가져오기가 완료되었습니다' in html,'확인 후 HTTP 초기화 가져오기 완료')
 check(json.loads(app('echo json_encode([query("SELECT COUNT(*) FROM customers")->fetchColumn(),query("SELECT COUNT(*) FROM orders")->fetchColumn()]);'))==[193,946],'실제 실행 후 중복 없이 새 자료만 유지')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
