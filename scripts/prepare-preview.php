<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/preview-storage.php';
$source=$argv[1]??(__DIR__.'/../.local/imports/attached-source.zip');
$name=$argv[2]??'오타와김치 김치방.xlsx';
if(!is_file($source)) exit("분석할 첨부 파일이 없습니다. 화면에서 .xlsx 파일을 선택해 주세요.\n");
$report=applyExcelDecisions(buildExcelPreview($source,$name,previewSnapshot()));
saveExcelPreview($report,previewDirectory().'/provided.json');
echo json_encode(['summary'=>$report['summary'],'issues'=>array_map(fn($i)=>['code'=>$i['code'],'count'=>$i['count']],array_values($report['issue_counts']))],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
