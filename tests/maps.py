from pathlib import Path
import subprocess
code='''<?php require 'app/bootstrap.php';
foreach(['','픽업','교회','미등록','613-123-4567','https://example.com/123','123 <script>St'] as $bad)if(mapSearchUrl($bad)!==null)throw new RuntimeException('Invalid address');
foreach(['812 Example Way Nepean',"123 O'Connor St #2 & Bank, Ottawa",'Unit 2 - 12 Main St, Ottawa'] as $value){$url=mapSearchUrl($value);if(!$url || parse_url($url,PHP_URL_HOST)!=='www.google.com')throw new RuntimeException('Map host');parse_str(parse_url($url,PHP_URL_QUERY),$q);if($q['query']!==$value)throw new RuntimeException('URL encoding');}
echo "PASS 빈값·메모·전화·URL 제외 / 지도 URL 고정 도메인·주소 인코딩\\n";
'''
r=subprocess.run(['docker','compose','exec','-T','web','php'],cwd=Path(__file__).resolve().parent.parent,input=code,text=True,capture_output=True)
print(r.stdout)
if r.returncode:raise RuntimeError(r.stderr or r.stdout)
