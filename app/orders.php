<?php
declare(strict_types=1);
function orderItems(int $id): array {return query('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$id])->fetchAll();}
function activeQty(array $i): int {return (int)$i['quantity']-(int)$i['cancelled_quantity'];}
function pendingQty(array $i): int {return activeQty($i)-(int)$i['delivered_quantity'];}
function itemDiscount(array $i,int $qty): int {return (int)$i['unit_discount_cents']*$qty+min($qty,(int)$i['extra_discount_units']);}
function itemNet(array $i,int $qty): int {return (int)$i['unit_price_cents']*$qty-itemDiscount($i,$qty);}
// Allocate an order discount exactly in cents; delivered units always keep their assigned discount.
function allocateDiscount(int $id,int $discount): void {
    $items=orderItems($id); $subtotal=0;
    foreach($items as $i) $subtotal+=(int)$i['unit_price_cents']*activeQty($i);
    if($discount>$subtotal) throw new InvalidArgumentException('할인액은 제품 합계를 넘을 수 없습니다.');
    $cumulative=0; $allocated=0;
    foreach($items as $i) {
        $qty=activeQty($i); $cumulative+=(int)$i['unit_price_cents']*$qty;
        $share=$subtotal?intdiv($discount*$cumulative,$subtotal)-$allocated:0;
        $allocated+=$share;
        query('UPDATE order_items SET unit_discount_cents=?,extra_discount_units=? WHERE id=?',[$qty?intdiv($share,$qty):0,$qty?$share%$qty:0,$i['id']]);
    }
}
function balance(array $o,?array $items=null): array {
    $items??=orderItems((int)$o['id']);$sub=$discount=$deliveredNet=$shipped=$active=$pending=0;
    foreach($items as $i) {
        $q=activeQty($i);$active+=$q;$pending+=pendingQty($i);$sub+=(int)$i['unit_price_cents']*$q;
        $discount+=itemDiscount($i,$q);$shipped+=(int)$i['delivered_quantity'];$deliveredNet+=itemNet($i,(int)$i['delivered_quantity']);
    }
    $fee=$active?(int)$o['delivery_fee_cents']:0;
    $received=(int)query('SELECT COALESCE(SUM(amount_cents-additional_income_cents),0) FROM payments WHERE order_id=?',[$o['id']])->fetchColumn();
    $charges=(int)($o['other_sales_cents']??0)+(int)($o['other_charges_cents']??0);
    $due=$deliveredNet+($shipped?$fee+$charges:0);
    return ['subtotal'=>$sub,'discount'=>$discount,'fee'=>$fee,'total'=>$sub-$discount+$fee+$charges,'received'=>$received,'delivery_due'=>$due,'collectable'=>max(0,$sub-$discount+$fee+$charges-$received),'pending'=>$pending,'shipped'=>$shipped,'active'=>$active];
}
function protectedItemQty(array $i): int {
    $paid=(int)query('SELECT COALESCE(SUM(amount_cents),0) FROM payment_allocations WHERE item_id=?',[$i['id']])->fetchColumn();
    $low=0;$high=activeQty($i);
    while($low<$high){$mid=intdiv($low+$high,2);if(itemNet($i,$mid)>=$paid)$high=$mid;else $low=$mid+1;}
    return max((int)$i['delivered_quantity'],$paid>0?$low:0);
}
function refreshOrder(int $id): array {
    $o=query('SELECT * FROM orders WHERE id=?',[$id])->fetch(); $b=balance($o);
    if($b['received']>$b['total']) throw new InvalidArgumentException('이미 받은 금액보다 주문 금액을 줄일 수 없습니다.');
    $cancelled=$b['active']===0; $delivered=!$cancelled && $b['pending']===0;
    $paid=!$cancelled && ($b['total']>0?$b['received']>=$b['total']:$delivered);
    query('UPDATE orders SET subtotal_cents=?,discount_cents=?,delivery_fee_cents=?,total_cents=?,received_cents=?,shipped_units=?,cancelled=?,delivered=?,paid=?,version=version+1 WHERE id=?',[$b['subtotal'],$b['discount'],$b['fee'],$b['total'],$b['received'],$b['shipped'],(int)$cancelled,(int)$delivered,(int)$paid,$id]);
    return $b;
}
function event(int $id,string $key,string $kind,string $summary,array $detail): void {
    preg_match('/^.{0,450}/us',$summary,$match);$summary=$match[0];
    query('INSERT INTO order_events (order_id,request_key,kind,summary,detail,created_by) VALUES (?,?,?,?,?,?)',[$id,$key,$kind,$summary,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$_SESSION['user_id']]);
}
function positiveInt(mixed $v,int $min=0,int $max=999): int {
    $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if($n===false) throw new InvalidArgumentException('수량을 올바른 정수로 입력해 주세요.');return $n;
}
function allocatePayment(int $paymentId,int $orderId,int $amount,bool $legacy=false): void {
    $items=orderItems($orderId);$shipped=false;
    $allocateProducts=function(bool $deliveredOnly)use($paymentId,$items,&$amount):void {
        foreach($items as $i) {
            $already=(int)query('SELECT COALESCE(SUM(amount_cents),0) FROM payment_allocations WHERE item_id=?',[$i['id']])->fetchColumn();
            $due=itemNet($i,$deliveredOnly?(int)$i['delivered_quantity']:activeQty($i))-$already;
            $part=min(max(0,$due),$amount);
            if($part)query("INSERT INTO payment_allocations (payment_id,item_id,amount_cents,allocation_type) VALUES (?,?,?,'product')",[$paymentId,$i['id'],$part]);
            $amount-=$part;if(!$amount)break;
        }
    };
    $allocateFee=function()use($paymentId,$orderId,&$amount):void {
        $fee=(int)query('SELECT delivery_fee_cents FROM orders WHERE id=?',[$orderId])->fetchColumn();
        $already=(int)query('SELECT COALESCE(SUM(a.amount_cents),0) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE p.order_id=? AND a.item_id IS NULL',[$orderId])->fetchColumn();
        $part=min(max(0,$fee-$already),$amount);
        if($part)query("INSERT INTO payment_allocations (payment_id,item_id,amount_cents,allocation_type) VALUES (?,NULL,?,'delivery_fee')",[$paymentId,$part]);
        $amount-=$part;
    };
    foreach($items as $i)if($i['delivered_quantity'])$shipped=true;
    // Delivered debt first; the remaining amount may be an advance on pending products.
    $allocateProducts(true);if($amount&&$shipped)$allocateFee();
    if($amount)$allocateProducts(false);if($amount)$allocateFee();
    if($amount)throw new InvalidArgumentException('입금 배분 금액을 확인해 주세요.');
}
function workflowAction(string $action): int {
    $id=positiveInt($_POST['id']??null,1,PHP_INT_MAX);$key=textfield($_POST,'request_key',32,true);
    if(!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new InvalidArgumentException('화면을 다시 열어 주세요.');
    db()->beginTransaction();
    try {
        $o=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();
        if(!$o) throw new InvalidArgumentException('주문을 찾을 수 없습니다.');
        if($o['import_batch_id']??null)throw new InvalidArgumentException('과거 엑셀 주문은 원본 보존을 위해 수정·취소할 수 없습니다.');
        if(query('SELECT id FROM order_events WHERE request_key=? AND order_id=?',[$key,$id])->fetch()) {db()->commit();return $id;}
        if((int)($_POST['version']??0)!==(int)$o['version']) throw new InvalidArgumentException('다른 화면에서 주문이 변경되었습니다. 새로고침한 뒤 다시 확인해 주세요.');
        if($o['cancelled']) throw new InvalidArgumentException('취소한 주문은 변경할 수 없습니다.');
        if($o['paid'] && !in_array($action,['deliver_items','deliver_all'],true)) throw new InvalidArgumentException('입금 완료된 주문은 수정·취소할 수 없습니다.');
        $items=orderItems($id);$before=balance($o,$items);$detail=['before'=>$before];
        if($action==='edit_order') {
            $pending=$_POST['pending']??[];
            if(!is_array($pending) || count($pending)!==count($items)) throw new InvalidArgumentException('전체 제품의 대기 수량을 확인해 주세요.');
            $changes=[];
            foreach($items as $i) {
                if(!array_key_exists($i['id'],$pending)) throw new InvalidArgumentException('제품 목록이 변경되었습니다. 새로고침해 주세요.');
                $n=positiveInt($pending[$i['id']]);$old=pendingQty($i);$delta=$n-$old;
                if(activeQty($i)+$delta<protectedItemQty($i))throw new InvalidArgumentException('전달했거나 입금이 배정된 제품 수량은 취소할 수 없습니다. 환불이 필요한 경우 별도 정정이 필요합니다.');
                if(activeQty($i)+$delta>999) throw new InvalidArgumentException('제품별 수량은 999개 이하로 입력해 주세요.');
                if($delta<0) query('UPDATE order_items SET cancelled_quantity=cancelled_quantity+? WHERE id=?',[-$delta,$i['id']]);
                if($delta>0) query('UPDATE order_items SET quantity=quantity+? WHERE id=?',[$delta,$i['id']]);
                if($delta) $changes[]=['item_id'=>$i['id'],'product'=>$i['product_name'],'pending_before'=>$old,'pending_after'=>$n];
            }
            $addQty=positiveInt($_POST['add_qty']??'0');
            if($addQty) {
                $p=query('SELECT * FROM products WHERE id=? AND active=1',[positiveInt($_POST['add_product_id']??null,1,PHP_INT_MAX)])->fetch();
                if(!$p) throw new InvalidArgumentException('추가할 제품을 선택해 주세요.');
                query('INSERT INTO order_items (order_id,product_id,product_code,product_name,weight_g,unit_price_cents,quantity) VALUES (?,?,?,?,?,?,?)',[$id,$p['id'],$p['code'],$p['name'],$p['weight_g'],$p['price_cents'],$addQty]);
                $changes[]=['product'=>$p['name'],'added'=>$addQty];
            }
            $date=validDate(textfield($_POST,'delivery_date',10,true));
            if($date<$o['order_date']) throw new InvalidArgumentException('예정일은 주문일 이후로 선택해 주세요.');
            $notes=textfield($_POST,'notes',15000);
            $method=textfield($_POST,'fulfillment',10,true);if(!in_array($method,['delivery','pickup','church'],true)) throw new InvalidArgumentException('받는 방법을 선택해 주세요.');
            $address=textfield($_POST,'delivery_address',500);if($method==='delivery' && $address==='') throw new InvalidArgumentException('배달 주소를 입력해 주세요.');
            $discount=cents($_POST['discount']??'0');
            if($before['shipped'] || $before['received']) {
                if($method!==$o['fulfillment'] || $address!==$o['delivery_address'] || $discount!==(int)$o['discount_cents']) throw new InvalidArgumentException('배송·입금 후에는 받는 방법, 주소, 할인액을 바꿀 수 없습니다. 대기 수량과 다음 예정일만 변경해 주세요.');
            } else {
                allocateDiscount($id,$discount);
                $sub=balance($o)['subtotal'];
                query('UPDATE orders SET fulfillment=?,delivery_address=?,delivery_fee_cents=? WHERE id=?',[$method,$address,deliveryFee($sub,$method),$id]);
            }
            query('UPDATE orders SET delivery_date=?,notes=? WHERE id=?',[$date,$notes,$id]);
            $detail+=['items'=>$changes,'previous_date'=>$o['delivery_date'],'next_date'=>$date,'previous_address'=>$o['delivery_address'],'previous_notes'=>$o['notes'],'reason'=>textfield($_POST,'reason',500,true)];
            $summary='대기 제품·예정일 수정: '.$detail['reason'];
        } elseif($action==='cancel_order') {
            if($before['received'] || $before['shipped']) throw new InvalidArgumentException('배송·입금 기록이 있는 주문은 전체 취소할 수 없습니다. 대기 제품 수량을 0으로 바꿔 취소해 주세요.');
            $reason=textfield($_POST,'reason',400,true);
            query('UPDATE order_items SET cancelled_quantity=quantity WHERE order_id=?',[$id]);
            $summary='주문 전체 취소: '.$reason;$detail['reason']=$reason;
        } elseif($action==='deliver_items' || $action==='deliver_all') {
            $date=validDate(textfield($_POST,'delivered_on',10,true));
            if($date<$o['order_date'] || $date>date('Y-m-d')) throw new InvalidArgumentException('실제 전달일은 주문일부터 오늘 사이로 입력해 주세요.');
            $quantities=$_POST['deliver']??[];if(!is_array($quantities)) throw new InvalidArgumentException('전달 수량을 확인해 주세요.');
            $known=array_column($items,'id');foreach(array_keys($quantities) as $qId) if(!in_array((int)$qId,array_map('intval',$known),true)) throw new InvalidArgumentException('주문에 없는 제품입니다.');
            $sent=[];
            foreach($items as $i) {
                $n=$action==='deliver_all'?pendingQty($i):positiveInt($quantities[$i['id']]??'0');
                if($n>pendingQty($i)) throw new InvalidArgumentException('남은 수량보다 많이 전달할 수 없습니다.');
                if($n) {query('UPDATE order_items SET delivered_quantity=delivered_quantity+? WHERE id=?',[$n,$i['id']]);$sent[]=['item_id'=>$i['id'],'product'=>$i['product_name'],'quantity'=>$n];}
            }
            if(!$sent) throw new InvalidArgumentException('이번에 전달한 수량을 하나 이상 입력해 주세요.');
            $summary=$date.' 전달: '.implode(', ',array_map(fn($i)=>$i['product'].' '.$i['quantity'].'개',$sent));
            $detail+=['delivered_on'=>$date,'address'=>$o['delivery_address'],'fulfillment'=>$o['fulfillment'],'items'=>$sent];
        } elseif($action==='receive_payment') {
            $amount=cents($_POST['amount']??'');
            if($amount<=0 || $amount>$before['collectable']) throw new InvalidArgumentException('입금액은 전체 주문의 남은 대금 이내로 입력해 주세요.');
            $method=textfield($_POST,'payment_method',20,true);if(!in_array($method,['cash','etransfer'],true)) throw new InvalidArgumentException('현금 또는 eTransfer를 선택해 주세요.');
            $date=validDate(textfield($_POST,'paid_on',10,true));
            if($date<$o['order_date'] || $date>date('Y-m-d')) throw new InvalidArgumentException('입금일은 주문일부터 오늘 사이로 입력해 주세요.');
            $note=textfield($_POST,'payment_note',500);$payer=payerName($_POST);
            query('INSERT INTO payments (order_id,request_key,amount_cents,method,paid_on,note,payer_name,created_by) VALUES (?,?,?,?,?,?,?,?)',[$id,$key,$amount,$method,$date,$note,$payer,$_SESSION['user_id']]);
            allocatePayment((int)db()->lastInsertId(),$id,$amount);
            query('UPDATE orders SET payment_method=? WHERE id=?',[$method,$id]);
            $summary=$date.' '.($method==='cash'?'현금':'eTransfer').' '.money($amount).' 입금';$detail+=['amount_cents'=>$amount,'paid_on'=>$date,'method'=>$method,'note'=>$note,'payer_name'=>$payer];
        } else throw new InvalidArgumentException('요청을 확인해 주세요.');
        $detail['after']=refreshOrder($id);
        event($id,$key,$action,$summary,$detail);
        db()->commit();return $id;
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack();throw $e;}
}

function workflowFields(array $o,string $action): string {
    $same=($_POST['action']??'')===$action;
    $key=$same && is_string($_POST['request_key']??null)?$_POST['request_key']:bin2hex(random_bytes(16));
    $version=$same?(int)($_POST['version']??$o['version']):(int)$o['version'];
    return csrf().'<input type="hidden" name="action" value="'.e($action).'"><input type="hidden" name="id" value="'.(int)$o['id'].'"><input type="hidden" name="version" value="'.$version.'"><input type="hidden" name="request_key" value="'.e($key).'">';
}
