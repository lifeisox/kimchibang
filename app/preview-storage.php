<?php
declare(strict_types=1);
require_once __DIR__.'/excel-preview.php';
require_once __DIR__.'/excel-decisions.php';
require_once __DIR__.'/preview-review.php';
function previewDirectory(): string {
    $dir=__DIR__.'/../.local/imports';
    if(!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('미리보기 저장 경로를 준비할 수 없습니다.');
    return $dir;
}
function saveExcelPreview(array $report,string $file): void {
    $report=applyExcelDecisions($report);
    $temp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
    try {
        $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        if(file_put_contents($temp,$json,LOCK_EX)===false) throw new RuntimeException('미리보기 파일을 저장할 수 없습니다.');
        chmod($temp,0600);if(!rename($temp,$file)) throw new RuntimeException('미리보기 파일을 준비할 수 없습니다.');
    } finally {if(is_file($temp)) unlink($temp);}
}
function currentExcelPreview(bool $withReview=true): ?array {
    $dir=previewDirectory();$id=$_SESSION['excel_preview']??null;$file=$dir.'/provided.json';
    if(is_string($id) && preg_match('/^[a-f0-9]{32}$/D',$id)) {
        $candidate=$dir.'/'.$id.'.json';
        if(is_file($candidate) && filemtime($candidate)>time()-86400) $file=$candidate;
        else {unset($_SESSION['excel_preview']);$_SESSION['preview_notice']='업로드한 미리보기가 만료되어 최초 첨부 파일의 결과를 표시합니다. 필요하면 파일을 다시 선택해 주세요.';}
    }
    if(!is_file($file) || filesize($file)>20*1024*1024) return null;
    $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($data['schema']??null)!==1)return null;
    $report=applyExcelDecisions($data);return $withReview?applyPreviewReview($report):$report;
}
function previewUploadAction(): never {
    $dir=previewDirectory();
    // Only disposable uploads owned by this app expire. Do not traverse or remove other local files.
    foreach(glob($dir.'/*.json')?:[] as $file) if(preg_match('/^[a-f0-9]{32}\.json$/D',basename($file)) && filemtime($file)<time()-86400) unlink($file);
    $upload=$_FILES['excel_file']??null;
    if(!is_array($upload) || ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new InvalidArgumentException('파일을 읽지 못했습니다. .xlsx 파일을 선택하고 업로드 용량 제한을 확인해 주세요.');
    if(!is_string($upload['name']??null) || strtolower(pathinfo($upload['name'],PATHINFO_EXTENSION))!=='xlsx' || (int)$upload['size']>8*1024*1024) throw new InvalidArgumentException('8MB 이하의 .xlsx 파일만 미리보기할 수 있습니다.');
    $filename=mb_substr(preg_replace('/[\x00-\x1f]/','',basename($upload['name']))??'엑셀 파일.xlsx',0,150);
    $id=bin2hex(random_bytes(16));$zip=$dir.'/'.$id.'.zip';
    if(!move_uploaded_file($upload['tmp_name'],$zip)) throw new RuntimeException('업로드 파일을 준비할 수 없습니다.');
    chmod($zip,0600);
    try {$report=buildExcelPreview($zip,$filename,previewSnapshot());saveExcelPreview($report,$dir.'/'.$id.'.json');}
    finally {if(is_file($zip)) unlink($zip);}
    $previous=$_SESSION['excel_preview']??'';
    if(is_string($previous) && preg_match('/^[a-f0-9]{32}$/D',$previous) && is_file($dir.'/'.$previous.'.json')) unlink($dir.'/'.$previous.'.json');
    $_SESSION['excel_preview']=$id;$_SESSION['flash']='엑셀 분석을 마쳤습니다. 고객·주문 자료는 변경하지 않았습니다.';redirect('/?page=excel-preview');
}
