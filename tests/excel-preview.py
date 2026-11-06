"""Preview data-integrity, OOXML and HTTP tests. No business data is inserted."""
from pathlib import Path
import subprocess, json, uuid, base64, http.cookiejar, urllib.request, urllib.error, urllib.parse, re
from excel_fixture import fixture,write_book
ROOT=Path(__file__).resolve().parent.parent
run_id=uuid.uuid4().hex[:10]
fixture_path=ROOT/'.local'/('preview-test-'+run_id+'.xlsx')
passed=0

def php(code):
    r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=ROOT,input='<?php require "app/bootstrap.php";require "app/preview-storage.php";'+code,text=True,capture_output=True,check=True)
    return r.stdout

def check(ok,label):
    global passed
    assert ok,label
    passed+=1;print('PASS',label)

def digest():
    return php('$s=[];foreach(["products","customers","orders","order_items","payments","payment_allocations","order_events"] as $t)$s[$t]=query("SELECT * FROM ".$t." ORDER BY id")->fetchAll();echo hash("sha256",json_encode($s));')

def build(path,snapshot=None):
    relative='/app/'+str(path.relative_to(ROOT))
    snap='previewSnapshot()' if snapshot is None else 'json_decode(base64_decode("'+base64.b64encode(json.dumps(snapshot).encode()).decode()+'"),true)'
    return json.loads(php('echo json_encode(buildExcelPreview('+json.dumps(relative)+',"fictional.xlsx",'+snap+'));'))

def client():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
opener=client()
def req(path='/',data=None,content_type=None,who=opener):
    if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
    request=urllib.request.Request('http://127.0.0.1:8080'+path,data=data)
    if content_type:request.add_header('Content-Type',content_type)
    try:
        with who.open(request) as r:return r.status,r.read().decode(errors='replace'),r.url
    except urllib.error.HTTPError as r:return r.code,r.read().decode(errors='replace'),r.url

def upload(fields,filename,content):
    boundary='----Preview'+uuid.uuid4().hex
    parts=[]
    for key,value in fields.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
    return b''.join(parts),'multipart/form-data; boundary='+boundary

files=[]
try:
    before=digest();customers,orders=fixture(fixture_path);files.append(fixture_path)
    report=build(fixture_path);check(report['summary']['customers']==4 and report['summary']['orders']==10,'빈 수식·0 수량 행 제외, 실제 후보 행 유지')
    check(report['customers']['고객명단!2']['name']=='가상김치고객','공유 문자열과 첫 고객 행 읽기')
    o=report['orders']['26!3']
    check(o['source_total_cents']==7500 and o['recomputed_total_cents']==7500,'수식은 실행하지 않고 저장된 결과·당시 단가·할인 대조')
    check(o['customer_refs']==['고객명단!2'],'닉네임으로 첫 고객 연결 후보 찾기')
    check('duplicate_order' in o['issues'] and o['duplicate_refs']==['26!4'],'중복 가능 주문 표시, 삭제하지 않음')
    check(len(report['orders']['26!5']['customer_refs'])==2 and 'ambiguous_customer' in report['orders']['26!5']['issues'],'동명이인 후보 자동 합치기 금지')
    unique_path=ROOT/'.local'/('preview-unique-'+run_id+'.xlsx');files.append(unique_path)
    unique_customers={k:dict(v) for k,v in customers.items()};unique_customers[2][2]='shared-alias';unique_customers[3][2]='shared-alias'
    unique_orders={1:orders[1],2:orders[2],3:dict(orders[3])};unique_orders[3][2]='가상김치고객'
    write_book(unique_path,{'고객명단':unique_customers,'26':unique_orders},shared=['가상김치고객'])
    unique=build(unique_path)
    check('duplicate_customer' in unique['customers']['고객명단!2']['issues'] and unique['orders']['26!3']['customer_refs']==['고객명단!2'] and 'ambiguous_customer' not in unique['orders']['26!3']['issues'],'다른 닉네임이 중복돼도 고유 실명 주문은 모호하지 않음')

    check(report['orders']['26!5']['recomputed_total_cents']==5500 and 'negative_discount' in report['orders']['26!5']['issues'],'음수 할인 원본 비교·추가금 여부 표시')
    check('sheet_year_mismatch' in report['orders']['26!5']['issues'],'시트 연도와 실제 주문일 불일치 표시')
    check('unregistered_product' in report['orders']['26!6']['issues'] and 'unmatched_customer' in report['orders']['26!6']['issues'],'미등록 과거 제품·명단 없는 주문자 표시')
    check('ambiguous_product' in report['orders']['26!7']['issues'] and len(report['orders']['26!7']['items'])==2,'중복 제품 약자는 열별로 구분, 임의 연결 없음')
    check(report['orders']['26!8']['difference_cents']==-4900,'원본 실제주문과 재계산 차이 정확성')
    check(report['orders']['26!9']['source_total_cents'] is None and report['orders']['26!9']['difference_cents'] is None,'수식 캐시 없음은 0으로 대체하지 않음')
    check('no_items' in report['orders']['26!10']['issues'],'제품 수량 없는 행을 누락하지 않고 표시')
    check('invalid_quantity' in report['orders']['26!11']['issues'] and 'invalid_quantity' in report['orders']['26!12']['issues'],'소수 수량·불리언 수량 검토 표시')
    check(report['orders']['26!3']['paid'] is True and report['orders']['26!3']['delivered'] is True,'생산·배송·입금 체크 읽기')
    check('customer_name_missing' in report['customers']['고객명단!5']['issues'],'실명 없는 닉네임 고객 확인 표시')
    snapshot=json.loads(php('echo json_encode(previewSnapshot());'))
    snapshot['customers']=[{'id':999,'name':'가상김치고객','nickname':'kimchi-test','phone':'','address':'다른 가상 주소'}]
    snapshot['orders']=[{'id':777,'customer_name':'kimchi-test','order_date':'2026-01-02','total_cents':7500}]
    matched=build(fixture_path,snapshot)
    check(matched['customers']['고객명단!2']['existing_candidates'][0]['id']==999 and matched['orders']['26!3']['existing_order_ids']==[777],'기존 시스템 중복 후보 읽기 전용 비교')
    check(report['orders']['26!3']['recomputed_total_cents']==7500,'과거 주문에 현재 배달비를 소급 부과하지 않음')
    date_path=ROOT/'.local'/('preview-dates-'+run_id+'.xlsx');files.append(date_path)
    date_orders={1:orders[1],2:orders[2],3:dict(orders[3],**{})};date_orders[3][1]=0
    write_book(date_path,{'26':date_orders},date1904=True)
    check(build(date_path)['orders']['26!3']['date']=='1904-01-01','1904 날짜 체계의 0일 처리')
    check(php('echo json_encode([previewDate(60,false),previewDate(61,false),previewDate("2026-02-30",false)]);')=='[null,"1900-03-01",null]','엑셀 1900 윤년 오류·잘못된 날짜 처리')
    for kind in ['doctype','external']:
        p=ROOT/'.local'/('preview-'+kind+'-'+run_id+'.xlsx');files.append(p)
        write_book(p,{'26':orders},**{kind:True})
        relative='/app/'+str(p.relative_to(ROOT))
        out=php('try{buildExcelPreview('+json.dumps(relative)+',"test.xlsx",previewSnapshot());echo "accepted";}catch(InvalidArgumentException $e){echo "rejected";}')
        check(out=='rejected','외부 XML·외부 시트 연결 차단: '+kind)
    _,body,_=req('/?page=excel-preview',who=client())
    check('name="password"' in body and '오타와김치 김치방.xlsx' not in body,'로그인 전 미리보기 접근 차단')
    _,body,_=req();csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
    _,body,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':(ROOT/'.local/admin-password.txt').read_text().strip()})
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
    raw=fixture_path.read_bytes()
    data,ct=upload({'action':'preview_upload'},fixture_path.name,raw)
    check(req('/?page=excel-preview',data,ct)[0]==422,'업로드 CSRF 검사')
    data,ct=upload({'action':'preview_upload','csrf':csrf},'fictional.csv',raw)
    check(req('/?page=excel-preview',data,ct)[0]==422,'잘못된 파일 확장자 거부')
    data,ct=upload({'action':'preview_upload','csrf':csrf},'fictional.xlsx',b'not a zip')
    check(req('/?page=excel-preview',data,ct)[0]==422,'잘못된 엑셀 내용 거부')
    data,ct=upload({'action':'preview_upload','csrf':csrf},fixture_path.name,raw)
    status,body,url=req('/?page=excel-preview',data,ct)
    check(status==200 and fixture_path.name in body and '주문 후보' in body,'파일 업로드·분석·결과 표시')
    status,body,_=req('/?page=excel-preview&tab=customers&row='+urllib.parse.quote('고객명단!2'))
    check(status==200 and '&lt;script&gt;' in body and '<script>alert("fixture")</script>' not in body,'엑셀 메모 HTML 이스케이프')
    for tab in ['issues','customers','orders','products']:
        check(req('/?page=excel-preview&tab='+tab)[0]==200,'미리보기 탭: '+tab)
    check('가상김치고객' not in req('/?page=excel-preview&tab=customers&q='+urllib.parse.quote('존재하지않는문자'))[1],'고객 검색 필터')
    check('−$49.00' in req('/?page=excel-preview&tab=issues&group=orders&issue=amount_mismatch')[1],'금액 차이 사유 필터')
    check(req('/.local/imports/provided.json')[0]==404,'비공개 분석 파일 웹 경로 차단')
    check(req('/?page=excel-preview',{'action':'preview_commit','csrf':csrf})[0]==422,'미리보기에는 실제 가져오기 실행 폼 없음')
    if (ROOT/'.local/imports/attached-source.zip').exists():
        actual=build(ROOT/'.local/imports/attached-source.zip')
        check(actual['summary']['customers']==193 and actual['summary']['orders']==948 and actual['summary']['amount_comparable']==946 and actual['summary']['amount_matches']==945 and actual['summary']['amount_mismatches']==1,'원본 첨부 전체 행·금액 집계 대조')
        reviewed=json.loads(php('echo json_encode(applyExcelDecisions(buildExcelPreview("/app/.local/imports/attached-source.zip","original.xlsx",previewSnapshot())));'))
        hist=reviewed['products']
        check(hist['24!N']['historical_product']['key']==hist['24!O']['historical_product']['key']=='yeolmu-3200' and hist['24!N']['historical_product']['weight_g']==hist['24!O']['historical_product']['weight_g']==3200,'2024년 두 열무 열을 같은 3.2kg 제품으로 분류')
        check(hist['24!N']['historical_price_cents']==2000 and hist['24!O']['historical_price_cents']==2500,'같은 열무김치의 당시 $20·$25 단가 각각 보존')
        check(hist['24!O']['current_product']['weight_g']==2500 and hist['24!O']['historical_product']['weight_g']==3200,'현재 열무김치 2.5kg과 과거 3.2kg 분리')
        check(hist['24!V']['historical_product']['weight_g']==5000 and hist['24!V']['historical_product']['family_code']=='맛5','2024 맛김치를 맛김치 5kg으로 분류')
        check(hist['24!P']['historical_product']['name']=='오이김치' and hist['24!P']['historical_product']['weight_g'] is None and hist['24!P']['current_product'] is None,'2024 오이김치 무게 미확인·현재 판매 제품 미등록')
        historical_lines=[i for o in reviewed['orders'].values() if o['sheet']=='24' for i in o['items'] if i['column'] in ['N','O'] and i['quantity']]
        check(all(i['historical_product']['weight_g']==3200 and i['unit_price_cents']==(2000 if i['column']=='N' else 2500) for i in historical_lines),'과거 주문 행마다 용량과 당시 단가 보존')
        cucumber_orders=[o for o in reviewed['orders'].values() if o['sheet']=='24' and any(i['column']=='P' and i['quantity'] for i in o['items'])]
        check(len(cucumber_orders)==6 and all('unknown_historical_weight' in o['issues'] for o in cucumber_orders),'오이김치 6행은 무게 미확인 표시')
        check(not any('ambiguous_product' in o['issues'] for o in reviewed['orders'].values()),'확인된 2024 제품 약자의 모호성 표시 해소')
        org=reviewed['customers']['고객명단!35']
        check(org['customer_kind']=='organization' and org['name']=='대사관' and org['contact_name']==org['original_customer_fields']['nickname'],'단체 고객과 원본 담당자 분리 보존')
        check(all(reviewed['customers']['고객명단!'+str(n)]['customer_kind']=='person' and reviewed['customers']['고객명단!'+str(n)]['nickname']=='대사관 '+reviewed['customers']['고객명단!'+str(n)]['name'] for n in [11,27,159]),'세 개인 고객의 소속 포함 닉네임')
        check(not any('duplicate_customer' in c['issues'] for c in reviewed['customers'].values()) and reviewed['orders']['26!308']['customer_refs']==['고객명단!35'] and reviewed['orders']['26!308']['customer_link_confirmed'],'공유 별명 중복 해소 및 단체 주문 확정 연결')
        check(all(reviewed['orders'][ref]['preview_disposition']=='exclude' and reviewed['orders'][ref]['excluded_issues'] for ref in ['25!281','25!323']),'수량 없는 두 행 원본 보존·가져오기 제외')
        check(all(reviewed['orders'][ref]['statistics_year']==2026 and reviewed['orders'][ref]['date']=='2026-01-01' and 'sheet_year_mismatch' not in reviewed['orders'][ref]['issues'] for ref in ['25!346','25!347']),'원본 시트 위치 유지·2026년 분류')
        other=[o for o in reviewed['orders'].values() if o.get('adjustment_kind')=='other_sales']
        extra=[o for o in reviewed['orders'].values() if o.get('adjustment_kind')=='consented_extra_income']
        check(len(other)==3 and sum(o['other_sales_cents'] for o in other)==18000 and all(o['other_sales_description']=='김밥' and o['other_sales_quantity'] is None and o['other_sales_unit_price_cents'] is None for o in other),'김밥 대금 3건 $180은 기타 판매로 분리')
        check(len(extra)==9 and sum(o['additional_income_cents'] for o in extra)==8200 and all(o['customer_credit_cents']==0 for o in extra),'동의받은 초과 입금 9건 $82·고객 잔액 생성 없음')
        check(all(o['income_total_cents']==o['expected_total_cents'] for o in reviewed['orders'].values() if o['expected_total_cents'] is not None and o.get('preview_disposition')!='exclude'),'분류 후 전체 금액 중복 가산 없음')
        check(all(o['sales_receivable_cents']+o['additional_income_cents']==o['recomputed_total_cents'] and o['historical_delivery_fee_cents']==0 for o in extra),'초과 입금은 김치 청구액·배달비로 섞지 않음')
        corrected=reviewed['orders']['24!52']
        check(corrected['source_total_cents']==2400 and corrected['expected_total_cents']==2500 and corrected['recomputed_total_cents']==2500,'사용자 정정 금액 $25·원본 $24 분리 보존')
        check(next(i for i in corrected['items'] if i['column']=='O')['quantity']==1 and all(i['quantity']==0 for i in corrected['items'] if i['header'].startswith('기타')),'정정 제품 1개·기타 0개 반영')
        check(corrected['correction']['original_recomputed_total_cents']==7500 and corrected['correction']['original_items'][0]['quantity']==3,'정정 전 계산·수량 이력 보존')
        fees=[o for o in reviewed['orders'].values() if o['historical_delivery_fee_cents']==500]
        check(len(fees)==47 and all(o['discount_cents']==-500 and o['reviewed_discount_cents']==0 and 'delivery_fee_candidate' in o['issues'] for o in fees),'-$5 47행은 과거 배달비 후보·원본 음수 할인 보존')
        check(reviewed['decisions']['other_negative_adjustments']==12 and all(o['historical_delivery_fee_cents']==0 for o in reviewed['orders'].values() if o['discount_cents'] is not None and o['discount_cents']<0 and o['discount_cents']!=-500),'다른 음수 금액 12행은 배달비로 자동 변환하지 않음')
        check(reviewed['summary']['amount_matches']==946 and reviewed['summary']['amount_mismatches']==0,'정정 후 검토 기준 금액 비교 재집계')
        check(reviewed['year_summary']['24']['source_total_cents']==actual['year_summary']['24']['source_total_cents'] and reviewed['year_summary']['24']['reviewed_total_cents']==actual['year_summary']['24']['source_total_cents']+100,'원본 연간 합계 유지·정정 합계 $1 차이 구분')
        check(json.loads(php('$r=applyExcelDecisions(buildExcelPreview("/app/.local/imports/attached-source.zip","original.xlsx",previewSnapshot()));echo json_encode(applyExcelDecisions($r)===$r);')) is True,'정정 반복 적용 시 중복 계산 없음')
        unrelated=json.loads(php('echo json_encode(applyExcelDecisions(buildExcelPreview("/app/'+str(fixture_path.relative_to(ROOT))+'","unrelated.xlsx",previewSnapshot())));'))
        check('decision_revision' not in unrelated and unrelated['orders']['26!5']['discount_cents']==-500,'다른 엑셀에는 원본 한정 정정을 적용하지 않음')
        req('/?page=excel-preview',{'action':'preview_refresh','csrf':csrf})
        status,body,_=req('/?page=excel-preview&tab=orders&row=24%2152')
        check(status==200 and '사용자 정정 반영' in body and '검토 기준 $25.00' in body and '원본 실제주문 $24.00' in body,'정정 이력·비교 기준을 화면에서 분리 표시')

    check(digest()==before,'전체 분석·업로드 후 고객·주문·입금·제품 DB 내용 불변')
    print(f'완료: 엑셀 미리보기 검증 {passed}개 통과')
finally:
    name64=base64.b64encode(fixture_path.name.encode()).decode()
    php('$name=base64_decode("'+name64+'");foreach(glob(previewDirectory()."/*.json")?:[] as $f){if(basename($f)==="provided.json")continue;$r=json_decode(file_get_contents($f),true);if(($r["filename"]??null)===$name)unlink($f);}')
    for p in files:p.unlink(missing_ok=True)
