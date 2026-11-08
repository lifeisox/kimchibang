<?php
require __DIR__.'/../app/assets.php';
$dir=sys_get_temp_dir().'/kimchi-assets-'.bin2hex(random_bytes(8));mkdir($dir);mkdir($dir.'/assets');
try {
 $_SERVER['DOCUMENT_ROOT']=$dir;
 file_put_contents($dir.'/assets/style.css','old css');file_put_contents($dir.'/assets/app.js','old js');
 $css=assetUrl('style.css');$js=assetUrl('app.js');
 if($css!=='/assets/style.css?v='.substr(hash('sha256','old css'),0,16)||$js!==assetUrl('app.js'))throw new RuntimeException('initial/stable hash failed');
 file_put_contents($dir.'/assets/style.css','new css');
 if($css===assetUrl('style.css')||$js!==assetUrl('app.js'))throw new RuntimeException('selective version change failed');
 unset($_SERVER['DOCUMENT_ROOT']);
 if(str_contains(assetUrl('app.js'),'missing'))throw new RuntimeException('development fallback failed');
 echo "PASS 공개 폴더별 CSS/JS 해시·변경 시 새 URL·동일 내용 캐시 유지·개발 경로\n";
} finally {unlink($dir.'/assets/style.css');unlink($dir.'/assets/app.js');rmdir($dir.'/assets');rmdir($dir);}
