# 클라우드 링크로 테스트하기

개발·테스트는 클라우드에서 하고 완성 후 Hostinger에 배포합니다. Windows 설치·ZIP 다운로드는 필요 없습니다.

## 처음 한 번: GitHub Codespaces 시작

1. GitHub에 로그인하고 프로젝트의 `codex/cloud-preview` 브랜치를 엽니다.
2. 초록색 **Code → Codespaces → Create codespace on codex/cloud-preview**를 누릅니다. 이전에 만들어 둔 Codespace 대신 이 브랜치의 새 공간을 사용합니다.
3. GitHub가 계정의 무료 사용량이나 결제 설정을 표시하면 먼저 확인합니다. 요금제에 따라 컴퓨팅·저장공간 비용이 생길 수 있습니다. 유료 사용을 자동 승인하거나 결제 정보를 설정하지 않습니다.
4. 처음 준비에는 몇 분 걸립니다. PHP와 MySQL이 자동으로 켜지며 첫 번째 DB는 비어 있습니다.
5. 앱 탭이 자동으로 열리지 않으면 아래 **PORTS(포트)**에서 **8000 / Rodem Namu kimchi app** 행의 지구본 모양 **Open in Browser**를 누릅니다. 이 URL이 실제 시험용 HTTPS 링크입니다. 이 링크를 알려주시면 이후 화면 안내에 사용할 수 있습니다.
6. 앱에서 관리자 비밀번호를 직접 정합니다. 아이디는 `admin`입니다. 비밀번호를 채팅에 보내지 마세요.

Codespaces 브라우저 창을 쓰며 Windows에 Docker나 PHP를 설치할 필요는 없습니다. 포트 공개 범위는 **Private**를 유지합니다. GitHub 로그인으로 접근하는 비공개 미리보기입니다. 공개 링크나 무인 운영 서버로 쓰지 않습니다.

## 이후 사용

GitHub의 **Your codespaces**에서 같은 공간을 다시 열고 앱 링크로 접속합니다. Codespace가 중지되면 링크도 일시 중지됩니다. 중지 후 다시 시작하면 데이터는 유지되지만 Codespace 자체를 삭제하면 이 환경의 DB도 잃을 수 있으므로 백업을 보관해야 합니다. 앱에 보이는 최신 기능은 그 Codespace가 실행 중인 코드 기준이며 브랜치 변경이 자동 배포되는 것은 아닙니다. 기능 갱신은 개발자가 해당 코드와 DB를 확인한 뒤 진행합니다.

처음에는 테스트 고객으로 주문·배송·분할 입금을 연습합니다. 현재 개발 환경의 실고객 193명·과거 주문 946건은 Git에 포함되지 않으므로 새 Codespace에는 자동으로 들어가지 않습니다. 실고객 자료 이전은 가상 자료로 동작과 접근 통제를 확인한 뒤 별도로 진행합니다.

## 개발자용

`.devcontainer/devcontainer.json`은 `compose.yaml`과 `.devcontainer/compose.yaml`을 병합합니다. 초기 설정 파일은 `scripts/configure.py`로 생성하고 덮어쓰지 않습니다. DB는 전용 named volume에 보관합니다. `postCreateCommand`는 마이그레이션을 실행합니다. `overrideCommand=true`로 에디터 컨테이너를 유지하고, `postStartCommand`의 `scripts/cloud-start.sh`가 마이그레이션 후 PHP 웹 서버를 별도로 시작합니다. 기존 서버가 있으면 중복 실행하지 않으며 HTTP 200 응답을 확인합니다. 기존 자료·가격을 보존합니다. 포트 8000은 PHP 서버이며 Codespaces 프록시를 통해 HTTPS로 연결합니다. `APP_SECURE_COOKIE=1`을 유지합니다.

최초 계정 생성 UI는 `APP_ALLOW_INITIAL_SETUP=1`이고 사용자가 0명일 때만 보입니다. CSRF·비밀번호 확인·DB별 잠금으로 보호하며 계정이 만들어지면 닫힙니다. 일반 개발과 운영에서는 이 옵션을 끕니다.

현재 Codex 작업 환경에는 외부 미리보기 URL 생성 도구가 없으며 GitHub API를 통한 Codespaces 생성도 사용할 수 없습니다. 실제 Codespaces 생성·HTTPS 경로·GitHub 포트 인증은 사용자의 계정에서 최초 실행 후 확인해야 합니다. Docker Compose 구성과 앱 초기 설정은 별도로 검증합니다. 링크가 생기기 전에는 실제 접속 주소를 만들어 안내하지 않습니다.


## 8000 주소에서 HTTP 502가 나오는 경우

터미널에서 `/app`과 PHP 버전이 확인된다면 다음 명령으로 서버를 직접 시작해 확인합니다.

```sh
php scripts/init.php && php -S 0.0.0.0:8000 -t public
```

`Development Server ... started`가 나오면 이 터미널을 닫지 말고 앱 탭을 새로고침합니다. `Address already in use`가 나오면 포트가 이미 사용 중입니다. 프로세스를 임의 종료하지 않습니다. PORTS에서 8000 행의 **Change Port Protocol → HTTP**를 확인하고, 브라우저의 GitHub 주소는 **HTTPS**를 그대로 사용합니다. 내부 응답은 아래 명령으로 확인하며, HTTP 200인데 외부가 502이면 포트 전달·프록시 연결을 확인합니다. HTTP 500이면 앱 로그를 확인합니다.

```sh
php -r '$h=@get_headers("http://127.0.0.1:8000/"); echo ($h[0] ?? "내부 서버 응답 없음"), PHP_EOL;'
```

최신 자동 실행 설정은 기존 Codespace에 저절로 반영되지 않습니다. 개발자가 변경 사항을 전달한 뒤 소스 갱신과 Rebuild Container를 진행합니다. 로컬 변경과 DB를 먼저 확인하고 Codespace를 삭제·재생성하지 않습니다.
