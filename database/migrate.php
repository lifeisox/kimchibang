<?php
// Additive migrations: never recalculate historical totals or reset edited prices.
db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$version='20261106_delivery_fee_product_order';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()) {
    $columns=query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders'")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('delivery_fee_cents',$columns,true)) db()->exec('ALTER TABLE orders ADD COLUMN delivery_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_cents');
    $columns=query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products'")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('sort_order',$columns,true)) db()->exec('ALTER TABLE products ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 100000');
    $codes=['포10','포5','맛10','맛5','깍','파','겉','물','물백','동','총각','갓','열'];
    // Existing customers and orders are left intact. Unknown products stay at the end.
    foreach($codes as $index=>$code) query('UPDATE products SET sort_order=? WHERE code=?',[$index+1,$code]);
    query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);
}

$version='20261106_partial_delivery_payments';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()) {
    $additions=[
        'orders'=>['received_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','cancelled'=>'TINYINT NOT NULL DEFAULT 0','version'=>'INT UNSIGNED NOT NULL DEFAULT 1','shipped_units'=>'INT UNSIGNED NOT NULL DEFAULT 0'],
        'order_items'=>['delivered_quantity'=>'INT UNSIGNED NOT NULL DEFAULT 0','cancelled_quantity'=>'INT UNSIGNED NOT NULL DEFAULT 0','unit_discount_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','extra_discount_units'=>'INT UNSIGNED NOT NULL DEFAULT 0']
    ];
    foreach($additions as $table=>$fields) {
        $columns=query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(PDO::FETCH_COLUMN);
        foreach($fields as $name=>$type) if(!in_array($name,$columns,true)) db()->exec("ALTER TABLE $table ADD COLUMN $name $type");
    }
    // DDL is complete before the data transaction. Legacy totals, delivery and receipts remain intact.
    db()->beginTransaction();
    try {
        foreach(query('SELECT * FROM orders ORDER BY id')->fetchAll() as $o) {
            $id=(int)$o['id'];allocateDiscount($id,(int)$o['discount_cents']);
            if($o['delivered']) query('UPDATE order_items SET delivered_quantity=quantity WHERE order_id=?',[$id]);
            if($o['paid']) {
                $key=substr(hash('sha256','legacy-receipt-'.$id),0,32);
                query('INSERT INTO payments (order_id,request_key,amount_cents,method,paid_on,note,created_by) VALUES (?,?,?,?,NULL,?,?)',[$id,$key,$o['total_cents'],$o['payment_method']?:'legacy','기존 전액 입금 기록 · 정확한 입금일 미기록',$o['created_by']]);
                allocatePayment((int)db()->lastInsertId(),$id,(int)$o['total_cents'],true);
            }
            $shipped=(int)query('SELECT COALESCE(SUM(delivered_quantity),0) FROM order_items WHERE order_id=?',[$id])->fetchColumn();
            query('UPDATE orders SET received_cents=?,shipped_units=? WHERE id=?',[$o['paid']?$o['total_cents']:0,$shipped,$id]);
        }
        query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);db()->commit();
    } catch(Throwable $e) {db()->rollBack();throw $e;}
}


$version='20261106_import_storage';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()) {
    $fields=[
      'customers'=>['customer_kind'=>"VARCHAR(20) NOT NULL DEFAULT 'person'",'affiliation'=>"VARCHAR(100) NOT NULL DEFAULT ''",'contact_name'=>"VARCHAR(100) NOT NULL DEFAULT ''"],
      'products'=>['legacy_key'=>'VARCHAR(191) NULL UNIQUE'],
      'orders'=>['other_sales_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','other_charges_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','additional_income_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','import_batch_id'=>'INT UNSIGNED NULL','delivery_state_known'=>'TINYINT NOT NULL DEFAULT 1','payment_state_known'=>'TINYINT NOT NULL DEFAULT 1','production_state'=>'TINYINT NULL'],
      'payments'=>['additional_income_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0'],
      'payment_allocations'=>['allocation_type'=>"VARCHAR(30) NOT NULL DEFAULT 'product'"]
    ];
    foreach($fields as $table=>$columns){$known=query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(PDO::FETCH_COLUMN);foreach($columns as $name=>$type)if(!in_array($name,$known,true))db()->exec("ALTER TABLE $table ADD COLUMN $name $type");}
    db()->exec('ALTER TABLE products MODIFY weight_g INT UNSIGNED NULL');
    db()->exec('ALTER TABLE order_items MODIFY weight_g INT UNSIGNED NULL');
    db()->exec('ALTER TABLE orders MODIFY delivery_date DATE NULL');
    query("UPDATE payment_allocations SET allocation_type='delivery_fee' WHERE item_id IS NULL");
    query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);
}


$version='20261106_payer_names';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()) {
    foreach(['customers','payments'] as $table) {
        $exists=query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='payer_name'",[$table])->fetchColumn();
        if(!$exists)db()->exec("ALTER TABLE $table ADD COLUMN payer_name VARCHAR(100) NOT NULL DEFAULT ''");
    }
    // Existing notes/nicknames are not unambiguously payer names; preserve them without guessing.
    query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);
}
