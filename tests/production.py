"""Production calculations, recipe isolation, saved snapshots and HTTP workflow in a separate DB."""
from pathlib import Path
import subprocess,uuid,json,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,os
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_production_'+uuid.uuid4().hex[:10];container=None
base='http://127.0.0.1:18090'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2500:])
 return r.stdout
def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def app(code):return cmd(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php"; require_once "app/production.php";'+code)
def req(path='/',data=None):
 try:
  with client.open(base+path,urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None) as r:return r.status,r.read().decode(),r.url
 except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url
def check(ok,label):
 assert ok,label
 print('PASS',label,flush=True)
def csrf(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 print(app('''
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\\n";}
$recipes=productionRecipes();$expected=['pogi'=>[950,120,90.25],'whitepogi'=>[700,200,66.5],'mat'=>[870,170,56.55],'fresh'=>[950,120,90.25],'water'=>[330,100,21.45],'dong'=>[0,380,0],'radish'=>[0,1050,0],'green'=>[0,0,0],'vegan'=>[680,140,44.2],'white'=>[530,140,34.45]];
foreach($recipes as $r){$m=productionMaterials($r,1000);foreach(['배추','무우','절임소금'] as $j=>$k)check(abs(($m[$k]??0)-$expected[$r['seed_key']][$j])<0.0001,$r['name'].' '.$k.' 기준량');}
check(abs(productionMaterials(['base_g'=>1000,'ingredients'=>'[{"material":"배추","mode":"grams","basis":"product","amount":950}]'],5000)['배추']-4750)<0.001,'g 기준량 배수 계산');
try{productionMaterials(['base_g'=>1000,'ingredients'=>'[{"material":"배추","mode":"percent","basis":"무우","amount":50},{"material":"무우","mode":"percent","basis":"배추","amount":50}]'],1000);throw new RuntimeException('cycle accepted');}catch(InvalidArgumentException $e){echo "PASS 순환 배합 차단\\n";}
query("INSERT INTO users (username,password_hash) VALUES (?,?)",['admin',password_hash('fixture-password-123',PASSWORD_DEFAULT)]);
query("INSERT INTO customers (id,name,notes) VALUES (1,'생산 시험 고객','')");
foreach([1,2,3] as $id){query("INSERT INTO orders (id,request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,total_cents,notes,created_by,paid) VALUES (?,?,1,'생산 시험 고객','','2026-01-01','2026-01-02','pickup',10000,10000,'',1,?)",[$id,bin2hex(random_bytes(16)),$id===1?1:0]);}
$pid=query("SELECT id FROM products WHERE code='포5'")->fetchColumn();
foreach([[1,4,1,1],[2,1,1,0],[3,1,0,1]] as [$oid,$qty,$sent,$cancel])query("INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity,delivered_quantity,cancelled_quantity) VALUES (?,?,'포5','포기김치',5000,5000,?,?,?)",[$oid,$pid,$qty,$sent,$cancel]);
$p=productionSnapshot([1],['1'=>'1','2'=>'2']);check($p['rows'][0]['quantity']===2,'전달·취소 제외한 잔여수량만 생산');check(abs($p['groups']['pogi']['배추']-13110)<0.001,'주문 10kg+마트 포기3.8kg 배추 합산');check(abs($p['groups']['mat']['배추']-2784)<0.001,'마트 맛1.6kg 2개 환산');check(abs($p['groups']['pogi']['절임소금']-1245.45)<0.001,'절임소금은 계산된 배추량 기준');check($p['orders'][0]['pending']===2,'선입금 주문도 생산 선택 가능');
try{productionSnapshot([2],[]);throw new RuntimeException('delivered accepted');}catch(InvalidArgumentException $e){echo "PASS 전달 완료 주문 차단\\n";}
check((int)query('SELECT COUNT(*) FROM products')->fetchColumn()===14,'마트제품이 판매제품 목록에 섞이지 않음');
'''))
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18090:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','web']).strip()
 for _ in range(40):
  try:_,html,_=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 _,html,_=req(data={'action':'login','csrf':csrf(html),'username':'admin','password':'fixture-password-123'})
 status,html,_=req('/?page=production');check(status==200 and '생산할 주문 선택 · 1건' in html,'미배송 주문만 표시 (완료·취소 수량 제외)')
 key=uuid.uuid4().hex;token=csrf(html)
 status,html,url=req('/?page=production',{'action':'production_save','csrf':token,'request_key':key,'title':'첫 생산 시험','plan_date':'2026-11-08','orders[]':['1'],'retail[1]':'1','retail[2]':'2'})
 check(status==200 and '13.11 kg' in html and '1.245 kg' in html,'계획 저장·주문마트 합산표·절임표 HTTP 렌더')
 plan_id=int(re.search(r'&id=(\d+)',url)[1])
 req('/?page=production',{'action':'production_save','csrf':token,'request_key':key,'title':'중복','plan_date':'2026-11-08','orders[]':['1']})
 check(app('echo query("SELECT COUNT(*) FROM production_plans")->fetchColumn();').strip()=='1','중복 제출은 같은 계획 반환')
 before=app('echo query("SELECT snapshot FROM production_plans WHERE id=1")->fetchColumn();')
 _,html,_=req('/?page=production-settings');token=csrf(html)
 data={'action':'recipe_save','csrf':token,'id':'0','name':'시험 총각 배합','base_g':'1000','brine_group':'none','material[]':['총각무','절임소금'],'basis[]':['product','총각무'],'mode[]':['percent','percent'],'amount[]':['90','5']}
 status,html,_=req('/?page=production-settings',data);check(status==200 and '시험 총각 배합' in html,'사용자 주재료·의존 비율 배합 추가')
 rid=app('echo query("SELECT id FROM production_recipes ORDER BY id DESC LIMIT 1")->fetchColumn();').strip()
 data.update({'id':rid,'amount[]':['80','4']});check(req('/?page=production-settings',data)[0]==200,'배합 수정')
 check(req('/?page=production-settings',{'action':'recipe_delete','csrf':token,'id':rid})[0]==200,'배합 삭제')
 check(app('echo query("SELECT snapshot FROM production_plans WHERE id=1")->fetchColumn();')==before,'배합 변경·삭제 후 저장 계획 불변')
 check(req('/?page=production-settings',{'action':'retail_save','csrf':token,'name':'테스트 마트','weight_g':'500','recipe_id':'1'})[0]==200,'마트 전용 제품 추가')
 check(req('/?page=production-settings',{'action':'recipe_save','csrf':'invalid'})[0]==422,'설정 변경 CSRF 보호')
 env=dict(os.environ,TEST_BASE=base,TEST_PLAN=str(plan_id),NODE_PATH='/workspace/browser-check/node_modules')
 r=subprocess.run(['node','tests/browser-production.cjs'],cwd=ROOT,env=env,text=True,capture_output=True);print(r.stdout,flush=True)
 if r.returncode:raise RuntimeError(r.stderr)
 import fitz
 pdf=fitz.open('/tmp/kimchi-production-plan.pdf')
 spans=[span for page in pdf for block in page.get_text('dict')['blocks'] if 'lines' in block for line in block['lines'] for span in line['spans'] if span['text'].strip()]
 check(bool(spans) and min(s['size'] for s in spans)>=10.99,'생산 계획 인쇄 PDF 모든 글씨 최소 11pt')
 check('생산 시험 고객' in ''.join(page.get_text() for page in pdf),'인쇄 PDF에 선택 주문자 포함')
 # Exercise listings/pagination and customer totals with >30 records.
 app("""query("INSERT INTO customers (id,name,notes) VALUES (2,'주문없는고객','')");
for($id=4;$id<=36;$id++) {query("INSERT INTO orders (id,request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,total_cents,notes,created_by,cancelled) VALUES (?,?,1,'생산 시험 고객','','2026-01-01','2026-01-02','pickup',5000,5000,'',1,?)",[$id,bin2hex(random_bytes(16)),$id===36?1:0]);$pid=query("SELECT id FROM products WHERE code='맛10'")->fetchColumn();query("INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) VALUES (?,?,'맛10','맛김치',10000,9000,1)",[$id,$pid]);}
""")
 env['TEST_LEGACY_PLAN']=app('''$s=json_decode(query("SELECT snapshot FROM production_plans WHERE id=1")->fetchColumn(),true);foreach($s["orders"] as &$o)unset($o["items"]);unset($o);query("INSERT INTO production_plans (request_key,plan_date,title,snapshot,created_by) VALUES (?,?,?,?,1)",[bin2hex(random_bytes(16)),"2026-11-11","이전 계획 시험",json_encode($s)]);echo db()->lastInsertId();''').strip()
 r=subprocess.run(['node','tests/browser-order-display.cjs'],cwd=ROOT,env=env,text=True,capture_output=True);print(r.stdout,flush=True)
 if r.returncode:raise RuntimeError(r.stderr)
 # Missing recipe is an expected business state, not a PHP 8.5 warning.
 app('query("UPDATE production_links SET recipe_id=NULL WHERE product_id=(SELECT id FROM products WHERE code=?)",["포5"]);')
 result=app('set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});$s=productionSnapshot([1],[]);echo json_encode([count($s["warnings"]),$s["rows"][0]["materials"],$s["rows"][0]["recipe_id"]]);')
 check(json.loads(result)==[1,[],None],'배합 미등록(null) 제품은 PHP 경고 없이 확인 필요로 표시')
 _,html,_=req('/?page=production')
 status,html,url=req('/?page=production',{'action':'production_save','csrf':csrf(html),'request_key':uuid.uuid4().hex,'title':'배합 미등록 시험','plan_date':'2026-11-08','orders[]':['1']})
 check(status==200 and '배합 기준 확인 필요' in html and 'Deprecated' not in html and 'Cannot modify header' not in html and 'id=' in url,'미등록 배합 포함 계획 저장·리다이렉트 성공')
 # Initialization is idempotent and never reseeds deleted/custom recipes.
 cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 check(app('echo query("SELECT COUNT(*) FROM production_retail WHERE active=1")->fetchColumn();').strip()=='10','초기화 재실행 시 사용자 설정 보존')
finally:
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
