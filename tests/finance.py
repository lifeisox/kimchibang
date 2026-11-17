"""Expenses, private receipts, workbook ingestion, three-year comparisons and browser-only readers in isolated DB."""
from pathlib import Path
import subprocess,uuid,json,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,os,glob,hashlib
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_finance_'+uuid.uuid4().hex[:10];container=None
base='http://127.0.0.1:18092'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2400:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','APP_ENABLE_EXPENSES=1','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None) as r:return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url
def token(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
def check(ok,label):
 assert ok,label
 print('PASS',label,flush=True)
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','APP_ENABLE_EXPENSES=1','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 print(app('''require 'app/sales.php';require 'app/expenses.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\\n";}
query('INSERT INTO users (username,password_hash) VALUES (?,?)',['admin',password_hash('fixture-password-123',PASSWORD_DEFAULT)]);query("INSERT INTO customers (id,name,notes) VALUES (1,'시험 고객','')");
foreach([[1,'2026-01-01',0,0,10000],[2,'2026-02-01',1,0,10000],[3,'2026-03-01',0,1,10000],[4,'2026-12-31',1,1,10000],[5,'2025-12-31',1,1,20000],[6,'2024-02-29',1,1,40000]] as [$id,$date,$sent,$paid,$amount])query("INSERT INTO orders (id,request_key,customer_id,customer_name,delivery_address,order_date,fulfillment,subtotal_cents,total_cents,notes,created_by,delivered,paid) VALUES (?,?,1,'시험 고객','',?,'pickup',?,?,'',1,?,?)",[$id,bin2hex(random_bytes(16)),$date,$amount,$amount,$sent,$paid]);
foreach(['week'=>52,'month'=>12,'year'=>1] as $kind=>$count){$r=salesThreeYears($kind,2026);check(count($r['rows'])===$count,'최근3년 '.$kind.' 행 수');check($r['totals']===[2026=>40000,2025=>20000,2024=>40000],'3개 연도 합계');check(array_sum(array_map(fn($x)=>$x['amounts'][2026],$r['rows']))===40000,'연말 포함 세부 합계');}
check(salesGrowth(20000,10000)==='100.0%'&&salesGrowth(1,0)==='—','증감률·0기준 처리');check(array_sum(expenseAllocate(113,[50,50]))===113,'분할 센트 보존');
'''))
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18092:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','-e','APP_ENABLE_EXPENSES=1','web']).strip()
 for _ in range(50):
  try:_,html,_=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 check(req('/?page=expense-receipt-view&id=1')[0]==401,'로그인 전 영수증 팝업 접근 차단')
 check(req('/?api=expense-receipt&id=1')[0]==401,'로그인 전 영수증 접근 차단')
 _,html,_=req(data={'action':'login','csrf':token(html),'username':'admin','password':'fixture-password-123'});csrf=token(html)
 category=app('echo query("SELECT id FROM expense_categories WHERE name=?",["Supplies"])->fetchColumn();').strip()
 data={'action':'expense_save','csrf':csrf,'request_key':uuid.uuid4().hex,'expense_date':'2026-01-02','vendor':'가상 구입처','category_id':category,'description':'fixture','subtotal':'100.00','hst':'13.00','other_tax':'0','total':'113.00','business_pct':'50','deductible_pct':'100','itc_status':'eligible','ocr_text':''}
 status,html,url=req('/?page=expense-edit',data);check(status==200 and '비용과 영수증을 저장했습니다' in html,'비용 입력 저장');check('page=expenses&year=2026&month=1' in url,'저장 후 거래 월의 기장 내역으로 이동');eid=int(app('echo query("SELECT id FROM expenses WHERE request_key=?",['+json.dumps(data['request_key'])+'])->fetchColumn();'))
 req('/?page=expense-edit',data);check(app('echo query("SELECT COUNT(*) FROM expenses")->fetchColumn();').strip()=='1','비용 중복 제출 방지')
 report=json.loads(app('require "app/expenses.php";echo json_encode(expenseReport(2026)["totals"]);'));check(report['paid']==11300 and report['itc']==650 and report['operating']==5000,'사업분50%·확인된 HST만 공제·운영비 집계')
 wrong=dict(data,request_key=uuid.uuid4().hex,total='114');check(req('/?page=expense-edit',wrong)[0]==422,'공급가·세금·합계 불일치 저장 차단')
 check(req('/?page=expense-edit',dict(data,csrf='bad'))[0]==422,'비용 CSRF 보호')
 # Supplier aliases, management and account defaults are checked through the same authenticated routes.
 print(app("""require 'app/bookkeeping.php';db()->beginTransaction();$a=supplierResolve('Costco Business Center');$b=supplierResolve('Costco Merivale');$c=supplierResolve('Costco Strandherd');if($a['id']!==$b['id']||$a['id']!==$c['id'])throw new RuntimeException('Costco normalization');db()->commit();echo 'PASS Costco 3개 지점 동일 구매처 연결';"""))
 status,html,_=req('/?page=bookkeeping-suppliers',{'action':'supplier_save','csrf':csrf,'id':0,'name':'Fixture Supplier','aliases':'Fixture Branch'})
 check(status==200 and 'Fixture Supplier' in html,'구매처 추가·별칭 등록')
 sid=app('echo query("SELECT id FROM bookkeeping_suppliers WHERE name=?",["Fixture Supplier"])->fetchColumn();').strip()
 target=app('echo query("SELECT id FROM bookkeeping_suppliers WHERE name=?",["Costco"])->fetchColumn();').strip()
 status,html,_=req('/?page=bookkeeping-suppliers',{'action':'supplier_merge','csrf':csrf,'id':sid,'version':1,'target_id':target})
 check(status==200 and app('require "app/bookkeeping.php";echo supplierResolve("Fixture Branch")["id"];').strip()==target,'구매처 통합 후 별칭 유지')
 status,html,_=req('/?page=bookkeeping-accounts',{'action':'accounting_save','csrf':csrf,'id':0,'name':'Fixture Account','tax_type':'operating','default_deductible_pct':75,'default_itc_pct':50,'sort_order':999,'income_tax_line':'Review'})
 check(status==200 and 'Fixture Account' in html,'Chart of Accounts 추가·기본 비율 저장')
 aid=app('echo query("SELECT id FROM expense_categories WHERE name=?",["Fixture Account"])->fetchColumn();').strip()
 check(req('/?page=bookkeeping-accounts',{'action':'accounting_delete','csrf':csrf,'id':aid,'version':1})[0]==200 and app('echo query("SELECT active FROM expense_categories WHERE id=?",['+aid+'])->fetchColumn();').strip()=='0','Account 삭제는 비활성화·기록 보존')
 print(app("""require 'app/expenses.php';db()->beginTransaction();$id=query('SELECT id FROM expenses LIMIT 1')->fetchColumn();$material=query('SELECT id FROM expense_categories WHERE name=?',['Material'])->fetchColumn();query('UPDATE expenses SET category_id=?,itc_limit_pct=50 WHERE id=?',[$material,$id]);$r=expenseReport(2026)['totals'];if($r['itc']!==325||$r['inventory']!==5325||$r['operating']!==0)throw new RuntimeException('Tax separation');db()->rollBack();echo 'PASS ITC Limit·Inventory/COGS 별도 집계';"""))
 # Corrected expense values teach labels, never literal amounts; everything stays in this fixture DB.
 learned=dict(data,id=str(eid),version='1',remember_receipt='1',receipt_rule_version='0',ocr_text='Learning Store\nTotal 113.00\nHST 13.00')
 check(req('/?page=expense-edit&id='+str(eid),learned)[0]==200,'수정 거래에서 읽기 표기 자동 기억')
 rule=json.loads(app('require "app/expense-learning.php";echo json_encode(receiptRule((int)query("SELECT supplier_id FROM expenses WHERE id=1")->fetchColumn()));'))
 check(rule['total_label']=='Total' and rule['hst_label']=='HST' and int(rule['version'])==1,'Amount·HST 표기만 저장·금액 미저장')
 check(req('/?page=expense-edit&id='+str(eid),dict(learned,version='2'))[0]==422,'동시 변경된 기억 규칙 덮어쓰기 차단')
 check(req('/?page=expense-edit&id='+str(eid),dict(learned,version='2',receipt_rule_version='1',forget_receipt_rule='1'))[0]==200,'기억 규칙 초기화')
 # Real workbooks are copied only into private fixture paths; all imports use this isolated DB.
 source_paths={}
 cid=cmd(['ps','-q','web']).strip()
 for path in glob.glob('/workspace/attachments/*/* Expenses.xlsx'):
  year=int(Path(path).name[:4]);
  if year not in (2025,2026):continue
  dest='/app/.local/expense-fixture-'+Path(path).name.replace(' ','-');subprocess.run(['docker','cp',path,cid+':'+dest],check=True,capture_output=True);source_paths[year]=path
  code='require "app/expense-import.php";$r=expenseWorkbook('+json.dumps(dest)+','+json.dumps(Path(path).name)+','+str(year)+');echo json_encode(["ready"=>$r["ready"],"held"=>$r["held"],"total"=>$r["total"],"splits"=>count(array_filter($r["rows"],fn($x)=>count($x["splits"])>1))]);'
  result=json.loads(app(code));check(result['ready']>500,str(year)+' 원본 월별 비용·분할 인식, 합계행 제외: '+str(result))
 from excel_fixture import write_book
 fixture=Path('/tmp/2026 Expenses Fixture.xlsx')
 write_book(fixture,{'Jan':{3:{1:'Date'},4:{5:'Material',11:'Supplies'},5:{1:'01-02',2:113,3:13,4:100,5:100,22:'TEST STORE'},6:{1:'01-03',2:226,3:26,4:200,5:150,11:50,22:'MIXED STORE'},7:{1:'01-04',2:99,3:0,4:98,5:98,22:'REVIEW STORE'},8:{1:'Total',2:438,3:39,4:399,5:398}}})
 env=dict(os.environ,TEST_BASE=base,NODE_PATH='/workspace/browser-check/node_modules',TEST_EXPENSE=str(eid),TEST_CATEGORY=category,TEST_WORKBOOK=str(fixture))
 r=subprocess.run(['node','tests/browser-finance.cjs'],cwd=ROOT,env=env,text=True,capture_output=True);print(r.stdout,flush=True)
 if r.returncode:raise RuntimeError(r.stderr)
 check(app('echo query("SELECT COUNT(*) FROM expenses")->fetchColumn();').strip()=='4','확인 비용2건 추가·보류 행 제외·기존 비용2건 유지')
 check(app('echo query("SELECT COUNT(*) FROM expense_splits WHERE expense_id=(SELECT id FROM expenses WHERE vendor=?)",["MIXED STORE"])->fetchColumn();').strip()=='2','한 영수증의 항목별 분할 보존')
 check(app('echo query("SELECT COUNT(*) FROM expenses e JOIN expense_import_rows r ON r.expense_id=e.id WHERE e.itc_status<>?",["eligible"])->fetchColumn();').strip()=='0','Meals 없는 신규 엑셀 거래 ITC Eligible')
 # CSV remains authenticated and exports only nonvoid records.
 check(req('/?api=expense-csv&year=2026')[0]==200,'신고용 상세 CSV')
 _,html,_=req('/?page=expense-edit&id='+str(eid));version=app('echo query("SELECT version FROM expenses WHERE id=1")->fetchColumn();').strip()
 status,html,_=req('/?page=expense-edit&id='+str(eid),{'action':'expense_void','csrf':token(html),'id':str(eid),'version':version,'reason':'fixture 취소'})
 check(status==200 and '취소된 비용' in html,'비용 취소·감사기록 보존')
 check(app('echo query("SELECT COUNT(*) FROM expense_events WHERE expense_id=1")->fetchColumn();').strip()=='4','생성·수정·규칙 초기화·취소 이력 보존')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
