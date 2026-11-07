<?php
declare(strict_types=1);
require_once __DIR__.'/preview-storage.php';
require_once __DIR__.'/import-backup.php';
function importHash(mixed $value): string {return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
function importText(mixed $v,int $length,string $ref): string {
    if(!is_string($v)||mb_strlen($v)>$length)throw new InvalidArgumentException($ref.'의 글자 수나 내용을 확인해 주세요.');return $v;
}
function importAmount(mixed $v,string $ref): int {
    if(!is_int($v)||$v<0||$v>4294967295)throw new InvalidArgumentException($ref.'의 금액을 확인해 주세요.');return $v;
}
function normalizeImportCustomer(array $c): array {
    $ref=$c['ref'];$kind=$c['customer_kind']??'person';if(!in_array($kind,['person','organization'],true))throw new InvalidArgumentException($ref.'의 고객 종류를 확인해 주세요.');
    $name=$c['name']?:$c['display'];if(trim($name)==='')throw new InvalidArgumentException($ref.'의 이름이 없습니다.');
    return ['name'=>importText($name,100,$ref),'nickname'=>importText($c['nickname'],100,$ref),'customer_kind'=>$kind,'affiliation'=>importText($c['affiliation']??'',100,$ref),'contact_name'=>importText($c['contact_name']??'',100,$ref),'phone'=>importText($c['phone'],50,$ref),'address'=>importText($c['address'],500,$ref),'area'=>importText($c['area'],100,$ref),'notes'=>importText($c['notes'],15000,$ref)];
}
function normalizeImportOrder(array $o,array $report): array {
    $ref=$o['ref'];$links=$o['customer_refs'];
    if(count($links)!==1 || !isset($report['customers'][$links[0]]))throw new InvalidArgumentException($ref.'의 고객 연결을 먼저 확인해 주세요.');
    $date=validDate((string)$o['date']);$items=[];$subtotal=0;
    foreach($o['items'] as $i) {
        if($i['quantity']===0 || $i['quantity']===0.0)continue;
        $q=$i['quantity'];if(!is_numeric($q)||floor((float)$q)!=(float)$q||$q<1||$q>999)throw new InvalidArgumentException($ref.'의 수량을 확인해 주세요.');$q=(int)$q;
        $price=importAmount($i['unit_price_cents'],$ref);$historical=$i['historical_product']??null;$current=$i['product'];
        $name=$historical['name']??$current['name']??($i['header'].' (과거 기록)');$weight=$historical?$historical['weight_g']:($current['weight_g']??null);
        if($weight!==null && (!is_int($weight)||$weight<1))throw new InvalidArgumentException($ref.'의 과거 용량을 확인해 주세요.');
        $family=$historical['family_code']??($current['code']??null);$live=$family?query('SELECT id,weight_g,legacy_key FROM products WHERE code=?',[$family])->fetch():false;
        if($live && $weight!==null && $live['weight_g']===$weight && $live['legacy_key']===null)$product=['kind'=>'current','code'=>$family,'weight_g'=>$weight];
        else $product=['kind'=>'legacy','key'=>$historical?'confirmed:'.$historical['key']:'source:'.$report['sha256'].':'.$o['sheet'].'!'.$i['column'],'weight_g'=>$weight];
        if(!empty($i['import_as_other'])){$product=['kind'=>'other'];$name='기타';}
        $items[]=['source_column'=>$i['column'],'code'=>!empty($i['import_as_other'])?'기타':importText($i['header'],30,$ref),'name'=>importText($name,100,$ref),'weight_g'=>$weight,'price_cents'=>$price,'quantity'=>$q,'product'=>$product];$subtotal+=$q*$price;
    }
    if(!$items)throw new InvalidArgumentException($ref.'에 제품이 없습니다.');
    $discount=importAmount($o['reviewed_discount_cents']??$o['discount_cents'],$ref);$fee=importAmount($o['historical_delivery_fee_cents']??0,$ref);$other=importAmount($o['other_sales_cents']??0,$ref);$charge=importAmount($o['historical_surcharge_cents']??0,$ref);$extra=importAmount($o['additional_income_cents']??0,$ref);
    if($discount>$subtotal)throw new InvalidArgumentException($ref.'의 할인액을 확인해 주세요.');
    $total=importAmount($subtotal-$discount+$fee+$other+$charge,$ref);$income=importAmount($total+$extra,$ref);
    $expected=$o['expected_total_cents']??$o['source_total_cents'];
    if($expected!==$income || $o['recomputed_total_cents']!==$income)throw new InvalidArgumentException($ref.'의 저장 금액과 검토 기준 금액이 다릅니다.');
    if($extra>0 && $o['paid']!==true)throw new InvalidArgumentException($ref.'의 추가 수입 입금 상태를 확인해 주세요.');
    return ['customer_ref'=>$links[0],'customer_name'=>importText($report['customers'][$links[0]]['name']?:$report['customers'][$links[0]]['display'],100,$ref),'address'=>importText($o['address'],500,$ref),'date'=>$date,'production'=>$o['production'],'delivered'=>$o['delivered'],'paid'=>$o['paid'],'notes'=>importText($o['notes'],15000,$ref),'items'=>$items,'subtotal'=>$subtotal,'discount'=>$discount,'fee'=>$fee,'other_sales'=>$other,'other_charges'=>$charge,'additional_income'=>$extra,'total'=>$total,'income_total'=>$income];
}
function importLedger(string $sha): array {
    $rows=query('SELECT r.* FROM import_records r JOIN import_sources s ON s.id=r.source_id WHERE s.source_sha=?',[$sha])->fetchAll();$map=[];foreach($rows as $r)$map[$r['entity_type'].':'.$r['source_ref']]=$r;return $map;
}
function buildImportPlan(array $report): array {
    if(!isset($report['review_groups']) || !isset($report['review_version']))$report=applyPreviewReview($report);
    if($report['summary']['customer_review'] || $report['summary']['order_review'])throw new InvalidArgumentException('확인 대기 항목을 처리하거나 보류한 뒤 가져오기를 준비해 주세요.');
    $ledger=importLedger($report['sha256']);$records=[];$counts=['new_customers'=>0,'new_orders'=>0,'skipped_customers'=>0,'skipped_orders'=>0,'excluded_orders'=>0,'held_customers'=>0,'held_orders'=>0,'kimchi_sales_cents'=>0,'delivery_fee_cents'=>0,'other_sales_cents'=>0,'other_charges_cents'=>0,'additional_income_cents'=>0,'income_total_cents'=>0,'order_total_cents'=>0];
    foreach(['customers','orders'] as $kind)foreach($report[$kind] as $r) {
        $state=($r['preview_disposition']??'')==='exclude'?'excluded':((($r['review_disposition']??'')==='hold')?'held':'ready');
        $data=$state!=='ready'?null:($kind==='customers'?normalizeImportCustomer($r):normalizeImportOrder($r,$report));
        $hash=importHash([$state,$data]);$old=$ledger[$kind.':'.$r['ref']]??null;
        if($old && $old['state']==='imported') {
            if($state!=='ready'||!hash_equals($old['content_sha'],$hash))throw new InvalidArgumentException($r['ref'].'은 이미 가져온 자료와 다릅니다. 기존 기록을 덮어쓰지 않습니다.');
            $table=$kind==='customers'?'customers':'orders';if(!query('SELECT id FROM '.$table.' WHERE id=?',[$old['target_id']])->fetch())throw new InvalidArgumentException('이미 가져온 자료의 연결이 없어 확인이 필요합니다.');
            $operation='skip';$counts[$kind==='customers'?'skipped_customers':'skipped_orders']++;
        } else {$operation=$state==='ready'?'insert':'archive';if($state==='ready')$counts[$kind==='customers'?'new_customers':'new_orders']++;}
        if($state==='held')$counts[$kind==='customers'?'held_customers':'held_orders']++;
        if($state==='excluded' && $kind==='orders')$counts['excluded_orders']++;
        if($kind==='orders' && $state==='ready') {
            foreach(['kimchi_sales_cents'=>$data['subtotal']-$data['discount'],'delivery_fee_cents'=>$data['fee'],'other_sales_cents'=>$data['other_sales'],'other_charges_cents'=>$data['other_charges'],'additional_income_cents'=>$data['additional_income'],'income_total_cents'=>$data['income_total'],'order_total_cents'=>$data['total']] as $key=>$value)$counts[$key]+=$value;
        }
        $records[]=['kind'=>$kind,'ref'=>$r['ref'],'state'=>$state,'operation'=>$operation,'data'=>$data,'content_sha'=>$hash,'snapshot'=>$r,'old_target'=>$old['target_id']??null];
    }
    // Never silently merge a different source's customer with the same name or nickname.
    $sourceTargets=array_column(array_filter($ledger,fn($r)=>$r['entity_type']==='customers'&&$r['state']==='imported'),'target_id');
    foreach($records as $r)if($r['kind']==='customers' && $r['operation']==='insert') {
        $c=$r['data'];$candidates=query("SELECT id FROM customers WHERE name=? OR name=? OR (nickname<>'' AND (nickname=? OR nickname=?))",[$c['name'],$c['nickname'],$c['name'],$c['nickname']])->fetchAll(PDO::FETCH_COLUMN);
        if(array_diff($candidates,$sourceTargets))throw new InvalidArgumentException($r['ref'].'과 같은 이름의 등록 고객이 있습니다.엑셀을 다시 분석해 중복 여부를 확인해 주세요.');
    }
    $plan=['schema'=>1,'source_sha'=>$report['sha256'],'filename'=>$report['filename'],'database_identity'=>importDatabaseIdentity(),'database_digest'=>importDatabaseDigest(),'review_version'=>$report['review_version'],'policy'=>[$report['decision_revision']??null,$report['product_decision_revision']??null,$report['user_decision_revision']??null,$report['import_rules_revision']??null],'records'=>$records,'counts'=>$counts];
    $plan['plan_sha']=importHash($plan);return $plan;
}
function importProduct(array $i): int {
    $p=$i['product'];
    if($p['kind']==='other') {
        $existing=query("SELECT * FROM products WHERE legacy_key='generic:other' OR code='기타'")->fetch();
        if($existing){if($existing['name']!=='기타')throw new InvalidArgumentException('기타 제품의 기존 이름을 확인해 주세요.');return (int)$existing['id'];}
        query("INSERT INTO products (code,name,weight_g,price_cents,active,legacy_key) VALUES ('기타','기타',NULL,0,0,'generic:other')");return (int)db()->lastInsertId();
    }
    if($p['kind']==='current'){
        $live=query('SELECT * FROM products WHERE code=?',[$p['code']])->fetch();
        if(!$live || $live['weight_g']!==$p['weight_g'] || $live['legacy_key']!==null)throw new InvalidArgumentException('제품 기준이 바뀌었습니다. 가져오기를 다시 준비해 주세요.');return (int)$live['id'];
    }
    $existing=query('SELECT id FROM products WHERE legacy_key=?',[$p['key']])->fetch();if($existing)return (int)$existing['id'];
    query('INSERT INTO products (code,name,weight_g,price_cents,active,legacy_key) VALUES (?,?,?,0,0,?)',['OLD-'.substr(hash('sha256',$p['key']),0,18),$i['name'],$i['weight_g'],$p['key']]);return (int)db()->lastInsertId();
}
function insertImportedOrder(array $o,int $customerId,int $batchId,int $actor,string $sourceSha,string $ref): int {
    $key=substr(hash('sha256','import-order:'.$sourceSha.':'.$ref),0,32);
    query('INSERT INTO orders (request_key,customer_id,customer_name,delivery_address,order_date,delivery_date,fulfillment,subtotal_cents,discount_cents,delivery_fee_cents,other_sales_cents,other_charges_cents,additional_income_cents,total_cents,received_cents,delivered,paid,notes,created_by,import_batch_id,delivery_state_known,payment_state_known,production_state) VALUES (?,?,?,?,?,NULL,\'unknown\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$key,$customerId,$o['customer_name'],$o['address'],$o['date'],$o['subtotal'],$o['discount'],$o['fee'],$o['other_sales'],$o['other_charges'],$o['additional_income'],$o['total'],$o['paid']===true?$o['total']:0,(int)($o['delivered']===true),(int)($o['paid']===true),$o['notes'],$actor,$batchId,(int)($o['delivered']!==null),(int)($o['paid']!==null),$o['production']===null?null:(int)$o['production']]);
    $id=(int)db()->lastInsertId();$shipped=0;
    foreach($o['items'] as $i){$pid=importProduct($i);$sent=$o['delivered']===true?$i['quantity']:0;$shipped+=$sent;
        query('INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity,delivered_quantity) VALUES (?,?,?,?,?,?,?,?)',[$id,$pid,$i['code'],$i['name'],$i['weight_g'],$i['price_cents'],$i['quantity'],$sent]);
    }
    allocateDiscount($id,$o['discount']);query('UPDATE orders SET shipped_units=? WHERE id=?',[$shipped,$id]);
    if($o['paid']===true) {
        query('INSERT INTO payments (order_id,request_key,amount_cents,additional_income_cents,method,paid_on,note,created_by) VALUES (?,?,?,?,\'legacy\',NULL,?,?)',[$id,substr(hash('sha256','import-payment:'.$sourceSha.':'.$ref),0,32),$o['income_total'],$o['additional_income'],'엑셀 입금 완료 기록 · 실제 입금일·방법 미기록',$actor]);
        $paymentId=(int)db()->lastInsertId();
        foreach(orderItems($id) as $i){$amount=itemNet($i,activeQty($i));if($amount)query('INSERT INTO payment_allocations (payment_id,item_id,amount_cents,allocation_type) VALUES (?,?,?,\'product\')',[$paymentId,$i['id'],$amount]);}
        foreach(['delivery_fee'=>$o['fee'],'other_sales'=>$o['other_sales'],'other_charge'=>$o['other_charges'],'additional_income'=>$o['additional_income']] as $type=>$amount)if($amount)query('INSERT INTO payment_allocations (payment_id,item_id,amount_cents,allocation_type) VALUES (?,NULL,?,?)',[$paymentId,$amount,$type]);
    }
    query('INSERT INTO order_events (order_id,request_key,kind,summary,detail,created_by) VALUES (?,?,\'imported\',?,?,?)',[$id,substr(hash('sha256','import-event:'.$sourceSha.':'.$ref),0,32),'과거 엑셀 가져오기 · 실제 배송일·입금일은 미기록',json_encode(['source_sha'=>$sourceSha,'source_ref'=>$ref,'batch_id'=>$batchId,'total_cents'=>$o['total'],'additional_income_cents'=>$o['additional_income']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);
    return $id;
}

function executeImportPlan(array $plan,int $actor): int {
    $copy=$plan;unset($copy['plan_sha']);
    if(!hash_equals(importHash($copy),$plan['plan_sha']) || !hash_equals(importDatabaseIdentity(),$plan['database_identity']))throw new InvalidArgumentException('가져오기 준비 자료를 다시 만들어 주세요.');
    $locked=databaseWriteLock();
    try {
        $existing=query('SELECT b.id FROM import_batches b JOIN import_sources s ON s.id=b.source_id WHERE s.source_sha=? AND b.plan_sha=?',[$plan['source_sha'],$plan['plan_sha']])->fetchColumn();
        if($existing)return (int)$existing;
        if(!hash_equals(importDatabaseDigest(),$plan['database_digest']))throw new InvalidArgumentException('준비 이후 자료가 변경되었습니다. 가져오기를 다시 준비해 주세요.');
        $backup=writeImportBackup();
        db()->beginTransaction();
        query('INSERT INTO import_sources (source_sha,filename) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$plan['source_sha'],$plan['filename']]);$source=(int)db()->lastInsertId();
        query('INSERT INTO import_batches (source_id,plan_sha,backup_name,backup_sha,summary,created_by) VALUES (?,?,?,?,?,?)',[$source,$plan['plan_sha'],$backup['name'],$backup['sha256'],json_encode($plan['counts'],JSON_THROW_ON_ERROR),$actor]);$batch=(int)db()->lastInsertId();$customers=[];
        foreach($plan['records'] as $r) {
            $target=$r['old_target'];$state=$r['state'];
            if($r['operation']==='insert') {
                if($r['kind']==='customers') {
                    $c=$r['data'];query('INSERT INTO customers (name,nickname,customer_kind,affiliation,contact_name,phone,address,area,notes) VALUES (?,?,?,?,?,?,?,?,?)',array_values($c));$target=(int)db()->lastInsertId();
                } else {
                    if(!isset($customers[$r['data']['customer_ref']]))throw new InvalidArgumentException('주문에 연결할 고객이 없습니다.');
                    $target=insertImportedOrder($r['data'],$customers[$r['data']['customer_ref']],$batch,$actor,$plan['source_sha'],$r['ref']);
                }
                $state='imported';
            } elseif($r['operation']==='skip')$state='imported';
            if($r['kind']==='customers' && $state==='imported')$customers[$r['ref']]=(int)$target;
            $snapshot=json_encode($r['snapshot'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            query('INSERT INTO import_records (source_id,entity_type,source_ref,content_sha,state,target_id,source_snapshot,last_batch_id) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content_sha=VALUES(content_sha),state=VALUES(state),target_id=VALUES(target_id),source_snapshot=VALUES(source_snapshot),last_batch_id=VALUES(last_batch_id)',[$source,$r['kind'],$r['ref'],$r['content_sha'],$state,$target,$snapshot,$batch]);
            query('INSERT INTO import_batch_rows (batch_id,entity_type,source_ref,state,operation,content_sha,target_id,source_snapshot) VALUES (?,?,?,?,?,?,?,?)',[$batch,$r['kind'],$r['ref'],$state,$r['operation'],$r['content_sha'],$target,$snapshot]);
        }
        db()->commit();return $batch;
    } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    finally {if($locked)databaseWriteLock(true);}
}
function saveImportJob(array $plan,int $actor): string {
    $token=bin2hex(random_bytes(24));$path=previewDirectory().'/import-job-'.$token.'.json';
    $job=['actor'=>$actor,'expires'=>time()+3600,'plan'=>$plan];
    if(file_put_contents($path,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('준비 자료를 저장하지 못했습니다.');chmod($path,0600);return $token;
}
function loadImportJob(string $token,int $actor): array {
    if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new InvalidArgumentException('가져오기를 다시 준비해 주세요.');
    $path=previewDirectory().'/import-job-'.$token.'.json';
    $job=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):null;
    if(!$job || $job['actor']!==$actor || $job['expires']<time())throw new InvalidArgumentException('준비 화면이 만료되었습니다. 다시 준비해 주세요.');return $job['plan'];
}
