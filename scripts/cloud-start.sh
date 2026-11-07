#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
mkdir -p .local
php scripts/init.php
# Codespaces may replace the container CMD with its editor keep-alive process.
# Start the app explicitly in the lifecycle hook and keep it independent of the terminal.
if php -r '$s=@fsockopen("127.0.0.1",8000,$errno,$error,1);if(!$s)exit(1);fclose($s);'; then
    echo '포트 8000의 기존 서버를 유지합니다.'
else
    nohup php -S 0.0.0.0:8000 -t public > .local/cloud-server.log 2>&1 < /dev/null &
    echo "$!" > .local/cloud-server.pid
fi
attempt=0
while [ "$attempt" -lt 20 ]; do
    if php -r '$c=stream_context_create(["http"=>["timeout"=>2,"ignore_errors"=>true]]);@file_get_contents("http://127.0.0.1:8000/",false,$c);exit(isset($http_response_header[0]) && preg_match("~^HTTP/\\S+ 200(?: |$)~",$http_response_header[0])?0:1);'; then
        echo '김치방 앱 준비 완료 · PORTS에서 8000 → Open in Browser로 여세요.'
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 0.2
done
echo '앱이 정상 응답하지 않습니다. .local/cloud-server.log의 오류를 확인해 주세요. 비밀번호는 공유하지 마세요.' >&2
exit 1
