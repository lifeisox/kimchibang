<?php
require_once __DIR__.'/../app/bootstrap.php';
db()->exec(file_get_contents(__DIR__.'/../database/schema.sql'));
require __DIR__.'/../database/migrate.php';
$products=[['포10','포기김치',10000,9000],['포5','포기김치',5000,5000],['물','물김치',3200,3000],['물백','물김치순한맛',3200,3000],['깍','깍두기',2500,3000],['파','파김치',1500,3000],['총각','총각김치',2500,3500],['갓','갓김치',2000,3000],['열','열무김치',2500,3500],['겉','겉절이',2500,3500],['동','동치미',3200,3500],['맛5','맛김치',5000,5000],['맛10','맛김치',10000,9000],['포백5','백포기김치',5000,5000]];
$order=array_flip(['포10','포5','맛10','맛5','깍','파','겉','물','물백','동','총각','갓','열','포백5']);
foreach($products as $p) query('INSERT INTO products (code,name,weight_g,price_cents,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[...$p,$order[$p[0]]+1]);
echo "DB와 초기 제품 준비 완료 (기존 가격 보존)\n";
