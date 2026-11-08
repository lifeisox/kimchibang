<?php
function handleAction(): never {
    $action=$_POST['action']??'';
    if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf']??'',$_POST['csrf'])) throw new InvalidArgumentException('화면이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
    if($action==='initial_setup') {require_once __DIR__.'/setup.php';initialSetupAction();}
    if($action==='login') {
        $name=textfield($_POST,'username',80,true);
        $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'').':'.$name);
        $attempt=query('SELECT * FROM login_attempts WHERE identity_hash=?',[$key])->fetch();
        if($attempt && $attempt['attempts']>=10 && strtotime($attempt['last_attempt'])>time()-900) throw new InvalidArgumentException('로그인 시도가 많습니다. 15분 후 다시 시도해 주세요.');
        $user=query('SELECT * FROM users WHERE username=?',[$name])->fetch();
        $password=textfield($_POST,'password',255,true);
        if(!$user || !password_verify($password,$user['password_hash'])) {
            query('INSERT INTO login_attempts (identity_hash,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < NOW() - INTERVAL 15 MINUTE,1,attempts+1), last_attempt=NOW()',[$key]);
            throw new InvalidArgumentException('아이디 또는 비밀번호를 확인해 주세요.');
        }
        query('DELETE FROM login_attempts WHERE identity_hash=?',[$key]);
        session_regenerate_id(true); $_SESSION=['user_id'=>$user['id'],'username'=>$user['username']]; redirect('/');
    }
    if(empty($_SESSION['user_id'])) redirect('/');
    if($action==='logout') { $_SESSION=[]; session_regenerate_id(true); redirect('/'); }
    if(in_array($action,['production_save','recipe_save','recipe_delete','production_links','retail_save','retail_delete'],true)) {
        $lock=databaseWriteLock();
        try {require_once __DIR__.'/production.php';productionAction($action);} finally {if($lock)databaseWriteLock(true);}
    }
    if(in_array($action,['preview_group_decision','preview_upload','preview_refresh','import_prepare','import_execute'],true) && excelImportMenuHidden())throw new InvalidArgumentException('엑셀 가져오기를 이미 완료했습니다. 추가 고객과 주문은 앱에서 등록해 주세요.');
    if($action==='preview_group_decision') {
        databaseWriteLock();
        require_once __DIR__.'/preview-storage.php';$report=currentExcelPreview(false);
        if(!$report)throw new InvalidArgumentException('먼저 엑셀 미리보기를 만들어 주세요.');
        $count=updatePreviewReview($report,$_POST,$_SESSION['username']);
        $_SESSION['flash']=$count.'개 그룹의 검토 기준을 반영했습니다. 실제 고객·주문 자료는 변경하지 않았습니다.';redirect('/?page=excel-preview&tab=groups');
    }
    if($action==='preview_upload') {require_once __DIR__.'/preview-storage.php';previewUploadAction();}
    if($action==='preview_refresh') {
        require_once __DIR__.'/preview-storage.php';
        $source=previewDirectory().'/attached-source.zip';
        if(!is_file($source)) throw new InvalidArgumentException('최초 첨부 파일이 없습니다. .xlsx 파일을 다시 선택해 주세요.');
        saveExcelPreview(buildExcelPreview($source,'오타와김치 김치방.xlsx',previewSnapshot()),previewDirectory().'/provided.json');
        unset($_SESSION['excel_preview']);$_SESSION['flash']='최초 첨부 파일을 현재 시스템 자료와 다시 비교했습니다. 고객·주문은 변경하지 않았습니다.';redirect('/?page=excel-preview');
    }
    if(in_array($action,['import_prepare','import_execute'],true)) {
        require_once __DIR__.'/excel-import.php';
        if($action==='import_prepare') {
            $report=currentExcelPreview();if(!$report)throw new InvalidArgumentException('먼저 엑셀 미리보기를 만들어 주세요.');
            $token=saveImportJob(buildImportPlan($report,true),(int)$_SESSION['user_id']);
            redirect('/?page=excel-preview&tab=import&job='.$token);
        }
        if(($_POST['confirm_import']??'')!=='yes'||($_POST['confirm_replace']??'')!=='replace')throw new InvalidArgumentException('기존 업무자료 삭제와 새 자료 저장 확인란을 모두 선택해 주세요.');
        $ownedLock=databaseWriteLock();
        $token=textfield($_POST,'job',48,true);$plan=loadImportJob($token,(int)$_SESSION['user_id']);
        if(($plan['mode']??'')!=='replace')throw new InvalidArgumentException('초기화 가져오기 방식으로 저장할 내용을 다시 확인해 주세요.');
        $current=currentExcelPreview();
        if(!$current || $current['sha256']!==$plan['source_sha'] || $current['review_version']!==$plan['review_version'])throw new InvalidArgumentException('검토 기준이 바뀌었습니다. 가져오기를 다시 준비해 주세요.');
        $policy=[$current['decision_revision']??null,$current['product_decision_revision']??null,$current['user_decision_revision']??null,$current['import_rules_revision']??null];
        if($policy!==$plan['policy'])throw new InvalidArgumentException('분류 기준이 바뀌었습니다. 가져오기를 다시 준비해 주세요.');
        $id=executeImportPlan($plan,(int)$_SESSION['user_id']);
        $_SESSION['flash']='가져오기가 완료되었습니다. 실행 번호 #'.$id.' · 원본 행과 백업을 보관했습니다.';
        redirect('/?page=excel-preview&tab=import');
    }
    databaseWriteLock();
    if(in_array($action,['account_create','account_password'],true)){require_once __DIR__.'/accounts.php';accountAction($action);}
    if($action==='brand_upload'){require_once __DIR__.'/branding.php';saveBrandLogo($_FILES['brand_file']??[]);$_SESSION['flash']='원본 브랜드 로고를 적용했습니다.';redirect('/?page=brand');}
    if($action==='customer') {
        $kind=$_POST['customer_kind']??'person';if(!in_array($kind,['person','organization'],true))throw new InvalidArgumentException('고객 종류를 확인해 주세요.');
        $values=[textfield($_POST,'name',100,true),textfield($_POST,'nickname'),textfield($_POST,'phone',50),textfield($_POST,'address',500),textfield($_POST,'area'),textfield($_POST,'notes',15000),$kind,textfield($_POST,'affiliation',100),textfield($_POST,'contact_name',100),payerName($_POST),optionalChoice(array_merge(['preferred_fulfillment'=>'delivery'],$_POST),'preferred_fulfillment',array_keys(FULFILLMENT_METHODS))?:'delivery'];
        if((int)($_POST['id']??0)>0) {
            $id=(int)$_POST['id']; query('UPDATE customers SET name=?,nickname=?,phone=?,address=?,area=?,notes=?,customer_kind=?,affiliation=?,contact_name=?,payer_name=?,preferred_fulfillment=? WHERE id=?',[...$values,$id]);
        } else { query('INSERT INTO customers (name,nickname,phone,address,area,notes,customer_kind,affiliation,contact_name,payer_name,preferred_fulfillment) VALUES (?,?,?,?,?,?,?,?,?,?,?)',$values); $id=(int)db()->lastInsertId(); }
        $_SESSION['flash']='고객 정보를 저장했습니다.'; redirect('/?page=customer&id='.$id);
    }
    if($action==='product') {
        $code=textfield($_POST,'code',30,true); $name=textfield($_POST,'name',100,true);
        $grams=filter_var($_POST['weight_g']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if($grams===false) throw new InvalidArgumentException('판매 용량을 g 단위의 양의 정수로 입력해 주세요.');
        $price=cents($_POST['price']??'');
        $active=isset($_POST['active'])?1:0;
        $id=(int)($_POST['id']??0);
        if($id && query('SELECT legacy_key FROM products WHERE id=?',[$id])->fetchColumn())throw new InvalidArgumentException('과거 제품은 원본 보존용으로 수정할 수 없습니다.');
        if(query('SELECT id FROM products WHERE code=? AND id<>?',[$code,$id])->fetch()) throw new InvalidArgumentException('이미 사용하는 제품 약자입니다.');
        if($id) query('UPDATE products SET code=?,name=?,weight_g=?,price_cents=?,active=? WHERE id=?',[$code,$name,$grams,$price,$active,$id]);
        else query('INSERT INTO products (code,name,weight_g,price_cents,active) VALUES (?,?,?,?,?)',[$code,$name,$grams,$price,$active]);
        $_SESSION['flash']='제품을 저장했습니다. 기존 주문의 가격은 그대로 유지됩니다.'; redirect('/?page=products');
    }
    if($action==='order') {
        $key=textfield($_POST,'request_key',32,true);
        if(!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new InvalidArgumentException('주문 화면을 다시 열어 주세요.');
        if($existing=query('SELECT id FROM orders WHERE request_key=?',[$key])->fetch()) redirect('/?page=order&id='.$existing['id']);
        $customer=query('SELECT * FROM customers WHERE id=?',[(int)($_POST['customer_id']??0)])->fetch();
        if(!$customer) throw new InvalidArgumentException('먼저 고객을 검색해서 선택해 주세요.');
        $orderDate=validDate(textfield($_POST,'order_date',10,true)); $deliveryDate=validDate(textfield($_POST,'delivery_date',10,true));
        if($deliveryDate<$orderDate) throw new InvalidArgumentException('배달·픽업일은 주문일 이후로 선택해 주세요.');
        $method=$_POST['fulfillment']??'';
        if(!in_array($method,['delivery','pickup','church'],true)) throw new InvalidArgumentException('집으로 배달·직접 픽업·교회 중에서 선택해 주세요.');
        $address=textfield($_POST,'delivery_address',500);
        if($method==='delivery' && $address==='') throw new InvalidArgumentException('배달 주소를 입력해 주세요.');
        $notes=textfield($_POST,'notes',15000); $discount=cents($_POST['discount']??'0');
        $quantities=$_POST['qty']??[];
        if(!is_array($quantities)) throw new InvalidArgumentException('제품 수량을 확인해 주세요.');
        $items=[]; $subtotal=0;
        foreach($quantities as $id=>$value) {
            if($value==='' || $value==='0') continue;
            $q=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
            if($q===false) throw new InvalidArgumentException('제품 수량은 1~999 사이의 정수로 입력해 주세요.');
            $p=query('SELECT * FROM products WHERE id=? AND active=1',[(int)$id])->fetch();
            if(!$p) throw new InvalidArgumentException('판매 중인 제품을 다시 선택해 주세요.');
            $items[]=[$p,$q]; $subtotal+=(int)$p['price_cents']*$q;
        }
        if(!$items) throw new InvalidArgumentException('제품 수량을 하나 이상 선택해 주세요.');
        if($discount>$subtotal) throw new InvalidArgumentException('할인액은 제품 합계를 넘을 수 없습니다.');
        $deliveryFee = deliveryFee($subtotal, $method);
        db()->beginTransaction();
        try {
            query('INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$key,$customer['id'],$customer['name'],$address,$orderDate,$deliveryDate,$method,$subtotal,$discount,$deliveryFee,$subtotal-$discount+$deliveryFee,$notes,$_SESSION['user_id']]);
            $id=(int)db()->lastInsertId();
            foreach($items as [$p,$q]) query('INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) VALUES (?,?,?,?,?,?,?)',[$id,$p['id'],$p['code'],$p['name'],$p['weight_g'],$p['price_cents'],$q]);
            allocateDiscount($id,$discount);
            refreshOrder($id);
            event($id,$key,'created','주문 등록',[]);
            db()->commit();
        } catch(Throwable $err) { db()->rollBack(); if($err instanceof PDOException && $err->getCode()==='23000' && ($duplicate=query('SELECT id FROM orders WHERE request_key=?',[$key])->fetch())) redirect('/?page=order&id='.$duplicate['id']); throw $err; }
        $_SESSION['flash']='주문을 저장했습니다.'; redirect('/?page=order&id='.$id);
    }
    if(in_array($action,['edit_order','cancel_order','deliver_items','deliver_all','receive_payment'],true)) {
        $id=workflowAction($action);
        $_SESSION['flash']='주문 처리 내용을 저장했습니다.';redirect('/?page=order&id='.$id);
    }
    throw new InvalidArgumentException('요청을 확인해 주세요.');
}
