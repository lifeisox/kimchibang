<?php
// Additive changes: supplier identity and editable accounts; never rewrite transaction amounts.
$fields=['expense_categories'=>['active'=>'TINYINT NOT NULL DEFAULT 1','sort_order'=>'INT NOT NULL DEFAULT 999','tax_type'=>"VARCHAR(20) NOT NULL DEFAULT 'operating'",'income_tax_line'=>"VARCHAR(30) NOT NULL DEFAULT ''",'default_deductible_pct'=>'DECIMAL(5,2) NOT NULL DEFAULT 100','default_itc_pct'=>'DECIMAL(5,2) NOT NULL DEFAULT 100','version'=>'INT NOT NULL DEFAULT 1'],
 'expenses'=>['supplier_id'=>'INT UNSIGNED NULL','itc_limit_pct'=>'DECIMAL(5,2) NOT NULL DEFAULT 100']];
foreach($fields as $table=>$columns){$known=query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(PDO::FETCH_COLUMN);foreach($columns as $column=>$type)if(!in_array($column,$known,true))db()->exec("ALTER TABLE $table ADD COLUMN $column $type");}
db()->exec("CREATE TABLE IF NOT EXISTS bookkeeping_suppliers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,name_key VARCHAR(191) NOT NULL UNIQUE,active TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS bookkeeping_supplier_aliases (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,alias_key VARCHAR(191) NOT NULL UNIQUE,label VARCHAR(150) NOT NULL,INDEX(supplier_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS bookkeeping_admin_events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_id INT UNSIGNED NOT NULL,kind VARCHAR(30) NOT NULL,detail LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$version='20261116_bookkeeping';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()){
 $map=['재료 구입'=>'Material','인건비'=>'Wages','광고'=>'Advertising','은행 수수료'=>'Bank','보험'=>'Insurance','식사·접대'=>'Meals','소모품'=>'Supplies','허가·면허'=>'Licenses','전문가 수수료'=>'Professional','임대료'=>'Rent','전화·인터넷'=>'Phone/Internet','수리·유지'=>'Maintenance','수도·전기·가스'=>'Utilities','차량'=>'Auto','리스'=>'Lease','장비·자산 구입'=>'Fixed Assets','기타·분류 확인'=>'Others'];
 // Display names become the spreadsheet/Income Tax terms. Existing IDs stay stable.
 foreach($map as $old=>$english)if(!query('SELECT id FROM expense_categories WHERE name=?',[$english])->fetch())query('UPDATE expense_categories SET name=? WHERE name=?',[$english,$old]);
 $rank=['Material','Supplies','Auto','Utilities','Lease','Phone/Internet','Insurance','Maintenance','Bank','Meals','Wages','Advertising','Licenses','Professional','Rent','Fixed Assets','Others','Interest','Office Expenses','Property Taxes','Travel','Delivery/Freight','Other Business Expenses'];
 foreach($rank as $i=>$name){$type=$name==='Fixed Assets'?'capital':($name==='Material'?'inventory':($name==='Others'?'review':'operating'));query('INSERT INTO expense_categories (name,capital,sort_order,tax_type,default_deductible_pct,default_itc_pct) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),tax_type=VALUES(tax_type),default_deductible_pct=VALUES(default_deductible_pct),default_itc_pct=VALUES(default_itc_pct)',[$name,$type==='capital'?1:0,$i+1,$type,$name==='Meals'?50:100,$name==='Meals'?50:100]);}
 require_once __DIR__.'/../app/bookkeeping.php';
 foreach(query('SELECT id,vendor FROM expenses WHERE supplier_id IS NULL')->fetchAll() as $legacy){if(supplierKey($legacy['vendor'])==='')continue;$supplier=supplierResolve($legacy['vendor']);query('UPDATE expenses SET supplier_id=? WHERE id=?',[$supplier['id'],$legacy['id']]);}
 query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);
}
