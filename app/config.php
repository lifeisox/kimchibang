<?php
declare(strict_types=1);
// Shared hosting can keep settings outside the public document root.
$localConfigPath=__DIR__.'/../config/local.php';
if(is_file($localConfigPath)) {
    $localSettings=require $localConfigPath;
    if(!is_array($localSettings))throw new RuntimeException('서버 설정 파일 형식을 확인해 주세요.');
    foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','APP_SECURE_COOKIE','APP_ALLOW_INITIAL_SETUP','APP_HIDE_IMPORT_AFTER_COMPLETE'] as $setting) {
        if(getenv($setting)===false && array_key_exists($setting,$localSettings)) {
            if(!is_string($localSettings[$setting]))throw new RuntimeException('서버 설정 값은 문자열이어야 합니다.');
            putenv($setting.'='.$localSettings[$setting]);
        }
    }
    unset($localSettings,$setting);
}
unset($localConfigPath);
