"""No-network region classification and pickup/church/delivery rules."""
from pathlib import Path
import subprocess
code='''<?php require 'app/bootstrap.php';require 'app/customer-import.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\\n";}
foreach(['센터'=>'Carlington','센터(158)'=>'Carlington','바헤븐'=>'Barrhaven','스팇빌'=>'Stittsville','대사관'=>'Downtown','다운타운'=>'Downtown','핀들레'=>'Findlay','올리즈'=>'East','올린즈'=>'East','카나다'=>'Kanata','카나타'=>'Kanata','교회'=>'Etc','픽업'=>'Etc','기타'=>'Etc','칼링'=>'Carlington','미등록지역'=>'Etc'] as $source=>$expected)check(importedCustomerArea('123 Example Street',$source)['area']===$expected,'D 지역 대응표 '.$source);
check(importedCustomerArea('123 Example St, Kanata, ON','바헤븐')['area']==='Kanata','주소에 명확한 지역명 우선');
check(importedCustomerArea('123 Example St, Nepean, ON','바헤븐')['area']==='Barrhaven','넓은 Nepean 우편 표기는 구체적 D 지역을 덮어쓰지 않음');
check(importedCustomerArea('10 Downtown Road, Ottawa','바헤븐')['area']==='Barrhaven','도로 이름을 지역명으로 추정하지 않음');
check(importedCustomerArea('10 Nepean Street, Ottawa','미등록')['area']==='Etc','넓은 우편 지역도 도로명과 구분');
check(importedCustomerMethod(['address'=>'123 Test','delivery_preference_raw'=>'교회'])==='church','H 교회 우선');
check(importedCustomerMethod(['address'=>'123 Test','delivery_preference_raw'=>'픽업'])==='pickup','H 픽업 우선');
check(importedCustomerMethod(['address'=>'123 Test','delivery_preference_raw'=>'현금수금'])==='delivery','주소 있음 배달');
check(importedCustomerMethod(['address'=>'','delivery_preference_raw'=>''])==='pickup','주소 없음 픽업');
'''
r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=Path(__file__).resolve().parent.parent,input=code,text=True,capture_output=True)
print(r.stdout)
if r.returncode:raise RuntimeError(r.stderr or r.stdout)
