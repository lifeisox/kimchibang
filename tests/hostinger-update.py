"""Exercise staged Hostinger update with a dedicated DB and temporary domain tree."""
from pathlib import Path
import subprocess,tempfile,uuid,zipfile,shutil,json
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_update_'+uuid.uuid4().hex[:10]
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
 php('''query("INSERT INTO users (username,password_hash) VALUES (?,?)",['fixture','fixturehash']);query("INSERT INTO customers (name,notes) VALUES ('보존고객','보존메모')");query("INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,fulfillment,subtotal_cents,total_cents,notes,created_by) VALUES (?,1,'보존고객','','2026-01-01','pickup',5000,5000,'',1)",[str_repeat('f',32)]);query("UPDATE products SET price_cents=7777 WHERE code='포5'");foreach(['production_plans','production_links','production_retail','production_recipes'] as $t)db()->exec('DROP TABLE '.$t);query("DELETE FROM schema_migrations WHERE version='20261108_production'");''')
 domain=work/'domain';stage=work/'stage'
 for path in [domain,stage]:
  path.mkdir()
  with zipfile.ZipFile(ROOT/'dist/kimchibang-hostinger.zip') as z:z.extractall(path)
 app=domain/'kimchibang'
 settings=b"<?php return ['APP_ALLOW_INITIAL_SETUP'=>'0','APP_HIDE_IMPORT_AFTER_COMPLETE'=>'1'];\n"
 (app/'config/local.php').write_bytes(settings)
 (app/'.local/branding').mkdir(parents=True);(app/'.local/branding/logo.png').write_bytes(b'private-logo-fixture')
 init=app/'scripts/init.php';init.write_text(init.read_text().replace("require __DIR__.'/../database/production.php';",''))
 htaccess=domain/'public_html/kimchibang/.htaccess';htaccess.write_text(htaccess.read_text()+'\n# existing host setting\n');oldht=htaccess.read_bytes()
 prefix='/app/'+work.relative_to(ROOT).as_posix()
 output=command(['exec','-T','-e','DB_NAME='+name,'-e','APP_SECURE_COOKIE=1','web','sh',prefix+'/stage/kimchibang/scripts/hostinger-update.sh',prefix+'/domain'])
 assert '업데이트 완료 · 기존 고객 1명 / 주문 1건 보존' in output and 'Hostinger 코드·생산 테이블 업데이트 완료' in output
 assert (app/'config/local.php').read_bytes()==settings
 assert (app/'.local/branding/logo.png').read_bytes()==b'private-logo-fixture'
 assert htaccess.read_bytes()==oldht
 assert (app/'public/index.php').read_bytes()==(ROOT/'public/index.php').read_bytes()
 assert (domain/'public_html/kimchibang/assets/app.js').read_bytes()==(ROOT/'public/assets/app.js').read_bytes()
 assert list((app/'.local').glob('code-before-update-*.tar.gz'))
 result=json.loads(php('echo json_encode([query("SELECT COUNT(*) FROM users")->fetchColumn(),query("SELECT COUNT(*) FROM customers")->fetchColumn(),query("SELECT COUNT(*) FROM orders")->fetchColumn(),query("SELECT price_cents FROM products WHERE code=\'포5\'")->fetchColumn(),query("SELECT COUNT(*) FROM production_retail")->fetchColumn()]);'))
 assert result==[1,1,1,7777,9],result
 print('PASS staged update: DB/code backups, new production tables, customers/orders/accounts/prices/logo/private settings and web config preserved')
finally:
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
 command(['exec','-T','web','rm','-rf','/app/'+work.relative_to(ROOT).as_posix()])
