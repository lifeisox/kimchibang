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
 d=plan('PT','Sliced Kimchi',2);check(post({'action':'biz_deliver','id':d,'delivered':2})[0]==200,'Popularitea 김치 납품');check(app('echo query("SELECT COUNT(*) FROM biz_invoices")->fetchColumn();').strip()=='2','Popularitea 김치는 월 정산까지 미청구');
 d=plan('PT','Tuna Triangle Kimbap',5);post({'action':'biz_deliver','id':d,'delivered':5});post({'action':'biz_result','id':d,'day':'2026-11-16','sold':5,'staff':0,'returned':0,'request_key':uuid.uuid4().hex});data={'action':'biz_settle','client_id':ids['clients']['PT'],'start':'2026-11-01','end':'2026-11-30'};data.update(staff_count=2,staff_unit='1.50',staff_confirm='yes');check(post(data)[0]==200,'Staff Purchases 별도 차감');check(app('echo query("SELECT total_cents FROM biz_invoices ORDER BY id DESC LIMIT 1")->fetchColumn();').strip()=='1956','김치 포함 월 정산·직원 차감 후 HST');snapshot=json.loads(app('echo query("SELECT snapshot FROM biz_invoices ORDER BY id DESC LIMIT 1")->fetchColumn();'));check(snapshot['lines'][-1]['en']=='Staff Purchases' and snapshot['lines'][-1]['net']==-300,'직원 판매 별도 인보이스 행');check(app('echo query("SELECT SUM(qty) FROM biz_sales s JOIN biz_deliveries d ON d.id=s.delivery_id WHERE d.client_id=3")->fetchColumn();').strip()=='7','Staff Purchases는 원래 판매 수량 불변')
 status,_,_=post({'action':'biz_payment','id':1,'amount':'36.09','day':'2026-11-23','method':'etransfer','request_key':uuid.uuid4().hex});check(status==200,'eTransfer 수금');check(post({'action':'biz_payment','id':1,'amount':'1','day':'2026-11-23','method':'cash','request_key':uuid.uuid4().hex})[0]==422,'초과 수금 차단')
 check(post({'action':'biz_client','id':0,'name':'개인 시험 고객','email':'','address':'Fixture pickup'})[0]==200,'개인 고객 추가')
 person=int(app('echo query("SELECT MAX(id) FROM biz_clients")->fetchColumn();'));before=int(app('echo query("SELECT MAX(id) FROM biz_deliveries")->fetchColumn();'))
 data={'action':'biz_plan','client_id':person,'day':'2026-11-24','request_key':uuid.uuid4().hex,'discount':'10','fulfillment':'pickup','qty['+str(ids['products']['Sliced Kimchi'])+']':1,'qty['+str(ids['products']['Beef Kimbap'])+']':2};check(post(data)[0]==200,'개인 픽업·묶음 주문 할인')
 delivery_ids=json.loads(app('echo json_encode(query("SELECT id FROM biz_deliveries WHERE id>? ORDER BY id",['+str(before)+'])->fetchAll(PDO::FETCH_COLUMN));'))
 for delivery in delivery_ids:
  qty=app('echo query("SELECT planned FROM biz_deliveries WHERE id=?",['+str(delivery)+'])->fetchColumn();').strip();check(post({'action':'biz_deliver','id':delivery,'delivered':qty})[0]==200,'개인 납품 확인')
 check(app('echo query("SELECT COUNT(*) FROM biz_invoices WHERE client_id=?",['+str(person)+'])->fetchColumn();').strip()=='1','여러 제품을 하나의 개인 인보이스로 발행')
 check(app('echo query("SELECT total_cents FROM biz_invoices WHERE client_id=?",['+str(person)+'])->fetchColumn();').strip()=='1464','개인 정가·10% 할인·품목별 HST')

 d=plan('OK','Crab Rice Ball',3,day='2026-11-30')
 check(post({'action':'biz_plan_update','id':d,'version':1,'planned':4})[0]==200,'납품 전 계획 수량 수정')
 check(post({'action':'biz_plan_update','id':d,'version':1,'planned':9})[0]==422,'계획 동시 수정 충돌 차단')
 check(post({'action':'biz_plan_update','id':d,'version':2,'planned':0})[0]==200,'계획 취소와 이력 보존')
 check(post({'action':'biz_deliver','id':d,'delivered':1})[0]==422,'취소한 계획 납품 차단')
 pid=ids['products']['Crab Rice Ball'];price_data={'action':'biz_catalog_save','id':pid,'ko':'크랩 주먹밥','en':'Crab Rice Ball','retail':'5.99'}
 for cid in ids['clients'].values():price_data['price_'+str(cid)]='3.50'
 check(post(price_data)[0]==200,'통합 가격표 저장')
 check(app('echo query("SELECT total_cents FROM biz_invoices WHERE id=1")->fetchColumn();').strip()=='3609','단가 수정 후 기존 인보이스 보존')
 # A product without any transaction can be removed, including after reinitialization.
 check(post({'action':'biz_product_save','id':0,'category':'kimchi','ko':'삭제 시험상품','en':'Deletable Fixture','retail':'1.00'})[0]==200,'미사용 상품 추가')
 deletable=int(app('echo query("SELECT MAX(id) FROM biz_products")->fetchColumn();'))
 check(post({'action':'biz_product_delete','id':deletable})[0]==200,'미사용 상품 삭제')
 app('foreach(query("SELECT id,retail_cents FROM biz_products WHERE id IN (1,'+str(ids['products']['Sliced Kimchi'])+','+str(ids['products']['Beef Kimbap'])+')")->fetchAll() as $p)query("INSERT INTO biz_prices VALUES (?,?,?) ON DUPLICATE KEY UPDATE cents=cents",['+str(person)+',$p["id"],$p["retail_cents"]]);')
 check(post({'action':'biz_order_save','batch_key':'','request_key':uuid.uuid4().hex,'client_id':person,'day':'2026-12-01','qty['+str(ids['products']['Tuna Kimbap'])+']':1})[0]==422,'거래처 납품가 미등록 상품 주문 차단')
 # Document-level workflow and deletion guards use real HTTP, not direct function mocks.
 check(post({'action':'biz_client_delete','id':ids['clients']['HN']})[0]==422,'거래내역이 있는 거래처 삭제 차단')
 check(post({'action':'biz_client','id':0,'name':'삭제용 거래처','email':'','address':''})[0]==200,'미사용 거래처 추가')
 unused=int(app('echo query("SELECT MAX(id) FROM biz_clients")->fetchColumn();'))
 check(post({'action':'biz_client_delete','id':unused})[0]==200 and app('echo query("SELECT active FROM biz_clients WHERE id=?",['+str(unused)+'])->fetchColumn();').strip()=='0','미사용 거래처 삭제')
 check(post({'action':'biz_product_delete','id':pid})[0]==422,'거래내역이 있는 상품 삭제 차단')
 newproduct={'action':'biz_product_save','id':0,'category':'etc','ko':'시험 기타상품','en':'Fixture Other','retail':'12.50','hst_bp':''}
 check(post(newproduct)[0]==200,'Etc 상품 추가·세율 미확정 허용')
 newpid=int(app('echo query("SELECT MAX(id) FROM biz_products")->fetchColumn();'))
 # Unsaved tax rate cannot silently create an invoice.
 app('query("INSERT INTO biz_prices VALUES (?,?,1250)",['+str(person)+','+str(newpid)+']);')
 batch=uuid.uuid4().hex
 order={'action':'biz_order_save','batch_key':'','request_key':batch,'client_id':person,'day':'2026-12-01','discount':'10','notes':'주문 비고','qty['+str(newpid)+']':2,'qty['+str(ids['products']['Beef Kimbap'])+']':1}
 check(post(order)[0]==200,'주문서 입력과 납품서 자동 생성')
 check(post(order)[0]==200 and app('echo query("SELECT COUNT(*) FROM biz_orders WHERE batch_key=?",["'+batch+'"])->fetchColumn();').strip()=='1','주문서 재전송 중복 방지')
 check(req('/?page=biz-document-print&batch='+batch)[0]==200,'납품 전 인보이스 미리보기')
 check(post({'action':'biz_document_confirm','batch_key':batch,'version':1})[0]==422,'Etc HST 미확정 납품 차단')
 check(app('echo query("SELECT SUM(sent) FROM biz_deliveries WHERE batch_key=?",["'+batch+'"])->fetchColumn();').strip()=='0','실패한 납품은 전체 롤백')
 check(post(dict(newproduct,id=newpid,hst_bp='1300'))[0]==200,'미납품 상품 HST 확인 후 재시도 가능')
 check(post({'action':'biz_order_delete','batch_key':batch,'version':1})[0]==200,'주문서 삭제와 납품서 동시 취소')
 check(app('echo query("SELECT COUNT(*) FROM biz_deliveries WHERE batch_key=? AND cancelled=0",["'+batch+'"])->fetchColumn();').strip()=='0','취소 주문의 생산·납품 제외')
 # Standard products synchronize until the delivery document is edited.
 batch=uuid.uuid4().hex;kimchi=ids['products']['Sliced Kimchi'];beef=ids['products']['Beef Kimbap']
 order={'action':'biz_order_save','batch_key':'','request_key':batch,'client_id':person,'day':'2026-12-02','discount':'10','notes':'첫 주문','qty['+str(kimchi)+']':2,'qty['+str(beef)+']':1}
 check(post(order)[0]==200,'주문서 두 품목 입력')
 update=dict(order,**{'batch_key':batch,'version':1,'day':'2026-12-03','notes':'수정 주문','qty['+str(kimchi)+']':3})
 check(post(update)[0]==200,'주문서 수정 시 납품서 동기화')
 check(app('echo query("SELECT SUM(planned) FROM biz_deliveries WHERE batch_key=? AND cancelled=0",["'+batch+'"])->fetchColumn();').strip()=='4','납품 수량 동기화 확인')
 check(post(update)[0]==422,'주문서 동시 수정 충돌 차단')
 doc={'action':'biz_document_save','batch_key':batch,'version':2,'day':'2026-12-04','discount':'5','notes':'실제 납품 변경','qty['+str(kimchi)+']':4,'qty['+str(beef)+']':2}
 check(post(doc)[0]==200,'납품서 직접 수정')
 check(app('echo query("SELECT order_day FROM biz_orders WHERE batch_key=?",["'+batch+'"])->fetchColumn();').strip()=='2026-12-03' and app('echo query("SELECT order_discount_bp FROM biz_orders WHERE batch_key=?",["'+batch+'"])->fetchColumn();').strip()=='1000','납품서 수정 후 원 주문의 날짜·할인율 보존')
 check(post(dict(update,version=3))[0]==422,'납품서 수정 후 주문서 변경 차단')
 check(post({'action':'biz_order_delete','batch_key':batch,'version':3})[0]==422,'납품서 수정 후 주문서 삭제 차단')
 check(post({'action':'biz_document_confirm','batch_key':batch,'version':2})[0]==422,'낡은 납품서 확정 차단')
 check(post({'action':'biz_document_confirm','batch_key':batch,'version':3})[0]==200,'납품서 전체 확정·직매출 인보이스 발행')
 check(app('echo query("SELECT SUM(delivered) FROM biz_deliveries WHERE batch_key=?",["'+batch+'"])->fetchColumn();').strip()=='6','납품서 실제 수량 반영')
 check(post(dict(doc,version=4))[0]==422,'납품 완료 후 납품서 수정 차단')
 check(post({'action':'biz_product_save','id':kimchi,'category':'kimbap','ko':'맛김치','en':'Sliced Kimchi','retail':'4.99'})[0]==422,'거래 이력이 있는 상품의 과세 분류 변경 차단')
 # Standalone add, edit and remove client prices.
 price={'action':'biz_price','client_id':person,'product_id':kimchi,'price':'4.49'}
 check(post(price)[0]==200 and 'biz-prices' in post(price)[2],'독립 납품가 추가·수정 화면')
 check(post(dict(price,action='biz_price_delete'))[0]==200,'납품가 삭제')
 # Bulk prices never overwrite a previously registered price, even on replay.
 bulk={'action':'biz_prices_add','client_id':person,'category':'kimchi','prices['+str(kimchi)+']':'6.25'}
 check(post(bulk)[0]==200,'분류별 납품가 일괄 추가')
 check(post(dict(bulk,**{'prices['+str(kimchi)+']':'9.99'}))[0]==200 and app('echo query("SELECT cents FROM biz_prices WHERE client_id=? AND product_id=?",['+str(person)+','+str(kimchi)+'])->fetchColumn();').strip()=='625','일괄 추가 재전송 시 기존 단가 보존')
 check(post(dict(bulk,category='kimbap'))[0]==422,'다른 분류의 일괄 등록 차단')
 # Re-running initialization must not recreate renamed/deleted products or lose documents.
 count=app('echo query("SELECT COUNT(*) FROM biz_products")->fetchColumn();').strip()
 cmd(['exec','-T','-e','APP_ENABLE_BUSINESS=1','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 check(app('echo query("SELECT COUNT(*) FROM biz_products")->fetchColumn();').strip()==count and app('echo query("SELECT active FROM biz_products WHERE id=?",['+str(deletable)+'])->fetchColumn();').strip()=='0','반복 초기화 시 상품·기존 문서 보존')
 for page in ['biz-order','biz-prices','biz-order&batch='+batch,'biz-delivery&batch='+batch,'biz-document-print&batch='+batch,'expense-edit','orders','customers','expense-report']:
  status,h,_=req('/?page='+page);check(status==200 and '화면을 불러오지 못했습니다' not in h and 'class="today"' not in h,'새 화면·제목 날짜 제거 '+page)
 for page in ['biz','biz-plan','biz-production','biz-deliveries','biz-results','biz-invoices','biz-products','biz-clients','biz-invoice&id=1']:
  status,h,_=req('/?page='+page);check(status==200 and '화면을 불러오지 못했습니다' not in h,'화면 '+page)
 env=dict(os.environ,TEST_BASE=base,NODE_PATH='/workspace/browser-check/node_modules');r=subprocess.run(['node','tests/browser-business.cjs'],cwd=ROOT,env=env,text=True,capture_output=True);print(r.stdout);assert r.returncode==0,r.stderr
 document_info=subprocess.run(['pdfinfo','/tmp/rodem-business-document.pdf'],capture_output=True,text=True,check=True).stdout;check(bool(re.search(r'Pages:\s+1\b',document_info)),'납품서 금액 미리보기 A4 한 페이지')
 info=subprocess.run(['pdfinfo','/tmp/rodem-business-invoice-max.pdf'],capture_output=True,text=True,check=True).stdout;check(bool(re.search(r'Pages:\s+1\b',info)),'최대 34개 긴 품목 A4 한 페이지')
finally:
 if container:subprocess.run(['docker','rm','-f',container],capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
