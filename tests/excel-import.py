"""Run reviewed source import in disposable databases; never import into the development DB."""
from pathlib import Path
import subprocess,uuid,json
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_import_'+uuid.uuid4().hex[:12]
def admin(sql):
    return subprocess.run(['docker','compose','exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],cwd=ROOT,input=sql,text=True,capture_output=True,check=True).stdout
def app(code):
    r=subprocess.run(['docker','compose','exec','-T','-e','DB_NAME='+name,'web','php'],cwd=ROOT,input='<?php '+code,text=True,capture_output=True)
    if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2500:])
    return r.stdout
try:
    admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
    app('require "scripts/init.php";')
    result=app('''require "app/bootstrap.php";require "app/excel-import.php";
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\\n";}
query("INSERT INTO users (username,password_hash) VALUES ('import_fixture','unused')");$actor=(int)db()->lastInsertId();
$r=currentExcelPreview();$p=buildImportPlan($r);
check($p['counts']['new_orders']===946 && $p['counts']['new_customers']===193,'원본 고객193·주문946');
check($p['counts']['income_total_cents']===7784700,'전체 금액 77847');
check($p['counts']['kimchi_sales_cents']===7735000 && $p['counts']['other_charges_cents']===26200 && $p['counts']['additional_income_cents']===0,'판매·배송비·기타수입 분리');
$token=saveImportJob($p,$actor);check(loadImportJob($token,$actor)===$p,'준비 화면 보관');
try{loadImportJob($token,$actor+1);throw new RuntimeException('actor');}catch(InvalidArgumentException $e){echo "PASS 다른 사용자 준비 화면 차단\\n";}
$bad=$p;foreach($bad['records'] as &$entry){if($entry['kind']==='orders' && $entry['state']==='ready'){$entry['data']['customer_ref']='missing';break;}}unset($entry);unset($bad['plan_sha']);$bad['plan_sha']=importHash($bad);
try{executeImportPlan($bad,$actor);throw new RuntimeException('rollback');}catch(InvalidArgumentException $e){check((int)query('SELECT COUNT(*) FROM customers')->fetchColumn()===0 && (int)query('SELECT COUNT(*) FROM import_batches')->fetchColumn()===0,'실행 도중 오류시 전체 롤백');}
$batch=executeImportPlan($p,$actor);
check((int)query('SELECT COUNT(*) FROM orders')->fetchColumn()===946,'주문 저장');
check((int)query('SELECT SUM(total_cents) FROM orders')->fetchColumn()===7784700,'전체 주문 금액 보존');
check((int)query('SELECT SUM(additional_income_cents) FROM payments')->fetchColumn()===0,'새 규칙에서 추가수입 중복 가산 없음');
check((int)query('SELECT COUNT(*) FROM payments p WHERE p.amount_cents<>(SELECT COALESCE(SUM(a.amount_cents),0) FROM payment_allocations a WHERE a.payment_id=p.id)')->fetchColumn()===0,'모든 입금과 배분 합계 일치');
check((int)query('SELECT COUNT(*) FROM products WHERE active=1')->fetchColumn()===14,'현재 판매제품 유지');
check((int)query('SELECT COUNT(*) FROM orders WHERE delivery_date IS NOT NULL')->fetchColumn()===0,'실제 배송일 추정 금지');
check((int)query('SELECT COUNT(*) FROM payments WHERE paid_on IS NOT NULL')->fetchColumn()===0,'입금일 추정 금지');
foreach(query('SELECT * FROM orders')->fetchAll() as $o){$b=balance($o);check($b['total']===$o['total_cents'] && $b['received']===$o['received_cents'],'주문 잔액 대조 #'.$o['id']);}
check(executeImportPlan($p,$actor)===$batch,'같은 실행 재시도 중복 없음');
$changed=$r;foreach($changed['orders'] as &$row){if(($row['preview_disposition']??'')!=='exclude'){$row['notes'].=' changed';break;}}unset($row);
try{buildImportPlan($changed);throw new RuntimeException('overwrite');}catch(InvalidArgumentException $e){echo "PASS 이미 저장된 원본 변경 덮어쓰기 차단\n";}
$_POST=['id'=>1,'request_key'=>str_repeat('f',32)];try{workflowAction('cancel_order');throw new RuntimeException('history lock');}catch(InvalidArgumentException $e){echo "PASS 과거 주문 직접 수정 요청 차단\n";}
$again=buildImportPlan($r);check($again['counts']['new_orders']===0 && $again['counts']['skipped_orders']===946,'원본 재준비시 모두 건너뛰기');
$stale=$again;query("UPDATE customers SET notes=CONCAT(notes,' ') ORDER BY id LIMIT 1");
try{executeImportPlan($stale,$actor);throw new RuntimeException('stale');}catch(InvalidArgumentException $e){echo "PASS 준비 이후 변경 감지\\n";}
$backup=query('SELECT backup_name FROM import_batches WHERE id=?',[$batch])->fetchColumn();
check(is_file(importBackupDirectory().'/'.$backup),'실행 전 비공개 백업 존재');
echo 'BACKUP '.json_encode($backup)."\\n";
unlink(previewDirectory().'/import-job-'.$token.'.json');
''')
    lines=result.splitlines();print('\n'.join(l for l in lines if not l.startswith('PASS 주문 잔액 대조')))
    print('PASS 946개 주문 잔액 대조')
    backup=json.loads(next(l[7:] for l in lines if l.startswith('BACKUP ')))
    # Restore the actual pre-import SQL to this disposable database only.
    app('require "app/bootstrap.php";require "app/import-backup.php";db()->exec(file_get_contents(importBackupDirectory()."/".'+json.dumps(backup)+'));if(query("SELECT COUNT(*) FROM orders")->fetchColumn()!=0)throw new RuntimeException("restore");unlink(importBackupDirectory()."/".'+json.dumps(backup)+');')
    print('PASS 가져오기 전 백업 복원 (별도 시험 DB)')
finally:
    admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL PRIVILEGES ON '+name+".* FROM 'kimchi'@'%';")
