<?php
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);if(is_file($root.'/config/release.php'))exit("운영 배포판에서는 개발 미리보기를 켜지 않습니다.\n");
if(!is_dir($root.'/.local'))mkdir($root.'/.local',0700,true);$file=$root.'/.local/business-preview.enabled';if(file_put_contents($file,getenv('DB_NAME')?:'kimchibang')===false)throw new RuntimeException('미리보기를 켤 수 없습니다.');chmod($file,0600);
require __DIR__.'/init.php';echo "판매 관리 미리보기 준비 완료. 브라우저에서 Ctrl+F5로 확인하세요.\n";
