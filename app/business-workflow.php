<?php
function bizCategories(): array {return ['kimchi'=>'Kimchi','kimbap'=>'Kimbap','triangle'=>'Triangle Kimbap','riceball'=>'Rice Ball','etc'=>'Etc'];}
function bizKo(array $p): string {return $p['ko'];}
function bizReferenced(string $kind,int $id): bool {
 if($kind==='client')return (bool)query('SELECT 1 FROM biz_deliveries WHERE client_id=? UNION SELECT 1 FROM biz_invoices WHERE client_id=? UNION SELECT 1 FROM biz_orders WHERE client_id=? LIMIT 1',[$id,$id,$id])->fetchColumn();
 return (bool)query('SELECT 1 FROM biz_deliveries WHERE product_id=? LIMIT 1',[$id])->fetchColumn();
}
function bizProductPosted(int $id): bool {return (bool)query('SELECT 1 FROM biz_deliveries WHERE product_id=? AND sent=1 LIMIT 1',[$id])->fetchColumn();}
function bizBatch(string $key,bool $lock=false): array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('주문서를 확인하세요.');
 $o=query('SELECT * FROM biz_orders WHERE batch_key=?'.($lock?' FOR UPDATE':''),[$key])->fetch();
 if(!$o||$o['cancelled'])throw new InvalidArgumentException('주문서가 없거나 취소되었습니다.');return $o;
}
function bizBatchSent(string $key): bool {return (bool)query('SELECT 1 FROM biz_deliveries WHERE batch_key=? AND sent=1 LIMIT 1',[$key])->fetchColumn();}
function bizBatchPending(string $key): bool {return (bool)query('SELECT 1 FROM biz_deliveries WHERE batch_key=? AND cancelled=0 AND sent=0 LIMIT 1',[$key])->fetchColumn();}
function bizCheckVersion(array $o): void {if((int)($_POST['version']??0)!==(int)$o['version'])throw new InvalidArgumentException('다른 화면에서 변경했습니다. 새로고침한 뒤 다시 확인하세요.');}
function bizQuantities(mixed $input): array {
 if(!is_array($input))throw new InvalidArgumentException('주문 수량을 입력하세요.');$items=[];
 foreach($input as $pid=>$value){$id=positiveInt($pid,1,PHP_INT_MAX);$qty=positiveInt($value,0,10000);if(!$qty)continue;if(!query('SELECT id FROM biz_products WHERE id=? AND active=1',[$id])->fetchColumn())throw new InvalidArgumentException('판매상품을 확인하세요.');$items[$id]=$qty;}
 if(!$items)throw new InvalidArgumentException('수량을 한 개 이상 입력하세요.');return $items;
}
function bizSyncLines(string $key,int $client,string $day,int $discount,array $items): void {
 // Keep cancelled rows for the audit trail; a quantity restored later reuses the same row.
 query('UPDATE biz_deliveries SET planned=0,cancelled=1,version=version+1 WHERE batch_key=?',[$key]);
 foreach($items as $pid=>$qty){$request=substr(hash('sha256',$key.'|'.$pid),0,32);query('INSERT INTO biz_deliveries (client_id,product_id,day,batch_key,planned,request_key,created_by,discount_bp) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id),day=VALUES(day),planned=VALUES(planned),discount_bp=VALUES(discount_bp),cancelled=0,version=version+1',[$client,$pid,$day,$key,$qty,$request,$_SESSION['user_id'],$discount]);}
}
function bizShip(int $id,int $qty,?int $discount=null): string {
 $d=query('SELECT d.*,p.category,p.ko,p.en,p.pack,p.retail_cents,p.hst_bp FROM biz_deliveries d JOIN biz_products p ON p.id=d.product_id WHERE d.id=? FOR UPDATE',[$id])->fetch();
 if(!$d||$d['sent']||$d['cancelled'])throw new InvalidArgumentException('이미 납품했거나 없는 납품서입니다.');
 $c=query('SELECT * FROM biz_clients WHERE id=?',[$d['client_id']])->fetch();bizClosed((int)$c['id'],$d['day']);$price=bizPrice($c,[...$d,'id'=>$d['product_id']]);
 if($d['hst_bp']===null)throw new InvalidArgumentException(bizKo($d).'의 HST를 판매상품 관리에서 먼저 지정하세요.');
 query('UPDATE biz_deliveries SET sent=1,delivered=?,version=version+1 WHERE id=?',[$qty,$id]);
 if(bizConsigned((string)$c['code'],$d['category']))return 'biz-results';
 query('INSERT INTO biz_sales (delivery_id,day,qty,cents,discount_bp,request_key) VALUES (?,?,?,?,?,?)',[$id,$d['day'],$qty,$price,$discount??$d['discount_bp'],bin2hex(random_bytes(16))]);
 if($c['code']==='PT')return 'biz-invoices';
 if((int)query("SELECT COUNT(*) FROM biz_deliveries d JOIN biz_products p ON p.id=d.product_id WHERE d.batch_key=? AND d.sent=0 AND d.cancelled=0".($c['code']==='OK'?" AND p.category='kimchi'":''),[$d['batch_key']])->fetchColumn())return 'biz-deliveries';
 $invoice=bizIssue((int)$c['id'],$d['day'],$d['day'],'direct',$d['batch_key']);return 'biz-invoice&id='.$invoice.(($c['code']==='HN'||!$c['code'])?'&print=1':'');
}
function bizWorkflowAction(string $action): ?string {
 if(in_array($action,['biz_client','biz_client_delete'],true)){
  $id=positiveInt($_POST['id']??0,0,PHP_INT_MAX);$c=$id?query('SELECT * FROM biz_clients WHERE id=? AND active=1 FOR UPDATE',[$id])->fetch():null;if($id&&!$c)throw new InvalidArgumentException('거래처를 확인하세요.');
  if($action==='biz_client_delete'){if(!$id||bizReferenced('client',$id))throw new InvalidArgumentException('거래내역이 있는 거래처는 삭제할 수 없습니다.');query('UPDATE biz_clients SET active=0,version=version+1 WHERE id=?',[$id]);query('DELETE FROM biz_prices WHERE client_id=?',[$id]);}
  else{$email=textfield($_POST,'email',150);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('이메일 주소를 확인하세요.');$values=[textfield($_POST,'name',150,true),$email,textfield($_POST,'address',500)];if($id)query('UPDATE biz_clients SET name=?,email=?,address=?,version=version+1 WHERE id=?',[...$values,$id]);else query('INSERT INTO biz_clients (name,email,address) VALUES (?,?,?)',$values);}
  return 'biz-clients';
 }
 if(in_array($action,['biz_product_save','biz_product_delete'],true)){
  $id=positiveInt($_POST['id']??0,0,PHP_INT_MAX);$p=$id?query('SELECT * FROM biz_products WHERE id=? AND active=1 FOR UPDATE',[$id])->fetch():null;if($id&&!$p)throw new InvalidArgumentException('상품을 확인하세요.');
  if($action==='biz_product_delete'){if(!$id||bizReferenced('product',$id))throw new InvalidArgumentException('거래내역이 있는 상품은 삭제할 수 없습니다.');query('UPDATE biz_products SET active=0 WHERE id=?',[$id]);query('DELETE FROM biz_prices WHERE product_id=?',[$id]);}
  else{$category=textfield($_POST,'category',20,true);if(!isset(bizCategories()[$category]))throw new InvalidArgumentException('분류를 선택하세요.');$tax=$category==='kimchi'?0:1300;if($category==='etc'){$v=textfield($_POST,'hst_bp',4);$tax=$v===''?null:positiveInt($v,0,1300);if($tax!==null&&!in_array($tax,[0,1300],true))throw new InvalidArgumentException('HST를 확인하세요.');}
   if($p&&bizProductPosted($id)&&($category!==$p['category']||$tax!==($p['hst_bp']===null?null:(int)$p['hst_bp'])))throw new InvalidArgumentException('거래내역이 있는 상품의 분류·HST는 변경할 수 없습니다. 새 상품으로 등록하세요.');
   $retail=textfield($_POST,'retail',20);$values=[$category,textfield($_POST,'ko',100,true),textfield($_POST,'en',100,true),$retail===''?null:cents($retail),$tax];if($id)query('UPDATE biz_products SET category=?,ko=?,en=?,retail_cents=?,hst_bp=? WHERE id=?',[...$values,$id]);else query("INSERT INTO biz_products (category,ko,en,retail_cents,hst_bp,pack) VALUES (?,?,?,?,?,'')",$values);
  }return 'biz-products';
 }
 if($action==='biz_prices_add'){
  $cid=positiveInt($_POST['client_id']??0,1,PHP_INT_MAX);$category=textfield($_POST,'category',20,true);
  if(!isset(bizCategories()[$category])||!query('SELECT id FROM biz_clients WHERE id=? AND active=1',[$cid])->fetchColumn())throw new InvalidArgumentException('거래처와 분류를 확인하세요.');
  if(!is_array($_POST['prices']??null)||!$_POST['prices'])throw new InvalidArgumentException('추가할 납품가를 먼저 입력하거나 일괄복사하세요.');
  foreach($_POST['prices'] as $pid=>$value){$pid=positiveInt($pid,1,PHP_INT_MAX);$amount=cents($value);if(!query('SELECT id FROM biz_products WHERE id=? AND category=? AND active=1',[$pid,$category])->fetchColumn())throw new InvalidArgumentException('상품 분류를 확인하세요.');
   query('INSERT INTO biz_prices (client_id,product_id,cents) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cents=cents',[$cid,$pid,$amount]);
  }return 'biz-prices&client_id='.$cid;
 }
 if(in_array($action,['biz_price','biz_price_delete'],true)){
  $cid=positiveInt($_POST['client_id']??0,1,PHP_INT_MAX);$pid=positiveInt($_POST['product_id']??0,1,PHP_INT_MAX);
  if(!query('SELECT id FROM biz_clients WHERE id=? AND active=1',[$cid])->fetchColumn()||!query('SELECT id FROM biz_products WHERE id=? AND active=1',[$pid])->fetchColumn())throw new InvalidArgumentException('거래처와 상품을 확인하세요.');
  if($action==='biz_price_delete')query('DELETE FROM biz_prices WHERE client_id=? AND product_id=?',[$cid,$pid]);else query('INSERT INTO biz_prices VALUES (?,?,?) ON DUPLICATE KEY UPDATE cents=VALUES(cents)',[$cid,$pid,cents($_POST['price']??'')]);return 'biz-prices&client_id='.$cid;
 }
 if(in_array($action,['biz_plan','biz_order_save'],true)){
  $editing=$action==='biz_order_save'&&($_POST['batch_key']??'')!=='';$key=textfield($_POST,$editing?'batch_key':'request_key',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('화면을 새로 여세요.');
  $existing=query('SELECT * FROM biz_orders WHERE batch_key=? FOR UPDATE',[$key])->fetch();
  if(!$editing&&$existing)return 'biz-plan'; // Same submission must not create another document.
  if($editing){$o=bizBatch($key,true);bizCheckVersion($o);if($o['manual_edited']||bizBatchSent($key))throw new InvalidArgumentException('납품서를 직접 수정했거나 납품한 주문서는 수정·삭제할 수 없습니다.');}
  $client=positiveInt($_POST['client_id']??0,1,PHP_INT_MAX);if(!query('SELECT id FROM biz_clients WHERE id=? AND active=1',[$client])->fetchColumn())throw new InvalidArgumentException('거래처를 선택하세요.');$day=validDate(textfield($_POST,'day',10,true));bizClosed($client,$day);$discount=bizRate($_POST['discount']??'0');$items=bizQuantities($_POST['qty']??null);$notes=textfield($_POST,'notes',2000);
  if($editing)query('UPDATE biz_orders SET client_id=?,day=?,order_day=?,discount_bp=?,order_discount_bp=?,notes=?,document_notes=?,items=?,version=version+1 WHERE batch_key=?',[$client,$day,$day,$discount,$discount,$notes,$notes,json_encode($items,JSON_THROW_ON_ERROR),$key]);
  else query('INSERT INTO biz_orders (batch_key,client_id,order_date,day,order_day,discount_bp,order_discount_bp,notes,document_notes,items,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$key,$client,date('Y-m-d'),$day,$day,$discount,$discount,$notes,$notes,json_encode($items,JSON_THROW_ON_ERROR),$_SESSION['user_id']]);
  bizSyncLines($key,$client,$day,$discount,$items);return 'biz-plan';
 }
 if($action==='biz_order_delete'){
  $o=bizBatch(textfield($_POST,'batch_key',32,true),true);bizCheckVersion($o);if($o['manual_edited']||bizBatchSent($o['batch_key']))throw new InvalidArgumentException('납품서를 직접 수정했거나 납품한 주문서는 수정·삭제할 수 없습니다.');
  query('UPDATE biz_orders SET cancelled=1,version=version+1 WHERE batch_key=?',[$o['batch_key']]);query('UPDATE biz_deliveries SET cancelled=1,version=version+1 WHERE batch_key=?',[$o['batch_key']]);return 'biz-plan';
 }
 if(in_array($action,['biz_document_save','biz_document_confirm'],true)){
  $o=bizBatch(textfield($_POST,'batch_key',32,true),true);bizCheckVersion($o);if(($action==='biz_document_save'&&bizBatchSent($o['batch_key']))||!bizBatchPending($o['batch_key']))throw new InvalidArgumentException('이미 납품한 문서는 수정할 수 없습니다.');$key=$o['batch_key'];
  if($action==='biz_document_save'||isset($_POST['qty'])){if(bizBatchSent($key))throw new InvalidArgumentException('이미 납품한 수량은 변경할 수 없습니다.');$items=bizQuantities($_POST['qty']??null);$day=validDate(textfield($_POST,'day',10,true));bizClosed((int)$o['client_id'],$day);$discount=bizRate($_POST['discount']??'0');$notes=textfield($_POST,'notes',2000);bizSyncLines($key,(int)$o['client_id'],$day,$discount,$items);query('UPDATE biz_orders SET day=?,discount_bp=?,document_notes=?,manual_edited=1,version=version+1 WHERE batch_key=?',[$day,$discount,$notes,$key]);if($action==='biz_document_save')return 'biz-delivery&batch='.$key;}
  $next='biz-deliveries';foreach(query('SELECT id,planned FROM biz_deliveries WHERE batch_key=? AND cancelled=0 AND sent=0 ORDER BY id',[$key])->fetchAll() as $line){$target=bizShip((int)$line['id'],(int)$line['planned']);if(str_starts_with($target,'biz-invoice&'))$next=$target;}
  query('UPDATE biz_orders SET version=version+1 WHERE batch_key=?',[$key]);return $next;
 }
 return null;
}
