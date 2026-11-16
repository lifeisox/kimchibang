<?php
function expensesEnabled(): bool {
 $release=__DIR__.'/../config/release.php';
 if(is_file($release)){$flags=require $release;if(!is_array($flags)||($flags['expenses']??false)!==true)return false;}
 return getenv('APP_ENABLE_EXPENSES')==='1';
}
function expenseRouteRequested(): bool {
 $page=$_GET['page']??'';$api=$_GET['api']??'';$action=$_POST['action']??'';
 return (is_string($page)&&in_array($page,['expenses','expense-edit','expense-report','expense-import','bookkeeping-suppliers','bookkeeping-accounts'],true))
  ||(is_string($api)&&str_starts_with($api,'expense-'))||(is_string($action)&&(str_starts_with($action,'expense_')||str_starts_with($action,'supplier_')||str_starts_with($action,'accounting_')));
}
