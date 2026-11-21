<?php
function receiptRule(int $supplier): array {return query('SELECT * FROM bookkeeping_receipt_rules WHERE supplier_id=?',[$supplier])->fetch()?:[];}
function receiptInferLabel(string $text,int $amount,string $field): string {
 $labels=[];foreach(preg_split('/\R/u',$text) as $line){if(!preg_match('/^(.*?)(?:\$\s*)?(\d[\d,]*\.\d{2})(?!\d)\s*$/u',trim($line),$m))continue;
 $label=trim($m[1]," \t:$");if(mb_strlen($label)>100||!preg_match('/\pL/u',$label)||preg_match('/subtotal|sub total|number|registration|#|discount|saving/i',$label))continue;
 if($field==='total'&&!preg_match('/total|amount|balance|payable|fuel sale/i',$label))continue;
 if($field==='hst'&&!preg_match('/\b(hst|gst|tvh|tps)\b/i',$label))continue;
 if((int)round((float)str_replace(',','',$m[2])*100)===$amount)$labels[mb_strtolower($label)]=$label;
 }return count($labels)===1?(string)reset($labels):'';
}
function saveReceiptLearning(int $supplier,int $expense,string $ocr,int $total,int $hst): ?array {
 if(($_POST['remember_receipt']??'')!=='1'&&($_POST['forget_receipt_rule']??'')!=='1')return null;
 $before=receiptRule($supplier);$expected=positiveInt($_POST['receipt_rule_version']??0,0,PHP_INT_MAX);if($expected!==(int)($before['version']??0))throw new InvalidArgumentException('이 구매처의 읽기 규칙이 변경되었습니다. 새로고침한 뒤 저장하세요.');
 if(($_POST['forget_receipt_rule']??'')==='1'){$totalLabel='';$hstLabel='';$zero=0;}
 else{$totalLabel=textfield($_POST,'receipt_total_label',100);$hstLabel=textfield($_POST,'receipt_hst_label',100);$totalLabel=$totalLabel!==''?$totalLabel:receiptInferLabel($ocr,$total,'total');$hstLabel=$hstLabel!==''?$hstLabel:receiptInferLabel($ocr,$hst,'hst');$zero=($_POST['receipt_no_hst_zero']??'')==='1'?1:0;

 foreach([$totalLabel,$hstLabel] as $label)if($label!==''&&!preg_match('/\pL/u',$label))throw new InvalidArgumentException('읽기 규칙에는 Total, HST 같은 표기를 입력하세요.');
 if($totalLabel===''&&$hstLabel===''&&!$zero&&!$before)return null;
 }
 query('INSERT INTO bookkeeping_receipt_rules (supplier_id,total_label,hst_label,missing_hst_zero,source_expense_id,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_label=VALUES(total_label),hst_label=VALUES(hst_label),missing_hst_zero=VALUES(missing_hst_zero),source_expense_id=VALUES(source_expense_id),updated_by=VALUES(updated_by),version=version+1',[$supplier,$totalLabel,$hstLabel,$zero,$expense,$_SESSION['user_id']]);
 return ['before'=>$before,'after'=>receiptRule($supplier)];
}

function saveReceiptLogo(int $supplier,int $expense): ?array {
 if(($_POST['forget_logo']??'')==='1'){$before=query('SELECT id,fingerprint,region FROM bookkeeping_logo_templates WHERE supplier_id=?',[$supplier])->fetchAll();query('DELETE FROM bookkeeping_logo_templates WHERE supplier_id=?',[$supplier]);return ['action'=>'forget','before'=>$before];}
 if(($_POST['remember_logo']??'')!=='1')return null;
 $hash=textfield($_POST,'logo_fingerprint',128);$region=textfield($_POST,'logo_region',200);if($hash===''&&$region==='')return null;
 if(!preg_match('/^[a-f0-9]{128}$/D',$hash))throw new InvalidArgumentException('로고 영역을 다시 선택하세요.');
 $r=json_decode($region,true);if(!is_array($r)||count($r)!==4)throw new InvalidArgumentException('로고 영역을 다시 선택하세요.');foreach(['x','y','w','h'] as $k)if(!isset($r[$k])||!is_numeric($r[$k])||!is_finite((float)$r[$k])||(float)$r[$k]<0||(float)$r[$k]>1)throw new InvalidArgumentException('로고 영역이 올바르지 않습니다.');if($r['w']<.01||$r['h']<.005||$r['x']+$r['w']>1.001||$r['y']+$r['h']>1.001)throw new InvalidArgumentException('로고 영역을 다시 선택하세요.');
 if((int)query('SELECT COUNT(*) FROM bookkeeping_logo_templates WHERE supplier_id=?',[$supplier])->fetchColumn()>=10&&!query('SELECT id FROM bookkeeping_logo_templates WHERE supplier_id=? AND fingerprint=?',[$supplier,$hash])->fetchColumn())throw new InvalidArgumentException('구매처별 로고는 최대 10개입니다. 기존 로고 기억을 초기화하세요.');
 query('INSERT INTO bookkeeping_logo_templates (supplier_id,fingerprint,region,source_expense_id,created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE region=VALUES(region),source_expense_id=VALUES(source_expense_id)',[$supplier,$hash,json_encode($r),$expense,$_SESSION['user_id']]);return ['action'=>'remember','fingerprint'=>$hash,'region'=>$r];
}
