<?php
declare(strict_types=1);
function salesPeriod(string $kind,string $date): array {
    $date=validDate($date);$d=new DateTimeImmutable($date);
    if(!in_array($kind,['week','month','year'],true))throw new InvalidArgumentException('주간·월간·연간 중에서 선택해 주세요.');
    if($kind==='week'){$start=$d->modify('monday this week');$end=$start->modify('+6 days');$previous=$start->modify('-52 weeks');$previousEnd=$previous->modify('+6 days');}
    elseif($kind==='month'){$start=$d->modify('first day of this month');$end=$d->modify('last day of this month');$previous=$start->modify('-1 year');$previousEnd=$previous->modify('last day of this month');}
    else{$start=$d->setDate((int)$d->format('Y'),1,1);$end=$d->setDate((int)$d->format('Y'),12,31);$previous=$start->modify('-1 year');$previousEnd=$previous->setDate((int)$previous->format('Y'),12,31);}
    return ['kind'=>$kind,'date'=>$date,'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'previous_start'=>$previous->format('Y-m-d'),'previous_end'=>$previousEnd->format('Y-m-d')];
}
function salesTotals(string $start,string $end): array {
    $result=query('SELECT COUNT(*) order_count,COUNT(DISTINCT customer_id) customer_count,COALESCE(SUM(subtotal_cents-discount_cents),0) kimchi,COALESCE(SUM(delivery_fee_cents),0) delivery,COALESCE(SUM(other_sales_cents),0) other_sales,COALESCE(SUM(other_charges_cents),0) other_charges,COALESCE(SUM(additional_income_cents),0) additional_income,COALESCE(SUM(total_cents),0) total,COALESCE(SUM(total_cents-received_cents),0) unpaid FROM orders WHERE cancelled=0 AND order_date BETWEEN ? AND ?',[$start,$end])->fetch();
    return array_map('intval',$result);
}
function salesProducts(string $start,string $end): array {
    return query('SELECT i.product_name,i.weight_g,SUM(i.quantity-i.cancelled_quantity) quantity,SUM(i.unit_price_cents*(i.quantity-i.cancelled_quantity)-i.unit_discount_cents*(i.quantity-i.cancelled_quantity)-LEAST(i.quantity-i.cancelled_quantity,i.extra_discount_units)) amount FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.cancelled=0 AND o.order_date BETWEEN ? AND ? GROUP BY i.product_name,i.weight_g HAVING quantity>0 ORDER BY amount DESC,i.product_name,i.weight_g',[$start,$end])->fetchAll();
}
function salesReceipts(string $start,string $end): array {
    return array_map('intval',query('SELECT COALESCE(SUM(p.amount_cents),0) amount,COALESCE(SUM(p.additional_income_cents),0) additional_income,COUNT(*) receipt_count FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.cancelled=0 AND p.paid_on BETWEEN ? AND ?',[$start,$end])->fetch());
}

function salesThreeYears(string $kind,int $year): array {
 if(!in_array($kind,['week','month','year'],true)||$year<1902||$year>9999)throw new InvalidArgumentException('비교 기간을 확인해 주세요.');
 $count=$kind==='week'?52:($kind==='month'?12:1);$rows=[];$totals=[$year=>0,$year-1=>0,$year-2=>0];
 for($i=1;$i<=$count;$i++)$rows[$i]=['label'=>$kind==='week'?$i.'주':($kind==='month'?$i.'월':'연간'),'amounts'=>array_fill_keys(array_keys($totals),0)];
 foreach(array_keys($totals) as $y){$daily=query('SELECT order_date,SUM(subtotal_cents-discount_cents) amount FROM orders WHERE cancelled=0 AND order_date BETWEEN ? AND ? GROUP BY order_date',[$y.'-01-01',$y.'-12-31'])->fetchAll();foreach($daily as $d){$date=new DateTimeImmutable($d['order_date']);$i=$kind==='week'?min(52,intdiv((int)$date->format('z'),7)+1):($kind==='month'?(int)$date->format('n'):1);$rows[$i]['amounts'][$y]+=(int)$d['amount'];$totals[$y]+=(int)$d['amount'];}}
 return ['year'=>$year,'rows'=>$rows,'totals'=>$totals];
}
function salesGrowth(int $now,int $before): string {return $before===0?'—':number_format(($now-$before)/$before*100,1).'%';}
