<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';
try {
    if(version_compare(PHP_VERSION,'8.2.0','<'))throw new RuntimeException('PHP 8.2 이상이 필요합니다.');
    foreach(['pdo_mysql','mbstring','SimpleXML','Phar','session'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('필요한 PHP 확장: '.$extension);
    foreach(['APP_SECURE_COOKIE'=>'1','APP_ALLOW_INITIAL_SETUP'=>'0','APP_HIDE_IMPORT_AFTER_COMPLETE'=>'1'] as $key=>$value)if(getenv($key)!==$value)throw new RuntimeException('운영 설정 확인: '.$key.'='.$value);
    $root=__DIR__.'/..';
    if(!is_dir($root.'/.local')&&!mkdir($root.'/.local',0700,true))throw new RuntimeException('비공개 저장 폴더를 만들 수 없습니다.');
    if(!is_writable($root.'/.local'))throw new RuntimeException('비공개 저장 폴더 쓰기 권한을 확인해 주세요.');
    query('SELECT 1');
    require_once __DIR__.'/../app/import-backup.php';
    importDatabaseIdentity();
    echo 'PASS PHP 확장·HTTPS 쿠키 설정·비공개 폴더·DB 연결·가져오기 DB 식별'.PHP_EOL;
    echo 'DB 버전: '.query('SELECT VERSION()')->fetchColumn().PHP_EOL;
    echo '검사는 업무 데이터를 변경하지 않았습니다. HTTPS와 공개 폴더 차단은 실제 URL에서 별도로 확인합니다.'.PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,'서버 점검 실패: '.($e instanceof PDOException?'DB 접속 설정과 DB 권한을 확인해 주세요.':$e->getMessage()).PHP_EOL);exit(1);}
