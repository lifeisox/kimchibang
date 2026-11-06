"""Bulk review, reversible staging, concurrency and read-only business-data integration tests."""
from pathlib import Path
import json,subprocess,uuid,base64,re,http.cookiejar,urllib.request,urllib.parse,urllib.error
from excel_fixture import fixture,write_book
ROOT=Path(__file__).resolve().parent.parent
prefix='groups-'+uuid.uuid4().hex
path=ROOT/'.local'/(prefix+'.xlsx')
passed=0;source_sha=None

def php(code):
 return subprocess.run(['docker','compose','exec','-T','web','php'],cwd=ROOT,input='<?php require "app/bootstrap.php";require "app/preview-storage.php";'+code,text=True,capture_output=True,check=True).stdout

def check(ok,label):
 global passed
 assert ok,label
 passed+=1;print('PASS',label)

def digest():return php('$s=[];foreach(["customers","orders","order_items","payments","payment_allocations","order_events","products"] as $t)$s[$t]=query("SELECT * FROM ".$t." ORDER BY id")->fetchAll();echo hash("sha256",json_encode($s));')

def loaded(code):return json.loads(php(code))

opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(url='/',data=None,ctype=None):
 data=urllib.parse.urlencode(data,doseq=True).encode() if isinstance(data,dict) else data
 r=urllib.request.Request('http://127.0.0.1:8080'+url,data=data)
 if ctype:r.add_header('Content-Type',ctype)
 try:
  with opener.open(r) as out:return out.status,out.read().decode(),out.url
 except urllib.error.HTTPError as out:return out.code,out.read().decode(),out.url

def fixtureReport():
 return loaded('echo json_encode(buildExcelPreview("/app/.local/'+path.name+'","fixture.xlsx",previewSnapshot()));')

def staged():return loaded('$r=buildExcelPreview("/app/.local/'+path.name+'","fixture.xlsx",previewSnapshot());echo json_encode(applyPreviewReview($r));')

def update(base,selected,choices=None,version=None,membership_override=None):
 data={'report_sha':base['sha256'],'review_version':staged()['review_version'] if version is None else version,'selected':selected,'choices':{},'memberships':{}}
 for id in selected:
  g=base['review_groups'][id];data['choices'][id]=(choices or {}).get(id,g['recommended']);data['memberships'][id]=membership_override or g['membership']
 encoded=base64.b64encode(json.dumps(data).encode()).decode()
 return php('$r=buildExcelPreview("/app/.local/'+path.name+'","fixture.xlsx",previewSnapshot());try{echo updatePreviewReview($r,json_decode(base64_decode("'+encoded+'"),true),"test-reviewer");}catch(InvalidArgumentException $e){echo "rejected";}')

try:
 before=digest();customers,orders=fixture(path);write_book(path,{'고객명단':customers,'26':orders,'검증':{1:{1:prefix}}},shared=['가상김치고객'])
 base=staged();source_sha=base['sha256'];groups=base['review_groups']
 white=loaded('echo json_encode(query("SELECT * FROM products WHERE code=?",["포백5"])->fetch());')
 check(white and white['active']==1 and white['weight_g']==5000 and white['price_cents']==5000,'백포기김치 5kg $50 판매 제품 등록')
 actual=loaded('echo json_encode(applyExcelDecisions(buildExcelPreview("/app/.local/imports/attached-source.zip","original.xlsx",previewSnapshot())));')
 check(all(actual['products'][year+'!K']['historical_product']['key']=='white-cabbage-5000' for year in ['24','25','26']),'포백5 3개 연도 같은 백포기김치 5kg으로 연결')
 check(actual['products']['24!K']['historical_price_cents']==4500 and actual['products']['25!K']['historical_price_cents']==actual['products']['26!K']['historical_price_cents']==5000,'과거 $45·$50 단가 보존')
 check(all(not any(i['header']=='포백5' for i in o['items']) or 'unregistered_product' not in o['issues'] for o in actual['orders'].values()),'확인된 포백5를 미등록 제품으로 표시하지 않음')
 check(all(g['count']==len(set(g['refs'])) for g in groups.values()),'그룹별 건수는 중복 사유가 아닌 고유 행 수')
 find=lambda code:next(id for id,g in groups.items() if g['code']==code)
 dup=find('duplicate_customer');amb=find('ambiguous_customer');addr=find('customer_address_missing');neg=find('negative_discount');financial=find('amount_discrepancy')
 check(groups[dup]['choices']=={'keep_separate':'고객을 각각 보존·자동으로 합치지 않음','defer':'고객과 연결 주문을 보류'},'동명이인 일괄 병합 선택 없음')
 check(groups[amb]['choices']=={'defer':'확인될 때까지 가져오기 보류'},'고객 불명 주문 자동 연결 선택 없음')
 check(groups[financial]['count']==1 and set(groups[financial]['codes'])=={'amount_mismatch','subtotal_mismatch'},'같은 금액 차이 행의 두 사유를 한 그룹으로 묶음')
 check(update(base,[dup,addr])=='2','여러 그룹을 한 번에 적용')
 current=staged()
 check('duplicate_customer' not in current['customers']['고객명단!3']['issues'] and current['customers']['고객명단!3']['keep_customer_separate'],'같은 이름 고객은 별도 보존')
 check('ambiguous_customer' in current['orders']['26!5']['issues'],'고객 분리 기준 적용 후에도 불명확한 주문 연결은 미해결')
 check(current['customers']['고객명단!5']['address']=='' and 'customer_address_missing' not in current['customers']['고객명단!5']['issues'],'주소 미기록 보존, 주소나 픽업 여부 추측 없음')
 check(update(base,[neg],{neg:'delivery_fee'})=='1','음수 금액 그룹을 배달비로 구분')
 current=staged();o=current['orders']['26!5']
 check(o['reviewed_discount_cents']==0 and o['historical_delivery_fee_cents']==500 and o['discount_cents']==-500 and o['recomputed_total_cents']==5500,'배달비 분류 후 원본·최종 금액 보존')
 check(update(base,[neg],{neg:'surcharge'})=='1','배달비 외 추가금으로 구분 가능')
 o=staged()['orders']['26!5'];check(o['historical_surcharge_cents']==500 and o['historical_delivery_fee_cents']==0 and o['recomputed_total_cents']==5500,'추가금 구분 후에도 최종 금액 동일')
 check(update(base,[neg],{neg:'reset'})=='1' and 'negative_discount' in staged()['orders']['26!5']['issues'],'적용 기준 되돌리기')
 previous=staged()['review_version'];update(base,[addr]);check(update(base,[dup],version=previous)=='rejected','동시 변경 오래된 화면 차단')
 check(update(base,[dup],membership_override='f'*64)=='rejected','다른 구성의 그룹 일괄 처리 차단')
 check(update(base,[dup],{dup:'merge_all'})=='rejected','허용되지 않은 일괄 병합 요청 차단')
 check(update(base,[amb])=='1' and staged()['orders']['26!5']['review_disposition']=='hold','불명확한 고객 주문만 일괄 보류')
 # Undo all then hold a customer; linked order must also be held.
 ids=list(groups);update(base,ids,{id:'reset' for id in ids})
 update(base,[addr],{addr:'defer'});current=staged()
 check(current['customers']['고객명단!5']['review_disposition']=='hold' and current['orders']['26!7']['review_disposition']=='hold','고객 보류 시 연결 후보 주문도 보류')
 # Every group can be selected together without importing or deleting the source records.
 update(base,ids,{id:'reset' for id in ids});check(update(base,ids)==str(len(ids)),'전체 그룹 선택 일괄 적용')
 current=staged();check(current['summary']['customers']==4 and current['summary']['orders']==10 and current['summary']['held_orders']>0,'보류 원본은 삭제하지 않고 별도 집계')
 check(loaded('$r=buildExcelPreview("/app/.local/'+path.name+'","fixture.xlsx",previewSnapshot());$r=applyPreviewReview($r);echo json_encode(applyPreviewReview($r)===$r);'),'그룹 기준 중복 적용 없음')
 changed=loaded('$r=buildExcelPreview("/app/.local/'+path.name+'","fixture.xlsx",previewSnapshot());$r["product_decision_revision"]="changed";echo json_encode(applyPreviewReview($r));')
 check(changed['review_group_counts']['applied']==0 and all(g['state']=='stale' for g in changed['review_groups'].values()),'분석 기준 변경 시 이전 선택을 무조건 재사용하지 않음')
 # HTTP tests use the same private fixture but no direct business-table writes.
 _,body,_=req();csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
 _,body,_=req(data={'action':'login','csrf':csrf,'username':'admin','password':(ROOT/'.local/admin-password.txt').read_text().strip()});csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
 boundary='----'+uuid.uuid4().hex
 body=(f'--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\npreview_upload\r\n--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{path.name}"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'.encode()+path.read_bytes()+f'\r\n--{boundary}--\r\n'.encode())
 check(req('/?page=excel-preview',body,'multipart/form-data; boundary='+boundary)[0]==200,'그룹 검토용 파일 업로드')
 params={'action':'preview_group_decision','report_sha':source_sha,'review_version':staged()['review_version'],'selected[]':[dup],f'choices[{dup}]':'reset',f'memberships[{dup}]':groups[dup]['membership']}
 check(req('/?page=excel-preview&tab=groups',params)[0]==422,'그룹 처리 CSRF 검사')
 params['csrf']=csrf;check(req('/?page=excel-preview&tab=groups',params)[0]==200,'화면에서 그룹 기준 되돌리기')
 params['review_version']=staged()['review_version'];params['report_sha']='0'*64
 check(req('/?page=excel-preview&tab=groups',params)[0]==422,'다른 파일을 대상으로 하는 요청 차단')
 check(req('/?page=excel-preview&tab=orders&review_group='+amb)[0]==200,'반영·보류한 그룹의 구성 행 조회')
 check(digest()==before,'그룹 처리 후 고객·주문·입금·제품 DB 불변')
 print(f'완료: 그룹 검토 검증 {passed}개 통과')
finally:
 if source_sha:
  name64=base64.b64encode(path.name.encode()).decode()
  php('$sha="'+source_sha+'";$file=previewReviewPath($sha);foreach([$file,$file.".lock"] as $f)if(is_file($f))unlink($f);$name=base64_decode("'+name64+'");foreach(glob(previewDirectory()."/*.json")?:[] as $f){if(basename($f)==="provided.json")continue;$r=json_decode(file_get_contents($f),true);if(($r["filename"]??null)===$name)unlink($f);}')
 path.unlink(missing_ok=True)
