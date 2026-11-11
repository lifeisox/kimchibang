<?php
// Fetch all visible orders in one query; never query once per row.
function orderSummaries(array $ids,bool $pendingOnly=false): array {
 $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return [];
 $summaries=[];$sql='SELECT order_id,product_code,quantity,cancelled_quantity,delivered_quantity FROM order_items WHERE order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY order_id,id';
 foreach(query($sql,$ids)->fetchAll() as $i){$n=(int)$i['quantity']-(int)$i['cancelled_quantity']-($pendingOnly?(int)$i['delivered_quantity']:0);if($n<=0)continue;$oid=(int)$i['order_id'];$code=$i['product_code'];$summaries[$oid][$code]=($summaries[$oid][$code]??0)+$n;}
 $result=[];foreach($summaries as $id=>$codes)foreach($codes as $code=>$n)$result[$id][]=['code'=>(string)$code,'quantity'=>$n];return $result;
}
function orderItemBadges(array $items): string {
 $html='';foreach($items as $i)$html.='<span class="badge order-item-badge">'.e($i['code']).' '.(int)$i['quantity'].'</span>';
 return '<div class="order-item-badges">'.($html?:'<span class="muted">표시할 제품 없음</span>').'</div>';
}
function customerOrderTotals(array $ids): array {
 if(!$ids)return [];
 return query('SELECT customer_id,COUNT(*) order_count,COALESCE(SUM(total_cents),0) order_amount FROM orders WHERE cancelled=0 AND customer_id IN ('.implode(',',array_fill(0,count($ids),'?')).') GROUP BY customer_id',$ids)->fetchAll(PDO::FETCH_UNIQUE);
}
