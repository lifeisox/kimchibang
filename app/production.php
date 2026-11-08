<?php
function productionRecipes(): array {return query('SELECT * FROM production_recipes WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);}
function productionNumber(mixed $v,float $max=1000000): float {
 if(!is_string($v)&&!is_int($v)&&!is_float($v))throw new InvalidArgumentException('수량·배합값을 확인해 주세요.');
 if(!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',(string)$v)||(float)$v>$max)throw new InvalidArgumentException('0 이상의 숫자를 소수점 네 자리까지 입력해 주세요.');return (float)$v;
}
function productionMaterials(array $recipe,float $grams): array {
 $lines=json_decode($recipe['ingredients'],true,512,JSON_THROW_ON_ERROR);$result=[];$pending=$lines;
 for($pass=0;$pending && $pass<=count($lines);$pass++)foreach($pending as $i=>$line) {
  $basis=$line['basis'];if($line['mode']==='percent'&&$basis!=='product'&&!array_key_exists($basis,$result))continue;
  $result[$line['material']]=$line['mode']==='grams'?($grams/(int)$recipe['base_g'])*(float)$line['amount']:($basis==='product'?$grams:$result[$basis])*(float)$line['amount']/100;
  unset($pending[$i]);
 }
 if($pending)throw new InvalidArgumentException('재료 기준이 없거나 서로 순환합니다. 배합 기준을 확인해 주세요.');return $result;
}
function productionSnapshot(array $ids,array $retail): array {
 $recipes=productionRecipes();$rows=[];$warnings=[];$sources=[];$used=[];
 if(count($ids)>10000)throw new InvalidArgumentException('선택 주문이 너무 많습니다.');
 foreach(array_unique($ids) as $id) {
  $id=positiveInt($id,1,PHP_INT_MAX);$o=query('SELECT * FROM orders WHERE id=? AND cancelled=0',[$id])->fetch();
  if(!$o)throw new InvalidArgumentException('선택한 주문이 취소되었거나 없습니다. 새로고침해 주세요.');
  $units=0;
  foreach(orderItems($id) as $item)if($n=pendingQty($item)) {
   $units+=$n;$rid=query('SELECT recipe_id FROM production_links WHERE product_id=?',[$item['product_id']])->fetchColumn();
   $key='order:'.$item['product_id'].':'.$item['weight_g'];
   if(!isset($rows[$key]))$rows[$key]=['channel'=>'주문분','name'=>$item['product_name'],'weight_g'=>$item['weight_g'],'quantity'=>0,'recipe_id'=>$rid?(int)$rid:null];
   $rows[$key]['quantity']+=$n;
  }
  if(!$units)throw new InvalidArgumentException('이미 모두 전달한 주문이 있습니다. 목록을 새로고침해 주세요.');
  $sources[]=['id'=>$id,'name'=>$o['customer_name'],'pending'=>$units];
 }
 foreach($retail as $id=>$qty) {
  $n=productionNumber($qty,100000);if(floor($n)!==$n)throw new InvalidArgumentException('마트 수량은 정수로 입력해 주세요.');if(!$n)continue;
  $p=query('SELECT * FROM production_retail WHERE id=? AND active=1',[positiveInt($id,1,PHP_INT_MAX)])->fetch();if(!$p)throw new InvalidArgumentException('마트 제품 목록이 변경됐습니다. 새로고침해 주세요.');
  $rows['retail:'.$id]=['channel'=>'마트판매분','name'=>$p['name'],'weight_g'=>(int)$p['weight_g'],'quantity'=>(int)$n,'recipe_id'=>(int)$p['recipe_id']];
 }
 if(!$rows)throw new InvalidArgumentException('주문을 선택하거나 마트 수량을 입력해 주세요.');
 $totals=[];$groups=['pogi'=>['배추'=>0,'무우'=>0,'절임소금'=>0],'mat'=>['배추'=>0,'무우'=>0,'절임소금'=>0]];
 foreach($rows as &$row) {
  $recipe=$recipes[$row['recipe_id']]??null;$row['grams']=$row['weight_g']===null?null:(int)$row['weight_g']*$row['quantity'];$row['materials']=[];$row['group']='none';$row['recipe_name']=$recipe['name']??'배합 기준 확인 필요';
  if(!$recipe||!$row['grams']){$warnings[]=$row['channel'].' '.$row['name'].' '.($row['weight_g']===null?'용량 확인 필요':'배합 기준 확인 필요');continue;}
  $row['materials']=productionMaterials($recipe,$row['grams']);$row['group']=$recipe['brine_group'];$used[$row['recipe_id']]=$recipe;
  foreach($row['materials'] as $m=>$g){$totals[$m]=($totals[$m]??0)+$g;if(isset($groups[$row['group']])&&array_key_exists($m,$groups[$row['group']]))$groups[$row['group']][$m]+=$g;}
 }unset($row);
 return ['rows'=>array_values($rows),'totals'=>$totals,'groups'=>$groups,'warnings'=>array_values(array_unique($warnings)),'orders'=>$sources,'recipes'=>$used];
}
function productionAction(string $action): never {
 if($action==='production_save') {
  $key=textfield($_POST,'request_key',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('화면을 새로고침해 주세요.');
  if($id=query('SELECT id FROM production_plans WHERE request_key=?',[$key])->fetchColumn())redirect('/?page=production&id='.$id);
  $ids=$_POST['orders']??[];$retail=$_POST['retail']??[];if(!is_array($ids)||!is_array($retail))throw new InvalidArgumentException('선택 내용을 확인해 주세요.');
  $date=validDate(textfield($_POST,'plan_date',10,true));$title=textfield($_POST,'title',100,true);$snapshot=productionSnapshot($ids,$retail);
  query('INSERT INTO production_plans (request_key,plan_date,title,snapshot,created_by) VALUES (?,?,?,?,?)',[$key,$date,$title,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$_SESSION['user_id']]);redirect('/?page=production&id='.db()->lastInsertId());
 }
 db()->beginTransaction();
 try {
 if($action==='recipe_save') {
  $id=positiveInt($_POST['id']??0,0,PHP_INT_MAX);$name=textfield($_POST,'name',100,true);$base=positiveInt($_POST['base_g']??0,1,1000000);$group=optionalChoice($_POST,'brine_group',['pogi','mat','none']);if(!$group)$group='none';$lines=[];$seen=[];
  foreach(['material','basis','mode','amount'] as $field)if(!is_array($_POST[$field]??null)||count($_POST[$field])>100)throw new InvalidArgumentException('재료 입력 행을 확인해 주세요.');
  foreach($_POST['material'] as $i=>$m) {
   $m=textfield(['m'=>$m],'m',40);if($m==='')continue;if(in_array($m,['product','제품무게'],true)||isset($seen[$m]))throw new InvalidArgumentException('재료명 중복 또는 예약된 이름입니다.');$seen[$m]=true;
   $basis=textfield(['b'=>$_POST['basis'][$i]??'product'],'b',40,true);$basis=$basis==='제품무게'?'product':$basis;$mode=$_POST['mode'][$i]??'';if(!in_array($mode,['percent','grams'],true))throw new InvalidArgumentException('입력 단위를 확인해 주세요.');
   $lines[]=['material'=>$m,'basis'=>$basis,'mode'=>$mode,'amount'=>productionNumber($_POST['amount'][$i]??'',1000000)];
  }
  if(!$lines)throw new InvalidArgumentException('주재료를 하나 이상 입력해 주세요.');
  $json=json_encode($lines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);productionMaterials(['ingredients'=>$json,'base_g'=>$base],$base);
  if($id){if(!query('SELECT id FROM production_recipes WHERE id=? AND active=1',[$id])->fetch())throw new InvalidArgumentException('배합 기준이 없습니다.');query('UPDATE production_recipes SET name=?,base_g=?,brine_group=?,ingredients=? WHERE id=?',[$name,$base,$group,$json,$id]);}
  else query('INSERT INTO production_recipes (name,base_g,brine_group,ingredients) VALUES (?,?,?,?)',[$name,$base,$group,$json]);
 } elseif($action==='recipe_delete')query('UPDATE production_recipes SET active=0 WHERE id=?',[positiveInt($_POST['id']??0,1,PHP_INT_MAX)]);
 elseif($action==='production_links') {
  foreach($_POST['recipe']??[] as $pid=>$rid){$pid=positiveInt($pid,1,PHP_INT_MAX);$rid=positiveInt($rid,0,PHP_INT_MAX);if($rid&&!query('SELECT id FROM production_recipes WHERE id=? AND active=1',[$rid])->fetch())throw new InvalidArgumentException('배합 기준을 확인해 주세요.');query('INSERT INTO production_links (product_id,recipe_id) VALUES (?,?) ON DUPLICATE KEY UPDATE recipe_id=VALUES(recipe_id)',[$pid,$rid?:null]);}
 } elseif($action==='retail_save') {
  $id=positiveInt($_POST['id']??0,0,PHP_INT_MAX);$name=textfield($_POST,'name',100,true);$g=positiveInt($_POST['weight_g']??0,1,1000000);$rid=positiveInt($_POST['recipe_id']??0,1,PHP_INT_MAX);if(!query('SELECT id FROM production_recipes WHERE id=? AND active=1',[$rid])->fetch())throw new InvalidArgumentException('배합 기준을 선택해 주세요.');
  if($id)query('UPDATE production_retail SET name=?,weight_g=?,recipe_id=? WHERE id=?',[$name,$g,$rid,$id]);else query('INSERT INTO production_retail (name,weight_g,recipe_id) VALUES (?,?,?)',[$name,$g,$rid]);
 } elseif($action==='retail_delete')query('UPDATE production_retail SET active=0 WHERE id=?',[positiveInt($_POST['id']??0,1,PHP_INT_MAX)]);
 else throw new InvalidArgumentException('요청을 확인해 주세요.');
 db()->commit();
 } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 $_SESSION['flash']='생산 기준을 저장했습니다. 기존에 저장한 계획은 그대로 유지됩니다.';redirect('/?page=production-settings');
}
function productionKg(?float $g): string {return $g===null?'미확인':rtrim(rtrim(number_format($g/1000,3,'.',','),'0'),'.').' kg';}
