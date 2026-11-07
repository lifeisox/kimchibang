<?php
declare(strict_types=1);
function productionRecipes(): array {return require __DIR__.'/../config/production-recipes.php';}
function productionKilograms(mixed $v): int {
    if(!is_string($v) || !preg_match('/^\d{1,5}(?:\.\d{1,3})?$/D',$v))throw new InvalidArgumentException('소매점 물량은 kg 단위, 소수 셋째 자리까지 입력해 주세요.');
    $parts=explode('.',$v);return (int)$parts[0]*1000+(int)str_pad($parts[1]??'',3,'0');
}
function productionRequirements(array $items,array $retail=[]): array {
    $recipes=productionRecipes();$groups=[];$totals=['cabbage'=>0.0,'radish'=>0.0];$missing=['cabbage'=>0,'radish'=>0];
    foreach($items as $item) {
        $qty=pendingQty($item);if($qty<=0)continue;
        $recipe=null;$key='unknown:'.$item['product_code'].':'.($item['weight_g']??'unknown');
        foreach($recipes['personal'] as $id=>$r)if(in_array($item['product_code'],$r['codes'],true)){$recipe=$r;$key='personal:'.$id;break;}
        $grams=$item['weight_g']===null?null:(int)$item['weight_g']*$qty;
        if(!isset($groups[$key]))$groups[$key]=['name'=>$recipe['name']??$item['product_name'],'quantity'=>0,'product_g'=>0,'unknown_weight'=>false,'recipe'=>$recipe];
        $groups[$key]['quantity']+=$qty;
        if($grams===null)$groups[$key]['unknown_weight']=true;else $groups[$key]['product_g']+=$grams;
    }
    foreach($retail as $key=>$value) {
        if(!isset($recipes['retail'][$key]))throw new InvalidArgumentException('소매점 제품 항목을 확인해 주세요.');
        $grams=productionKilograms($value===''?'0':$value);if(!$grams)continue;
        $groups['retail:'.$key]=['name'=>$recipes['retail'][$key]['name'],'quantity'=>null,'product_g'=>$grams,'unknown_weight'=>false,'recipe'=>$recipes['retail'][$key]];
    }
    foreach($groups as &$g)foreach(['cabbage','radish'] as $ingredient) {
        $ratio=$g['recipe'][$ingredient]??null;
        if($ratio===null || $g['unknown_weight']){$g[$ingredient]=null;$missing[$ingredient]++;}
        else {$g[$ingredient]=$g['product_g']*$ratio[0]/$ratio[1];$totals[$ingredient]+=$g[$ingredient];}
    }unset($g);
    return ['groups'=>$groups,'totals'=>$totals,'missing'=>$missing];
}
function productionKg(?float $grams): string {return $grams===null?'기준 확인 필요':number_format(ceil($grams-1e-9)/1000,3).' kg';}
function productionSelection(mixed $values): array {
    if(!is_array($values)||count($values)>200)throw new InvalidArgumentException('한 번에 최대 200개 주문을 선택해 주세요.');
    $ids=[];foreach($values as $id)$ids[]=positiveInt($id,1,PHP_INT_MAX);return array_values(array_unique($ids));
}
