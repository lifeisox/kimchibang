<?php
require_once __DIR__.'/expenses.php';
function serveExpenseViewer(): never {
 if(empty($_SESSION['user_id'])){http_response_code(401);exit('로그인해 주세요.');}
 $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$r=$id?query('SELECT * FROM expense_receipts WHERE id=?',[$id])->fetch():false;
 if(!$r){http_response_code(404);exit('영수증을 찾을 수 없습니다.');}
 if($r['mime']==='application/pdf')redirect('/?api=expense-receipt&id='.$id);
 require_once __DIR__.'/assets.php';
 ?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>영수증 보기</title><link rel="stylesheet" href="<?=e(assetUrl('style.css'))?>"><script defer src="<?=e(assetUrl('app.js'))?>"></script></head><body class="receipt-viewer"><div class="receipt-viewer-tools"><button type="button" class="button" data-receipt-zoom="in">확대 ＋</button><button type="button" class="button secondary" data-receipt-zoom="out">축소 −</button><button type="button" class="button secondary" data-receipt-zoom="fit">화면에 맞추기</button><span><?=e($r['original_name'])?></span></div><div class="receipt-viewer-canvas"><img id="receipt-full-image" src="/?api=expense-receipt&id=<?=$id?>" alt="영수증 원본"></div></body></html><?php exit;
}
