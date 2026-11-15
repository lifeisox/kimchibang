<?php
require_once __DIR__.'/excel-preview.php';
function expenseCategoryMap(): array {return ['Material'=>'재료 구입','Wages'=>'인건비','Advertising'=>'광고','Bank'=>'은행 수수료','Insurance'=>'보험','Meals'=>'식사·접대','Supplies'=>'소모품','Licenses'=>'허가·면허','Professional'=>'전문가 수수료','Rent'=>'임대료','Phone/Internet'=>'전화·인터넷','Maintenance'=>'수리·유지','Utilities'=>'수도·전기·가스','Auto'=>'차량','Lease'=>'리스','Fixed Assets'=>'장비·자산 구입','Others'=>'기타·분류 확인'];}
function expenseWorkbook(string $path,string $filename,int $year): array {
 if(preg_match('/\b(20\d{2})\b/u',$filename,$match)&&(int)$match[1]!==$year)throw new InvalidArgumentException('파일 이름의 연도와 선택한 비용 연도가 다릅니다.');
 $book=new PreviewXlsx($path);$records=[];$ready=0;$held=0;$total=0;$tax=0;$mapping=expenseCategoryMap();$months=['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
 foreach(array_keys($book->sheets) as $sheet){$month=$months[$sheet]??(preg_match('/^'.$year.'-(\d{2})$/D',$sheet,$m)?(int)$m[1]:0);if($month<1||$month>12)continue;$rows=$book->rows($sheet);if(strtolower(previewText(previewValue($rows[3]??[],1)))!=='date')continue;
  $headers=$rows[4]??[];$cols=[];foreach($headers as $col=>$cell){$label=previewText($cell['v']??'');if(isset($mapping[$label]))$cols[$col]=$label;}
  foreach($rows as $rn=>$row){if($rn<5)continue;$rawdate=previewText(previewValue($row,1));if(preg_match('/^(total|sum|합계|소계)$/iu',$rawdate))continue;$amount=previewCents(previewValue($row,2));if($rawdate===''||$amount===null||$amount===0)continue;
   $issues=[];$date=null;
   if(preg_match('/^(\d{1,2})[-\/](\d{1,2})$/D',$rawdate,$m)){$date=sprintf('%04d-%02d-%02d',$year,(int)$m[1],(int)$m[2]);}
   else $date=previewDate(previewValue($row,1),$book->date1904);
   try{if(!$date)throw new InvalidArgumentException();validDate($date);}catch(Throwable){$issues[]='날짜 확인 필요';}
   if($date&&(int)substr($date,0,4)!==$year)$issues[]='파일 연도와 실제 날짜 불일치';if($date&&(int)substr($date,5,2)!==$month)$issues[]='시트 월과 날짜 불일치';
   $hst=previewCents(previewValue($row,3))??0;$net=previewCents(previewValue($row,4));$alloc=[];
   foreach($cols as $col=>$label){$n=previewCents(previewValue($row,$col))??0;if($n<0)$issues[]='음수 항목 확인 필요';if($n)$alloc[]=['category'=>$mapping[$label],'source_category'=>$label,'net'=>$n];}
   if($amount<0||$hst<0||$net===null||$net<0)$issues[]='금액 확인 필요';
   if($net!==null&&$net+$hst!==$amount)$issues[]='Amount/HST/Net 합계 불일치';
   if(!$alloc||array_sum(array_column($alloc,'net'))!==$net)$issues[]='항목별 합계 불일치';
   $vendor=previewText(previewValue($row,22));if($vendor==='')$issues[]='구입처/설명 없음';
   $state=$issues?'held':'ready';if($issues)$held++;else{$ready++;$total+=$amount;$tax+=$hst;}
   $records[]=['ref'=>$sheet.'!'.$rn,'date'=>$date,'vendor'=>$vendor,'total'=>$amount,'hst'=>$hst,'net'=>$net,'splits'=>$alloc,'issues'=>array_values(array_unique($issues)),'state'=>$state,'raw'=>$row];
  }
 }
 if(!$records)throw new InvalidArgumentException('월별 비용 행을 찾지 못했습니다. 연도와 파일을 확인하세요.');
 return ['year'=>$year,'filename'=>$filename,'sha'=>hash_file('sha256',$path),'rows'=>$records,'ready'=>$ready,'held'=>$held,'total'=>$total,'hst'=>$tax];
}
function expenseImportDirectory(): string {$d=__DIR__.'/../.local/expense-imports';if(!is_dir($d)&&!mkdir($d,0700,true))throw new RuntimeException('가져오기 폴더 생성 실패');return $d;}
function expenseImportJob(string $token): array {
 if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new InvalidArgumentException('가져오기 화면을 다시 준비하세요.');$f=expenseImportDirectory().'/'.$token.'.json';if(!is_file($f))throw new InvalidArgumentException('가져오기 화면을 다시 준비하세요.');$j=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);if(($j['actor']??null)!==(int)$_SESSION['user_id']||($j['expires']??0)<time())throw new InvalidArgumentException('가져오기 준비가 만료되었습니다. 파일을 다시 선택하세요.');return $j;
}
function expenseImportAction(): never {
 if($_POST['action']==='expense_import_preview'){
  $year=positiveInt($_POST['year']??0,2024,2100);$f=$_FILES['workbook']??[];if(($f['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??''))throw new InvalidArgumentException('비용 엑셀 .xlsx를 선택하세요.');
  $name=mb_substr(basename($f['name']),0,200);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new InvalidArgumentException('.xlsx 파일만 읽습니다.');$temp=expenseImportDirectory().'/upload-'.bin2hex(random_bytes(16)).'.zip';if(!move_uploaded_file($f['tmp_name'],$temp))throw new RuntimeException('엑셀 임시 보관 실패');chmod($temp,0600);try{$r=expenseWorkbook($temp,$name,$year);$original=expenseImportDirectory().'/'.$r['sha'].'.xlsx';if(!is_file($original)){if(!copy($temp,$original))throw new RuntimeException('원본 보관 실패');chmod($original,0600);}}finally{if(is_file($temp))unlink($temp);}$token=bin2hex(random_bytes(24));$j=['actor'=>(int)$_SESSION['user_id'],'expires'=>time()+3600,'report'=>$r];$path=expenseImportDirectory().'/'.$token.'.json';file_put_contents($path,json_encode($j,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);redirect('/?page=expense-import&job='.$token);
 }
 if(($_POST['confirm']??'')!=='ready')throw new InvalidArgumentException('확인된 비용만 추가하는 것에 체크하세요.');$j=expenseImportJob(textfield($_POST,'job',48,true));$r=$j['report'];if(!$r['ready'])throw new InvalidArgumentException('가져올 수 있는 비용이 없습니다.');
 if(query('SELECT id FROM expense_import_batches WHERE year=?',[$r['year']])->fetch())throw new InvalidArgumentException('이 연도는 이미 가져왔습니다. 재가져오기로 중복 입력하지 않고 보류 행은 직접 추가하세요.');
 require_once __DIR__.'/import-backup.php';$backup=writeImportBackup();db()->beginTransaction();
 try{
  $summary=['ready'=>$r['ready'],'held'=>$r['held'],'total'=>$r['total'],'backup'=>$backup];query('INSERT INTO expense_import_batches (year,source_sha,filename,summary,created_by) VALUES (?,?,?,?,?)',[$r['year'],$r['sha'],$r['filename'],json_encode($summary),$_SESSION['user_id']]);$bid=(int)db()->lastInsertId();$cats=array_column(query('SELECT id,name FROM expense_categories')->fetchAll(),'id','name');
  foreach($r['rows'] as $row){$eid=null;if($row['state']==='ready'){$cat=$cats[$row['splits'][0]['category']];$key=substr(hash('sha256',$r['sha'].'|'.$row['ref']),0,32);$notes='엑셀 '.$row['ref'].' · 원본 Description: '.$row['vendor'];
    query("INSERT INTO expenses (request_key,expense_date,vendor,category_id,description,subtotal_cents,hst_cents,total_cents,business_pct,itc_status,deductible_pct,ocr_text,created_by) VALUES (?,?,?,?,?,?,?,?,100,'review',100,'',?)",[$key,$row['date'],mb_substr($row['vendor'],0,150),$cat,$notes,$row['net'],$row['hst'],$row['total'],$_SESSION['user_id']]);$eid=(int)db()->lastInsertId();foreach($row['splits'] as $part)query('INSERT INTO expense_splits (expense_id,category_id,net_cents) VALUES (?,?,?)',[$eid,$cats[$part['category']],$part['net']]);query('INSERT INTO expense_events (expense_id,actor_id,detail) VALUES (?,?,?)',[$eid,$_SESSION['user_id'],json_encode(['action'=>'import','source'=>$row['ref'],'source_sha'=>$r['sha']],JSON_UNESCAPED_UNICODE)]);
   }
   query('INSERT INTO expense_import_rows (batch_id,source_ref,expense_id,state,snapshot) VALUES (?,?,?,?,?)',[$bid,$row['ref'],$eid,$row['state'],json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  }db()->commit();
 }catch(Throwable $e){db()->rollBack();throw $e;}
 $_SESSION['flash']=$r['ready'].'건 비용 추가 완료 · '.$r['held'].'건은 보류. 기존 비용·고객·주문은 유지했습니다.';redirect('/?page=expenses&year='.$r['year']);
}
