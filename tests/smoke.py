"""HTTP + MySQL integration tests. Uses isolated fixtures; never imports real customers."""
from pathlib import Path
import http.cookiejar, urllib.request, urllib.parse, urllib.error, re, json, uuid, subprocess
ROOT=Path(__file__).resolve().parent.parent
BASE='http://127.0.0.1:8080'
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
passed=0
prefix='TEST_'+uuid.uuid4().hex[:10]
cid=pid=oid=None

def req(path='/',data=None,client=opener):
    encoded=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
    try:
        with client.open(BASE+path,encoded) as r:return r.status,r.read().decode(),r.url
    except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url

def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
def check(condition,label):
    global passed
    assert condition,label
    passed+=1;print('PASS',label)
def php(code):
    r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=ROOT,input='<?php require "app/bootstrap.php"; '+code,text=True,capture_output=True,check=True)
    return r.stdout

try:
    check(req('/?api=customers&q=test')[0]==401,'인증 없이 고객 API 차단')
    _,body,_=req()
    status,body,_=req(data={'action':'login','csrf':token(body),'username':'admin','password':(ROOT/'.local/admin-password.txt').read_text().strip()})
    check(status==200 and '이번 주 업무' in body,'로그인과 대시보드')
    csrf=token(body)
    check(req(data={'action':'customer','name':prefix})[0]==422,'CSRF 없는 저장 차단')
    status,body,url=req('/?page=customer-edit',{'action':'customer','csrf':csrf,'name':prefix,'nickname':prefix+'_별명','address':prefix+' Maple Ottawa','phone':'555-0100','area':'테스트','notes':'<script>alert(1)</script>'})
    cid=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0])
    check(status==200 and '&lt;script&gt;' in body and '<script>alert' not in body,'고객 저장 및 HTML 이스케이프')
    for field in [prefix,prefix+'_별명','Maple','555-0100']:
        status,body,_=req('/?api=customers&q='+urllib.parse.quote(field));check(status==200 and any(c['id']==cid for c in json.loads(body)),'고객 검색: '+('주소·연락처' if field!=prefix else '이름'))
    status,body,url=req('/?page=product-edit',{'action':'product','csrf':csrf,'code':prefix,'name':'검증용 제품','weight_g':'3200','price':'30.10','active':'1'})
    pid=int(php('echo query("SELECT id FROM products WHERE code=?",['+json.dumps(prefix)+'])->fetchColumn();'))
    check(status==200,'제품 추가')
    data={'action':'order','csrf':csrf,'request_key':uuid.uuid4().hex,'customer_id':str(cid),'order_date':'2026-11-05','delivery_date':'2026-11-06','fulfillment':'delivery','delivery_address':prefix+' Maple Ottawa','discount':'0.15','notes':'가상 주문',f'qty[{pid}]':'3'}
    status,body,url=req('/?page=new-order',data)
    oid=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['id'][0])
    check(status==200 and '$90.15' in body,'주문 저장 및 정수 센트 할인 계산')
    check(req('/?page=new-order',data)[2]==url,'동일 주문 중복 제출 방지')
    bad=data|{'request_key':uuid.uuid4().hex,'discount':'1000'}
    check(req('/?page=new-order',bad)[0]==422,'초과 할인 거부')
    check(req('/?page=new-order',data|{'request_key':uuid.uuid4().hex,f'qty[{pid}]':'-1'})[0]==422,'음수 수량 거부')
    check(req('/?page=new-order',data|{'request_key':uuid.uuid4().hex,'delivery_date':'2026-02-30'})[0]==422,'잘못된 날짜 거부')
    expected=['포10','포5','맛10','맛5','깍','파','겉','물','물백','동','총각','갓','열']
    order_page=req('/?page=new-order')[1]
    codes=re.findall(r'class="product-code">([^<]+)',order_page)
    check(codes[:13]==expected and codes[-1]==prefix,'요청한 제품 순서 및 새 제품 후순위')
    for fulfillment,discount,fee,total in [('delivery','10.31',0,7999),('delivery','10.30',0,8000),('delivery','10.29',0,8001),('pickup','10.31',0,7999),('delivery','90.30',0,0)]:
        case=data|{'request_key':uuid.uuid4().hex,'discount':discount,'fulfillment':fulfillment,'delivery_fee_cents':'0'}
        status,body,url_case=req('/?page=new-order',case)
        case_id=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url_case).query)['id'][0])
        saved=json.loads(php('echo json_encode(query("SELECT delivery_fee_cents,total_cents FROM orders WHERE id=?",['+str(case_id)+'])->fetch());'))
        check(status==200 and saved['delivery_fee_cents']==fee and saved['total_cents']==total,'배달비 서버 계산 '+fulfillment+' / 할인 $'+discount)
        check('배달비 <strong>$'+format(fee/100,'.2f')+'</strong>' in body,'저장 주문·인쇄 화면 배달비 표시')
    for quantity,discount,fee,total in [('1','0',500,3510),('1','1',500,3410),('2','0',500,6520)]:
        status,body,url_case=req('/?page=new-order',data|{'request_key':uuid.uuid4().hex,'discount':discount,f'qty[{pid}]':quantity})
        case_id=int(urllib.parse.parse_qs(urllib.parse.urlsplit(url_case).query)['id'][0])
        saved=json.loads(php('echo json_encode(query("SELECT delivery_fee_cents,total_cents FROM orders WHERE id=?",['+str(case_id)+'])->fetch());'))
        check(status==200 and saved['delivery_fee_cents']==fee and saved['total_cents']==total,'할인 전 $80 미만 배달비 적용')
    before=json.loads(php('echo json_encode(query("SELECT id,total_cents,delivery_fee_cents FROM orders WHERE customer_id=? ORDER BY id",['+str(cid)+'])->fetchAll());'))
    php('require "scripts/init.php";')
    after=json.loads(php('echo json_encode(query("SELECT id,total_cents,delivery_fee_cents FROM orders WHERE customer_id=? ORDER BY id",['+str(cid)+'])->fetchAll());'))
    check(before==after,'초기화 재실행 시 주문 금액·배달비 보존')
    req('/?page=product-edit',{'action':'product','csrf':csrf,'id':pid,'code':prefix,'name':'수정된 제품','weight_g':'5000','price':'99.99','active':'1'})
    body=req('/?page=order&id='+str(oid))[1]
    check('$90.15' in body and '$30.10' in body and '검증용 제품' in body and '3.2kg' in body,'가격·이름·용량 변경 후 과거 주문 보존')
    version=php('echo query("SELECT version FROM orders WHERE id=?",['+str(oid)+'])->fetchColumn();')
    status,body,_=req('/?page=order&id='+str(oid),{'action':'deliver_all','csrf':csrf,'id':oid,'version':version,'request_key':uuid.uuid4().hex,'delivered_on':'2026-11-06'})
    check(status==200 and '✓ 전달 완료' in body and '전체 주문의 남은 대금' in body and '$90.15' in body,'배달과 입금 상태 분리')
    version=php('echo query("SELECT version FROM orders WHERE id=?",['+str(oid)+'])->fetchColumn();')
    status,body,_=req('/?page=order&id='+str(oid),{'action':'receive_payment','csrf':csrf,'id':oid,'version':version,'request_key':uuid.uuid4().hex,'payment_method':'etransfer','paid_on':'2026-11-06','amount':'90.15'})
    check(status==200 and '✓ 입금 완료' in body,'eTransfer 입금 처리')
    expected_unpaid=sum(row['total_cents'] for row in before if row['id']!=oid)
    expected_amount='$'+format(expected_unpaid/100,',.2f')
    check('미입금 금액</p><strong>'+expected_amount+'</strong>' in req('/?page=customer&id='+str(cid))[1],'고객 원장에 배달비 포함 미입금 갱신')
    for page in ['orders','customers','products','new-order']:
        check(req('/?page='+page)[0]==200,'화면 조회: '+page)
    req(data={'action':'logout','csrf':csrf})
    check(req('/?api=customers')[0]==401,'로그아웃 후 정보 차단')
    print(f'완료: {passed}개 검증 통과')
finally:
    # Only this run's fixture IDs are removed, including an order saved before an assertion failure.
    php('''db()->beginTransaction();
    $ids=query("SELECT id FROM customers WHERE name=?",['''+json.dumps(prefix)+'''])->fetchAll(PDO::FETCH_COLUMN);
    foreach($ids as $id){query("DELETE i FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.customer_id=?",[$id]);query("DELETE FROM orders WHERE customer_id=?",[$id]);query("DELETE FROM customers WHERE id=?",[$id]);}
    query("DELETE FROM products WHERE code=?",['''+json.dumps(prefix)+''']);db()->commit();''')
