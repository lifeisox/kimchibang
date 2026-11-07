<?php
declare(strict_types=1);
function importedCustomerMethod(array $c): string {
    $flag=trim((string)($c['delivery_preference_raw']??''));
    if(str_contains($flag,'교회'))return 'church';
    if(str_contains($flag,'픽업')||preg_match('/\bpick\s*up\b/i',$flag))return 'pickup';
    return trim($c['address'])===''?'pickup':'delivery';
}
function importedCustomerArea(string $address,string $source): array {
    // Infer only an explicit neighbourhood name, never a street or a guessed postal boundary.
    $addressNames=['Barrhaven'=>'Barrhaven','Kanata'=>'Kanata','Stittsville'=>'Stittsville','Carlington'=>'Carlington','Downtown'=>'Downtown','Centretown'=>'Downtown','Vanier'=>'Vanier','Findlay Creek'=>'Findlay','Findlay'=>'Findlay','Orleans'=>'East','Orléans'=>'East'];
    foreach($addressNames as $needle=>$area)if(preg_match('/(?<![\pL])'.preg_quote($needle,'/').'(?![\pL])(?!\s+(?:road|rd|avenue|ave|street|st|drive|dr|crescent|cres|lane|ln|way|place|pl|court|ct|terrace|terr|trail|trl|boulevard|blvd)\b)/iu',$address))return ['area'=>$area,'basis'=>'주소의 지역명'];
    $text=trim($source);$map=['센터'=>'Carlington','센터(158)'=>'Carlington','바헤븐'=>'Barrhaven','스팇빌'=>'Stittsville','대사관'=>'Downtown','다운타운'=>'Downtown','핀들레'=>'Findlay','올리즈'=>'East','올린즈'=>'East','올리언즈'=>'East','카나다'=>'Kanata','카나타'=>'Kanata','교회'=>'Etc','픽업'=>'Etc','기타'=>'Etc','칼링'=>'Carlington'];
    if(isset($map[$text]))return ['area'=>$map[$text],'basis'=>'엑셀 D 지역'];
    if(in_array($text,CUSTOMER_AREAS,true))return ['area'=>$text,'basis'=>'엑셀 D 지역'];
    // Accept spelling variants while storing the canonical names used by the area's dropdown.
    if(strcasecmp($text,'Calrinton')===0)return ['area'=>'Carlington','basis'=>'엑셀 D 지역 표기 정리'];
    if(strcasecmp($text,'Sttitsville')===0)return ['area'=>'Stittsville','basis'=>'엑셀 D 지역 표기 정리'];
    if(preg_match('/\bNepean\b(?!\s+(?:road|rd|street|st|avenue|ave|drive|dr|crescent|cres|lane|ln|way|place|pl|court|ct|terrace|terr|trail|trl|boulevard|blvd)\b)/i',$address))return ['area'=>'Nepean','basis'=>'주소의 지역명'];
    return ['area'=>'Etc','basis'=>'일치하는 지역 없음'];
}
