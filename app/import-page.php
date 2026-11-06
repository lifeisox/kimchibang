<?php
require_once __DIR__.'/excel-import.php';
$plan=null;$problem='';
try {if(is_string($_GET['job']??null))$plan=loadImportJob($_GET['job'],(int)$_SESSION['user_id']);}catch(InvalidArgumentException $e){$problem=$e->getMessage();}
?>
<section class="panel"><h2>확인한 엑셀을 실제 자료로 저장하기</h2><p>고객과 주문을 함께 저장합니다. 실행 직전에 비공개 데이터베이스 백업을 만들며, 같은 원본 행은 중복으로 등록하지 않습니다.</p><p>과거 주문은 당시 단가를 유지하는 조회 전용 자료입니다. 기록에 없는 입금일·배송일·결제 방법은 미확인으로 남깁니다.</p>
<?php if($problem): ?><p class="alert error"><?=e($problem)?></p><?php endif ?>
<?php if(!$plan): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="import_prepare"><button class="button">저장할 내용 확인하기</button></form>
<?php else: $c=$plan['counts']; ?>
<div class="notice"><strong>아직 저장하지 않았습니다.</strong><p>새 고객 <?=$c['new_customers']?>명 · 새 주문 <?=$c['new_orders']?>건 · 기존 주문 건너뛰기 <?=$c['skipped_orders']?>건</p><p>제외 <?=$c['excluded_orders']?>건 · 보류 고객 <?=$c['held_customers']?>명 / 주문 <?=$c['held_orders']?>건</p></div>
<div class="table-wrap"><table><tbody><?php foreach(['kimchi_sales_cents'=>'김치 매출','delivery_fee_cents'=>'배달비','other_sales_cents'=>'기타 판매 (김밥)','other_charges_cents'=>'기타 청구','additional_income_cents'=>'동의받은 추가 수입','income_total_cents'=>'전체 원본 금액 합계'] as $k=>$label): ?><tr><th><?=$label?></th><td><?=money($c[$k])?></td></tr><?php endforeach ?></tbody></table></div><p class="hint">위 합계는 이 원본의 가져오기 대상 전체 기준입니다. 추가 수입은 김치 매출과 고객 예치금에서 분리합니다.</p>
<form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="import_execute"><input type="hidden" name="job" value="<?=e($_GET['job'])?>"><label><input type="checkbox" name="confirm_import" value="yes" required> 위 내용을 확인했으며 고객·주문을 실제로 저장합니다.</label><button class="button">백업 후 실제 가져오기</button></form><p class="hint">준비 화면은 1시간 동안 유효합니다. 자료가 바뀌면 다시 준비해야 합니다.</p>
<?php endif ?></section>
<?php $batches=query('SELECT b.id,b.created_at,b.summary,b.backup_name,s.filename FROM import_batches b JOIN import_sources s ON s.id=b.source_id ORDER BY b.id DESC LIMIT 20')->fetchAll();if($batches): ?>
<section class="panel"><h2>가져오기 실행 기록</h2><?php foreach($batches as $b): ?><p>#<?=$b['id']?> · <?=e($b['created_at'])?> · <?=e($b['filename'])?><small>비공개 백업: <?=e($b['backup_name'])?></small></p><?php endforeach ?></section><?php endif ?>
