<?php
declare(strict_types=1);
/** Initial setup is explicitly enabled only for an empty, private cloud preview. */
function initialSetupAvailable(): bool {
    return getenv('APP_ALLOW_INITIAL_SETUP')==='1' && !(bool)query('SELECT EXISTS(SELECT 1 FROM users)')->fetchColumn();
}
function initialSetupAction(): never {
    if(!initialSetupAvailable())throw new InvalidArgumentException('초기 계정 설정은 이미 완료되었거나 허용되지 않습니다.');
    $password=$_POST['password']??null;$confirmation=$_POST['password_confirmation']??null;
    if(!is_string($password)||strlen($password)<12||strlen($password)>72)throw new InvalidArgumentException('비밀번호는 12자 이상, 72바이트 이하로 입력해 주세요.');
    if(!is_string($confirmation)||!hash_equals($password,$confirmation))throw new InvalidArgumentException('두 비밀번호가 일치하지 않습니다.');
    $owned=databaseWriteLock();
    try {
        if(!initialSetupAvailable())throw new InvalidArgumentException('다른 화면에서 초기 설정을 완료했습니다. 로그인해 주세요.');
        query('INSERT INTO users (username,password_hash) VALUES (?,?)',['admin',password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)db()->lastInsertId();session_regenerate_id(true);$_SESSION=['user_id'=>$id,'username'=>'admin'];
    } finally {if($owned)databaseWriteLock(true);}
    redirect('/');
}
