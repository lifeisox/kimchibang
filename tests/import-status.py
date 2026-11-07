"""Imported pending orders can be delivered/paid without rewriting source financial history."""
from pathlib import Path
import subprocess,uuid,json,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,datetime,zoneinfo,os
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_status_'+uuid.uuid4().hex[:12]
container=None
base='http://127.0.0.1:18086'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
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
def csrf(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
def state(oid):return json.loads(app('echo json_encode(query("SELECT * FROM orders WHERE id=?",['+str(oid)+'])->fetch());'))
def check(ok,label):
 assert ok,label
 print('PASS',label)
def action(oid,kind,**values):
 _,html,_=req('/?page=order&id='+str(oid))
 return req('/?page=order&id='+str(oid),{'csrf':csrf(html),'action':kind,'id':oid,'version':state(oid)['version'],'request_key':uuid.uuid4().hex,**values})
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 app('''query('INSERT INTO users (id,username,password_hash) VALUES (1,?,?)',['admin',password_hash('fixture-admin-123',PASSWORD_DEFAULT)]);
query("INSERT INTO customers (id,name,address,notes) VALUES (1,'MAP_FIXTURE','123 Example Street, Ottawa','fixture')");
query("INSERT INTO import_sources (id,source_sha,filename) VALUES (1,?,'fixture.xlsx')",[str_repeat('f',64)]);
query("INSERT INTO import_batches (id,source_id,plan_sha,backup_name,backup_sha,summary,created_by) VALUES (1,1,?,'fixture.sql',?,'{}',1)",[str_repeat('e',64),str_repeat('d',64)]);
$pid=query("SELECT id FROM products WHERE code='포5'")->fetchColumn();
foreach([[1,0,0,500,2000],[2,1,1,0,0],[3,0,1,0,0],[4,1,0,0,0]] as [$id,$delivered,$paid,$fee,$charge]) {
$total=5000+$fee+$charge;
query("INSERT INTO orders (id,request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,delivery_fee_cents,other_charges_cents,total_cents,received_cents,delivered,paid,notes,created_by,import_batch_id,delivery_state_known,payment_state_known,shipped_units) VALUES (?,?,1,'MAP_FIXTURE','123 Example Street, Ottawa','2024-01-01',NULL,'unknown',5000,?,?,?,?,?,?,'source fixture',1,1,?,?,?)",[$id,bin2hex(random_bytes(16)),$fee,$charge,$total,$paid?$total:0,$delivered,$paid,$id===1?0:1,$id===1?0:1,$delivered]);
query("INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity,delivered_quantity) VALUES (?,?,'포5','포기김치',5000,5000,1,?)",[$id,$pid,$delivered]);$iid=(int)db()->lastInsertId();
if($paid){query("INSERT INTO payments (order_id,request_key,amount_cents,method,paid_on,note,created_by) VALUES (?,?,?,'legacy',NULL,'source',1)",[$id,bin2hex(random_bytes(16)),$total]);query('INSERT INTO payment_allocations (payment_id,item_id,amount_cents) VALUES (?,?,?)',[db()->lastInsertId(),$iid,$total]);}
query("INSERT INTO import_records (source_id,entity_type,source_ref,content_sha,state,target_id,source_snapshot,last_batch_id) VALUES (1,'orders',?,?,'imported',?,'{\\"original\\":true}',1)",['24!'.$id,str_repeat('a',64),$id]);
}
foreach([['TEST-A','가상가',2,450],['TEST-B','가상나',10,100],['TEST-C','가상다',100,50]] as [$code,$label,$qty,$price]) {
query('INSERT INTO products (code,name,weight_g,price_cents,active) VALUES (?,?,1000,?,0)',[$code,$label,$price]);$p=(int)db()->lastInsertId();$sub=$qty*$price;
query("INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,total_cents,notes,created_by) VALUES (?,1,'MAP_FIXTURE','','2024-01-02','2024-01-02','pickup',?,?,'fixture',1)",[bin2hex(random_bytes(16)),$sub,$sub]);$o=(int)db()->lastInsertId();query('INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) VALUES (?,?,?,?,1000,?,?)',[$o,$p,$code,$label,$price,$qty]);
}
query("UPDATE products SET price_cents=9999 WHERE code='포5'");''')
 original=app('echo json_encode(query("SELECT source_ref,source_snapshot FROM import_records ORDER BY id")->fetchAll());')
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18086:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','web']).strip()
 for _ in range(30):
  try:_,html,_=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 _,html,_=req(data={'action':'login','csrf':csrf(html),'username':'admin','password':'fixture-admin-123'})
 _,html,_=req('/?page=order&id=1');check('선택 수량 전달 완료' in html and '입금 기록하기</button>' in html,'미완료 엑셀 주문의 전달·입금 폼 표시')
 today=datetime.datetime.now(zoneinfo.ZoneInfo('America/Toronto')).date().isoformat()
 check(action(1,'receive_payment',amount='75',payment_method='etransfer',paid_on=today,payer_name='TEST PAYER')[0]==200,'가져온 주문 배송 전 입금 가능')
 s=state(1);check(s['paid']==1 and s['delivered']==0 and s['payment_state_known']==1,'가져온 주문 선입금과 배송 전 상태 독립')
 check(action(1,'deliver_all',delivered_on=today)[0]==200 and state(1)['delivered']==1 and state(1)['delivery_state_known']==1,'가져온 주문 완납 후 전달 가능·미확인 표시 해제')
 check(state(1)['total_cents']==7500 and state(1)['other_charges_cents']==2000,'당시 금액·기타수입 보존')
 alloc=json.loads(app('echo json_encode(query("SELECT a.allocation_type,SUM(a.amount_cents) amount FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE p.order_id=1 GROUP BY a.allocation_type")->fetchAll(PDO::FETCH_KEY_PAIR));'))
 check({k:int(v) for k,v in alloc.items()}=={'product':5000,'delivery_fee':500,'other_charge':2000},'제품·배송비·기타수입 입금 배분')
 check(action(2,'receive_payment',amount='1',payment_method='cash',paid_on=today)[0]==422,'이미 완납한 엑셀 주문 중복 입금 차단')
 check(action(1,'cancel_order',reason='test')[0]==422,'엑셀 원본 주문 금액·취소 보호')
 check(action(3,'deliver_all',delivered_on=today)[0]==200,'이미 선입금된 가져오기 주문 전달 처리')
 check(action(4,'receive_payment',amount='50',payment_method='cash',paid_on=today)[0]==200 and state(4)['paid']==1,'배송 완료 미입금 주문 수금 가능')
 check(original==app('echo json_encode(query("SELECT source_ref,source_snapshot FROM import_records ORDER BY id")->fetchAll());'),'원본 엑셀 스냅샷 불변')
 env=os.environ.copy();env['NODE_PATH']='/workspace/browser-check/node_modules';env['TEST_BASE']=base
 r=subprocess.run(['node','tests/browser-maps-sort.cjs'],cwd=ROOT,env=env,text=True,capture_output=True)
 print(r.stdout)
 if r.returncode:raise RuntimeError(r.stderr[-2500:])
 import fitz
 pdf=fitz.open(ROOT/'.local/screenshots/order-print-margins.pdf')
 for page in pdf:
  boxes=page.get_text('blocks')
  check(all(x[0]>=55 and x[1]>=55 and x[2]<=page.rect.width-55 and x[3]<=page.rect.height-55 for x in boxes),'인쇄 PDF 본문 사방 약2cm 여백')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
