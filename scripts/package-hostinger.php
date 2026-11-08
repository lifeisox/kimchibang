<?php
// Runs in Codespaces without Python or Composer. No local settings or business data.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$out=$argv[1]??$root.'/dist/kimchibang-hostinger.zip';
if(!is_dir(dirname($out))&&!mkdir(dirname($out),0700,true))throw new RuntimeException('배포 폴더 생성 실패');
$temp=dirname($out).'/release-'.bin2hex(random_bytes(8)).'.zip';
try {
    $zip=new PharData($temp,0,null,Phar::ZIP);
    foreach(['app','database'] as $folder) {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder,FilesystemIterator::SKIP_DOTS)) as $file) {
            if($file->isFile()&&!$file->isLink()&&$file->getExtension()==='php') {
                $relative=substr($file->getPathname(),strlen($root)+1);
                $zip->addFile($file->getPathname(),'kimchibang/'.$relative);
            }
        }
    }
    foreach(['database/schema.sql','config/excel-decisions.json','config/hostinger.example.php','public/index.php','scripts/init.php','scripts/create-user.php','scripts/hostinger-check.php','scripts/apply-cloud-update.php','scripts/restore-import.php','scripts/hostinger-update.sh'] as $file)$zip->addFile($root.'/'.$file,'kimchibang/'.$file);
    $web='public_html/kimchibang/';
    $zip->addFromString($web.'index.php',"<?php\nrequire __DIR__.'/../../kimchibang/public/index.php';\n");
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/assets',FilesystemIterator::SKIP_DOTS)) as $file) {
        if($file->isFile()&&!$file->isLink())$zip->addFile($file->getPathname(),$web.'assets/'.substr($file->getPathname(),strlen($root.'/public/assets/')));
    }
    $zip->addFile($root.'/public/favicon.ico',$web.'favicon.ico');
    $zip->addFromString($web.'.htaccess',<<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://k.ottawakfood.com%{REQUEST_URI} [R=302,L]
RewriteRule ^(?:index\.php)?$ - [L]
RewriteRule ^favicon\.ico$ - [L]
RewriteRule ^assets/[A-Za-z0-9_./-]+\.(?:css|js|png|ico|svg|webp|jpg|jpeg)$ - [L,NC]
RewriteRule ^ - [F,L]

HTACCESS);
    $zip->addFromString($web.'.user.ini',"display_errors=Off\nlog_errors=On\nexpose_php=Off\nupload_max_filesize=10M\npost_max_size=12M\n");
    $zip->addFile($root.'/docs/HOSTINGER.md','START-HERE.md');
    unset($zip);
    if(!rename($temp,$out))throw new RuntimeException('배포 파일 저장 실패');
    echo '배포 파일 준비 완료: '.$out.PHP_EOL.'비밀번호·고객자료·엑셀·백업 미포함'.PHP_EOL.'SHA256: '.hash_file('sha256',$out).PHP_EOL;
} finally {if(is_file($temp))unlink($temp);}
