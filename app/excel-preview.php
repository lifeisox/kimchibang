<?php
declare(strict_types=1);

/** Read OOXML data only. No macros, formulas, external links or workbook code are executed. */
final class PreviewXlsx {
    private PharData $zip;
    private array $strings=[];
    public array $sheets=[];
    public bool $date1904=false;
    public function __construct(string $path) {
        if(filesize($path)>8*1024*1024 || filesize($path)<4) throw new InvalidArgumentException('8MB 이하의 .xlsx 파일을 선택해 주세요.');
        $handle=fopen($path,'rb');$signature=fread($handle,4);fclose($handle);
        if($signature!=="PK\x03\x04") throw new InvalidArgumentException('올바른 .xlsx 파일이 아닙니다. 엑셀에서 .xlsx 형식으로 다시 저장해 주세요.');
        try {
            $this->zip=new PharData($path,0,null,Phar::ZIP);
            $count=$size=0;
            foreach(new RecursiveIteratorIterator($this->zip) as $entry) {
                if(++$count>2000 || $entry->isLink() || $entry->getSize()>20*1024*1024 || ($size+=$entry->getSize())>80*1024*1024) throw new InvalidArgumentException('파일 안의 자료가 너무 큽니다. 연도별로 나누어 주세요.');
            }
            if(isset($this->zip['xl/sharedStrings.xml'])) {
                $xml=$this->xml('xl/sharedStrings.xml');
                foreach($xml->xpath('//*[local-name()="si"]') as $si) {
                    $text='';foreach($si->xpath('.//*[local-name()="t"]') as $t) $text.=(string)$t;
                    if(count($this->strings)>=20000 || strlen($text)>20000) throw new InvalidArgumentException('문자 자료가 너무 많거나 깁니다.');
                    $this->strings[]=$text;
                }
            }
            $relations=[];$rels=$this->xml('xl/_rels/workbook.xml.rels');
            foreach($rels->children() as $rel) {
                if((string)$rel['TargetMode']==='External') continue;
                $target=str_replace('\\','/',(string)$rel['Target']);
                $target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
                if(str_contains($target,'..') || str_contains($target,':')) throw new InvalidArgumentException('지원하지 않는 시트 연결입니다.');
                $relations[(string)$rel['Id']]=$target;
            }
            $book=$this->xml('xl/workbook.xml');
            $props=$book->xpath('//*[local-name()="workbookPr"]');
            $this->date1904=isset($props[0]) && in_array(strtolower((string)$props[0]['date1904']),['1','true'],true);
            foreach($book->xpath('//*[local-name()="sheet"]') as $sheet) {
                if(count($this->sheets)>=20) throw new InvalidArgumentException('시트는 20개 이하로 나누어 주세요.');
                $id='';foreach($sheet->getNamespaces(true) as $ns) {if(str_contains($ns,'relationships')) $id=(string)$sheet->attributes($ns)['id'];}
                if(!isset($relations[$id])) throw new InvalidArgumentException('시트 연결 정보를 읽을 수 없습니다.');
                $this->sheets[(string)$sheet['name']]=$relations[$id];
            }
        } catch(InvalidArgumentException $e) {throw $e;}
        catch(Throwable $e) {throw new InvalidArgumentException('엑셀 내부 구조를 읽을 수 없습니다. .xlsx로 다시 저장해 주세요.');}
    }
    private function xml(string $name): SimpleXMLElement {
        if(!isset($this->zip[$name]) || $this->zip[$name]->getSize()>20*1024*1024) throw new InvalidArgumentException('엑셀에 필요한 자료가 없거나 너무 큽니다.');
        $data=$this->zip[$name]->getContent();
        if(stripos($data,'<!DOCTYPE')!==false || stripos($data,'<!ENTITY')!==false) throw new InvalidArgumentException('외부 XML 정의가 있는 파일은 분석할 수 없습니다.');
        $old=libxml_use_internal_errors(true);
        try {$xml=simplexml_load_string($data,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);}
        finally {libxml_clear_errors();libxml_use_internal_errors($old);}
        if($xml===false) throw new InvalidArgumentException('엑셀 XML 자료가 올바르지 않습니다.');
        return $xml;
    }
    public function rows(string $sheet): array {
        $rows=[];$cells=0;$xml=$this->xml($this->sheets[$sheet]);
        foreach($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $r=(int)$row['r'];
            if($r<1 || $r>10000) throw new InvalidArgumentException('한 시트의 행 수는 10,000행 이하여야 합니다.');
            foreach($row->children() as $cell) {
                if(++$cells>100000) throw new InvalidArgumentException('한 시트의 셀이 너무 많습니다. 연도별로 나누어 주세요.');
                $ref=(string)$cell['r'];if(!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D',$ref,$m) || (int)$m[2]!==$r) throw new InvalidArgumentException('셀 위치가 올바르지 않습니다.');
                $column=0;foreach(str_split($m[1]) as $ch) $column=$column*26+ord($ch)-64;
                $v=$cell->xpath('./*[local-name()="v"]');$f=$cell->xpath('./*[local-name()="f"]');$inline=$cell->xpath('./*[local-name()="is"]');
                if(!$v && !$f && !$inline) continue;
                if($column>128) throw new InvalidArgumentException('지원 범위(128열)를 넘는 자료가 있습니다.');
                $type=(string)$cell['t'];$value=isset($v[0])?(string)$v[0]:null;
                if($type==='s') {
                    if($value===null || !ctype_digit($value) || !array_key_exists((int)$value,$this->strings)) throw new InvalidArgumentException('문자열 참조가 올바르지 않습니다.');
                    $value=$this->strings[(int)$value];
                } elseif($type==='inlineStr') {
                    $value='';foreach($cell->xpath('./*[local-name()="is"]//*[local-name()="t"]') as $t) $value.=(string)$t;
                } elseif($type==='b') $value=$value==='1';
                if(is_string($value) && strlen($value)>20000) throw new InvalidArgumentException('셀의 글자가 너무 깁니다.');
                $rows[$r][$column]=['v'=>$value,'type'=>$type,'formula'=>isset($f[0])?(string)$f[0]:null,'ref'=>$ref];
            }
        }
        return $rows;
    }
}
function previewValue(array $row,int $col): mixed {return ($row[$col]['type']??'')==='e'?null:($row[$col]['v']??null);}
function previewText(mixed $v): string {return is_string($v)||is_numeric($v)?trim((string)$v):'';}
function previewKey(string $v): string {return mb_strtolower(preg_replace('/\s+/u','',trim($v))??trim($v),'UTF-8');}
function previewNumber(mixed $v): ?float {
    if(is_bool($v) || $v===null || $v==='' || !is_numeric($v)) return null;
    $n=(float)$v;return is_finite($n) && abs($n)<=100000000?$n:null;
}
function previewCents(mixed $v): ?int {$n=previewNumber($v);return $n===null?null:(int)round($n*100,0,PHP_ROUND_HALF_UP);}
function previewFlag(mixed $v): ?bool {
    if(is_bool($v)) return $v;
    if(in_array(strtolower(previewText($v)),['true','1'],true)) return true;
    if(in_array(strtolower(previewText($v)),['false','0'],true)) return false;
    return null;
}
function previewDate(mixed $v,bool $date1904): ?string {
    $n=previewNumber($v);
    if($n!==null) {
        if($n<($date1904?0:1) || $n>100000 || (!$date1904 && (int)$n===60)) return null;
        $base=$date1904?'1904-01-01':((int)$n<60?'1899-12-31':'1899-12-30');
        return (new DateTimeImmutable($base))->modify('+'.(int)$n.' days')->format('Y-m-d');
    }
    if(preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[T ].*)?$/D',previewText($v),$m) && checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return sprintf('%04d-%02d-%02d',$m[1],$m[2],$m[3]);
    return null;
}
function previewColumn(int $n): string {$s='';while($n>0){$n--;$s=chr(65+$n%26).$s;$n=intdiv($n,26);}return $s;}
function previewIssue(array &$record,string $code,string $message): void {$record['issues'][$code]=$message;}
function previewSnapshot(): array {
    $customers=query('SELECT id,name,nickname,phone,address FROM customers ORDER BY id LIMIT 20001')->fetchAll();
    $orders=query('SELECT id,customer_name,order_date,total_cents FROM orders WHERE cancelled=0 ORDER BY id LIMIT 50001')->fetchAll();
    if(count($customers)>20000 || count($orders)>50000) throw new InvalidArgumentException('현재 자료가 미리보기 비교 한도를 넘었습니다. 기간을 나누는 작업이 필요합니다.');
    return ['customers'=>$customers,'orders'=>$orders,'products'=>query('SELECT id,code,name,weight_g,price_cents,active FROM products ORDER BY sort_order,id')->fetchAll()];
}
function buildExcelPreview(string $path,string $filename,array $snapshot): array {
    $book=new PreviewXlsx($path);
    $report=['schema'=>1,'customer_preferences_schema'=>1,'filename'=>basename($filename),'sha256'=>hash_file('sha256',$path),'generated_at'=>date(DATE_ATOM),'customers'=>[],'orders'=>[],'products'=>[],'ignored_sheets'=>[],'year_summary'=>[],'issue_counts'=>[],'notes'=>[]];
    $customerIndex=[];$existingIndex=[];$products=[];$existingOrders=[];
    foreach($snapshot['products'] as $p) $products[$p['code']]=$p;
    foreach($snapshot['customers'] as $c) {
        foreach(array_unique([$c['name'],$c['nickname']]) as $alias) if(previewKey($alias)!=='') $existingIndex[previewKey($alias)][$c['id']]=$c;
    }
    foreach($snapshot['orders'] as $o) $existingOrders[$o['order_date'].'|'.previewKey($o['customer_name']).'|'.$o['total_cents']][]=$o['id'];
    if(isset($book->sheets['고객명단'])) {
        $rows=$book->rows('고객명단');$headers=[];foreach($rows[1]??[] as $col=>$cell) $headers[previewText($cell['v'])]=$col;
        foreach(['표기성명','아이디','성명','주소'] as $h) if(!isset($headers[$h])) throw new InvalidArgumentException('고객명단 시트의 표기성명·아이디·성명·주소 열을 확인해 주세요.');
        foreach($rows as $r=>$row) {
            if($r<2) continue;
            $name=previewText(previewValue($row,$headers['성명']));$nick=previewText(previewValue($row,$headers['아이디']));
            if($name==='' && $nick==='') continue;
            $display=previewText(previewValue($row,$headers['표기성명']));
            $record=['ref'=>'고객명단!'.$r,'sheet'=>'고객명단','row'=>$r,'name'=>$name,'nickname'=>$nick,'display'=>$display?:($name?:$nick),'phone'=>previewText(previewValue($row,$headers['전화번호']??$headers['연락처']??0)),'area'=>previewText(previewValue($row,$headers['지역']??0)),'source_area'=>previewText(previewValue($row,$headers['지역']??0)),'delivery_preference_raw'=>previewText(previewValue($row,$headers['배달여부']??$headers['배달 여부']??8)),'address'=>previewText(previewValue($row,$headers['주소'])),'notes'=>previewText(previewValue($row,$headers['비고']??0)),'existing_candidates'=>[],'issues'=>[]];
            if($name==='') previewIssue($record,'customer_name_missing','실명이 비어 있어 닉네임을 표시했습니다. 이름 확인이 필요합니다.');
            if($record['address']==='') previewIssue($record,'customer_address_missing','주소가 없습니다. 픽업 고객인지 확인해 주세요.');
            $aliases=array_unique([$name,$nick,$record['display']]);$matches=[];
            foreach($aliases as $alias) {
                $key=previewKey($alias);if($key==='') continue;
                $customerIndex[$key][$record['ref']]=true;
                foreach($existingIndex[$key]??[] as $id=>$candidate) $matches[$id]=$candidate;
            }
            $record['existing_candidates']=array_values($matches);
            if($matches) previewIssue($record,'existing_customer','시스템에 같은 이름·닉네임의 고객이 있습니다. 새 고객으로 중복 등록하지 않도록 확인해 주세요.');
            $report['customers'][$record['ref']]=$record;
        }
        foreach($customerIndex as $refs) if(count($refs)>1) foreach(array_keys($refs) as $ref) previewIssue($report['customers'][$ref],'duplicate_customer','엑셀 안에 같은 이름·닉네임을 쓰는 고객이 여러 명입니다. 자동으로 합치지 않습니다.');
    } else $report['notes'][]='고객명단 시트가 없습니다. 주문의 고객 연결을 별도로 확인해야 합니다.';
    $fingerprints=[];
    foreach($book->sheets as $sheet=>$target) {
        $sheet=(string)$sheet;
        if(!preg_match('/^(?:\d{2}|20\d{2})$/D',$sheet)) {if($sheet!=='고객명단') $report['ignored_sheets'][]=$sheet;continue;}
        $rows=$book->rows($sheet);$headers=$rows[1]??[];$first=previewText(previewValue($headers,1));
        if($first!=='주문일자' || previewText(previewValue($headers,2))!=='주문자') throw new InvalidArgumentException($sheet.' 시트의 주문일자·주문자 열을 확인해 주세요.');
        $noteCol=0;$addressCol=0;
        foreach($headers as $col=>$cell) {if(previewText($cell['v'])==='비고') $noteCol=$col;if(previewText($cell['v'])==='주소') $addressCol=$col;}
        if($noteCol<10) throw new InvalidArgumentException($sheet.' 시트에서 제품 목록 뒤의 비고 열을 찾을 수 없습니다.');
        $seenHeaders=[];$defs=[];
        for($col=9;$col<$noteCol;$col++) {$h=previewText(previewValue($headers,$col));if($h!=='') $seenHeaders[$h]=($seenHeaders[$h]??0)+1;}
        for($col=9;$col<$noteCol;$col++) {
            $h=previewText(previewValue($headers,$col));if($h==='') continue;
            $code=$h==='겉절이'?'겉':$h;
            $ambiguous=($seenHeaders[$h]??0)>1 || $h==='맛김치' || str_starts_with($h,'기타');
            $mapped=!$ambiguous?($products[$code]??null):null;
            $key=$sheet.'!'.previewColumn($col);
            $defs[$col]=['ref'=>$key,'sheet'=>$sheet,'column'=>previewColumn($col),'header'=>$h,'historical_price_cents'=>previewCents(previewValue($rows[2]??[],$col)),'current_product'=>$mapped,'ambiguous'=>$ambiguous,'order_count'=>0,'quantity'=>0,'reason'=>''];
            $defs[$col]['reason']=$ambiguous?(($seenHeaders[$h]??0)>1?'같은 제품 약자가 두 열에 있어 제품 구분이 필요합니다.':'과거 제품의 종류·용량을 확인해야 합니다.'):($mapped?'현재 제품과 연결 후보입니다. 과거 단가는 그대로 보존합니다.':'현재 판매 목록에 없는 과거 제품입니다. 과거 기록용 제품 확인이 필요합니다.');
            $report['products'][$key]=$defs[$col];
        }
        $report['year_summary'][$sheet]=['sheet'=>$sheet,'orders'=>0,'with_products'=>0,'source_total_cents'=>0,'source_total_known'=>0,'recomputed_total_cents'=>0,'recomputed_total_known'=>0,'review'=>0,'date_min'=>null,'date_max'=>null];
        foreach($rows as $r=>$row) {
            if($r<3) continue;
            $rawDate=previewValue($row,1);$customer=previewText(previewValue($row,2));$hasQty=false;
            foreach($defs as $col=>$def) {if(previewValue($row,$col)!==null && previewValue($row,$col)!=='' && previewNumber(previewValue($row,$col))!==0.0) {$hasQty=true;break;}}
            if(!$hasQty && $customer==='' && ($rawDate===null || $rawDate==='') && in_array(previewValue($row,3),[null,''],true) && in_array(previewValue($row,5),[null,''],true)) continue;
            if(count($report['orders'])>=10000) throw new InvalidArgumentException('주문은 한 파일에 10,000건 이하로 나누어 주세요.');
            $date=previewDate($rawDate,$book->date1904);$discountRaw=previewValue($row,4);$discount=$discountRaw===null || $discountRaw===''?0:previewCents($discountRaw);
            $o=['ref'=>$sheet.'!'.$r,'sheet'=>$sheet,'row'=>$r,'date'=>$date,'date_raw'=>previewText($rawDate),'customer'=>$customer,'customer_refs'=>array_keys($customerIndex[previewKey($customer)]??[]),'existing_customer_candidates'=>array_values($existingIndex[previewKey($customer)]??[]),'source_subtotal_cents'=>previewCents(previewValue($row,3)),'source_total_cents'=>previewCents(previewValue($row,5)),'discount_cents'=>$discount,'recomputed_subtotal_cents'=>0,'recomputed_total_cents'=>null,'difference_cents'=>null,'production'=>previewFlag(previewValue($row,6)),'delivered'=>previewFlag(previewValue($row,7)),'paid'=>previewFlag(previewValue($row,8)),'address'=>previewText(previewValue($row,$addressCol)),'notes'=>previewText(previewValue($row,$noteCol)),'items'=>[],'issues'=>[],'duplicate_refs'=>[],'existing_order_ids'=>[]];
            if(!$date) previewIssue($o,'invalid_date','주문 날짜가 없거나 올바르지 않습니다.');
            elseif((int)substr($date,0,4)!==(strlen($sheet)===2?2000+(int)$sheet:(int)$sheet)) previewIssue($o,'sheet_year_mismatch','시트 연도와 실제 주문일의 연도가 다릅니다. 통계는 실제 주문일 기준입니다.');
            if($customer==='') previewIssue($o,'missing_customer','주문자 이름이 없습니다.');
            elseif(count($o['customer_refs'])===0) previewIssue($o,'unmatched_customer','주문자와 일치하는 고객이 고객명단에 없습니다.');
            elseif(count($o['customer_refs'])>1) previewIssue($o,'ambiguous_customer','주문자와 일치하는 고객 후보가 여러 명입니다.');
            if($o['existing_customer_candidates']) previewIssue($o,'existing_customer','현재 시스템에도 같은 이름·닉네임의 고객이 있습니다. 연결 대상을 확인해 주세요.');
            if($discount===null) previewIssue($o,'invalid_discount','할인액을 숫자로 읽을 수 없습니다.');
            elseif($discount<0) previewIssue($o,'negative_discount','할인액이 음수입니다. 추가금 등의 조정인지 확인해 주세요.');
            $known=true;
            foreach($defs as $col=>$def) {
                $raw=previewValue($row,$col);if($raw===null || $raw==='' || $raw==='0') continue;
                $qty=previewNumber($raw);if($qty===0.0) continue;
                $price=$def['historical_price_cents'];$line=$qty===null || $price===null?null:(int)round($qty*$price,0,PHP_ROUND_HALF_UP);
                $o['items'][]=['column'=>$def['column'],'header'=>$def['header'],'quantity'=>$qty,'quantity_raw'=>previewText($raw),'unit_price_cents'=>$price,'line_total_cents'=>$line,'product'=>$def['current_product'],'mapping_reason'=>$def['reason']];
                $report['products'][$def['ref']]['order_count']++;
                if($qty!==null) $report['products'][$def['ref']]['quantity']+=$qty;
                if($qty===null || $qty<0 || floor($qty)!==$qty) previewIssue($o,'invalid_quantity','수량이 숫자가 아니거나 음수·소수입니다. 반품·반 통 판매 등인지 확인해 주세요.');
                if($line===null) {$known=false;previewIssue($o,'missing_price','수량 또는 당시 단가를 숫자로 읽을 수 없어 금액 계산을 보류했습니다.');}
                else $o['recomputed_subtotal_cents']+=$line;
                if($price===0) previewIssue($o,'zero_price','단가가 0인 제품이 있습니다. 실제 금액 확인이 필요합니다.');
                if(!$def['current_product']) previewIssue($o,$def['ambiguous']?'ambiguous_product':'unregistered_product',$def['ambiguous']?'제품 약자·용량이 불분명한 항목이 있습니다.':'현재 판매 목록에 없는 과거 제품이 있습니다.');
            }
            if(!$o['items']) {previewIssue($o,'no_items','제품 수량이 없는 주문 행입니다. 누락·메모 행인지 확인해 주세요.');$known=false;}
            if(!$known) $o['recomputed_subtotal_cents']=null;
            if($known && $discount!==null) $o['recomputed_total_cents']=$o['recomputed_subtotal_cents']-$discount;
            if($o['source_subtotal_cents']===null || $o['source_total_cents']===null) previewIssue($o,'missing_excel_amount','엑셀 합계 계산 결과가 비어 있습니다. 엑셀에서 다시 계산·저장한 뒤 확인할 수 있습니다.');
            if($o['source_total_cents']!==null && $o['recomputed_total_cents']!==null) {
                $o['difference_cents']=$o['recomputed_total_cents']-$o['source_total_cents'];
                if($o['difference_cents']!==0) previewIssue($o,'amount_mismatch','당시 단가·수량·할인으로 계산한 금액과 엑셀 실제주문 금액이 다릅니다.');
            }
            if($o['source_subtotal_cents']!==null && $o['recomputed_subtotal_cents']!==null && $o['source_subtotal_cents']!==$o['recomputed_subtotal_cents']) previewIssue($o,'subtotal_mismatch','제품 합계가 엑셀 주문합계와 다릅니다.');
            if($o['recomputed_total_cents']!==null && $o['recomputed_total_cents']<0) previewIssue($o,'negative_total','최종 금액이 음수입니다. 환불·조정 여부를 확인해 주세요.');
            if($o['production']===null || $o['delivered']===null || $o['paid']===null) previewIssue($o,'unknown_status','생산·배송·입금 중 기록이 비어 있거나 형식을 확인할 수 없는 항목이 있습니다.');
            if($o['paid']===true && $o['delivered']!==true) previewIssue($o,'payment_before_delivery','입금 완료지만 배송 완료가 아닙니다. 선입금 여부를 확인해 주세요.');
            if($date && $customer!=='' && $o['source_total_cents']!==null) {
                $o['existing_order_ids']=$existingOrders[$date.'|'.previewKey($customer).'|'.$o['source_total_cents']]??[];
                if($o['existing_order_ids']) previewIssue($o,'existing_order','현재 시스템에 날짜·고객·금액이 같은 주문이 있습니다. 중복 여부를 확인해 주세요.');
            }
            $fingerprint=hash('sha256',json_encode([$date,previewKey($customer),array_map(fn($i)=>[$i['header'],$i['quantity'],$i['unit_price_cents']],$o['items']),$discount,$o['source_total_cents'],$o['notes']],JSON_UNESCAPED_UNICODE));
            $fingerprints[$fingerprint][]=$o['ref'];$report['orders'][$o['ref']]=$o;
        }
    }
    if(!$report['year_summary']) throw new InvalidArgumentException('연도 시트(예: 24·25·26)의 주문 자료를 찾을 수 없습니다. 기존 엑셀과 같은 열 구조를 사용해 주세요.');
    foreach($fingerprints as $refs) if(count($refs)>1) foreach($refs as $ref) {$report['orders'][$ref]['duplicate_refs']=array_values(array_diff($refs,[$ref]));previewIssue($report['orders'][$ref],'duplicate_order','날짜·고객·제품·금액·메모가 같은 주문 행이 있습니다. 실제 반복 주문인지 확인해 주세요.');}
    foreach($report['orders'] as $o) {
        $y=&$report['year_summary'][$o['sheet']];$y['orders']++;if($o['items']) $y['with_products']++;
        if($o['source_total_cents']!==null){$y['source_total_known']++;$y['source_total_cents']+=$o['source_total_cents'];}
        if($o['recomputed_total_cents']!==null){$y['recomputed_total_known']++;$y['recomputed_total_cents']+=$o['recomputed_total_cents'];}
        if($o['issues']) $y['review']++;
        if($o['date']) {$y['date_min']=$y['date_min']===null?$o['date']:min($y['date_min'],$o['date']);$y['date_max']=$y['date_max']===null?$o['date']:max($y['date_max'],$o['date']);}
        unset($y);
    }
    $report['summary']=['customers'=>count($report['customers']),'orders'=>count($report['orders']),'orders_with_products'=>0,'customer_review'=>0,'order_review'=>0,'amount_comparable'=>0,'amount_matches'=>0,'amount_mismatches'=>0];
    foreach(['customers','orders'] as $group) foreach($report[$group] as $record) {
        if($record['issues']) $report['summary'][$group==='customers'?'customer_review':'order_review']++;
        foreach($record['issues'] as $code=>$message) {$key=$group.':'.$code;$report['issue_counts'][$key]??=['group'=>$group,'code'=>$code,'message'=>$message,'count'=>0];$report['issue_counts'][$key]['count']++;}
        if($group==='orders') {
            if($record['items']) $report['summary']['orders_with_products']++;
            if($record['difference_cents']!==null){$report['summary']['amount_comparable']++;$report['summary'][$record['difference_cents']===0?'amount_matches':'amount_mismatches']++;}
        }
    }
    uasort($report['issue_counts'],fn($a,$b)=>$b['count']<=>$a['count']);
    return $report;
}
