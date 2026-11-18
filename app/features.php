<?php
function expensesEnabled(): bool {
 $release=__DIR__.'/../config/release.php';
 if(is_file($release)){$flags=require $release;return is_array($flags)&&($flags['expenses']??false)===true;}
 return getenv('APP_ENABLE_EXPENSES')==='1';
}
function expenseRouteRequested(): bool {
 $page=$_GET['page']??'';$api=$_GET['api']??'';$action=$_POST['action']??'';
 return (is_string($page)&&in_array($page,['expenses','expense-edit','expense-report','expense-import','bookkeeping-suppliers','bookkeeping-accounts','bookkeeping-supplier','expense-receipt-view'],true))
  ||(is_string($api)&&str_starts_with($api,'expense-'))||(is_string($action)&&(str_starts_with($action,'expense_')||str_starts_with($action,'supplier_')||str_starts_with($action,'accounting_')));
}

function businessEnabled(): bool { $p=__DIR__.'/../config/release.php';if(is_file($p)){$r=require $p;return is_array($r)&&($r['business']??false)===true;}return getenv('APP_ENABLE_BUSINESS')==='1'||(is_file(__DIR__.'/../.local/business-preview.enabled')&&trim(file_get_contents(__DIR__.'/../.local/business-preview.enabled'))===(getenv('DB_NAME')?:'kimchibang'));}
