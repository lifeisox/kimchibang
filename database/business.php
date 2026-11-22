<?php
$ddl=[
"clients"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(20) NULL UNIQUE,name VARCHAR(150) NOT NULL,email VARCHAR(150) NOT NULL DEFAULT '',address VARCHAR(500) NOT NULL DEFAULT '',staff_discount_bp INT NULL,version INT NOT NULL DEFAULT 1",
"products"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,category VARCHAR(20) NOT NULL,ko VARCHAR(100) NOT NULL,en VARCHAR(100) NOT NULL,pack VARCHAR(30) NOT NULL,retail_cents INT NULL,active TINYINT NOT NULL DEFAULT 1,UNIQUE(category,en,pack)",
"prices"=>"client_id INT UNSIGNED NOT NULL,product_id INT UNSIGNED NOT NULL,cents INT NOT NULL,PRIMARY KEY(client_id,product_id)",
"deliveries"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,client_id INT UNSIGNED NOT NULL,product_id INT UNSIGNED NOT NULL,day DATE NOT NULL,fulfillment VARCHAR(20) NOT NULL DEFAULT 'delivery',discount_bp INT NOT NULL DEFAULT 0,batch_key CHAR(32) NOT NULL,planned INT NOT NULL,delivered INT NOT NULL DEFAULT 0,sent TINYINT NOT NULL DEFAULT 0,version INT NOT NULL DEFAULT 1,request_key CHAR(32) NOT NULL UNIQUE,created_by INT UNSIGNED NOT NULL",
"sales"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,delivery_id INT UNSIGNED NOT NULL,day DATE NOT NULL,qty INT NOT NULL,staff_qty INT NOT NULL DEFAULT 0,cents INT NOT NULL,discount_bp INT NOT NULL DEFAULT 0,invoice_id INT UNSIGNED NULL,request_key CHAR(32) NOT NULL UNIQUE",
"returns"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,delivery_id INT UNSIGNED NOT NULL,day DATE NOT NULL,qty INT NOT NULL,request_key CHAR(32) NOT NULL UNIQUE",
"reports"=>"client_id INT UNSIGNED NOT NULL,delivery_id INT UNSIGNED NOT NULL,day DATE NOT NULL,PRIMARY KEY(delivery_id,day)",
"invoices"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,client_id INT UNSIGNED NOT NULL,issued DATE NOT NULL,period_start DATE NOT NULL,period_end DATE NOT NULL,kind VARCHAR(20) NOT NULL,snapshot LONGTEXT NOT NULL,total_cents INT NOT NULL,mail_state VARCHAR(20) NOT NULL DEFAULT 'pending',mail_error VARCHAR(200) NOT NULL DEFAULT '',created_by INT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
"periods"=>"client_id INT UNSIGNED NOT NULL,kind VARCHAR(20) NOT NULL,start_day DATE NOT NULL,end_day DATE NOT NULL,invoice_id INT UNSIGNED NOT NULL,PRIMARY KEY(client_id,kind,start_day,end_day)",
"payments"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNSIGNED NOT NULL,day DATE NOT NULL,cents INT NOT NULL,method VARCHAR(20) NOT NULL,request_key CHAR(32) NOT NULL UNIQUE,created_by INT UNSIGNED NOT NULL",
"events"=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_id INT UNSIGNED NULL,kind VARCHAR(30) NOT NULL,detail LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
"settings"=>"id INT PRIMARY KEY,seller VARCHAR(150) NOT NULL DEFAULT 'Rodem Namu',address VARCHAR(500) NOT NULL DEFAULT '',gst VARCHAR(100) NOT NULL DEFAULT '',email VARCHAR(150) NOT NULL DEFAULT ''"
];
foreach($ddl as $name=>$columns)db()->exec('CREATE TABLE IF NOT EXISTS biz_'.$name.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
foreach([['OK','OK Mart'],['HN','Hungry Ninja'],['PT','Popularitea']] as $c)query('INSERT INTO biz_clients (code,name) VALUES (?,?) ON DUPLICATE KEY UPDATE id=id',$c);
if(!(int)query('SELECT COUNT(*) FROM biz_products')->fetchColumn())foreach(json_decode(file_get_contents(__DIR__.'/../config/business-products.json'),true) as $p)query('INSERT INTO biz_products (category,ko,en,pack) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=id',$p);
query('INSERT INTO biz_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id=id');

foreach(['fulfillment'=>"VARCHAR(20) NOT NULL DEFAULT 'delivery'",'discount_bp'=>'INT NOT NULL DEFAULT 0'] as $column=>$type)if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['biz_deliveries',$column])->fetch())db()->exec('ALTER TABLE biz_deliveries ADD COLUMN '.$column.' '.$type);
foreach(['biz_sales'=>['biz_sale_delivery'=>'delivery_id,day','biz_sale_invoice'=>'invoice_id'],'biz_returns'=>['biz_return_delivery'=>'delivery_id,day'],'biz_deliveries'=>['biz_delivery_client'=>'client_id,day','biz_delivery_batch'=>'batch_key']] as $table=>$indexes)foreach($indexes as $index=>$columns)if(!query('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$index])->fetch())db()->exec('CREATE INDEX '.$index.' ON '.$table.' ('.$columns.')');

if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['biz_deliveries','cancelled'])->fetch())db()->exec('ALTER TABLE biz_deliveries ADD COLUMN cancelled TINYINT NOT NULL DEFAULT 0');

// Additive migration: keep old delivery lines and link each batch to one order/document.
foreach(['biz_clients'=>['active'=>'TINYINT NOT NULL DEFAULT 1'],'biz_products'=>['hst_bp'=>'INT NULL']] as $table=>$columns)foreach($columns as $column=>$type)if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetch()){
 db()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$type);
 if($table==='biz_products')db()->exec("UPDATE biz_products SET hst_bp=CASE WHEN category='kimchi' THEN 0 WHEN category='etc' THEN NULL ELSE 1300 END");
}
db()->exec("CREATE TABLE IF NOT EXISTS biz_orders (batch_key CHAR(32) PRIMARY KEY,client_id INT UNSIGNED NOT NULL,order_date DATE NOT NULL,day DATE NOT NULL,discount_bp INT NOT NULL DEFAULT 0,notes VARCHAR(2000) NOT NULL DEFAULT '',document_notes VARCHAR(2000) NOT NULL DEFAULT '',items LONGTEXT NOT NULL,manual_edited TINYINT NOT NULL DEFAULT 0,cancelled TINYINT NOT NULL DEFAULT 0,version INT NOT NULL DEFAULT 1,created_by INT UNSIGNED NULL,INDEX biz_order_date (order_date),INDEX biz_order_client (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Preserve the original order when delivery date/discount are changed on its document.
foreach(['order_day'=>'DATE NULL','order_discount_bp'=>'INT NULL'] as $column=>$type)if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['biz_orders',$column])->fetch()){
 db()->exec('ALTER TABLE biz_orders ADD COLUMN '.$column.' '.$type);
 db()->exec('UPDATE biz_orders SET '.$column.'='.($column==='order_day'?'day':'discount_bp'));
}

foreach(query('SELECT d.batch_key,MIN(d.client_id) client_id,MIN(d.day) day,MIN(d.discount_bp) discount_bp,MAX(d.sent OR d.version>1) edited,MIN(d.cancelled) cancelled,MIN(d.created_by) created_by FROM biz_deliveries d LEFT JOIN biz_orders o ON o.batch_key=d.batch_key WHERE o.batch_key IS NULL GROUP BY d.batch_key')->fetchAll() as $batch){
 $items=[];foreach(query('SELECT product_id,planned FROM biz_deliveries WHERE batch_key=?',[$batch['batch_key']])->fetchAll() as $line)$items[$line['product_id']]=(int)$line['planned'];
 query('INSERT INTO biz_orders (batch_key,client_id,order_date,day,order_day,discount_bp,order_discount_bp,items,manual_edited,cancelled,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$batch['batch_key'],$batch['client_id'],$batch['day'],$batch['day'],$batch['day'],$batch['discount_bp'],$batch['discount_bp'],json_encode($items,JSON_THROW_ON_ERROR),$batch['edited'],$batch['cancelled'],$batch['created_by']]);
}
if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['biz_deliveries','unit_cents'])->fetch())db()->exec('ALTER TABLE biz_deliveries ADD COLUMN unit_cents INT NULL');

foreach(['biz_deliveries'=>'amount_cents','biz_sales'=>'gross_cents'] as $table=>$column)if(!query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetch())db()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' INT NULL');
db()->exec("CREATE TABLE IF NOT EXISTS biz_recipes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,base_g INT NOT NULL,base_unit VARCHAR(10) NOT NULL DEFAULT 'g',ingredients LONGTEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS biz_recipe_links (product_id INT UNSIGNED PRIMARY KEY,recipe_id INT UNSIGNED NULL,weight_g INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS biz_production_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_key CHAR(32) NOT NULL UNIQUE,plan_date DATE NOT NULL,title VARCHAR(100) NOT NULL,snapshot LONGTEXT NOT NULL,created_by INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if(!query("SELECT version FROM schema_migrations WHERE version='biz_recipes_20261121'")->fetchColumn()){
 db()->beginTransaction();try{
 $recipeIds=[];foreach(query('SELECT * FROM production_recipes WHERE active=1')->fetchAll() as $r){$lines=json_decode($r['ingredients'],true);foreach($lines as &$l)$l['unit']='g';unset($l);query('INSERT INTO biz_recipes (name,base_g,ingredients) VALUES (?,?,?)',[$r['name'],$r['base_g'],json_encode($lines,JSON_UNESCAPED_UNICODE)]);if(!empty($r['seed_key']))$recipeIds[$r['seed_key']]=(int)db()->lastInsertId();}
 $map=['Sliced Kimchi'=>'mat','Whole Napa Kimchi'=>'pogi','Vegan Kimchi'=>'vegan','Kkakdugi'=>'radish','White Kimchi'=>'white','Geotjeori'=>'fresh','Green Onion Kimchi'=>'green'];
 foreach(query('SELECT * FROM biz_products')->fetchAll() as $p){$family=$map[$p['en']]??null;if(!$family)foreach($map as $prefix=>$candidate)if(preg_match('/^'.preg_quote($prefix,'/').'(?:$|\s|\()/i',$p['en'])){$family=$candidate;break;}$rid=$family===null?null:($recipeIds[$family]??null);$weight=preg_match('/^([0-9.]+)(kg|g)$/',$p['pack'],$m)?(int)round((float)$m[1]*($m[2]==='kg'?1000:1)):null;
 query('INSERT INTO biz_recipe_links VALUES (?,?,?)',[$p['id'],$rid,$weight]);}
 $defs=[
 'Beef Kimbap'=>['불고기 김밥',['쌀'=>[1/2.6,'컵'],'당근'=>[78,'g'],'계란'=>[.5,'개'],'단무지'=>[1,'줄'],'오이'=>[1/8,'개'],'소고기 (조리 전)'=>[60,'g']]],
 'Tuna Kimbap'=>['참치마요 김밥',['쌀'=>[1/2.6,'컵'],'당근'=>[50,'g'],'계란'=>[.5,'개'],'단무지'=>[1,'줄'],'맛살'=>[1,'줄'],'참치'=>[57*.8,'g'],'마요'=>[57*.2,'g']]],
 'Basic Kimbap'=>['일반 김밥',['쌀'=>[1/2.6,'컵'],'당근'=>[78,'g'],'계란'=>[.5,'개'],'단무지'=>[1,'줄'],'오이'=>[1/8,'개'],'우엉'=>[1,'줄'],'소시지'=>[1,'줄'],'맛살'=>[1,'줄']]],
 'Veggie Kimbap'=>['베지 김밥',['쌀'=>[1/2.6,'컵'],'당근'=>[78,'g'],'계란'=>[.5,'개'],'단무지'=>[1,'줄'],'오이'=>[1/8,'개'],'우엉'=>[2,'줄']]],
 'Crab Triangle Kimbap'=>['크랩 삼각김밥',['쌀'=>[1/3.9,'컵'],'크랩'=>[36*.53,'g'],'크랩마요'=>[36*.47,'g']]],
 'Tuna Triangle Kimbap'=>['참치 삼각김밥',['쌀'=>[1/3.9,'컵'],'참치'=>[33*.42,'g'],'마요'=>[33*.44,'g'],'단무지'=>[33*.14,'g']]],
 'Beef Triangle Kimbap'=>['불고기 삼각김밥',['쌀'=>[1/3.9,'컵'],'소고기 (조리 전)'=>[33,'g']]]];
 foreach($defs as $en=>[$name,$amounts]){$lines=[];foreach($amounts as $m=>[$amount,$unit])$lines[]=['material'=>$m,'basis'=>'product','mode'=>'grams','amount'=>$amount,'unit'=>$unit];query("INSERT INTO biz_recipes (name,base_g,base_unit,ingredients) VALUES (?,1,'개',?)",[$name,json_encode($lines,JSON_UNESCAPED_UNICODE)]);$rid=(int)db()->lastInsertId();foreach(query('SELECT id FROM biz_products WHERE en=?',[$en])->fetchAll(PDO::FETCH_COLUMN) as $pid)query('UPDATE biz_recipe_links SET recipe_id=? WHERE product_id=?',[$rid,$pid]);}
 query("INSERT INTO schema_migrations (version) VALUES ('biz_recipes_20261121')");db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}
}
