"""Latest import rules + real HTTP import, in isolated DB/container only."""
from pathlib import Path
import subprocess,uuid,json,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,hashlib
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_approved_'+uuid.uuid4().hex[:12]
container=None
base='http://127.0.0.1:18082'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def cmd(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2000:])
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
 stats_raw=app('''require 'app/excel-import.php';$r=currentExcelPreview();$p=buildImportPlan($r);echo json_encode(['summary'=>$r['summary'],'counts'=>$p['counts'],'other_items'=>count(array_filter($p['records'],fn($x)=>$x['kind']==='orders' && $x['data'] && count(array_filter($x['data']['items'],fn($i)=>$i['product']['kind']==='other'))>0))]);''')
 if not stats_raw.lstrip().startswith('{'):raise RuntimeError(stats_raw[:1500])
 stats=json.loads(stats_raw)
 check(stats['summary']['customer_review']==0 and stats['summary']['order_review']==0,'첨부 원본의 확인 대기 0')
 c=stats['counts'];check(c['new_customers']==193 and c['new_orders']==946 and c['excluded_orders']==2,'193고객·946주문·무수량2행 제외')
 check(c['delivery_fee_cents']==23500 and c['other_charges_cents']==26200 and c['additional_income_cents']==0,'-$5 배송비235 / 나머지 음수 기타수입262')
 check(c['kimchi_sales_cents']==7735000 and c['income_total_cents']==7784700 and stats['other_items']>0,'당시 판매액·전체금액 보존 및 기타 제품 분류')
 app('query("INSERT INTO users (username,password_hash) VALUES (?,?)",["admin",password_hash("fixture-password-123",PASSWORD_DEFAULT)]);')
 container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18082:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','-e','APP_HIDE_IMPORT_AFTER_COMPLETE=1','-e','APP_BRANDING_DIR=/app/.local/'+name,'web']).strip()
 for _ in range(30):
  try:status,html,url=req();break
  except (OSError,urllib.error.URLError):time.sleep(.2)
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 _,html,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':'fixture-password-123'})
 csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
 check('엑셀 가져오기</a>' in html or '엑셀 가져오기' in html,'가져오기 전 메뉴 표시')
 # Upload an image fixture to an isolated branding directory; bytes are preserved exactly.
 boundary='brand_'+uuid.uuid4().hex
 logo=(ROOT/'public/assets/sprout.png').read_bytes()
 body=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nbrand_upload\r\n--{boundary}\r\nContent-Disposition: form-data; name="brand_file"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n').encode()+logo+f'\r\n--{boundary}--\r\n'.encode()
 with client.open(urllib.request.Request(base+'/?page=brand',body,{'Content-Type':'multipart/form-data; boundary='+boundary})) as response:
  check(response.status==200,'로고 파일 업로드 적용')
 with client.open(base+'/?api=brand-logo') as response:
  image_data=response.read();etag=response.headers['ETag']
 check(hashlib.sha256(image_data).digest()==hashlib.sha256(logo).digest(),'원본 PNG 바이트·투명 배경 보존')
 try:client.open(urllib.request.Request(base+'/?api=brand-logo',headers={'If-None-Match':etag}));raise AssertionError('cache')
 except urllib.error.HTTPError as response:check(response.code==304,'로고 캐시 재검증')

 status,html,url=req('/?page=excel-preview',{'action':'import_prepare','csrf':csrf})
 check(status==200 and '새 고객 193명' in html and '새 주문 946건' in html and '$262.00' in html,'실제 준비 화면과 분류 합계')
 token=re.search(r'name="job" value="([a-f0-9]+)"',html)[1]
 status,html,url=req('/?page=excel-preview',{'action':'import_execute','csrf':csrf,'job':token,'confirm_import':'yes','confirm_replace':'replace'})
 check(status==200 and '엑셀 가져오기 완료' in html,'HTTP 실제 가져오기 완료')
 check(not re.search(r'<a[^>]+href="/\?page=excel-preview"',html),'완료 후 사이드바 가져오기 메뉴 숨김')
 check(req('/?page=excel-preview',{'action':'import_prepare','csrf':csrf})[0]==422,'완료 후 재가져오기 요청 차단')
 result=json.loads(app('''echo json_encode(['orders'=>query('SELECT COUNT(*) FROM orders')->fetchColumn(),'other'=>query("SELECT COUNT(*) FROM products WHERE name='기타'")->fetchColumn(),'org'=>query("SELECT COUNT(*) FROM customers WHERE customer_kind='organization'")->fetchColumn(),'receipts'=>query('SELECT SUM(amount_cents) FROM payments')->fetchColumn(),'sum'=>query('SELECT SUM(total_cents) FROM orders')->fetchColumn()]);'''))
 check(result['orders']==946 and result['other']==1 and result['org']==1,'주문946·기타제품1·단체1 저장')
 check(int(result['receipts'])==7774700 and int(result['sum'])==7784700,'입금77747·주문77847·미입금100 보존')
 # Inspect rule changes without exposing any names.
 app('''require 'app/excel-import.php';$r=currentExcelPreview();if(count(array_filter($r['customers'],fn($c)=>$c['customer_kind']==='organization' && $c['contact_name']!==''))!==1)throw new RuntimeException('contact');''')
 check(json.loads(app('echo json_encode([excelImportCompleted(),excelImportMenuHidden()]);'))==[True,False],'개발 설정은 완료 후에도 가져오기 메뉴 유지')
 print('PASS 대사관 담당자 보존·별도 시험 DB 정리')
finally:
 app("$d=__DIR__.'/.local/"+name+"';if(is_file($d.'/logo.png'))unlink($d.'/logo.png');if(is_dir($d))rmdir($d);")
 if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
