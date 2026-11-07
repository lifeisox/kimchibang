"""Pure calculations: pending quantities, workbook ratios and explicit unknown coefficients."""
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parent.parent
code='''<?php require 'app/bootstrap.php';require 'app/production.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\\n";}
$items=[['product_code'=>'포5','product_name'=>'포기김치','weight_g'=>5000,'quantity'=>3,'delivered_quantity'=>1,'cancelled_quantity'=>1],['product_code'=>'깍','product_name'=>'깍두기','weight_g'=>2500,'quantity'=>2,'delivered_quantity'=>1,'cancelled_quantity'=>0],['product_code'=>'물백','product_name'=>'물김치순한맛','weight_g'=>3200,'quantity'=>1,'delivered_quantity'=>0,'cancelled_quantity'=>0]];
$r=productionRequirements($items,['white'=>'0.5']);
check($r['groups']['personal:cabbage']['quantity']===1 && $r['groups']['personal:cabbage']['product_g']===5000,'전달·취소 수량 제외');
check(abs($r['groups']['personal:cabbage']['cabbage']-4800)<.0001 && abs($r['groups']['personal:cabbage']['radish']-500)<.0001,'포기김치 엑셀 보정값');
check(abs($r['groups']['personal:cubed']['radish']-2500000/950)<.0001,'개인 깍두기 1000/950 보정');
check($r['missing']['cabbage']===2 && $r['missing']['radish']===1,'빈 배합값·순한맛 기준을 0으로 추정하지 않음');
check(abs($r['totals']['cabbage']-5057.4)<.0001,'소매점 백김치 1.17 보정·개인 주문 합산');
check(productionKilograms('0.001')===1 && productionKilograms('12.345')===12345,'kg 입력을 정수 g로 보존');
check(productionKg(1234.01)==='1.235 kg' && productionKg(null)==='기준 확인 필요','표시 올림·미확인 구분');
$items[0]['delivered_quantity']=2;check(!isset(productionRequirements([$items[0]])['groups']['personal:cabbage']),'남은 수량 0은 계산 제외');
$items[0]['delivered_quantity']=0;$items[0]['weight_g']=null;check(productionRequirements([$items[0]])['missing']['cabbage']===1,'용량 미확인은 재료 미확인');
foreach(['-1','1e5','1.0001'] as $v){try{productionKilograms($v);throw new RuntimeException('accepted bad quantity');}catch(InvalidArgumentException $e){}}
check(deliveryFee(5000,'church')===0 && deliveryFee(5000,'pickup')===0 && deliveryFee(5000,'delivery')===500,'교회·픽업 무료 / 배달 5달러');
'''
r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=root,input=code,text=True,capture_output=True)
print(r.stdout)
if r.returncode:raise RuntimeError(r.stderr or r.stdout)
