<?php
require __DIR__.'/../app/bootstrap.php';
$name=$argv[1]??'';
if(!preg_match('/^[a-zA-Z0-9_-]{3,40}$/D',$name)) exit("사용법: php scripts/create-user.php 아이디 < 비밀번호파일\n");
if(($argv[2]??'')==='--if-missing' && query('SELECT id FROM users WHERE username=?',[$name])->fetch()){echo "기존 계정 유지\n";exit;}
$password=trim(stream_get_contents(STDIN));
if(strlen($password)<12) exit("비밀번호는 12자 이상이어야 합니다.\n");
query('INSERT INTO users (username,password_hash) VALUES (?,?)',[$name,password_hash($password,PASSWORD_DEFAULT)]);
echo "계정 생성 완료: $name\n";
