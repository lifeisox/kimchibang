<?php
declare(strict_types=1);
function accountPassword(array $input): string {
    $password=$input['new_password']??null;$confirmation=$input['password_confirmation']??null;
    if(!is_string($password)||mb_strlen($password)<12||strlen($password)>72)throw new InvalidArgumentException('비밀번호는 12자 이상, 72바이트 이하로 입력해 주세요.');
    if(!is_string($confirmation)||!hash_equals($password,$confirmation))throw new InvalidArgumentException('두 비밀번호가 일치하지 않습니다.');return $password;
}
function accountAction(string $action): never {
    if($action==='account_create') {
        $name=textfield($_POST,'new_username',40,true);
        if(!preg_match('/^[a-zA-Z0-9_-]{3,40}$/D',$name))throw new InvalidArgumentException('아이디는 영문·숫자·밑줄·하이픈으로 3~40자 입력해 주세요.');
        $password=accountPassword($_POST);
        if(query('SELECT id FROM users WHERE username=?',[$name])->fetch())throw new InvalidArgumentException('이미 사용하는 아이디입니다.');
        query('INSERT INTO users (username,password_hash) VALUES (?,?)',[$name,password_hash($password,PASSWORD_DEFAULT)]);
        $_SESSION['flash']='새 계정을 추가했습니다. 두 분이 각자 다른 아이디로 동시에 사용할 수 있습니다.';
    } else {
        $current=$_POST['current_password']??null;$user=query('SELECT * FROM users WHERE id=?',[$_SESSION['user_id']])->fetch();
        if(!$user||!is_string($current)||!password_verify($current,$user['password_hash']))throw new InvalidArgumentException('현재 비밀번호를 확인해 주세요.');
        $password=accountPassword($_POST);
        query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['flash']='내 비밀번호를 변경했습니다.';
    }
    redirect('/?page=accounts');
}
