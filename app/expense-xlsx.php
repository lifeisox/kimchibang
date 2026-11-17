<?php
function expenseXlsxBytes(array $rows,int $year): string {
 $xml=fn($v)=>htmlspecialchars(preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u','',(string)$v),ENT_XML1|ENT_QUOTES,'UTF-8');
 $files=[];$sheets='';$rels='';$types='';
 for($m=1;$m<=12;$m++){
  $data=[['Date','구매처','Account','Amount CAD','HST CAD','Other Tax CAD','Net Amount CAD','메모']];$sum=[0,0,0,0];
  foreach($rows as $r){if((int)substr($r['expense_date'],5,2)!==$m)continue;$nums=array_map(fn($k)=>(int)$r[$k]/100,['total_cents','hst_cents','other_tax_cents','subtotal_cents']);foreach($nums as $i=>$n)$sum[$i]+=$n;$data[]=[$r['expense_date'],$r['supplier_name'],($r['account_text']??$r['category']??''),...$nums,$r['description']];}
  $data[]=['합계','','',...$sum,''];$body='';foreach($data as $ri=>$row){$body.='<row r="'.($ri+1).'">';foreach($row as $ci=>$v){$ref=chr(65+$ci).($ri+1);$body.=is_float($v)||is_int($v)?'<c r="'.$ref.'" s="1"><v>'.number_format($v,2,'.','').'</v></c>':'<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$xml($v).'</t></is></c>';}$body.='</row>';}
  $files['xl/worksheets/sheet'.$m.'.xml']='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="3" width="28" customWidth="1"/><col min="4" max="7" width="18" customWidth="1"/><col min="8" max="8" width="55" customWidth="1"/></cols><sheetData>'.$body.'</sheetData></worksheet>';
  $sheets.='<sheet name="'.$m.'월" sheetId="'.$m.'" r:id="rId'.$m.'"/>';$rels.='<Relationship Id="rId'.$m.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$m.'.xml"/>';$types.='<Override PartName="/xl/worksheets/sheet'.$m.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
 }
 $files['xl/workbook.xml']='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>';
 $files['xl/_rels/workbook.xml.rels']='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
 $files['xl/styles.xml']='<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf xfId="0"/><xf xfId="0" numFmtId="4" applyNumberFormat="1"/></cellXfs></styleSheet>';
 $files['_rels/.rels']='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
 $files['[Content_Types].xml']='<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>';
 // Stored ZIP entries avoid a server dependency on ZipArchive.
 $zip='';$central='';foreach($files as $name=>$body){$len=strlen($body);$crc=crc32($body);$offset=strlen($zip);$zip.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,0,$crc,$len,$len,strlen($name),0).$name.$body;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,0,$crc,$len,$len,strlen($name),0,0,0,0,0,$offset).$name;}
 return $zip.$central.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($central),strlen($zip),0);
}
function expenseXlsx(): never {
 if(empty($_SESSION['user_id'])){http_response_code(401);exit;}$year=filter_var($_GET['year']??date('Y'),FILTER_VALIDATE_INT);if(!$year||$year<2000||$year>2100){http_response_code(422);exit;}
 $bytes=expenseXlsxBytes(expenseReport($year)['rows'],$year);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="bookkeeping-'.$year.'.xlsx"');echo $bytes;exit;
}
