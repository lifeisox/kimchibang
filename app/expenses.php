<?php
function expenseReceiptDirectory(): string {$dir=__DIR__.'/../.local/receipts';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('영수증 저장 폴더를 만들 수 없습니다.');return $dir;}
function serveExpenseReceipt(): never {
 if(empty($_SESSION['user_id'])){http_response_code(401);exit;}
 $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$r=$id?query('SELECT * FROM expense_receipts WHERE id=?',[$id])->fetch():false;
 if(!$r||!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|pdf)$/D',$r['storage_key'])){http_response_code(404);exit;}
 $file=expenseReceiptDirectory().'/'.$r['storage_key'];if(!is_file($file)){http_response_code(404);exit;}
 header('Content-Type: '.$r['mime']);header('Content-Disposition: inline; filename="receipt-'.$id.'.'.pathinfo($file,PATHINFO_EXTENSION).'"');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($file));readfile($file);exit;
}
function expenseFile(array $upload): ?array {
 if(($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
 if(($upload['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??''))throw new InvalidArgumentException('영수증 업로드를 확인해 주세요.');
 if(filesize($upload['tmp_name'])>10*1024*1024)throw new InvalidArgumentException('영수증은 10MB 이하로 올려 주세요.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];if(!isset($types[$mime]))throw new InvalidArgumentException('JPG·PNG·WebP 사진 또는 PDF만 지원합니다.');
 if($mime!=='application/pdf'){$size=@getimagesize($upload['tmp_name']);if(!$size||$size[0]*$size[1]>40000000)throw new InvalidArgumentException('사진이 너무 크거나 올바르지 않습니다.');}
 else {if(file_get_contents($upload['tmp_name'],false,null,0,5)!=='%PDF-')throw new InvalidArgumentException('올바른 PDF 파일을 선택하세요.');}
 $key=bin2hex(random_bytes(24)).'.'.$types[$mime];$path=expenseReceiptDirectory().'/'.$key;
 if(!move_uploaded_file($upload['tmp_name'],$path))throw new RuntimeException('영수증 저장 실패');chmod($path,0600);
 return ['storage_key'=>$key,'mime'=>$mime,'original_name'=>mb_substr(basename($upload['name']),0,200),'sha256'=>hash_file('sha256',$path)];
}
function expenseAction(): never {
 $action=$_POST['action']??'';$id=positiveInt($_POST['id']??0,0,PHP_INT_MAX);$attachment=null;
 db()->beginTransaction();
 try {
  $before=$id?query('SELECT * FROM expenses WHERE id=? FOR UPDATE',[$id])->fetch():null;
  if($id&&(!$before||$before['voided']||(int)$before['version']!==(int)($_POST['version']??0)))throw new InvalidArgumentException('다른 화면에서 변경되었거나 취소된 비용입니다. 새로고침하세요.');
  if($action==='expense_void') {
   if(!$before)throw new InvalidArgumentException('비용을 찾을 수 없습니다.');$reason=textfield($_POST,'reason',500,true);query('UPDATE expenses SET voided=1,version=version+1 WHERE id=?',[$id]);$detail=['before'=>$before,'reason'=>$reason,'action'=>'void'];
  } else {
   $key=textfield($_POST,'request_key',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('화면을 새로고침하세요.');
   if(!$id&&($same=query('SELECT id FROM expenses WHERE request_key=?',[$key])->fetchColumn())){db()->rollBack();redirect('/?page=expense-edit&id='.$same);}
   $date=validDate(textfield($_POST,'expense_date',10,true));$vendor=textfield($_POST,'vendor',150,true);$category=positiveInt($_POST['category_id']??0,1,PHP_INT_MAX);if(!query('SELECT id FROM expense_categories WHERE id=?',[$category])->fetch())throw new InvalidArgumentException('비용 항목을 선택하세요.');
   $subtotal=cents($_POST['subtotal']??'');$hst=cents($_POST['hst']??'0');$other=cents($_POST['other_tax']??'0');$total=cents($_POST['total']??'');if($total!==$subtotal+$hst+$other||$total===0)throw new InvalidArgumentException('공급가 + GST/HST + 기타 세금이 총액과 일치해야 합니다.');
   require_once __DIR__.'/production.php';$business=productionNumber($_POST['business_pct']??'100',100);$deductible=productionNumber($_POST['deductible_pct']??'100',100);
   $itc=textfield($_POST,'itc_status',12,true);if(!in_array($itc,['review','eligible','ineligible'],true))throw new InvalidArgumentException('매입세액 검토 상태를 선택하세요.');
   $method=textfield($_POST,'payment_method',30);$description=textfield($_POST,'description',5000);$ocr=textfield($_POST,'ocr_text',20000);
   $splitCount=$id?(int)query('SELECT COUNT(*) FROM expense_splits WHERE expense_id=?',[$id])->fetchColumn():0;
   if($splitCount>1&&($subtotal!==(int)$before['subtotal_cents']||$hst!==(int)$before['hst_cents']||$other!==(int)$before['other_tax_cents']||$total!==(int)$before['total_cents']||$category!==(int)$before['category_id']))throw new InvalidArgumentException('여러 항목으로 분할된 엑셀 비용의 금액·분류는 이 화면에서 바꾸지 않습니다. 취소 후 정확한 항목으로 다시 입력하세요. 증빙·사업 비율·검토 상태는 수정할 수 있습니다.');
   $values=[$date,$vendor,$category,$description,$subtotal,$hst,$other,$total,$business,$itc,$deductible,$method,$ocr];
   if($id)query('UPDATE expenses SET expense_date=?,vendor=?,category_id=?,description=?,subtotal_cents=?,hst_cents=?,other_tax_cents=?,total_cents=?,business_pct=?,itc_status=?,deductible_pct=?,payment_method=?,ocr_text=?,version=version+1 WHERE id=?',[...$values,$id]);
   else {query('INSERT INTO expenses (expense_date,vendor,category_id,description,subtotal_cents,hst_cents,other_tax_cents,total_cents,business_pct,itc_status,deductible_pct,payment_method,ocr_text,request_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$values,$key,$_SESSION['user_id']]);$id=(int)db()->lastInsertId();}
   if($id&&$splitCount===1)query('UPDATE expense_splits SET category_id=?,net_cents=? WHERE expense_id=?',[$category,$subtotal,$id]);
   $attachment=expenseFile($_FILES['receipt']??[]);if($attachment)query('INSERT INTO expense_receipts (expense_id,storage_key,mime,original_name,sha256) VALUES (?,?,?,?,?)',[$id,...array_values($attachment)]);
   $detail=['action'=>$before?'edit':'create','before'=>$before,'after'=>query('SELECT * FROM expenses WHERE id=?',[$id])->fetch(),'receipt'=>$attachment];
  }
  query('INSERT INTO expense_events (expense_id,actor_id,detail) VALUES (?,?,?)',[$id,$_SESSION['user_id'],json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);db()->commit();
 } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($attachment){$f=expenseReceiptDirectory().'/'.$attachment['storage_key'];if(is_file($f))unlink($f);}throw $e;}
 $_SESSION['flash']=$action==='expense_void'?'비용을 취소했습니다. 기록과 영수증은 보존합니다.':'비용과 영수증을 저장했습니다.';redirect('/?page=expense-edit&id='.$id);
}
function expenseAllocate(int $total,array $weights): array {
 $sum=array_sum($weights);if($sum<=0)return array_fill(0,count($weights),0);$result=[];$cumulative=0;$allocated=0;foreach($weights as $w){$cumulative+=$w;$target=intdiv($total*$cumulative,$sum);$result[]=$target-$allocated;$allocated=$target;}return $result;
}
function expenseReport(int $year): array {
 $rows=query('SELECT e.*,c.name category,c.capital FROM expenses e JOIN expense_categories c ON c.id=e.category_id WHERE e.voided=0 AND e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date,e.id',[$year.'-01-01',$year.'-12-31'])->fetchAll();$categories=[];$monthly=array_fill(1,12,['paid'=>0,'hst'=>0,'itc'=>0,'count'=>0]);$totals=['paid'=>0,'hst'=>0,'itc'=>0,'operating'=>0,'capital'=>0,'review'=>0];$splits=[];
 if($rows){$ids=array_column($rows,'id');foreach(query('SELECT s.*,c.name category,c.capital FROM expense_splits s JOIN expense_categories c ON c.id=s.category_id WHERE expense_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY s.id',$ids)->fetchAll() as $part)$splits[$part['expense_id']][]=$part;}
 foreach($rows as &$r){$b=(float)$r['business_pct']/100;$itc=$r['itc_status']==='eligible'?(int)round($r['hst_cents']*$b):0;$base=(int)round(($r['total_cents']*$b-$itc)*(float)$r['deductible_pct']/100);$totals['paid']+=(int)$r['total_cents'];$totals['hst']+=(int)$r['hst_cents'];$totals['itc']+=$itc;$month=(int)substr($r['expense_date'],5,2);$monthly[$month]['paid']+=(int)$r['total_cents'];$monthly[$month]['hst']+=(int)$r['hst_cents'];$monthly[$month]['itc']+=$itc;$monthly[$month]['count']++;if($r['itc_status']==='review')$totals['review']++;
  $parts=$splits[$r['id']]??[['category_id'=>$r['category_id'],'category'=>$r['category'],'capital'=>$r['capital'],'net_cents'=>max(1,(int)$r['subtotal_cents'])]];$weights=array_column($parts,'net_cents');$paid=expenseAllocate((int)$r['total_cents'],$weights);$hst=expenseAllocate((int)$r['hst_cents'],$weights);$itcs=expenseAllocate($itc,$weights);$bases=expenseAllocate($base,$weights);$r['split_text']=implode(' / ',array_map(fn($p)=>$p['category'].' '.money((int)$p['net_cents']),$parts));
  foreach($parts as $i=>$p){$key=$p['category_id'];if(!isset($categories[$key]))$categories[$key]=['name'=>$p['category'],'capital'=>(bool)$p['capital'],'count'=>0,'paid'=>0,'hst'=>0,'itc'=>0,'base'=>0];$c=&$categories[$key];$c['count']++;$c['paid']+=$paid[$i];$c['hst']+=$hst[$i];$c['itc']+=$itcs[$i];$c['base']+=$bases[$i];$totals[$p['capital']?'capital':'operating']+=$bases[$i];unset($c);}
 }unset($r);
 return ['rows'=>$rows,'categories'=>$categories,'totals'=>$totals,'monthly'=>$monthly];
}
function expenseCsv(): never {
 if(empty($_SESSION['user_id'])){http_response_code(401);exit;}$year=(int)($_GET['year']??date('Y'));if($year<2000||$year>2100){http_response_code(422);exit;}$r=expenseReport($year);
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="expenses-'.$year.'.csv"');$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['날짜','구입처','항목','공급가 CAD','GST/HST CAD','기타 세금 CAD','총액 CAD','사업 사용 %','비용 인정 %','매입세액 검토','메모'],',','"','');
 foreach($r['rows'] as $e){$safe=fn($v)=>preg_match('/^(?:[\t\r\n]|\s*[=+\-@])/u',(string)$v)?"'".$v:$v;fputcsv($f,array_map($safe,[$e['expense_date'],$e['vendor'],$e['split_text'],number_format($e['subtotal_cents']/100,2,'.',''),number_format($e['hst_cents']/100,2,'.',''),number_format($e['other_tax_cents']/100,2,'.',''),number_format($e['total_cents']/100,2,'.',''),$e['business_pct'],$e['deductible_pct'],$e['itc_status'],$e['description']]),',','"','');}fclose($f);exit;
}
