<?php
declare(strict_types=1);
function brandLogoPath(): string {return rtrim(getenv('APP_BRANDING_DIR')?:__DIR__.'/../.local/branding','/').'/logo.png';}
function hasBrandLogo(): bool {return is_file(brandLogoPath());}
function saveBrandLogo(array $upload): void {
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??''))throw new InvalidArgumentException('원본 PNG 파일을 선택해 주세요.');
    if(($upload['size']??0)>2*1024*1024)throw new InvalidArgumentException('로고는 2MB 이하 PNG 파일을 선택해 주세요.');
    $size=@getimagesize($upload['tmp_name']);
    if(!$size || ($size['mime']??'')!=='image/png' || $size[0]>6000 || $size[1]>6000)throw new InvalidArgumentException('가로·세로 6000픽셀 이하의 PNG 파일을 선택해 주세요.');
    $dir=dirname(brandLogoPath());if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('로고 저장 경로를 만들 수 없습니다.');
    $temp=$dir.'/'.bin2hex(random_bytes(12)).'.png';
    try{if(!move_uploaded_file($upload['tmp_name'],$temp))throw new RuntimeException('로고를 저장할 수 없습니다.');chmod($temp,0600);if(!rename($temp,brandLogoPath()))throw new RuntimeException('로고를 적용할 수 없습니다.');}
    finally{if(is_file($temp))unlink($temp);}
}
function serveBrandLogo(): never {
    if(!hasBrandLogo()){http_response_code(404);exit;}
    $file=brandLogoPath();$etag='"'.hash_file('sha256',$file).'"';header('Content-Type: image/png');header('Cache-Control: private, max-age=0, must-revalidate');header('ETag: '.$etag);
    if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
    header('Content-Length: '.filesize($file));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file);exit;
}
function brandMarkup(): void {
    if(hasBrandLogo())echo '<img class="brand-image original-brand" src="/?api=brand-logo&amp;v='.substr(hash_file('sha256',brandLogoPath()),0,12).'" alt="로뎀나무 · RODEM NAMU">';
    else echo '<span class="brand-placeholder">로뎀나무<small>RODEM NAMU</small></span>';
}
