<?php
if(PHP_SAPI!=='cli')exit(1);require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/business-mail.php';
if(!businessEnabled())exit("판매 관리가 활성화되지 않았습니다.\n");
if(!in_array('--run',$argv,true))exit("점검 모드: 자동 정산은 --run, 실제 이메일은 --run --send 및 BIZ_MAIL_ENABLED=1 설정이 필요합니다.\n");
$lock=databaseWriteLock();try{
 if(date('N')==='1'&&(int)date('G')>=8){$client=query("SELECT id FROM biz_clients WHERE code='OK'")->fetchColumn();$start=date('Y-m-d',strtotime('monday last week'));$end=date('Y-m-d',strtotime('sunday last week'));
 if($client&&bizReportsComplete((int)$client,$start,$end)){db()->beginTransaction();try{bizIssue((int)$client,$start,$end,'consigned');db()->commit();echo "OK Mart 주간 정산 확인 완료\n";}catch(Throwable $e){db()->rollBack();fwrite(STDERR,$e->getMessage()."\n");}}else fwrite(STDERR,"OK Mart 일일 마감 누락: 자동 정산을 보류했습니다.\n");}
 if(in_array('--send',$argv,true)){
  if(getenv('BIZ_MAIL_ENABLED')!=='1')exit("메일 발송 설정이 꺼져 있습니다.\n");
  foreach(query("SELECT * FROM biz_invoices WHERE mail_state='pending' ORDER BY id LIMIT 20")->fetchAll() as $i){$s=json_decode($i['snapshot'],true);$to=query('SELECT email FROM biz_clients WHERE id=?',[$i['client_id']])->fetchColumn();$from=query('SELECT email FROM biz_settings WHERE id=1')->fetchColumn();if(!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL)||!$s['seller']['gst']||!$s['seller']['address']){query("UPDATE biz_invoices SET mail_error='거래처 이메일·발신 정보 확인 필요' WHERE id=?",[$i['id']]);continue;}
  query("UPDATE biz_invoices SET mail_state='sending',mail_error='' WHERE id=? AND mail_state='pending'",[$i['id']]);$ok=mail($to,'Rodem Namu Invoice RN'.substr($i['issued'],0,4).'-'.str_pad((string)$i['id'],6,'0',STR_PAD_LEFT),bizMailBody($i),['From'=>$from,'Reply-To'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/html; charset=UTF-8']);query('UPDATE biz_invoices SET mail_state=?,mail_error=? WHERE id=?',[$ok?'accepted':'failed',$ok?'메일 서버 접수 · 실제 도착은 확인 필요':'메일 서버가 발송을 거절했습니다.',$i['id']]);}
 }
}finally{if($lock)databaseWriteLock(true);}
