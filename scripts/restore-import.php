<?php
// CLI only. Deliberately requires an exact acknowledgement, never exposed by HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/import-backup.php';
$name=$argv[1]??'';$confirm=$argv[2]??'';
if(!preg_match('/^before-import-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.sql$/D',$name)||$confirm!=='RESTORE_CURRENT_DATABASE'){
    fwrite(STDERR,"사용법: php scripts/restore-import.php 백업파일명 RESTORE_CURRENT_DATABASE\n현재 DB 전체를 백업 시점으로 되돌립니다. 먼저 새 백업을 보관하세요.\n");exit(1);
}
$file=importBackupDirectory().'/'.$name;if(!is_file($file))throw new RuntimeException('백업 파일이 없습니다.');
$contents=file_get_contents($file);
if(!str_starts_with($contents,"-- KIMCHIBANG BACKUP v1\n") || !str_contains($contents,'-- database-identity: '.importDatabaseIdentity()."\n"))throw new RuntimeException('현재 DB의 백업이 아닙니다.');
$lock=databaseWriteLock();
try{$safety=writeImportBackup();db()->exec($contents);echo '복원 완료 · 복원 직전 백업: '.$safety['name']."\n";}
finally{if($lock)databaseWriteLock(true);}
