<?php
declare(strict_types=1);
/** Apply reviewed proposals to an exact source file only. Never write into the workbook or business DB. */
function applyExcelDecisions(array $report): array {
    $config=json_decode(file_get_contents(__DIR__.'/../config/excel-decisions.json'),true,512,JSON_THROW_ON_ERROR);
    if(($report['sha256']??'')!==$config['source_sha256']) return $report;
    if(($report['decision_revision']??'')===$config['revision']) return applyUserInterpretation(applyHistoricalProducts($report,$config),$config);
    $report['decision_revision']=$config['revision'];$report['source_amount_mismatches']=$report['summary']['amount_mismatches'];
    $report['decisions']=['corrected_orders'=>0,'delivery_fee_candidates'=>0,'other_negative_adjustments'=>0];
    foreach($report['orders'] as $ref=>&$o) {
        $o['reviewed_discount_cents']=$o['discount_cents'];$o['historical_delivery_fee_cents']=0;$o['expected_total_cents']=$o['source_total_cents'];
        if($o['discount_cents']===-$config['delivery_fee_candidate_cents']) {
            $o['reviewed_discount_cents']=0;$o['historical_delivery_fee_cents']=$config['delivery_fee_candidate_cents'];
            unset($o['issues']['negative_discount']);
            previewIssue($o,'delivery_fee_candidate','원본 -$5를 과거 배달비 $5로 분류했습니다. 실제 저장 전 확인할 후보입니다.');
            $report['decisions']['delivery_fee_candidates']++;
        } elseif($o['discount_cents']!==null && $o['discount_cents']<0) {
            unset($o['issues']['negative_discount']);
            previewIssue($o,'other_negative_adjustment','-$5 이외의 음수 금액입니다. 과거 배달비 금액 또는 다른 조정인지 별도 확인이 필요합니다.');
            $report['decisions']['other_negative_adjustments']++;
        }
        $rule=$config['corrections'][$ref]??null;
        if(!$rule) continue;
        $o['correction']=['reason'=>$rule['reason'],'original_items'=>$o['items'],'original_recomputed_total_cents'=>$o['recomputed_total_cents']];
        foreach($o['items'] as &$item) {
            $item['original_quantity_raw']=$item['quantity_raw'];
            if($item['column']===$rule['column']) $item['quantity']=$rule['quantity'];
            elseif($rule['zero_misc'] && str_starts_with($item['header'],'기타')) $item['quantity']=0;
            $item['quantity_raw']=(string)$item['quantity'];
            $item['line_total_cents']=(int)$item['quantity']*$item['unit_price_cents'];
        }
        unset($item);
        $o['expected_total_cents']=$rule['expected_total_cents'];
        $o['recomputed_subtotal_cents']=array_sum(array_column($o['items'],'line_total_cents'));
        $o['recomputed_total_cents']=$o['recomputed_subtotal_cents']-$o['reviewed_discount_cents']+$o['historical_delivery_fee_cents'];
        $o['difference_cents']=$o['recomputed_total_cents']-$o['expected_total_cents'];
        if($o['difference_cents']===0) unset($o['issues']['amount_mismatch'],$o['issues']['subtotal_mismatch']);
        $zeroPrice=false;foreach($o['items'] as $item) if($item['quantity']>0 && $item['unit_price_cents']===0) $zeroPrice=true;
        if(!$zeroPrice) unset($o['issues']['zero_price']);
        $report['decisions']['corrected_orders']++;
    }
    unset($o);
    foreach($report['products'] as &$p) {$p['source_order_count']=$p['order_count'];$p['order_count']=0;$p['quantity']=0;}
    unset($p);
    foreach($report['year_summary'] as &$y) foreach(['source_total_cents','source_total_known','recomputed_total_cents','recomputed_total_known','review','reviewed_total_cents','reviewed_total_known','corrected_orders'] as $field) $y[$field]=0;
    unset($y);
    $report['issue_counts']=[];
    $report['summary']=['customers'=>count($report['customers']),'orders'=>count($report['orders']),'orders_with_products'=>0,'customer_review'=>0,'order_review'=>0,'amount_comparable'=>0,'amount_matches'=>0,'amount_mismatches'=>0];
    foreach($report['orders'] as $o) {
        $y=&$report['year_summary'][$o['sheet']];
        if($o['source_total_cents']!==null){$y['source_total_known']++;$y['source_total_cents']+=$o['source_total_cents'];}
        if($o['expected_total_cents']!==null){$y['reviewed_total_known']++;$y['reviewed_total_cents']+=$o['expected_total_cents'];}
        if($o['recomputed_total_cents']!==null){$y['recomputed_total_known']++;$y['recomputed_total_cents']+=$o['recomputed_total_cents'];}
        if($o['issues']) $y['review']++;if(isset($o['correction'])) $y['corrected_orders']++;
        unset($y);
        foreach($o['items'] as $i) if($i['quantity']!==null && $i['quantity']!=0) {$key=$o['sheet'].'!'.$i['column'];$report['products'][$key]['order_count']++;$report['products'][$key]['quantity']+=$i['quantity'];}
    }
    foreach(['customers','orders'] as $group) foreach($report[$group] as $r) {
        if($r['issues']) $report['summary'][$group==='customers'?'customer_review':'order_review']++;
        foreach($r['issues'] as $code=>$message) {$key=$group.':'.$code;$report['issue_counts'][$key]??=['group'=>$group,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
        if($group==='orders') {
            if($r['items']) $report['summary']['orders_with_products']++;
            if($r['difference_cents']!==null){$report['summary']['amount_comparable']++;$report['summary'][$r['difference_cents']===0?'amount_matches':'amount_mismatches']++;}
        }
    }
    uasort($report['issue_counts'],fn($a,$b)=>$b['count']<=>$a['count']);
    $report['notes'][]='사용자 정정 1건을 미리보기에 반영했습니다. 원본 셀과 원본 금액은 보존하며 검토 반영 기준 금액을 별도로 표시합니다.';
    $report['notes'][]='원본 -$5는 설명에 따라 과거 배달비 $5 후보로 분류했습니다. 다른 음수 금액은 자동 확정하지 않습니다. 실제 고객·주문 저장은 하지 않았습니다.';
    return applyUserInterpretation(applyHistoricalProducts($report,$config),$config);
}


/** Historical product identity and weight are independent of today's sellable SKU. */
function applyHistoricalProducts(array $report,array $config): array {
    if(($report['product_decision_revision']??'')===($config['product_revision']??'')) return $report;
    $catalog=[];
    foreach($report['products'] as $p) if($p['current_product']) $catalog[$p['current_product']['code']]=$p['current_product'];
    foreach($config['historical_products']??[] as $ref=>$mapping) {
        if(!isset($report['products'][$ref])) continue;
        $p=&$report['products'][$ref];
        $p['historical_product']=$mapping;
        $p['current_product']=$mapping['family_code']===null?null:($catalog[$mapping['family_code']]??null);
        $p['ambiguous']=false;$p['reason']=$mapping['reason'];
        unset($p);
    }
    foreach($report['orders'] as &$o) {
        unset($o['issues']['ambiguous_product'],$o['issues']['unregistered_product'],$o['issues']['unknown_historical_weight']);
        foreach($o['items'] as &$item) {
            $p=$report['products'][$o['sheet'].'!'.$item['column']];
            if(isset($p['historical_product'])) {
                $item['historical_product']=$p['historical_product'];$item['product']=$p['current_product'];$item['mapping_reason']=$p['reason'];
            }
            if($item['quantity']===null || $item['quantity']==0) continue;
            if(isset($item['historical_product'])) {
                if($item['historical_product']['weight_g']===null) previewIssue($o,'unknown_historical_weight','오이김치의 과거 용량은 미확인으로 보존합니다. 무게 기준 통계·재료 계산에는 사용할 수 없습니다.');
            } elseif(!$item['product']) previewIssue($o,$p['ambiguous']?'ambiguous_product':'unregistered_product',$p['ambiguous']?'제품 약자·용량이 불분명한 항목이 있습니다.':'현재 판매 목록에 없는 과거 제품이 있습니다.');
        }
        unset($item);
    }
    unset($o);
    // Rebuild review counts only; prices, amounts, source/corrected quantities remain unchanged.
    $report['issue_counts']=[];$report['summary']['customer_review']=0;$report['summary']['order_review']=0;
    foreach($report['year_summary'] as &$y) $y['review']=0;unset($y);
    foreach(['customers','orders'] as $group) foreach($report[$group] as $r) {
        if($r['issues']) {$report['summary'][$group==='customers'?'customer_review':'order_review']++;if($group==='orders')$report['year_summary'][$r['sheet']]['review']++;}
        foreach($r['issues'] as $code=>$message) {$key=$group.':'.$code;$report['issue_counts'][$key]??=['group'=>$group,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
    }
    uasort($report['issue_counts'],fn($a,$b)=>$b['count']<=>$a['count']);
    $report['product_decision_revision']=$config['product_revision'];
    $report['notes'][]='2024년 N·O열의 열은 같은 열무김치 3.2kg으로 분류하되 당시 단가 $20·$25를 각각 보존합니다. 맛김치는 5kg, 오이는 오이김치이며 과거 무게는 미확인입니다. 현재 판매 제품의 가격·용량은 바꾸지 않았습니다.';
    return $report;
}

require_once __DIR__.'/excel-interpretation.php';
