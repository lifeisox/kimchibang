"""Upgrade a separate legacy fixture database, verify receipts and rerun safely."""
from pathlib import Path
import subprocess, uuid, json
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_migration_'+uuid.uuid4().hex[:12]

def admin(sql):
    return subprocess.run(['docker','compose','exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],cwd=ROOT,input=sql,text=True,capture_output=True,check=True).stdout

def app(code):
    return subprocess.run(['docker','compose','exec','-T','-e','DB_NAME='+name,'web','php'],cwd=ROOT,input='<?php '+code,text=True,capture_output=True,check=True).stdout
try:
    admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
    schema=(ROOT/'database/schema.sql').read_text().split('CREATE TABLE IF NOT EXISTS payments')[0]
    columns=['received_cents','cancelled','version','shipped_units','delivered_quantity','cancelled_quantity','unit_discount_cents','extra_discount_units']
    schema='\n'.join(line for line in schema.splitlines() if not any(line.strip().startswith(c+' ') for c in columns))
    fixture="""
INSERT INTO users (id,username,password_hash) VALUES (1,'migration_fixture','unused');
INSERT INTO customers (id,name,notes) VALUES (1,'가상 이전 고객','검증용');
INSERT INTO products (id,code,name,weight_g,price_cents) VALUES (1,'포5','포기김치',5000,5500),(2,'깍','깍두기',2500,3000);
INSERT INTO orders (id,request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,delivered,paid,payment_method,notes,created_by) VALUES
(1,'11111111111111111111111111111111',1,'가상 이전 고객','가상 주소','2026-01-01','2026-01-02','pickup',8000,1,0,7999,1,1,'cash','',1),
(2,'22222222222222222222222222222222',1,'가상 이전 고객','가상 주소','2026-01-01','2026-01-02','delivery',5000,0,500,5500,0,0,NULL,'',1);
INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) VALUES (1,1,'포5','포기김치',5000,5000,1),(1,2,'깍','깍두기',2500,3000,1),(2,1,'포5','포기김치',5000,5000,1);
"""
    admin('USE '+name+';\n'+schema+'\n'+fixture)
    app('require "scripts/init.php";')
    snapshot_code='''require "app/bootstrap.php";echo json_encode(["orders"=>query("SELECT id,total_cents,received_cents,shipped_units,paid FROM orders ORDER BY id")->fetchAll(),"items"=>query("SELECT * FROM order_items ORDER BY id")->fetchAll(),"payments"=>query("SELECT * FROM payments ORDER BY id")->fetchAll(),"allocations"=>query("SELECT * FROM payment_allocations ORDER BY id")->fetchAll(),"price"=>query("SELECT price_cents FROM products WHERE id=1")->fetchColumn()]);'''
    before=json.loads(app(snapshot_code))
    assert before['orders'][0]=={'id':1,'total_cents':7999,'received_cents':7999,'shipped_units':2,'paid':1}
    assert before['orders'][1]=={'id':2,'total_cents':5500,'received_cents':0,'shipped_units':0,'paid':0}
    assert len(before['payments'])==1 and before['payments'][0]['paid_on'] is None
    assert sum(a['amount_cents'] for a in before['allocations'])==7999
    assert before['items'][0]['delivered_quantity']==1 and before['items'][0]['unit_price_cents']==5000
    assert before['price']==5500
    app('require "scripts/init.php";')
    assert json.loads(app(snapshot_code))==before
    print('PASS 별도 구형 DB 이전: 완납·미입금·제품가격·할인·전달수량 보존, 입금 중복 없음, 재실행 동일')
finally:
    admin('DROP DATABASE IF EXISTS '+name+"; REVOKE ALL PRIVILEGES ON "+name+".* FROM 'kimchi'@'%';")
