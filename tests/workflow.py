"""Financial workflow integration tests: only unique virtual fixtures; always cleaned up."""
from pathlib import Path
import http.cookiejar, urllib.request, urllib.parse, urllib.error, re, json, uuid, subprocess, datetime
ROOT=Path(__file__).resolve().parent.parent
BASE='http://127.0.0.1:8080'
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
prefix='FLOW_'+uuid.uuid4().hex[:10]
TODAY=datetime.datetime.now(__import__('zoneinfo').ZoneInfo('America/Toronto')).date().isoformat()
passed=0

def php(code):
    r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=ROOT,input='<?php require "app/bootstrap.php"; '+code,text=True,capture_output=True,check=True)
    return r.stdout

def req(path='/',data=None):
    encoded=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        with opener.open(BASE+path,encoded) as r:return r.status,r.read().decode(errors='replace'),r.url
    except urllib.error.HTTPError as r:return r.code,r.read().decode(errors='replace'),r.url

def check(ok,label):
    global passed
    assert ok,label
    passed+=1;print('PASS',label)

def state(id):
    return json.loads(php('echo json_encode(["order"=>query("SELECT * FROM orders WHERE id=?",['+str(id)+'])->fetch(),"items"=>orderItems('+str(id)+'),"balance"=>balance(query("SELECT * FROM orders WHERE id=?",['+str(id)+'])->fetch())]);'))

def action(id,kind,**extra):
    o=state(id)['order']
    data={'action':kind,'csrf':csrf,'id':id,'version':o['version'],'request_key':uuid.uuid4().hex}
    data.update(extra)
    return req('/?page=order&id='+str(id),data),data

def create(q,discount='0',method='pickup'):
    data={'action':'order','csrf':csrf,'request_key':uuid.uuid4().hex,'customer_id':cid,'order_date':TODAY,'delivery_date':TODAY,'fulfillment':method,'delivery_address':'가상 주소 Ottawa','discount':discount,'notes':'가상 업무 검증'}
    data.update({f'qty[{products[code]}]':n for code,n in q.items()})
    status,body,url=req('/?page=new-order',data)
    assert status==200,(status,body[:100])
    return int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0])

def edit(id,pending=None,**extra):
    snap=state(id);o=snap['order'];items=snap['items']
    data={'delivery_date':o['delivery_date'],'fulfillment':o['fulfillment'],'delivery_address':o['delivery_address'],'discount':format(o['discount_cents']/100,'.2f'),'notes':o['notes'],'reason':'업무 흐름 검증','add_qty':'0'}
    for i in items:data[f'pending[{i["id"]}]']=(pending or {}).get(i['product_code'],i['quantity']-i['cancelled_quantity']-i['delivered_quantity'])
    data.update(extra)
    return action(id,'edit_order',**data)

try:
    _,body,_=req();csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
    _,body,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':(ROOT/'.local/admin-password.txt').read_text().strip()})
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
    _,_,url=req('/?page=customer-edit',{'action':'customer','csrf':csrf,'name':prefix,'nickname':'가상 검증','address':'가상 주소 Ottawa','phone':'','area':'','notes':'','payer_name':prefix+'_PAYER'})
    cid=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0])
    products={p['code']:p['id'] for p in json.loads(php('echo json_encode(query("SELECT id,code FROM products")->fetchAll());'))}
    oid=create({'포5':1,'깍':1})
    snap=state(oid);cabbage=snap['items'][0];radish=snap['items'][1]
    check(action(oid,'receive_payment',amount='80.01',payment_method='cash',paid_on=TODAY)[0][0]==422,'배송 전에도 주문 대금 초과 입금은 차단')
    check(action(oid,'deliver_items',delivered_on=TODAY,**{f'deliver[{cabbage["id"]}]':'2'})[0][0]==422,'주문 초과 전달 차단')
    check(action(oid,'deliver_items',delivered_on=TODAY,**{f'deliver[{cabbage["id"]}]':'1'})[0][0]==200,'포기김치만 먼저 전달')
    check(state(oid)['balance']['collectable']==8000 and state(oid)['balance']['pending']==1,'미전달 제품을 포함한 전체 주문 잔액 계산')
    check(action(oid,'receive_payment',amount='80.01',payment_method='cash',paid_on=TODAY)[0][0]==422,'주문 대금 초과 입금 차단')
    check(prefix+'_PAYER' in req('/?page=customer&id='+str(cid))[1],'고객 기본 입금자 성명 저장·원장 표시')
    check(req('/?api=customers&q='+urllib.parse.quote(prefix+'_PAYER'))[1].find(prefix)>0,'기본 입금자 이름으로 주문 고객 검색')
    check(action(oid,'receive_payment',amount='25',payment_method='cash',paid_on=TODAY,payer_name='가'*101)[0][0]==422,'입금자 성명 100자 제한')
    r,payload=action(oid,'receive_payment',amount='25',payment_method='cash',paid_on=TODAY,payer_name=prefix+'_FIRST')
    check(r[0]==200 and state(oid)['order']['received_cents']==2500,'전달 제품 일부 입금')
    check(req('/?page=order&id='+str(oid),payload)[0]==200 and state(oid)['order']['received_cents']==2500,'입금 중복 제출 방지')
    check(action(oid,'receive_payment',amount='25',payment_method='etransfer',paid_on=TODAY,payer_name=prefix+'_SECOND')[0][0]==200,'동일 전달분을 두 번에 나누어 입금')
    payers=json.loads(php('echo json_encode(query("SELECT payer_name FROM payments WHERE order_id=? ORDER BY id",['+str(oid)+'])->fetchAll(PDO::FETCH_COLUMN));'))
    check(payers==[prefix+'_FIRST',prefix+'_SECOND'],'분할 입금마다 다른 성명 별도 보존')
    php('query("UPDATE customers SET payer_name=? WHERE id=?",['+json.dumps(prefix+'_CHANGED')+','+str(cid)+']);')
    check(json.loads(php('echo json_encode(query("SELECT payer_name FROM payments WHERE order_id=? ORDER BY id",['+str(oid)+'])->fetchAll(PDO::FETCH_COLUMN));'))==payers,'고객 기본 이름 변경시 과거 입금자 보존')
    check(str(cid) in req('/?api=customers&q='+urllib.parse.quote(prefix+'_FIRST'))[1],'이전 실제 입금자 이름으로 고객 검색')
    check(prefix+'_SECOND' in req('/?page=order&id='+str(oid))[1],'입금 내역에 성명 표시')
    check(edit(oid,discount='1')[0][0]==422,'배송·입금 후 기존 할인 변경 차단')
    check(edit(oid,delivery_address='바뀐 주소')[0][0]==422,'배송·입금 후 기존 주소 변경 차단')
    check(edit(oid,{'깍':2})[0][0]==200 and state(oid)['order']['total_cents']==11000,'대기 제품 수량 증가 허용')
    snap=state(oid)
    check(snap['items'][0]['delivered_quantity']==1 and snap['order']['received_cents']==5000,'수량 수정 후 전달·입금 보존')
    stale_version=snap['order']['version']
    check(edit(oid,{'깍':1})[0][0]==200,'대기 제품 일부 수량 취소')
    check(edit(oid,{'깍':0},version=stale_version)[0][0]==422 and state(oid)['balance']['pending']==1,'동시 변경 오래된 화면 차단')
    check(action(oid,'cancel_order',reason='잘못된 전체 취소')[0][0]==422,'부분 배송·입금 주문 전체 취소 차단')
    check(action(oid,'deliver_items',delivered_on=TODAY,**{f'deliver[{radish["id"]}]':'1'})[0][0]==200,'보류 제품 나중에 추가 전달')
    check(state(oid)['balance']['collectable']==3000,'나중 전달한 제품 추가 입금액 계산')
    check(action(oid,'receive_payment',amount='30',payment_method='cash',paid_on=TODAY)[0][0]==200,'나머지 금액 추가 입금')
    snap=state(oid)
    check(snap['order']['paid']==1 and snap['order']['received_cents']==8000 and snap['order']['total_cents']==8000,'완납 및 잔액 일치')
    check(edit(oid,{'깍':0})[0][0]==422,'완납 주문 직접 수정 요청 차단')
    check(action(oid,'cancel_order',reason='완납 취소 시도')[0][0]==422,'완납 주문 직접 취소 요청 차단')
    check('주문 수정·대기 제품 취소</a>' not in req('/?page=order&id='+str(oid))[1],'완납 주문 수정 버튼 숨김')
    allocated=int(php('echo query("SELECT SUM(a.amount_cents) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE p.order_id=?",['+str(oid)+'])->fetchColumn();'))
    check(allocated==8000,'입금 기록과 제품별 배분 합계 일치')
    oid2=create({'포5':1,'깍':1},method='delivery');cabbage2=state(oid2)['items'][0]
    action(oid2,'deliver_items',delivered_on=TODAY,**{f'deliver[{cabbage2["id"]}]':'1'})
    action(oid2,'receive_payment',amount='50',payment_method='cash',paid_on=TODAY)
    check(edit(oid2,{'깍':0})[0][0]==200,'입금된 제품 보존하며 미생산 제품 취소')
    snap=state(oid2)
    check(snap['order']['paid']==1 and snap['order']['total_cents']==5000 and snap['order']['delivery_fee_cents']==0,'부분 취소 후 완납 처리·기존 무료 배달 유지')
    check(snap['items'][1]['cancelled_quantity']==1,'취소 수량 이력 보존')
    oid3=create({'포5':1},method='delivery')
    check(edit(oid3,{'포5':0},add_product_id=products['맛10'],add_qty='1')[0][0]==200,'배송 전 제품 교체·기존 행 취소 기록')
    check(state(oid3)['order']['total_cents']==9000 and state(oid3)['order']['delivery_fee_cents']==0,'배송 전 수정 시 할인 전 합계로 배달비 재계산')
    check(action(oid3,'cancel_order',reason='중복 주문')[0][0]==200,'미배송·미입금 주문 전체 취소')
    snap=state(oid3)
    check(snap['order']['cancelled']==1 and snap['order']['total_cents']==0 and len(snap['items'])==2,'삭제 없이 취소·주문행 보존')
    check(req('/?page=orders&filter=cancelled&q='+urllib.parse.quote(prefix))[0]==200,'취소 주문 이름 검색')
    check(action(oid3,'deliver_all',delivered_on=TODAY)[0][0]==422,'취소 주문 배송 차단')
    oid4=create({'포5':3},discount='0.01');item4=state(oid4)['items'][0]
    action(oid4,'deliver_items',delivered_on=TODAY,**{f'deliver[{item4["id"]}]':'1'})
    check(state(oid4)['balance']['collectable']==14999,'1센트 할인 배분 정확성')
    action(oid4,'receive_payment',amount='49.99',payment_method='cash',paid_on=TODAY)
    check(edit(oid4,{'포5':1})[0][0]==200,'같은 제품에서 받은 1개 보존·대기 1개 취소')
    check(state(oid4)['order']['total_cents']==9999 and state(oid4)['order']['received_cents']==4999,'취소 후 할인·입금 센트 보존')
    action(oid4,'deliver_all',delivered_on=TODAY)
    action(oid4,'receive_payment',amount='50',payment_method='etransfer',paid_on=TODAY)
    check(state(oid4)['order']['paid']==1,'동일 제품 분할 배송·입금 완결')
    prepaid=create({'포5':1,'깍':1})
    check(action(prepaid,'receive_payment',amount='80',payment_method='etransfer',paid_on=TODAY,payer_name='PREPAID TEST')[0][0]==200,'배송 전 전액 선입금 허용')
    snap=state(prepaid)
    check(snap['order']['paid']==1 and snap['order']['delivered']==0 and snap['balance']['collectable']==0,'입금 완료와 전달 전 상태 독립')
    check(edit(prepaid,{'깍':0})[0][0]==422,'선입금 완납 주문 수정 차단')
    check(action(prepaid,'deliver_all',delivered_on=TODAY)[0][0]==200 and state(prepaid)['order']['delivered']==1,'완납 후에도 전달 완료 처리 허용')
    partial_prepaid=create({'포5':1,'깍':1})
    check(action(partial_prepaid,'receive_payment',amount='25',payment_method='cash',paid_on=TODAY)[0][0]==200,'부분 선입금 허용')
    check(edit(partial_prepaid,{'포5':0})[0][0]==422,'부분 선입금이 배정된 미전달 제품 취소 차단')
    check(edit(partial_prepaid,{'깍':0})[0][0]==200 and state(partial_prepaid)['order']['total_cents']==5000,'미입금 미전달 제품은 취소 가능')
    check(state(partial_prepaid)['order']['received_cents']==2500,'취소 후 선입금 기록 보존')
    reverse_order=create({'포5':1,'깍':1})
    radish_only=state(reverse_order)['items'][1]
    action(reverse_order,'deliver_items',delivered_on=TODAY,**{f'deliver[{radish_only["id"]}]':'1'})
    action(reverse_order,'receive_payment',amount='30',payment_method='cash',paid_on=TODAY)
    check(edit(reverse_order,{'포5':0})[0][0]==200,'주문 행 순서와 달라도 전달한 제품부터 입금 배정')
    check(state(reverse_order)['order']['paid']==1 and state(reverse_order)['order']['total_cents']==3000,'미전달 미입금 제품만 취소하고 전달·입금 보존')
    fee_order=create({'깍':1,'파':1},method='delivery')
    green=next(i for i in state(fee_order)['items'] if i['product_code']=='파')
    action(fee_order,'deliver_items',delivered_on=TODAY,**{f'deliver[{green["id"]}]':'1'})
    action(fee_order,'receive_payment',amount='35',payment_method='cash',paid_on=TODAY)
    check(edit(fee_order,{'깍':0})[0][0]==200 and state(fee_order)['order']['total_cents']==3500,'전달 제품과 배송비부터 배정하여 미입금 대기 제품 취소 허용')
    check(req('/favicon.ico')[0]==200 and req('/assets/favicon-32.png')[0]==200,'파비콘 파일 응답')
    print(f'완료: 업무 검증 {passed}개 통과')
finally:
    php('''db()->beginTransaction();$ids=query("SELECT id FROM customers WHERE name=?",['''+json.dumps(prefix)+'''])->fetchAll(PDO::FETCH_COLUMN);
    foreach($ids as $id){query("DELETE i FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.customer_id=?",[$id]);query("DELETE FROM orders WHERE customer_id=?",[$id]);query("DELETE FROM customers WHERE id=?",[$id]);}db()->commit();''')
