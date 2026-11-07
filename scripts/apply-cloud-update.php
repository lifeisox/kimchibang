<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/import-backup.php';
$lock=databaseWriteLock();
try {
    $before=[(int)query('SELECT COUNT(*) FROM customers')->fetchColumn(),(int)query('SELECT COUNT(*) FROM orders')->fetchColumn()];
    $backup=writeImportBackup();echo '업데이트 전 비공개 백업: '.$backup['name'].PHP_EOL;
    require __DIR__.'/init.php';
    $after=[(int)query('SELECT COUNT(*) FROM customers')->fetchColumn(),(int)query('SELECT COUNT(*) FROM orders')->fetchColumn()];
    if($before!==$after)throw new RuntimeException('고객·주문 건수가 변경되어 확인이 필요합니다. 백업을 보존하세요.');
    echo '업데이트 완료 · 기존 고객 '.$after[0].'명 / 주문 '.$after[1].'건 보존'.PHP_EOL;
} finally {if($lock)databaseWriteLock(true);}
