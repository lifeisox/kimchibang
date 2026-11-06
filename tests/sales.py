"""Sales analytics correctness in an isolated database, with fictional orders only."""
from pathlib import Path
import subprocess,uuid
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_sales_'+uuid.uuid4().hex[:12]
def admin(sql):
    return subprocess.run(['docker','compose','exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],cwd=ROOT,input=sql,text=True,capture_output=True,check=True).stdout
def app(code):
    r=subprocess.run(['docker','compose','exec','-T','-e','DB_NAME='+name,'web','php'],cwd=ROOT,input='<?php '+code,text=True,capture_output=True)
    if r.returncode:raise RuntimeError((r.stderr or r.stdout)[-2500:])
    return r.stdout
try:
    admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
    app('require "scripts/init.php";')
    print(app('''require 'app/bootstrap.php';require 'app/sales.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\\n";}
query("INSERT INTO users (id,username,password_hash) VALUES (1,'stats_fixture','unused')");
query("INSERT INTO customers (id,name,notes) VALUES (1,'가상 통계 고객','시험 전용')");
$pid=(int)query("SELECT id FROM products WHERE code='포5'")->fetchColumn();
function fixture($date,$sub,$discount,$fee,$other,$extra,$cancelled=0){global $pid;
$total=$sub-$discount+$fee+$other;
query("INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,discount_cents,delivery_fee_cents,other_sales_cents,additional_income_cents,total_cents,received_cents,notes,created_by,cancelled) VALUES (?,1,'가상 통계 고객','가상 주소',?,?,'delivery',?,?,?,?,?,?,0,'',1,?)",[bin2hex(random_bytes(16)),$date,$date,$sub,$discount,$fee,$other,$extra,$total,$cancelled]);$id=(int)db()->lastInsertId();
query("INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity,cancelled_quantity) VALUES (?,?,'포5','포기김치',5000,?,3,1)",[$id,$pid,intdiv($sub,2)]);allocateDiscount($id,$discount);return $id;}
$id=fixture('2026-02-03',10000,1,500,4000,300);fixture('2025-02-03',5000,0,0,0,0);fixture('2026-02-04',10000,0,0,0,0,1);fixture('2026-03-01',10000,0,0,0,0);
$s=salesTotals('2026-02-01','2026-02-28');check($s['order_count']===1 && $s['kimchi']===9999,'기간·취소 주문 제외·할인 1센트 정확성');
check($s['delivery']===500 && $s['other_sales']===4000 && $s['additional_income']===300 && $s['total']===14499,'배달비·기타판매·추가수입 분리');
$p=salesProducts('2026-02-01','2026-02-28');check((int)$p[0]['quantity']===2 && (int)$p[0]['amount']===9999,'취소 수량 제외·제품별 할인 배분');
check(salesTotals('2025-02-01','2025-02-28')['kimchi']===5000,'전년도 별도 집계');
query("INSERT INTO payments (order_id,request_key,amount_cents,additional_income_cents,method,paid_on,note,created_by) VALUES (?,?,14799,300,'cash','2026-03-01','',1)",[$id,bin2hex(random_bytes(16))]);
query("INSERT INTO payments (order_id,request_key,amount_cents,method,paid_on,note,created_by) VALUES (?,?,100,'legacy',NULL,'날짜 미기록',1)",[$id,bin2hex(random_bytes(16))]);
check(salesReceipts('2026-02-01','2026-02-28')['amount']===0 && salesReceipts('2026-03-01','2026-03-31')['amount']===14799,'입금일 기준·미기록 날짜 제외');
check(salesPeriod('week','2026-01-01')['start']==='2025-12-29' && salesPeriod('week','2026-01-01')['end']==='2026-01-04','연도를 넘는 주간');
check(salesPeriod('month','2024-02-29')['end']==='2024-02-29' && salesPeriod('month','2024-02-29')['previous_end']==='2023-02-28','윤년 및 전년도 월말');
try{salesPeriod('month','2026-02-30');throw new RuntimeException('invalid date');}catch(InvalidArgumentException $e){echo "PASS 잘못된 날짜 차단\\n";}
check(salesTotals('2030-01-01','2030-12-31')['total']===0 && salesProducts('2030-01-01','2030-12-31')===[],'빈 기간 처리');
'''))
finally:
    admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
