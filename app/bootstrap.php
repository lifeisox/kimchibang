<?php
declare(strict_types=1);
date_default_timezone_set('America/Toronto');
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly'=>true, 'secure'=>getenv('APP_SECURE_COOKIE') !== '0', 'samesite'=>'Lax', 'path'=>'/']);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
}
function db(): PDO {
    static $db;
    if($db) return $db;
    $db = new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';dbname='.(getenv('DB_NAME') ?: 'kimchibang').';charset=utf8mb4', getenv('DB_USER') ?: 'kimchi', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone = '".date('P')."'");
    return $db;
}
function query(string $sql, array $args=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($args); return $s; }
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
const FREE_DELIVERY_MIN_CENTS = 8000;
const DELIVERY_FEE_CENTS = 500;
function deliveryFee(int $productSubtotalCents, string $fulfillment): int {
    return $fulfillment === 'delivery' && $productSubtotalCents < FREE_DELIVERY_MIN_CENTS ? DELIVERY_FEE_CENTS : 0;
}
function money(int $c): string { return '$'.number_format($c/100, 2); }
function weight(?int $g): string { if($g===null)return '용량 미확인'; return rtrim(rtrim(number_format($g/1000, 3, '.', ''), '0'), '.').'kg'; }
function redirect(string $url): never { header('Location: '.$url, true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf'] ??= bin2hex(random_bytes(32))).'">'; }
function textfield(array $data,string $key,int $max=100,bool $required=false): string {
    $v=$data[$key]??'';
    if(!is_string($v) || strlen($v)>$max*4 || ($required && trim($v)==='')) throw new InvalidArgumentException('입력 내용을 확인해 주세요: '.$key);
    return trim($v);
}
function cents(mixed $value): int {
    if(!is_string($value) || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D',$value)) throw new InvalidArgumentException('금액은 소수점 두 자리까지 입력해 주세요.');
    $parts=explode('.',$value); return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
}
function validDate(string $v): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if(!$d || $d->format('Y-m-d')!==$v) throw new InvalidArgumentException('날짜를 확인해 주세요.');
    return $v;
}

require_once __DIR__.'/orders.php';

function databaseWriteLock(bool $release=false): bool {
    $name='kimchi-write-'.substr(hash('sha256',(string)query('SELECT DATABASE()')->fetchColumn()),0,32);
    if($release){query('SELECT RELEASE_LOCK(?)',[$name]);return true;}
    if((int)query('SELECT IS_USED_LOCK(?)',[$name])->fetchColumn()===(int)query('SELECT CONNECTION_ID()')->fetchColumn())return false;
    if((int)query('SELECT GET_LOCK(?,10)',[$name])->fetchColumn()!==1)throw new InvalidArgumentException('다른 저장 작업이 진행 중입니다. 잠시 후 다시 시도해 주세요.');
    return true;
}

function payerName(array $data,string $key='payer_name'): string {
    $value=$data[$key]??'';
    if(!is_string($value) || mb_strlen(trim($value))>100)throw new InvalidArgumentException('입금자 성명은 100자 이내로 입력해 주세요.');
    return trim($value);
}
// Both saved payer hints and names on individual receipts can identify a customer.
function customerSearchCondition(): string {
    return "name LIKE ? ESCAPE '!' OR nickname LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR address LIKE ? ESCAPE '!' OR contact_name LIKE ? ESCAPE '!' OR affiliation LIKE ? ESCAPE '!' OR payer_name LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM orders search_o JOIN payments search_p ON search_p.order_id=search_o.id WHERE search_o.customer_id=customers.id AND search_p.payer_name<>'' AND search_p.payer_name LIKE ? ESCAPE '!')";
}

const CUSTOMER_AREAS = ['Barrhaven','Kanata','Stittsville','Nepean','Carlington','Downtown','Vanier','Findlay','East','Etc'];
const FULFILLMENT_METHODS = ['delivery'=>'집으로 배달','pickup'=>'직접 픽업','church'=>'교회'];
function fulfillmentLabel(string $method): string {return FULFILLMENT_METHODS[$method]??'미기록';}
function optionalChoice(array $data,string $field,array $allowed): string {
    $v=textfield($data,$field,30);
    if($v!=='' && !in_array($v,$allowed,true))throw new InvalidArgumentException('선택 항목을 확인해 주세요.');return $v;
}

function excelImportCompleted(): bool {
    foreach(query('SELECT summary FROM import_batches ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $s=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if((($s['new_orders']??0)+($s['skipped_orders']??0)>0) && !($s['held_orders']??0) && !($s['held_customers']??0))return true;
    }
    return false;
}

function excelImportMenuHidden(): bool {return getenv('APP_HIDE_IMPORT_AFTER_COMPLETE')==='1' && excelImportCompleted();}

require_once __DIR__.'/maps.php';
