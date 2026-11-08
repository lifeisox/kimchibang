# Hostinger 실전 테스트 — k.ottawakfood.com

사용자 확인: Premium Web Hosting, k 서브도메인 생성 완료, SSH 접속 성공. 공개 경로는 `/home/u870259482/domains/ottawakfood.com/public_html/kimchibang`, 비공개 경로는 `/home/u870259482/domains/ottawakfood.com/kimchibang`입니다. CLI PHP 8.5.12와 필요한 확장 확인 완료. 웹 PHP 버전과 실제 DB 연결은 설치 후 별도로 검증합니다. 최신 엑셀로 새 DB에서 시작하며 기존 Codespaces 업무 DB는 복원하지 않습니다.

배포 대상은 기존 ottawakfood.com 사이트와 분리된 서브도메인입니다. 기존 사이트의 public_html 전체를 덮어쓰거나 지우지 마세요. 아직 실제 Hostinger 서버 배포는 실행하지 않았습니다.

## 1. hPanel에서 준비

1. ottawakfood.com 웹사이트 관리 → 서브도메인에서 `k`를 생성합니다. 웹 폴더 위치를 확인합니다. 아래 예시는 `domains/ottawakfood.com/public_html/kimchibang`입니다. 실제 경로가 다르면 설치 경로를 조정해야 합니다.
2. 서브도메인의 SSL 인증서를 활성화하고 HTTPS 접속을 확인합니다. DNS를 다른 업체에서 관리 중이면 Hostinger가 안내하는 서버 IP로 k 레코드를 추가합니다. 기존 루트 도메인·메일 레코드는 유지합니다.
3. MySQL 관리에서 이 앱 전용의 빈 DB와 전용 사용자를 만듭니다. DB 이름·사용자·접속 호스트는 hPanel에 표시된 정확한 값을 사용합니다. 실제 비밀번호는 채팅에 올리지 않습니다.
4. PHP는 지원되는 8.2 이상으로 선택합니다. 현재 개발 환경은 PHP 8.5입니다. `pdo_mysql`, `mbstring`, `SimpleXML`, `Phar`, `session` 확장이 필요합니다. 메인 사이트와 PHP 설정을 공유하는 상품이면 기존 사이트 호환성을 먼저 확인합니다.
5. SSH 접속 메뉴의 제공 여부를 확인합니다. 제공되지 않으면 이 문서의 CLI 명령을 그대로 실행할 수 없으므로 상품·실행 방식부터 확인해야 합니다.

## 2. 배포 파일 구조

개발 환경에서 `php scripts/package-hostinger.php` (또는 Python이 있는 환경에서 `python scripts/package-hostinger.py`)를 실행하면 `dist/kimchibang-hostinger.zip`이 생성됩니다. DB 비밀번호·계정·고객 자료·엑셀·백업·개발용 실행 도구는 포함하지 않습니다. 원본 로고는 따로 옮기거나 앱에서 등록합니다.

압축을 **도메인 폴더**에 풀었을 때 다음 구조가 되어야 합니다. ZIP 자체는 공개 폴더에 보관하지 마세요.

```text
domains/ottawakfood.com/
  kimchibang/                 ← 비공개 앱·설정·백업
    app/
    config/
    database/
    scripts/
    public/index.php
  public_html/
    k/                        ← k.ottawakfood.com 웹 루트
      index.php
      .htaccess
      .user.ini
      assets/
      favicon.ico
```

공개 index.php는 `../../kimchibang/public/index.php`를 불러옵니다. 서브도메인 폴더 위치가 다르면 이 한 경로를 실제 비공개 앱 위치에 맞춥니다. `.htaccess`와 `.user.ini`도 함께 업로드해야 합니다. Apache/LiteSpeed의 rewrite와 .htaccess 지원은 실제 서버에서 검증합니다. HTTPS 강제 이동은 인증서 설정 후 사용합니다.

## 3. 서버에서만 설정

비공개 `kimchibang/config/hostinger.example.php`를 `local.php`로 복사하고 DB 접속 값을 입력합니다. 기존 환경 변수가 있으면 그 값이 파일 설정보다 우선합니다. 운영 설정은 다음과 같습니다.

```text
APP_SECURE_COOKIE=1
APP_ALLOW_INITIAL_SETUP=0
APP_HIDE_IMPORT_AFTER_COMPLETE=1
```

설정 파일은 서버 소유자만 읽도록 권한 600, 비공개 저장 폴더 `.local`은 700을 권장합니다. 777은 사용하지 않습니다. 공개 디렉터리에는 local.php, .env, DB 덤프, 엑셀, ZIP을 두지 않습니다.

SSH에서 실제 비공개 앱 폴더로 이동한 뒤:

```sh
php scripts/hostinger-check.php
```

이 검사는 확장·쿠키 설정·비공개 저장 폴더·DB 연결을 확인합니다. 업무 테이블을 생성하거나 기존 주문을 변경하지 않습니다. 서버 DB가 MySQL인지 MariaDB인지와 버전도 확인합니다. MariaDB는 실제 해당 버전에서 스키마와 핵심 업무 검증이 필요합니다.

## 4. 데이터 시작 방식에 따라 설치

### 최신 엑셀로 시작 — 새 빈 DB에만 실행

```sh
php scripts/init.php
read -r -s -p '관리자 비밀번호(12자 이상): ' kimchi_admin_password; echo
printf '%s' "$kimchi_admin_password" | php scripts/create-user.php admin
unset kimchi_admin_password
```

명령에 실제 비밀번호를 직접 적지 않습니다. 비밀번호는 입력 중 표시되지 않습니다. 공개 웹 화면의 초기 계정 생성은 사용하지 않습니다.

HTTPS로 로그인 → 계정 관리에서 배우자 계정 생성 → 로고 등록 → 최신 엑셀 미리보기 → 가져올 건수·금액 확인 → 실제 가져오기 순서로 진행합니다. 가져오기는 기존 업무 자료를 교체하는 기능입니다. 첫 가져오기를 마치면 운영 메뉴에서 사라집니다. 그 뒤 실제 주문을 쓰기 시작하면 재가져오기로 업무 자료를 덮어쓰지 마세요.

### Codespaces 자료를 유지하며 이전

먼저 Codespaces 쓰기 작업을 멈추고 최종 DB 백업과 비공개 로고 파일을 준비합니다. Hostinger의 별도 빈 DB에 복원하여 계정·고객·주문·입금·완료 상태·합계를 비교합니다. 이 경로에서는 엑셀 실제 가져오기를 실행하지 않습니다. DB 덤프는 공개 웹 폴더에 두지 않습니다. 이전 범위와 실제 백업 위치를 확인한 후 구체적인 복원 명령을 정합니다.

## 5. 실제 서버에서 확인할 사항

- HTTPS 인증서·로그인·로그아웃·두 계정의 동시 사용.
- `/config/local.php`, `/.env`, `/app/bootstrap.php`, `/database/schema.sql`, `/.local/`, `/.user.ini` 요청이 파일을 반환하지 않고 403 또는 404인지 확인.
- 초기 관리자 등록 화면이 나타나지 않는지, 로그인하지 않고 고객 API를 조회할 수 없는지 확인.
- 가상 고객으로 주문 → 선입금 → 부분 전달 → 잔여 전달 흐름과 금액·작업자 이력 확인. 실제 입금은 실제 거래에서만 기록.
- PC·셀폰 메뉴, 주소 지도 링크, 인쇄 여백, 통계 정렬 확인.
- Hostinger 백업 제공 범위와 보관 기간 확인. DB·로고·설정은 호스팅 외 안전한 위치에도 백업. 복원은 별도 DB에서 검증.

실전 테스트 중 입력한 업무 자료는 Hostinger에 보관합니다. Codespaces 자료와 자동 동기화되지 않습니다. 이후 변경은 먼저 개발 환경에서 검증한 다음 Hostinger 코드만 업데이트하고, 업데이트 전 DB를 백업합니다. `.local`과 `config/local.php`는 배포 파일로 덮어쓰지 않습니다.

## MariaDB 가져오기 호환성

DB 식별은 MySQL에서는 기존 server_uuid 해시를 유지하고 MariaDB에서는 hostname과 server_id를 이용합니다. MariaDB에는 server_uuid가 없어 이전 코드로 저장 준비 시 오류 1193이 발생했습니다. 수정은 app/import-backup.php의 식별 함수에만 해당하며 스키마·업무 자료 변경은 없습니다. scripts/hostinger-check.php도 식별 조회를 검사합니다. `python tests/mariadb-import.py`는 별도 MariaDB 11.8 서버에서 오류 재현·HTTP 준비/실행·금액 보존·복원·기존 MySQL 해시 호환성을 검증합니다.
