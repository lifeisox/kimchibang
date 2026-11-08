<?php
declare(strict_types=1);
function importDatabaseIdentity(): string {
    // MariaDB has no MySQL server_uuid variable. Keep existing MySQL backup IDs stable.
    $version=(string)query('SELECT VERSION()')->fetchColumn();
    $server=stripos($version,'MariaDB')!==false
        ? query("SELECT CONCAT('mariadb:',@@hostname,':',@@server_id)")->fetchColumn()
        : query('SELECT @@server_uuid')->fetchColumn();
    return hash('sha256',(string)getenv('DB_HOST').'|'.query('SELECT DATABASE()')->fetchColumn().'|'.$server);
}
function importDatabaseDigest(): string {
    $context=hash_init('sha256');
    foreach(['customers','products','orders','order_items','payments','payment_allocations','order_events','import_records','import_sources','import_batches','import_batch_rows'] as $table) {
        hash_update($context,$table);
        $s=query('SELECT * FROM `'.$table.'` ORDER BY id');while($r=$s->fetch())hash_update($context,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    return hash_final($context);
}
function importBackupDirectory(): string {
    $dir=__DIR__.'/../.local/import-backups';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('백업 경로를 준비할 수 없습니다.');return $dir;
}
function writeImportBackup(): array {
    $name='before-import-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sql';$file=importBackupDirectory().'/'.$name;$temp=$file.'.tmp';
    $handle=fopen($temp,'xb');if(!$handle)throw new RuntimeException('가져오기 전 백업을 만들 수 없습니다.');chmod($temp,0600);
    $write=function(string $s)use($handle):void{if(fwrite($handle,$s)!==strlen($s))throw new RuntimeException('백업을 끝까지 기록하지 못했습니다.');};
    try {
        $write("-- KIMCHIBANG BACKUP v1\n-- database-identity: ".importDatabaseIdentity()."\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        $tables=query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table) {
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('백업할 테이블 이름을 확인해야 합니다.');
            $schema=query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$write('DROP TABLE IF EXISTS `'.$table."`;\n".$schema.";\n");
            $rows=query('SELECT * FROM `'.$table.'`');
            while($r=$rows->fetch()) {
                $columns=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($r));$values=array_map(fn($v)=>$v===null?'NULL':db()->quote((string)$v),array_values($r));
                $write('INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");fclose($handle);$handle=null;
        if(!rename($temp,$file))throw new RuntimeException('백업을 보관할 수 없습니다.');
        return ['name'=>$name,'sha256'=>hash_file('sha256',$file)];
    } finally {if(is_resource($handle))fclose($handle);if(is_file($temp))unlink($temp);}
}
