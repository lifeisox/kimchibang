"""Exercise staged Hostinger update with a dedicated DB and temporary domain tree."""
from pathlib import Path
import subprocess,tempfile,uuid,zipfile,shutil,json,sys,time,urllib.request,urllib.parse,http.cookiejar,re
bookkeeping="--bookkeeping" in sys.argv
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_update_'+uuid.uuid4().hex[:10]
container=None
work=Path(tempfile.mkdtemp(prefix='hostinger-update-',dir=ROOT/'.local'))
def command(args,data=None):
 r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2500:])
 return r.stdout
def admin(sql):return command(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def php(code):return command(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
try:
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 command(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
 php('''query("INSERT INTO users (username,password_hash) VALUES (?,?)",['fixture',password_hash('fixture-password-123',PASSWORD_DEFAULT)]);query("INSERT INTO customers (name,notes) VALUES ('보존고객','보존메모')");query("INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,fulfillment,subtotal_cents,total_cents,notes,created_by) VALUES (?,1,'보존고객','','2026-01-01','pickup',5000,5000,'',1)",[str_repeat('f',32)]);query("UPDATE products SET price_cents=7777 WHERE code='포5'");foreach(['production_plans','production_links','production_retail','production_recipes'] as $t)db()->exec('DROP TABLE '.$t);query("DELETE FROM schema_migrations WHERE version='20261108_production'");''')
 if bookkeeping:
  php("require 'database/expenses.php';query(\"INSERT INTO expenses (expense_date,vendor,category_id,description,subtotal_cents,hst_cents,total_cents,ocr_text,request_key,created_by) VALUES ('2026-01-02','Preserved supplier',(SELECT id FROM expense_categories WHERE name='Material'),'Preserved expense',10000,1300,11300,'',?,1)\",[str_repeat('a',32)]);")
 domain=work/'domain';stage=work/'stage'
 for path in [domain,stage]:
  path.mkdir()
  with zipfile.ZipFile(ROOT/('dist/kimchibang-bookkeeping-hostinger.zip' if bookkeeping and path==stage else 'dist/kimchibang-hostinger.zip')) as z:z.extractall(path)
 app=domain/'kimchibang'
 settings=b"<?php return ['APP_ENABLE_EXPENSES'=>'0','APP_ALLOW_INITIAL_SETUP'=>'0','APP_HIDE_IMPORT_AFTER_COMPLETE'=>'1'];\n"
 (app/'config/local.php').write_bytes(settings)
 (app/'.local/branding').mkdir(parents=True);(app/'.local/branding/logo.png').write_bytes(b'private-logo-fixture')
 (app/'.local/receipts').mkdir();(app/'.local/receipts/preserved.jpg').write_bytes(b'private-receipt-fixture')
 ini=domain/'public_html/kimchibang/.user.ini';ini.write_text('memory_limit=512M\nupload_max_filesize=4M\npost_max_size=5M\n')
 init=app/'scripts/init.php';init.write_text(init.read_text().replace("require __DIR__.'/../database/production.php';",''))
 htaccess=domain/'public_html/kimchibang/.htaccess';htaccess.write_text(htaccess.read_text().replace('css|js|mjs|wasm|traineddata|png|ico|svg|webp|jpg|jpeg','css|js|png|ico|svg|webp|jpg|jpeg').replace('AddType application/javascript .mjs\n','').replace('AddType application/wasm .wasm\n','')+'\n# existing host setting\n')
 prefix='/app/'+work.relative_to(ROOT).as_posix()
 output=command(['exec','-T','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=1','web','sh',prefix+'/stage/kimchibang/scripts/hostinger-update.sh',prefix+'/domain'])
 assert '업데이트 완료 · 기존 고객 1명 / 주문 1건 보존' in output and 'Hostinger 코드·생산 테이블 업데이트 완료' in output
 assert (app/'config/local.php').read_bytes()==settings
 assert (app/'.local/branding/logo.png').read_bytes()==b'private-logo-fixture'
 assert '# existing host setting' in htaccess.read_text() and 'mjs|wasm|traineddata' in htaccess.read_text() and 'AddType application/javascript .mjs' in htaccess.read_text() and 'RewriteRule ^ - [F,L]' in htaccess.read_text()
 assert (app/'.local/receipts/preserved.jpg').read_bytes()==b'private-receipt-fixture'
 assert 'memory_limit=512M' in ini.read_text() and 'upload_max_filesize=10M' in ini.read_text() and 'post_max_size=12M' in ini.read_text()
 if bookkeeping:
  assert 'PASS 기장 활성화' in output,output
  code='require '+json.dumps(prefix+'/domain/kimchibang/app/features.php')+';echo expensesEnabled()?"on":"off";'
  assert command(['exec','-T','-e','APP_ENABLE_EXPENSES=0','web','php','-r',code]).strip()=='on'
  assert php('echo query("SELECT COUNT(*) FROM bookkeeping_receipt_rules")->fetchColumn();').strip()=='0'
 assert (app/'public/index.php').read_bytes()==(ROOT/'public/index.php').read_bytes()
 assert (domain/'public_html/kimchibang/assets/app.js').read_bytes()==(ROOT/'public/assets/app.js').read_bytes()
 assert list((app/'.local').glob('code-before-update-*.tar.gz'))
 result=json.loads(php('echo json_encode([query("SELECT COUNT(*) FROM users")->fetchColumn(),query("SELECT COUNT(*) FROM customers")->fetchColumn(),query("SELECT COUNT(*) FROM orders")->fetchColumn(),query("SELECT price_cents FROM products WHERE code=\'포5\'")->fetchColumn(),query("SELECT COUNT(*) FROM production_retail")->fetchColumn()]);'))
 assert result==[1,1,1,7777,9],result
 if bookkeeping:
  assert php('echo query("SELECT COUNT(*) FROM expenses WHERE total_cents=11300")->fetchColumn();').strip()=='1'
  container=command(['run','--rm','-d','--no-deps','-p','127.0.0.1:18096:8000','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=0','web','php','-S','0.0.0.0:8000','-t',prefix+'/domain/public_html/kimchibang']).strip()
  client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base='http://127.0.0.1:18096'
  for _ in range(40):
   try:html=client.open(base).read().decode();break
   except OSError:time.sleep(.2)
  csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
  html=client.open(base,urllib.parse.urlencode({'action':'login','csrf':csrf,'username':'fixture','password':'fixture-password-123'}).encode()).read().decode()
  assert '기장 (Bookkeeping)' in html and 'wasm-unsafe-eval' not in html # Policy is in headers.
  for page in ['expenses','expense-edit','expense-report','bookkeeping-suppliers','bookkeeping-accounts','expense-import']:
   html=client.open(base+'/?page='+page).read().decode();assert '화면을 불러오지 못했습니다' not in html,page
  for asset in ['receipt-reader.js','receipt-imaging.mjs','vendor/ocr/eng.traineddata','vendor/ocr/tesseract-core-lstm.wasm','vendor/pdf/pdf.worker.mjs']:
   with client.open(base+'/assets/'+asset) as response:assert response.status==200
  with client.open(base+'/?api=expense-xlsx&year=2026') as response:assert response.read(2)==b'PK'
  print('PASS bookkeeping package: existing expenses preserved, login, menu, all finance screens, local OCR/PDF assets and XLSX via split public root')
 print('PASS staged update: DB/code backups, new production tables, customers/orders/accounts/prices/logo/private settings and web config preserved')
finally:
 if container:subprocess.run(['docker','rm','-f',container],capture_output=True)
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
 command(['exec','-T','web','rm','-rf','/app/'+work.relative_to(ROOT).as_posix()])
