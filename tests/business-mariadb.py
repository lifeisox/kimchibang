"""MariaDB 11.8 sales schema, legacy batch migration and reinitialization."""
from pathlib import Path
import subprocess,uuid,json,time
root=Path(__file__).resolve().parent.parent;name='business-maria-'+uuid.uuid4().hex[:8];pw=uuid.uuid4().hex

def cmd(args,data=None):
 p=subprocess.run(args,cwd=root,input=data,text=True,capture_output=True)
 if p.returncode:raise RuntimeError((p.stderr or p.stdout).replace(pw,'[password]')[-2000:])
 return p.stdout
try:
 web=cmd(['docker','compose','ps','-q','web']).strip();net=next(iter(json.loads(cmd(['docker','inspect',web]))[0]['NetworkSettings']['Networks']))
 cmd(['docker','run','-d','--name',name,'--network',net,'-e','MARIADB_ROOT_PASSWORD='+pw,'-e','MARIADB_DATABASE=bookkeeping','-e','MARIADB_USER=test','-e','MARIADB_PASSWORD='+pw,'mariadb:11.8'])
 for _ in range(100):
  if subprocess.run(['docker','exec',name,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True).returncode==0:break
  time.sleep(.2)
 env=['-e','DB_HOST='+name,'-e','DB_NAME=bookkeeping','-e','DB_USER=test','-e','DB_PASSWORD='+pw,'-e','APP_ENABLE_BUSINESS=1']
 for _ in range(2):cmd(['docker','compose','exec','-T',*env,'web','php','scripts/init.php'])
 code='''<?php require 'app/bootstrap.php';require 'app/business.php';if(count(query('SELECT * FROM biz_products WHERE active=1')->fetchAll())!==31)throw new RuntimeException('catalog');$key=str_repeat('a',32);query("INSERT INTO biz_deliveries (client_id,product_id,day,batch_key,planned,request_key,created_by) VALUES (1,1,'2026-12-01',?,3,?,1)",[$key,$key]);require 'database/business.php';require 'database/business.php';$o=bizBatch($key);if($o['order_day']!=='2026-12-01'||(int)$o['order_discount_bp']!==0||(int)json_decode($o['items'],true)[1]!==3)throw new RuntimeException('legacy migration');if((int)query('SELECT COUNT(*) FROM biz_orders')->fetchColumn()!==1)throw new RuntimeException('duplicate');echo 'PASS MariaDB 11.8 판매 테이블·기존 묶음 이관·반복 초기화·원 주문 날짜/수량 보존';'''
 print(cmd(['docker','compose','exec','-T',*env,'web','php'],code))
finally:subprocess.run(['docker','rm','-f',name],capture_output=True)
