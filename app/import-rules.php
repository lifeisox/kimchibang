<?php
declare(strict_types=1);
/** User-approved import rules. Raw snapshots and workbook cells are retained unchanged. */
function applyApprovedImportRules(array $report): array {
    $handled=['delivery_fee_candidate','negative_discount','other_negative_adjustment','overpayment_settlement','unknown_historical_weight','unregistered_product','ambiguous_product','sheet_year_mismatch','duplicate_customer','customer_address_missing'];
    $names=[];$aliases=[];$catalog=[];
    foreach($report['products'] as $p)if($p['current_product'])$catalog[$p['current_product']['code']]=$p['current_product'];
    foreach($report['customers'] as $ref=>&$c) {
        $original=$c['original_customer_fields']??['name'=>$c['name'],'nickname'=>$c['nickname'],'display'=>$c['display']];$c['original_customer_fields']=$original;
        if(str_contains($original['name'],'대사관')) {
            $c['customer_kind']='organization';$c['name']=$original['name'];$c['contact_name']=$original['nickname'];$c['nickname']=$original['name'].'(단체)';$c['display']=$c['name'];
        } else {
            $c['customer_kind']='person';
            if(str_contains($original['nickname'],'대사관')||($c['affiliation']??'')==='대사관'){$c['affiliation']='대사관';$c['nickname']='대사관 '.$c['name'];}
        }
        foreach(['duplicate_customer','customer_address_missing'] as $code)unset($c['issues'][$code]);
        if(previewKey($c['name'])!=='')$names[previewKey($c['name'])][$ref]=true;
        foreach(array_unique([$c['name'],$c['nickname'],$c['display'],$c['contact_name']??'',...array_values($original)]) as $alias)if(previewKey($alias)!=='')$aliases[previewKey($alias)][$ref]=true;
    }unset($c);
    foreach($report['orders'] as $ref=>&$o) {
        $links=array_keys($names[previewKey($o['customer'])]??[]);if(count($links)!==1)$links=array_keys($aliases[previewKey($o['customer'])]??[]);
        if(!empty($o['customer_link_confirmed']))$links=$o['customer_refs'];
        $o['customer_refs']=$links;
        unset($o['issues']['ambiguous_customer'],$o['issues']['unmatched_customer']);
        if(count($links)===1)$o['resolved_customer_ref']=$links[0];
        else previewIssue($o,count($links)?'ambiguous_customer':'unmatched_customer','고객 연결을 확인해야 합니다. 이름만으로 자동 병합하지 않습니다.');
        $quantity=false;foreach($o['items'] as $i)if(($i['quantity']===null || (is_numeric($i['quantity'])&&$i['quantity']!=0)))$quantity=true;
        if(!$quantity){$o['preview_disposition']='exclude';$o['exclusion_reason']='사용자 확인: 제품 수량이 없는 주문 행은 제외합니다.';$o['excluded_issues']=$o['issues'];$o['issues']=[];continue;}
        foreach($o['items'] as &$i) {
            $header=previewKey($i['header']);$historical=$i['historical_product']??null;
            if((string)$o['sheet']==='24'&&in_array($header,['열','열무','열무김치'],true))$historical=['key'=>'yeolmu-3200','family_code'=>'열','name'=>'열무김치','weight_g'=>3200];
            if((string)$o['sheet']==='24'&&$header==='맛김치')$historical=['key'=>'mat-5000','family_code'=>'맛5','name'=>'맛김치','weight_g'=>5000];
            if(in_array($header,['포백5','백포기김치5kg'],true))$historical=['key'=>'white-cabbage-5000','family_code'=>'포백5','name'=>'백포기김치','weight_g'=>5000];
            if($historical){$i['historical_product']=$historical;if($historical['family_code'])$i['product']=$catalog[$historical['family_code']]??$i['product'];}
            $family=$historical['family_code']??($i['product']['code']??null);
            if(!$family || (isset($catalog[$family]) && empty($catalog[$family]['active']))){$i['import_as_other']=true;$report['products'][$o['sheet'].'!'.$i['column']]['import_as_other']=true;$i['mapping_reason']='현재 판매 목록에 없는 과거 제품은 기타로 분류합니다. 원래 명칭·단가·수량은 원본에 보존합니다.';}
        }unset($i);
        foreach($handled as $code)unset($o['issues'][$code]);
        $discount=$o['discount_cents'];
        if(is_int($discount)&&$discount<0) {
            $o['reviewed_discount_cents']=0;$o['historical_delivery_fee_cents']=$discount===-500?500:0;
            $o['historical_surcharge_cents']=$discount===-500?0:-$discount;
            $o['other_sales_cents']=0;$o['additional_income_cents']=0;$o['customer_credit_cents']=0;
            $o['adjustment_kind']=$discount===-500?'delivery_fee':'other_income';
        }
        $o['statistics_year']=$o['date']?(int)substr($o['date'],0,4):null;
        if($o['recomputed_subtotal_cents']!==null && is_int($discount)) {
            $o['kimchi_sales_cents']=$o['recomputed_subtotal_cents']-max(0,$discount);
            $o['sales_receivable_cents']=$o['kimchi_sales_cents']+($o['historical_delivery_fee_cents']??0)+($o['historical_surcharge_cents']??0);
            $o['income_total_cents']=$o['sales_receivable_cents'];$o['recomputed_total_cents']=$o['income_total_cents'];
            $o['difference_cents']=$o['recomputed_total_cents']-($o['expected_total_cents']??$o['source_total_cents']);
            if($o['difference_cents']===0)unset($o['issues']['amount_mismatch']);
        }
    }unset($o);
    foreach(['customers','orders'] as $kind)foreach($report[$kind] as &$r) {
        if(($r['review_disposition']??'')==='hold' && !array_diff(array_keys($r['deferred_issues']??[]),$handled))unset($r['review_disposition']);
    }unset($r);
    $report['import_rules_revision']='2026-11-07-approved-1';$report['summary']['customer_review']=$report['summary']['order_review']=0;$report['summary']['excluded_orders']=0;$report['issue_counts']=[];
    foreach($report['year_summary'] as &$y){$y['review']=0;$y['kimchi_sales_cents']=0;$y['other_income_cents']=0;}unset($y);
    foreach(['customers','orders'] as $kind)foreach($report[$kind] as $r) {
        if($r['issues']){$report['summary'][$kind==='customers'?'customer_review':'order_review']++;if($kind==='orders')$report['year_summary'][$r['sheet']]['review']++;}
        foreach($r['issues'] as $code=>$message){$key=$kind.':'.$code;$report['issue_counts'][$key]??=['group'=>$kind,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
        if($kind==='orders') {
            if(($r['preview_disposition']??'')==='exclude')$report['summary']['excluded_orders']++;
            else {$report['year_summary'][$r['sheet']]['kimchi_sales_cents']+=$r['kimchi_sales_cents']??0;$report['year_summary'][$r['sheet']]['other_income_cents']+=$r['historical_surcharge_cents']??0;}
        }
    }
    if(!isset($report['review_version']))unset($report['review_groups'],$report['review_group_counts']);
    return $report;
}

function resolveKnownImportDuplicates(array $report): array {
    $records=query('SELECT r.entity_type,r.source_ref,r.target_id FROM import_records r JOIN import_sources s ON s.id=r.source_id WHERE s.source_sha=? AND r.state=\'imported\' AND r.target_id IS NOT NULL',[$report['sha256']])->fetchAll();
    $ids=[];$refs=[];foreach($records as $r){$ids[$r['entity_type']][]=$r['target_id'];$refs[$r['entity_type']][$r['source_ref']]=true;}
    foreach(['customers','orders'] as $kind)foreach($report[$kind] as $ref=>&$r)if(isset($refs[$kind][$ref])) {
        $candidates=$kind==='customers'?array_column($r['existing_candidates']??[],'id'):array_column($r['existing_customer_candidates']??[],'id');
        if(!array_diff($candidates,$ids['customers']??[]))unset($r['issues']['existing_customer']);
        if($kind==='orders'&&!array_diff($r['existing_order_ids']??[],$ids['orders']??[]))unset($r['issues']['existing_order']);
    }unset($r);
    return $report;
}
