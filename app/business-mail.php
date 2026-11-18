<?php
require_once __DIR__.'/business.php';
function bizMailBody(array $invoice): string {
 $s=json_decode($invoice['snapshot'],true,512,JSON_THROW_ON_ERROR);$h='<html><body><h2>'.e($s['seller']['seller']).' — Invoice RN'.substr($invoice['issued'],0,4).'-'.str_pad((string)$invoice['id'],6,'0',STR_PAD_LEFT).'</h2><p>'.e($s['seller']['address']).'<br>GST/HST '.e($s['seller']['gst']).'</p><p>Bill to: '.e($s['client']['name']).'<br>Period: '.e($invoice['period_start'].' — '.$invoice['period_end']).'</p><table border="1" cellpadding="5" cellspacing="0"><tr><th>Product</th><th>Qty</th><th>Unit CAD</th><th>Discount</th><th>Net</th><th>HST</th></tr>';
 foreach($s['lines'] as $l)$h.='<tr><td>'.e($l['ko'].' / '.$l['en'].' '.$l['pack']).'</td><td>'.$l['qty'].'</td><td>'.money($l['unit']).'</td><td>'.money($l['discount']).'</td><td>'.money($l['net']).'</td><td>'.money($l['hst']).'</td></tr>';
 return $h.'</table><p>Subtotal CAD '.money($s['net']).'<br>HST '.money($s['hst']).'<br><strong>Total CAD '.money($s['total']).'</strong></p><p>Payment: eTransfer / Cash</p></body></html>';
}
function bizReportsComplete(int $client,string $start,string $end): bool {
 $d=query("SELECT d.* FROM biz_deliveries d JOIN biz_products p ON p.id=d.product_id WHERE d.client_id=? AND d.sent=1 AND d.day<=? AND p.category<>'kimchi'",[$client,$end])->fetchAll();
 foreach($d as $batch){$from=max($start,$batch['day']);for($day=new DateTimeImmutable($from);$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$used=(int)query('SELECT COALESCE(SUM(qty),0) FROM biz_sales WHERE delivery_id=? AND day<?',[$batch['id'],$date])->fetchColumn()+(int)query('SELECT COALESCE(SUM(qty),0) FROM biz_returns WHERE delivery_id=? AND day<?',[$batch['id'],$date])->fetchColumn();if($used<(int)$batch['delivered']&&!query('SELECT day FROM biz_reports WHERE delivery_id=? AND day=?',[$batch['id'],$date])->fetchColumn())return false;}}
 return true;
}
