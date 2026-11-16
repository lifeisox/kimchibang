"""Isolated MariaDB 11.8 compatibility for additive bookkeeping changes."""
from pathlib import Path
import subprocess,uuid,json,time
root=Path(__file__).resolve().parent.parent;name='bookkeeping-maria-'+uuid.uuid4().hex[:8];pw=uuid.uuid4().hex

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
 env=['-e','DB_HOST='+name,'-e','DB_NAME=bookkeeping','-e','DB_USER=test','-e','DB_PASSWORD='+pw,'-e','APP_ENABLE_EXPENSES=1']
 for _ in range(2):cmd(['docker','compose','exec','-T',*env,'web','php','scripts/init.php'])
 code='''<?php require 'app/bootstrap.php';require 'app/expenses.php';$a=supplierResolve('Costco Merivale');$b=supplierResolve('Costco Business Center');if($a['id']!==$b['id'])throw new RuntimeException('supplier');if(count(bookkeepingAccounts())<17)throw new RuntimeException('accounts');$r=expenseReport(2026);if($r['totals']['paid']!==0)throw new RuntimeException('report');echo 'PASS MariaDB 11.8 기장 마이그레이션 반복·구매처 정규화·Account·신고 집계';'''
 print(cmd(['docker','compose','exec','-T',*env,'web','php'],code))
finally:subprocess.run(['docker','rm','-f',name],capture_output=True)
