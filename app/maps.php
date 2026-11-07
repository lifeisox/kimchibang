<?php
declare(strict_types=1);
function mapAddress(string $address): ?string {
    $address=trim(preg_replace('/\s+/u',' ',$address)??'');
    if(mb_strlen($address)<6||mb_strlen($address)>500||preg_match('~https?://|[<>@]~i',$address))return null;
    if(!preg_match('/\pL/u',$address)||!preg_match('/(?:^|[ ,#-])\d{1,6}[a-z]?(?:\s|,|-|$)/iu',$address))return null;
    return $address;
}
function mapSearchUrl(string $address): ?string {
    $value=mapAddress($address);return $value===null?null:'https://www.google.com/maps/search/?'.http_build_query(['api'=>'1','query'=>$value],'','&',PHP_QUERY_RFC3986);
}
function mapLink(string $address): string {
    $url=mapSearchUrl($address);return $url===null?'':'<a class="map-link no-print" href="'.e($url).'" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" aria-label="Google 지도에서 주소 확인 (새 탭)">Google 지도 ↗</a>';
}
