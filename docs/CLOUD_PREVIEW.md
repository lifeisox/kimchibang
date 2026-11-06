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

`.devcontainer/devcontainer.json`은 `compose.yaml`과 `.devcontainer/compose.yaml`을 병합합니다. 초기 설정 파일은 `scripts/configure.py`로 생성하고 덮어쓰지 않습니다. DB는 전용 named volume에 보관합니다. `postCreateCommand`와 `postStartCommand`는 마이그레이션을 실행하며 기존 자료·가격을 보존합니다. 포트 8000은 PHP 서버이며 Codespaces 프록시를 통해 HTTPS로 연결합니다. `APP_SECURE_COOKIE=1`을 유지합니다.

최초 계정 생성 UI는 `APP_ALLOW_INITIAL_SETUP=1`이고 사용자가 0명일 때만 보입니다. CSRF·비밀번호 확인·DB별 잠금으로 보호하며 계정이 만들어지면 닫힙니다. 일반 개발과 운영에서는 이 옵션을 끕니다.

현재 Codex 작업 환경에는 외부 미리보기 URL 생성 도구가 없으며 GitHub API를 통한 Codespaces 생성도 사용할 수 없습니다. 실제 Codespaces 생성·HTTPS 경로·GitHub 포트 인증은 사용자의 계정에서 최초 실행 후 확인해야 합니다. Docker Compose 구성과 앱 초기 설정은 별도로 검증합니다. 링크가 생기기 전에는 실제 접속 주소를 만들어 안내하지 않습니다.
