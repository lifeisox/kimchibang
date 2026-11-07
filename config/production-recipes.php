<?php
// Only coefficients evidenced by the user's workbook. Missing cells remain unknown, never zero.
return [
 'personal'=>[
  'cabbage'=>['name'=>'포기김치','codes'=>['포10','포5'],'cabbage'=>[960,1000],'radish'=>[100,1000],'source'=>'소요량 3행 · 배추 800g × 1.2 / 무 100g (제품 1kg당)'],
  'white'=>['name'=>'백포기김치','codes'=>['포백5'],'cabbage'=>[828,1000],'radish'=>[200,1000],'source'=>'소요량 4행 · 배추 690g × 1.2 / 무 200g'],
  'cut'=>['name'=>'맛김치','codes'=>['맛10','맛5'],'cabbage'=>[864,1000],'radish'=>[170,1000],'source'=>'소요량 5행 · 배추 720g × 1.2 / 무 170g'],
  'water'=>['name'=>'물김치','codes'=>['물'],'cabbage'=>[330,1000],'radish'=>[100,1000],'source'=>'소요량 7행 · 배추 300g × 1.1 / 무 100g'],
  'dong'=>['name'=>'동치미','codes'=>['동'],'cabbage'=>null,'radish'=>[380,1000],'source'=>'소요량 8행 · 배추 값 미기록 / 무 380g · 판매 용량은 등록된 3.2kg 사용'],
  'cubed'=>['name'=>'깍두기','codes'=>['깍'],'cabbage'=>null,'radish'=>[1000,950],'source'=>'소요량 9행 · 배추 값 미기록 / G9의 무 1000 ÷ 950 손실 보정'],
  'fresh'=>['name'=>'겉절이','codes'=>['겉'],'cabbage'=>[880,1000],'radish'=>[0,1000],'source'=>'소요량 10행 · 배추 800g × 1.1 / 무 0g (공유수식 포함)']
 ],
 'retail'=>[
  'cut'=>['name'=>'소매점 맛김치','cabbage'=>[864,1000],'radish'=>[170,1000],'source'=>'소요량 13행 · 배추 720g × 1.2 / 무 170g'],
  'vegan'=>['name'=>'소매점 비건김치','cabbage'=>[672,1000],'radish'=>[140,1000],'source'=>'소요량 14행 · 배추 560g × 1.2 / 무 140g'],
  'white'=>['name'=>'소매점 백김치','cabbage'=>[5148,10000],'radish'=>[140,1000],'source'=>'소요량 15행 · 배추 440g × 1.17 / 무 140g'],
  'fresh'=>['name'=>'소매점 겉절이','cabbage'=>[936,1000],'radish'=>null,'source'=>'소요량 16행 · 배추 800g × 1.17 / 무 값 미기록'],
  'cubed'=>['name'=>'소매점 깍두기','cabbage'=>null,'radish'=>[1,1],'source'=>'소요량 17행 · 배추 값 미기록 / 무 1000g']
 ]
];
