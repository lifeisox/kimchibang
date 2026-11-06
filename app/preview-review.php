<?php
declare(strict_types=1);
function previewReviewPath(string $sha): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new InvalidArgumentException('미리보기 파일 정보가 올바르지 않습니다.');
    return previewDirectory().'/review-'.$sha.'.json';
}
function previewReviewState(string $sha): array {
    $file=previewReviewPath($sha);
    if(!is_file($file)) return ['sha256'=>$sha,'version'=>0,'decisions'=>[],'events'=>[]];
    if(filesize($file)>5*1024*1024) throw new RuntimeException('그룹 검토 기록의 크기를 확인해야 합니다.');
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($state['sha256']??'')!==$sha) throw new RuntimeException('검토 기록의 원본 정보가 일치하지 않습니다.');
    return $state;
}
function previewReviewGroups(array $report): array {
    $groups=[];
    foreach(['customers','orders'] as $kind) foreach($report[$kind] as $r) foreach($r['issues'] as $code=>$message) {
        $sub='';$title=$message;$choices=['defer'=>'확인될 때까지 가져오기 보류'];$recommended='defer';$explanation='원본을 보존하고 실제 가져오기 대상에서는 보류합니다.';$groupCode=$code;
        if($code==='delivery_fee_candidate') {$title='-$5 기록을 과거 배달비로 구분';$choices=['delivery_fee'=>'과거 배달비 $5로 확인','defer'=>'확인 전까지 가져오기 보류'];$recommended='delivery_fee';$explanation='원본 음수 할인액과 최종 금액은 유지하고, 할인액 0·배달비 $5로 구분합니다.';}
        elseif($code==='other_negative_adjustment' || $code==='negative_discount') {$sub=(string)$r['discount_cents'];$title='음수 할인액 '.money(abs((int)$r['discount_cents'])).' 기록';$choices=['defer'=>'금액 의미를 확인할 때까지 보류','delivery_fee'=>'이 금액을 과거 배달비로 구분','surcharge'=>'이 금액을 배달비 외 추가금으로 구분'];$explanation='금액별로 따로 묶었습니다. 배달비인지 다른 추가금인지 선택해야 합니다. 최종 금액은 바꾸지 않습니다.';}
        elseif($code==='unknown_historical_weight') {$title='과거 제품의 무게 미확인';$choices=['preserve_unknown_weight'=>'무게 미확인으로 보존·kg 계산에서 제외','defer'=>'실제 가져오기는 보류'];$recommended='preserve_unknown_weight';$explanation='판매 금액과 개수는 보존합니다. 무게를 0이나 현재 제품 용량으로 채우지 않습니다.';}
        elseif($code==='customer_address_missing') {$title='고객 주소 미기록';$choices=['preserve_blank'=>'주소를 빈 값으로 보존','defer'=>'고객과 연결 주문을 보류'];$recommended='preserve_blank';$explanation='픽업 고객으로 추측하지 않습니다. 앞으로 배달 주문을 받을 때 주소를 입력하면 됩니다.';}
        elseif($code==='duplicate_customer') {$title='같은 이름·닉네임의 고객';$choices=['keep_separate'=>'고객을 각각 보존·자동으로 합치지 않음','defer'=>'고객과 연결 주문을 보류'];$recommended='keep_separate';$explanation='서로 다른 고객으로 유지합니다. 어느 고객 주문인지 불분명한 주문은 별도로 확인하거나 보류해야 합니다.';}
        elseif($code==='sheet_year_mismatch') {$title='시트 연도와 주문 날짜가 다른 기록';$choices=['actual_date'=>'실제 주문 날짜의 연도로 분류','defer'=>'주문 날짜 확인 전까지 보류'];$recommended='actual_date';$explanation='원본 시트 위치와 주문 날짜는 보존하고, 통계는 실제 날짜를 사용합니다.';}
        elseif($code==='unregistered_product') {
            $headers=[];foreach($r['items'] as $i) if($i['quantity']!=0 && !$i['product'] && !isset($i['historical_product']))$headers[]=$i['header'];
            $headers=array_unique($headers);sort($headers);$sub=implode(' · ',$headers);$title='현재 판매 목록에 없는 과거 제품: '.$sub;
            $choices=['preserve_legacy'=>'원래 약자·당시 금액으로 보존·무게 미확인','defer'=>'제품 확인 전까지 보류'];$recommended='preserve_legacy';$explanation='과거 기록 전용으로 보존합니다. 현재 판매 목록에 자동 추가하거나 제품 이름·용량을 추측하지 않습니다.';
        }
        elseif($kind==='orders' && isset($r['issues']['no_items']) && in_array($code,['no_items','missing_excel_amount','unknown_status'],true)) {$groupCode='empty_order_rows';$title='제품 수량이 없는 주문 행';$explanation='메모·누락 행인지 확인될 때까지 실제 가져오기에서 보류합니다. 원본 행은 삭제하지 않습니다.';}
        elseif(in_array($code,['amount_mismatch','subtotal_mismatch'],true)) {$groupCode='amount_discrepancy';$title='엑셀 금액과 다시 계산한 금액이 다른 기록';$explanation='원본 금액을 임의로 고르거나 덮어쓰지 않고 확인될 때까지 가져오기에서 보류합니다.';}
        elseif($code==='ambiguous_customer') {$title='어느 고객인지 불분명한 주문';$explanation='같은 이름이라는 이유로 한 고객에게 일괄 연결하지 않습니다. 고객 연결을 확인할 때까지 이 주문만 보류할 수 있습니다.';}
        elseif($code==='existing_customer' || $code==='existing_order') {$explanation='기존 자료와 중복 가능성이 있어 일괄 합치기·삭제를 제공하지 않습니다. 비교 후 연결할 때까지 보류합니다.';}
        $id=substr(hash('sha256',$kind.'|'.$groupCode.'|'.$sub),0,20);
        if(!isset($groups[$id])) $groups[$id]=['id'=>$id,'kind'=>$kind,'code'=>$groupCode,'title'=>$title,'explanation'=>$explanation,'choices'=>$choices,'recommended'=>$recommended,'refs'=>[],'codes'=>[]];
        $groups[$id]['refs'][$r['ref']]=true;$groups[$id]['codes'][$code]=true;
    }
    foreach($groups as &$g) {
        $g['refs']=array_keys($g['refs']);sort($g['refs']);$g['codes']=array_keys($g['codes']);sort($g['codes']);$g['count']=count($g['refs']);
        $g['membership']=hash('sha256',json_encode([$report['sha256'],$g['id'],$g['refs'],$g['codes'],$report['decision_revision']??null,$report['product_decision_revision']??null],JSON_UNESCAPED_UNICODE));
    }unset($g);
    uasort($groups,fn($a,$b)=>$b['count']<=>$a['count']);return $groups;
}
function applyPreviewReview(array $report): array {
    if(isset($report['review_groups'])) return $report;
    $groups=previewReviewGroups($report);$state=previewReviewState($report['sha256']);$coverage=[];$accepted=[];
    foreach($groups as $id=>&$g) {
        $saved=$state['decisions'][$id]??null;$choice=$saved['choice']??null;
        $valid=$saved && hash_equals($g['membership'],$saved['membership']??'') && isset($g['choices'][$choice]);
        $g['state']=$valid?($choice==='defer'?'held':'applied'):($saved?'stale':'pending');$g['decision']=$valid?$saved:null;
        foreach($g['refs'] as $ref) {
            foreach($g['codes'] as $code) if(isset($report[$g['kind']][$ref]['issues'][$code])) $coverage[$g['kind']][$ref][$code][]=$id;
            if(!$valid)continue;
            $r=&$report[$g['kind']][$ref];$r['review_actions'][$id]=['label'=>$g['choices'][$choice],'actor'=>$saved['actor'],'at'=>$saved['at']];
            if($choice==='defer')$r['review_disposition']='hold';
            if($choice==='delivery_fee' || $choice==='surcharge') {
                $amount=abs((int)$r['discount_cents']);$r['reviewed_discount_cents']=0;
                $r['historical_delivery_fee_cents']=$choice==='delivery_fee'?$amount:0;
                $r['historical_surcharge_cents']=$choice==='surcharge'?$amount:0;
                $r['historical_fee_status']='confirmed_by_group';
            }
            if($choice==='preserve_unknown_weight' || $choice==='preserve_legacy')$r['weight_excluded_from_calculation']=true;
            if($choice==='keep_separate')$r['keep_customer_separate']=true;
            if($choice==='actual_date')$r['statistics_year']=(int)substr($r['date'],0,4);
            unset($r);
        }
        if($valid && $choice!=='defer')$accepted[$id]=true;
    }unset($g);
    foreach($coverage as $kind=>$refs) foreach($refs as $ref=>$codes) foreach($codes as $code=>$ids) {
        if(count(array_filter($ids,fn($id)=>isset($accepted[$id])))!==count($ids))continue;
        $report[$kind][$ref]['resolved_issues'][$code]=$report[$kind][$ref]['issues'][$code];unset($report[$kind][$ref]['issues'][$code]);
    }
    // Holding a customer also holds their possible orders; never import a guessed connection.
    foreach($report['orders'] as &$o) foreach($o['customer_refs'] as $ref) if(($report['customers'][$ref]['review_disposition']??'')==='hold') {$o['review_disposition']='hold';$o['review_hold_reason']='연결 후보 고객의 가져오기가 보류되어 함께 보류합니다.';}
    unset($o);
    $report['summary']['customer_review']=$report['summary']['order_review']=0;$report['summary']['held_customers']=$report['summary']['held_orders']=0;$report['summary']['excluded_orders']=0;
    foreach($report['year_summary'] as &$y)$y['review']=0;unset($y);
    foreach(['customers','orders'] as $kind) foreach($report[$kind] as &$r) {
        if(($r['preview_disposition']??'')==='exclude'){$r['issues']=[];if($kind==='orders')$report['summary']['excluded_orders']++;}
        if(($r['review_disposition']??'')==='hold') {$r['deferred_issues']=$r['issues'];$r['issues']=[];$report['summary'][$kind==='customers'?'held_customers':'held_orders']++;}
        if($r['issues']){$report['summary'][$kind==='customers'?'customer_review':'order_review']++;if($kind==='orders')$report['year_summary'][$r['sheet']]['review']++;}
    }unset($r);
    $report['issue_counts']=[];
    foreach(['customers','orders'] as $kind) foreach($report[$kind] as $r) foreach($r['issues'] as $code=>$message) {$key=$kind.':'.$code;$report['issue_counts'][$key]??=['group'=>$kind,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
    uasort($report['issue_counts'],fn($a,$b)=>$b['count']<=>$a['count']);
    $report['review_groups']=$groups;$report['review_version']=$state['version'];
    $report['review_group_counts']=['total'=>count($groups),'applied'=>count(array_filter($groups,fn($g)=>$g['state']==='applied')),'held'=>count(array_filter($groups,fn($g)=>$g['state']==='held'))];
    return $report;
}
function updatePreviewReview(array $report,array $data,string $actor): int {
    if(!hash_equals($report['sha256'],is_string($data['report_sha']??null)?$data['report_sha']:''))throw new InvalidArgumentException('분석한 파일이 바뀌었습니다. 새로고침해 주세요.');
    $selected=$data['selected']??[];$choices=$data['choices']??[];$memberships=$data['memberships']??[];
    if(!is_array($selected)||!is_array($choices)||!is_array($memberships)||!$selected||count($selected)>100)throw new InvalidArgumentException('처리할 그룹을 하나 이상 선택해 주세요.');
    $selected=array_unique($selected,SORT_REGULAR);$groups=previewReviewGroups($report);$validated=[];
    foreach($selected as $id) {
        if(!is_string($id)||!isset($groups[$id]))throw new InvalidArgumentException('현재 결과에 없는 그룹입니다. 새로고침해 주세요.');
        $choice=$choices[$id]??'';$member=$memberships[$id]??'';
        if(!is_string($choice)||($choice!=='reset'&&!isset($groups[$id]['choices'][$choice])))throw new InvalidArgumentException('그룹 처리 방법을 선택해 주세요.');
        if(!is_string($member)||!hash_equals($groups[$id]['membership'],$member))throw new InvalidArgumentException('그룹 구성이나 기준이 바뀌었습니다. 새로고침해 주세요.');
        $validated[$id]=['choice'=>$choice,'membership'=>$member,'actor'=>$actor,'at'=>date(DATE_ATOM)];
    }
    $path=previewReviewPath($report['sha256']);$lock=fopen($path.'.lock','c');if(!$lock)throw new RuntimeException('검토 기록을 준비할 수 없습니다.');chmod($path.'.lock',0600);
    try {
        if(!flock($lock,LOCK_EX))throw new RuntimeException('검토 기록을 잠글 수 없습니다.');
        $state=previewReviewState($report['sha256']);
        if((string)($data['review_version']??'')!==(string)$state['version'])throw new InvalidArgumentException('다른 화면에서 검토 기준이 변경됐습니다. 새로고침 후 다시 확인해 주세요.');
        foreach($validated as $id=>$d){if($d['choice']==='reset')unset($state['decisions'][$id]);else $state['decisions'][$id]=$d;}
        $state['version']++;$state['events'][]=['version'=>$state['version'],'actor'=>$actor,'at'=>date(DATE_ATOM),'changes'=>$validated];$state['events']=array_slice($state['events'],-500);
        $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try{if(file_put_contents($temp,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('검토 기록을 저장할 수 없습니다.');chmod($temp,0600);if(!rename($temp,$path))throw new RuntimeException('검토 기록을 반영할 수 없습니다.');}
        finally{if(is_file($temp))unlink($temp);}
        return count($validated);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
