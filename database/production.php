<?php
// New tables only; recipes and retail packs never enter the sales catalog.
db()->exec("CREATE TABLE IF NOT EXISTS production_recipes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, seed_key VARCHAR(40) NULL UNIQUE, name VARCHAR(100) NOT NULL, base_g INT UNSIGNED NOT NULL, brine_group VARCHAR(10) NOT NULL DEFAULT 'none', ingredients LONGTEXT NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS production_retail (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, weight_g INT UNSIGNED NOT NULL, recipe_id INT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS production_links (product_id INT UNSIGNED PRIMARY KEY, recipe_id INT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS production_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(32) NOT NULL UNIQUE, plan_date DATE NOT NULL, title VARCHAR(100) NOT NULL, snapshot LONGTEXT NOT NULL, created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$version='20261108_production';
if(!query('SELECT version FROM schema_migrations WHERE version=?',[$version])->fetch()) {
 db()->beginTransaction();
 try {
  $defs=[['pogi','포기김치',95,12,9.5,'pogi'],['whitepogi','백포기김치',70,20,9.5,'pogi'],['mat','맛김치',87,17,6.5,'mat'],['fresh','겉절이',95,12,9.5,'pogi'],['water','물김치·순한맛',33,10,6.5,'mat'],['dong','동치미',0,38,0,'none'],['radish','깍두기',0,105,0,'none'],['green','파김치',0,0,0,'none'],['vegan','비건김치',68,14,6.5,'mat'],['white','백김치',53,14,6.5,'mat']];$ids=[];
  foreach($defs as [$key,$name,$c,$r,$s,$group]) {
   $items=[];foreach(['배추'=>$c,'무우'=>$r,'절임소금'=>$s,'파'=>$key==='green'?90:0] as $m=>$n)if($n)$items[]=['material'=>$m,'basis'=>$m==='절임소금'?'배추':'product','mode'=>'percent','amount'=>$n];
   query('INSERT INTO production_recipes (seed_key,name,base_g,brine_group,ingredients) VALUES (?,?,1000,?,?)',[$key,$name,$group,json_encode($items,JSON_UNESCAPED_UNICODE)]);$ids[$key]=(int)db()->lastInsertId();
  }
  foreach(['포10'=>'pogi','포5'=>'pogi','포백5'=>'whitepogi','맛10'=>'mat','맛5'=>'mat','겉'=>'fresh','물'=>'water','물백'=>'water','동'=>'dong','깍'=>'radish','파'=>'green'] as $code=>$key)query('INSERT INTO production_links (product_id,recipe_id) SELECT id,? FROM products WHERE code=?',[$ids[$key],$code]);
  foreach([['포기김치',3800,'pogi'],['맛김치',1600,'mat'],['맛김치',850,'mat'],['맛김치',300,'mat'],['비건김치',500,'vegan'],['비건김치',450,'vegan'],['백김치',850,'white'],['깍두기',800,'radish'],['파김치',300,'green']] as [$name,$g,$key])query('INSERT INTO production_retail (name,weight_g,recipe_id) VALUES (?,?,?)',[$name,$g,$ids[$key]]);
  query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);db()->commit();
 } catch(Throwable $e){db()->rollBack();throw $e;}
}
