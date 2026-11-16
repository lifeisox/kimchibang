<?php
// A new CSS/JS body gets a new URL so browser/CDN caches cannot reuse an older release.
function assetUrl(string $name): string {
    if(!in_array($name,['style.css','app.js','receipt-reader.js'],true))throw new InvalidArgumentException('지원하지 않는 정적 파일입니다.');
    $root=$_SERVER['DOCUMENT_ROOT']??'';
    if(!is_string($root)||$root==='')$root=dirname(__DIR__).'/public';
    $file=rtrim($root,'/').'/assets/'.$name;
    // Hostinger serves assets from its public document root, separate from the private app.
    $hash=is_file($file)&&is_readable($file)?hash_file('sha256',$file):false;
    if($name==='receipt-reader.js'&&$hash!==false){$parser=rtrim($root,'/').'/assets/receipt-parser.mjs';if(is_file($parser))$hash=hash('sha256',$hash.hash_file('sha256',$parser));}
    return '/assets/'.$name.'?v='.($hash===false?'missing':substr($hash,0,16));
}
