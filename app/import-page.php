<?php
require_once __DIR__.'/excel-import.php';
$plan=null;$problem='';
try {if(is_string($_GET['job']??null))$plan=loadImportJob($_GET['job'],(int)$_SESSION['user_id']);}catch(InvalidArgumentException $e){$problem=$e->getMessage();}
?>
<section class="panel"><h2>확인한 엑셀을 실제 자료로 저장하기</h2><p>기존 고객·주문·입금·이전 가져오기 내역을 모두 삭제한 뒤 선택한 엑셀로 다시 채웁니다. 실행 직전에 전체 DB를 비공개 백업하며 오류가 나면 삭제와 저장을 함께 되돌립니다. 로그인 계정·로고·판매 제품과 가격 설정은 유지합니다.</p><p>가져온 주문은 당시 제품과 금액을 유지합니다. 미완료 전달과 입금은 주문 상세에서 각각 처리할 수 있습니다. 기록에 없는 입금일·배송일·결제 방법은 새로 입력하기 전까지 미확인으로 남깁니다.</p>
<?php if($problem): ?><p class="alert error"><?=e($problem)?></p><?php endif ?>
<?php if(!$plan && ($report['summary']['customer_review']||$report['summary']['order_review'])): ?><div class="notice">아직 확인이 필요한 고객 <?=$report['summary']['customer_review']?>명 / 주문 <?=$report['summary']['order_review']?>건이 있습니다. <a href="/?page=excel-preview&tab=issues">확인할 항목 보기</a></div><?php endif ?>
<?php if(!$plan): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="import_prepare"><button class="button">저장할 내용 확인하기</button></form>
<?php else: $c=$plan['counts']; ?>
<div class="notice"><strong>아직 삭제하거나 저장하지 않았습니다.</strong><?php if(($plan['mode']??'')==='replace'): ?><p>삭제 대상: 기존 고객 <?=$plan['delete_counts']['customers']?>명 · 주문 <?=$plan['delete_counts']['orders']?>건 · 입금 <?=$plan['delete_counts']['payments']?>건 및 관련 이력</p><?php endif ?><p>새 고객 <?=$c['new_customers']?>명 · 새 주문 <?=$c['new_orders']?>건 · 기존 주문 건너뛰기 <?=$c['skipped_orders']?>건</p><p>제외 <?=$c['excluded_orders']?>건 · 보류 고객 <?=$c['held_customers']?>명 / 주문 <?=$c['held_orders']?>건</p></div>
<div class="table-wrap"><table><tbody><?php foreach(['kimchi_sales_cents'=>'판매금액','delivery_fee_cents'=>'배송비','other_charges_cents'=>'기타수입','income_total_cents'=>'전체 원본 금액 합계'] as $k=>$label): ?><tr><th><?=$label?></th><td><?=money($c[$k])?></td></tr><?php endforeach ?></tbody></table></div><p class="hint">위 합계는 이 원본의 가져오기 대상 전체 기준입니다. 추가 수입은 김치 매출과 고객 예치금에서 분리합니다.</p>
<form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="import_execute"><input type="hidden" name="job" value="<?=e($_GET['job'])?>"><label class="checkbox"><input type="checkbox" name="confirm_replace" value="replace" required> 기존 고객·주문·입금·이전 가져오기 내역이 모두 삭제되는 것을 확인했습니다.</label><label class="checkbox"><input type="checkbox" name="confirm_import" value="yes" required> 위 내용을 확인했으며 고객·주문을 실제로 저장합니다.</label><button class="button">백업 후 기존 자료 삭제·가져오기</button></form><p class="hint">준비 화면은 1시간 동안 유효합니다. 자료가 바뀌면 다시 준비해야 합니다.</p>
<?php endif ?></section>
<?php $batches=query('SELECT b.id,b.created_at,b.summary,b.backup_name,s.filename FROM import_batches b JOIN import_sources s ON s.id=b.source_id ORDER BY b.id DESC LIMIT 20')->fetchAll();if($batches): ?>
<section class="panel"><h2>가져오기 실행 기록</h2><?php foreach($batches as $b): ?><p>#<?=$b['id']?> · <?=e($b['created_at'])?> · <?=e($b['filename'])?><small>비공개 백업: <?=e($b['backup_name'])?></small></p><?php endforeach ?></section><?php endif ?>
