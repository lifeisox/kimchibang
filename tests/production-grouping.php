<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/production.php';
function check($value,$label){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
$rows=[];
foreach([['주문분','맛김치',10000,2,8700,1700,565.5],['주문분','맛김치',5000,3,4350,850,282.75],['주문분','포기김치',10000,1,9500,1200,902.5],['주문분','포기김치',5000,2,4750,600,451.25],['마트판매분','맛김치',1600,2,1392,272,90.48],['마트판매분','맛김치',850,3,739.5,144.5,48.0675],['마트판매분','맛김치',300,4,261,51,16.965],['마트판매분','비건김치',500,1,340,70,22.1],['마트판매분','비건김치',450,2,306,63,19.89],['마트판매분','백김치',850,1,450.5,119,29.2825]] as [$channel,$name,$weight,$qty,$cabbage,$radish,$salt])$rows[]=['channel'=>$channel,'name'=>$name,'weight_g'=>$weight,'quantity'=>$qty,'grams'=>$weight*$qty,'materials'=>['배추'=>$cabbage*$qty,'무우'=>$radish*$qty,'절임소금'=>$salt*$qty]];
$original=$rows;$g=productionSummaryRows($rows);
check(count($g)===5,'종류별 5행으로 합산 / 주문분·마트분 분리');
check($g[0]['quantity']===5&&$g[0]['grams']===35000,'주문 맛10kg 2개+맛5kg 3개 = 5개·35kg');
check($g[1]['quantity']===3&&$g[1]['grams']===20000,'주문 포기10kg 1개+포기5kg 2개 = 3개·20kg');
check($g[2]['quantity']===9&&$g[2]['grams']===6950,'마트 맛1.6kg·850g·300g 합산');
check($g[3]['quantity']===3&&$g[3]['grams']===1400,'마트 비건500g·450g 합산 / 백김치 분리');
foreach(['배추','무우','절임소금'] as $m){$before=array_sum(array_column(array_column($rows,'materials'),$m));$after=array_sum(array_column(array_column($g,'materials'),$m));check(abs($before-$after)<0.000001,$m.' 총량 보존 (중간 반올림 없음)');}
check($rows===$original,'기존 저장 스냅샷 상세 보존');
$rows[]=['channel'=>'주문분','name'=>'맛김치','quantity'=>1,'grams'=>null,'materials'=>[]];$g=productionSummaryRows($rows);
check($g[0]['incomplete']&&$g[0]['grams']===null&&$g[0]['quantity']===6,'같은 종류 중 미확인 용량·배합 경고 유지');
