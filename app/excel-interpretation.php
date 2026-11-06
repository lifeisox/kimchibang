<?php
declare(strict_types=1);
function applyUserInterpretation(array $report,array $config): array {
    if(($report['user_decision_revision']??'')===($config['user_revision']??''))return $report;
    foreach($config['customer_rules']??[] as $row=>$rule) {
        $ref='고객명단!'.$row;if(!isset($report['customers'][$ref]))continue;
        $c=&$report['customers'][$ref];
        $c['original_customer_fields']??=['name'=>$c['name'],'nickname'=>$c['nickname'],'display'=>$c['display']];
        $c['customer_kind']=$rule['kind'];
        if($rule['kind']==='person'){$c['affiliation']=$rule['nickname_prefix'];$c['nickname']=$rule['nickname_prefix'].' '.$c['name'];}
        else {$c['contact_name']=$c['original_customer_fields'][$rule['contact_from']];$c['name']=$rule['name'];$c['nickname']=$rule['nickname'];$c['display']=$rule['name'];}
        $c['customer_classification_confirmed']=true;unset($c);
    }
    $index=[];$orderIndex=[];
    foreach($report['customers'] as $ref=>&$c) {
        unset($c['issues']['duplicate_customer']);
        foreach(array_unique([$c['name'],$c['nickname'],$c['display']]) as $alias) if(previewKey($alias)!==''){$index[previewKey($alias)][$ref]=true;$orderIndex[previewKey($alias)][$ref]=true;}
        if(!empty($c['contact_name']))$orderIndex[previewKey($c['contact_name'])][$ref]=true;
    }unset($c);
    foreach($index as $refs)if(count($refs)>1)foreach(array_keys($refs) as $ref)previewIssue($report['customers'][$ref],'duplicate_customer','엑셀 안에 같은 이름·닉네임을 쓰는 고객이 여러 명입니다. 자동으로 합치지 않습니다.');
    $report['interpretation_summary']=['excluded_orders'=>0,'other_sales_orders'=>0,'other_sales_cents'=>0,'overpayment_orders'=>0,'overpayment_cents'=>0,'additional_income_cents'=>0];
    foreach($report['orders'] as $ref=>&$o) {
        $o['customer_refs']=array_keys($orderIndex[previewKey($o['customer'])]??[]);
        unset($o['issues']['ambiguous_customer'],$o['issues']['unmatched_customer']);
        $linked=$config['confirmed_order_customers'][$ref]??null;
        if($linked && isset($report['customers'][$linked])){$o['customer_refs']=[$linked];$o['customer_link_confirmed']=true;}
        if(!$o['customer_refs'])previewIssue($o,'unmatched_customer','주문자와 일치하는 고객이 고객명단에 없습니다.');
        elseif(count($o['customer_refs'])>1)previewIssue($o,'ambiguous_customer','주문자와 일치하는 고객 후보가 여러 명입니다.');
        if(count($o['customer_refs'])===1)$o['resolved_customer_ref']=$o['customer_refs'][0];
        if(in_array($ref,$config['confirmed_date_orders']??[],true)){$o['statistics_year']=(int)substr($o['date'],0,4);$o['order_date_confirmed']=true;unset($o['issues']['sheet_year_mismatch']);}
        $o['kimchi_sales_cents']=$o['recomputed_subtotal_cents']===null || $o['discount_cents']===null?null:$o['recomputed_subtotal_cents']-max(0,$o['discount_cents']);
        $amount=$o['discount_cents']!==null && $o['discount_cents']<0?-$o['discount_cents']:0;
        $kind=$config['adjustment_classification'][(string)$amount]??null;
        if($kind) {
            $o['adjustment_kind']=$kind;$o['reviewed_discount_cents']=0;$o['historical_delivery_fee_cents']=0;
            unset($o['issues']['other_negative_adjustment'],$o['issues']['negative_discount']);
            if($kind==='other_sales') {
                $o['other_sales_cents']=$amount;$o['other_sales_description']='김밥';$o['other_sales_quantity']=null;$o['other_sales_unit_price_cents']=null;
                $report['interpretation_summary']['other_sales_orders']++;$report['interpretation_summary']['other_sales_cents']+=$amount;
            } elseif($kind==='consented_extra_income') {
                $o['overpayment_cents']=$amount;$o['additional_income_cents']=$amount;$o['customer_credit_cents']=0;$o['overpayment_settlement']='retained_with_consent';
                unset($o['issues']['overpayment_settlement']);
                $report['interpretation_summary']['overpayment_orders']++;$report['interpretation_summary']['overpayment_cents']+=$amount;$report['interpretation_summary']['additional_income_cents']+=$amount;
            } else {
                $o['overpayment_cents']=$amount;$o['overpayment_settlement']='unclassified';
                previewIssue($o,'overpayment_settlement','고객 동의로 처리한 초과 입금입니다. 추가 수입인지 다음 주문 잔액인지 정산 방식을 확인해야 합니다.');
                $report['interpretation_summary']['overpayment_orders']++;$report['interpretation_summary']['overpayment_cents']+=$amount;
            }
        }
        $o['sales_receivable_cents']=$o['kimchi_sales_cents']===null?null:$o['kimchi_sales_cents']+($o['historical_delivery_fee_cents']??0)+($o['other_sales_cents']??0);
        $o['income_total_cents']=$o['sales_receivable_cents']===null?null:$o['sales_receivable_cents']+($o['additional_income_cents']??0);
        if(in_array($ref,$config['excluded_orders']??[],true)) {
            $o['preview_disposition']='exclude';$o['excluded_issues']??=$o['issues'];$o['issues']=[];
            $o['exclusion_reason']='사용자 확인: 제품 수량 없는 행은 실제 가져오기에서 제외합니다. 원본 행은 보존합니다.';
            $report['interpretation_summary']['excluded_orders']++;
        }
    }unset($o);
    $report['user_decision_revision']=$config['user_revision'];
    $report['summary']['customer_review']=$report['summary']['order_review']=0;$report['issue_counts']=[];
    foreach($report['year_summary'] as &$y){$y['review']=0;$y['kimchi_sales_cents']=0;$y['other_sales_cents']=0;$y['overpayment_cents']=0;$y['additional_income_cents']=0;}unset($y);
    foreach(['customers','orders'] as $kind)foreach($report[$kind] as $r){
        if($r['issues']){$report['summary'][$kind==='customers'?'customer_review':'order_review']++;if($kind==='orders')$report['year_summary'][$r['sheet']]['review']++;}
        foreach($r['issues'] as $code=>$message){$key=$kind.':'.$code;$report['issue_counts'][$key]??=['group'=>$kind,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
        if($kind==='orders' && ($r['preview_disposition']??'')!=='exclude')foreach(['kimchi_sales_cents','other_sales_cents','overpayment_cents','additional_income_cents'] as $field)$report['year_summary'][$r['sheet']][$field]+=$r[$field]??0;
    }
    uasort($report['issue_counts'],fn($a,$b)=>$b['count']<=>$a['count']);
    $report['notes'][]='사용자 확인: 김밥 대금은 기타 판매, 소액 초과 입금은 고객 동의로 받은 추가 수입으로 구분하며 다음 주문 잔액으로 만들지 않습니다. 개인 고객과 단체 고객·담당자를 구분하고 원본 금액·이름·시트 위치를 보존합니다.';
    return $report;
}
