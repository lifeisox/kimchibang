"""Boot the actual bookkeeping ZIP against an isolated database; sales stays disabled."""
from pathlib import Path
import subprocess,uuid,zipfile,tempfile,json
root=Path(__file__).resolve().parent.parent
name='kimchi_release_'+uuid.uuid4().hex[:10]
work=Path(tempfile.mkdtemp(prefix='bookkeeping-release-',dir=root/'.local'))
prefix='/app/'+work.relative_to(root).as_posix()
def cmd(args,data=None):
 r=subprocess.run(args,cwd=root,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2500:])
 return r.stdout
def admin(sql):return cmd(['docker','compose','exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
try:
 print(cmd(['python','scripts/package-hostinger.py','--bookkeeping']))
 with zipfile.ZipFile(root/'dist/kimchibang-bookkeeping-hostinger.zip') as z:
  assert not any(Path(n).name.startswith('business') for n in z.namelist())
  assert not any(n.endswith(('.sql.gz','.xlsx','.env','local.php')) or '/.local/' in n or '/tests/' in n for n in z.namelist())
  assert 'kimchibang/database/bookkeeping.php' in z.namelist()
  assert "'expenses'=>true" in z.read('kimchibang/config/release.php').decode()
  z.extractall(work)
 admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
 env=['-e','DB_NAME='+name,'-e','APP_ENABLE_BUSINESS=1','-e','APP_ENABLE_EXPENSES=0']
 for _ in range(2):cmd(['docker','compose','exec','-T',*env,'web','php',prefix+'/kimchibang/scripts/init.php'])
 code="<?php require "+json.dumps(prefix+'/kimchibang/app/bootstrap.php')+";if(!expensesEnabled()||businessEnabled())throw new RuntimeException('release feature flags');$tables=query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);if(!in_array('bookkeeping_supplier_accounts',$tables))throw new RuntimeException('missing migration');foreach($tables as $t)if(str_starts_with($t,'biz_'))throw new RuntimeException('business table in release');echo 'PASS 실제 기장 ZIP 반복 설치·Account 기억 테이블·판매 관리 강제 제외';"
 print(cmd(['docker','compose','exec','-T',*env,'web','php'],code))
finally:
 admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
 cmd(['docker','compose','exec','-T','web','rm','-rf','--',prefix])
