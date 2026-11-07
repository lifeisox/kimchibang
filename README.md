# 로뎀나무 김치방 판매관리

오타와 Rodem Namu의 직원용 PHP 8.5 / MySQL 8.4 판매관리 앱입니다. 한국어 화면, CAD, 오타와 시간 기준이며 금액은 정수 센트로 저장합니다. 외부 유료 API는 사용하지 않습니다.

## 클라우드에서 확인하기

[클라우드 미리보기 안내](docs/CLOUD_PREVIEW.md)에 따라 `codex/cloud-preview` 브랜치의 GitHub Codespaces에서 앱과 DB를 실행합니다. 사용자 컴퓨터에 PHP·Docker를 설치하지 않아도 됩니다. Codespaces 무료 사용량·요금은 사용자 계정에서 확인하며 자동으로 유료 결제를 설정하지 않습니다. 테스트 후 완성되면 Hostinger로 배포합니다.

Codespaces 준비 후 PORTS의 8000에서 Open in Browser를 누릅니다. 포트 프로토콜은 HTTP, 실제 GitHub 브라우저 주소는 HTTPS이며 공개 범위는 Private입니다. 처음에는 앱에서 관리자 비밀번호를 정합니다. 아이디는 admin입니다. 실제 고객 파일과 기존 개발 DB는 Git에 넣지 않으므로 새 Codespace에는 자동으로 포함되지 않습니다.

실행 중인 Codespaces 업데이트:

```sh
cd /app
(command -v git >/dev/null || (apt-get update && apt-get install -y git)) && git -c safe.directory=/app pull --ff-only origin codex/cloud-preview && php scripts/apply-cloud-update.php
```

이 명령은 코드를 fast-forward로 갱신하고 DB를 백업한 뒤 마이그레이션합니다. 완료 후 Ctrl+F5로 새로고침합니다. 충돌은 강제로 덮어쓰지 않습니다. `.env`, `.local/`, DB 볼륨은 삭제하지 마세요.

## 고객과 주문

- 고객은 이름만 필수이며 기본 종류는 개인, 기본 받는 방법은 집으로 배달입니다. 지역 목록, 닉네임, 소속, 단체 담당자, 입금자 성명, 주소, 메모를 기록합니다.
- 고객 이름·닉네임·전화·주소·담당자·소속·입금자 성명으로 검색합니다. 고객 원장에서 새 주문을 열면 해당 고객이 미리 선택됩니다.
- 새 주문에는 고객의 기본 수령 방법과 메모를 복사하며 주문마다 변경할 수 있습니다. 집으로 배달 / 직접 픽업 / 교회 중 선택합니다.
- 배달비는 할인 전 제품 합계 $80 미만이면 $5, $80 이상·픽업·교회 수령은 $0입니다. 과거 주문에는 새 가격·배달비를 소급하지 않습니다.
- **배송과 입금은 독립적으로 기록합니다.** 배송 전 전액·부분 선입금, 배송 후 입금, 분할 입금이 가능합니다. 입력 한도는 전체 주문의 남은 대금입니다. 입금이 완료되어도 배송 전 상태는 그대로 남으며 나중에 전달 완료를 기록합니다.
- 전달 수량의 기본값은 취소·이전 전달을 제외한 남은 전체 수량입니다. 일부만 전달했다면 미전달 제품을 0으로 바꾸고 저장합니다.
- 전달했거나 입금이 배정된 수량은 줄일 수 없습니다. 미입금·미전달 수량은 수정·취소할 수 있고, 완납 후에는 주문 수정·취소가 잠깁니다. 완납 뒤 전달 처리는 가능합니다. 환불·입금 정정은 아직 제공하지 않습니다.
- 주문·입금·전달·취소 이력과 고객 원장을 보존합니다. 취소는 삭제가 아닙니다. 전체 취소는 미배송·미입금 주문에서 사유를 남겨 처리합니다.

[고객·통계·로고 설정](docs/CUSTOMER_SETTINGS.md)을 참고하세요.

## 판매 통계와 브랜드

주간·월간·연간 및 기준 날짜를 선택합니다. 주문일 기준 판매금액, 주문 수, 현재 미입금과 전년도 기간을 비교합니다. 수입 표시는 **판매금액 / 배송비 / 기타수입** 3개입니다. 취소·할인·과거 용량을 반영하며 날짜 없는 입금은 실제 입금일 기준 집계에 추정해 넣지 않습니다. 도로 기준 배달 최적화와 오타와 전체 주소 자동완성은 아직 제공하지 않습니다. 주소는 기존 고객의 등록 주소를 제안합니다.

원본 로고는 앱 주소의 `/?page=brand`에서 PNG를 직접 선택해 적용합니다. 원본 바이트·투명 배경을 유지하고 CSS로 비율을 유지해 축소하며 ETag 캐시를 사용합니다. 원본 적용 전에는 텍스트만 표시합니다. 로고 파일은 `.local/branding/logo.png`에 저장하고 Hostinger 이전 시 함께 옮깁니다.

생산·재료 계산은 사용자 요청으로 제거했습니다. 기존 주문과 원본의 생산 체크 정보는 삭제하지 않습니다.

## 엑셀 가져오기

[현재 가져오기 규칙](docs/EXCEL_IMPORT.md)에 따라 파일 분석 → 저장할 내용 확인 → 백업 후 실제 가져오기로 실행합니다. -$5는 배송비, 나머지 음수는 기타수입, 미판매 과거 제품은 기타, 수량 없는 행은 제외하며 실제 주문 날짜로 집계합니다. 대사관 단체·담당자와 개인을 구분하고 기존 제품·용량 확인과 원본 정정을 유지합니다. 잘못된 금액·숫자·불명확한 고객은 임의로 추측하지 않습니다.

개발 테스트에서는 완료 후에도 메뉴를 유지합니다. Hostinger 최종 배포에서만 `APP_HIDE_IMPORT_AFTER_COMPLETE=1`을 설정해 완료 후 메뉴와 추가 실행을 막습니다. 동일 원본의 같은 행은 중복 등록하지 않으며 변경된 행은 덮어쓰지 않습니다. 다른 파일과의 증분 비교 기능은 제공하지 않습니다. 이전 규칙으로 가져온 DB도 코드 업데이트만으로 덮어쓰거나 자동 재분류하지 않습니다.

## 내부 개발 실행

관리형 개발 환경에 Docker Compose와 Python 3가 있으면 다음을 실행합니다.

```sh
cd /workspace/kimchibang
./scripts/dev.sh
```

내부 주소 `http://127.0.0.1:8080`은 개발 환경 안에서만 접근하며 사용자에게 공개 미리보기 링크로 안내하지 않습니다. 개발 관리자 비밀번호는 `.local/admin-password.txt`에 있고 채팅·Git에 올리지 않습니다. 두 번째 계정은 다음 명령으로 생성합니다.

```sh
docker compose exec -T web php scripts/create-user.php second-user < /안전한/임시비밀번호파일
```

클라우드 최초 관리자 설정은 `APP_ALLOW_INITIAL_SETUP=1`이고 사용자가 0명일 때만 활성화됩니다. 일반 내부 개발과 운영에서는 끕니다. HTTPS에서는 `APP_SECURE_COOKIE=1`을 사용합니다. 로컬 Windows 설치는 필요하지 않으나 기존 선택용 도구는 [Windows 안내](docs/WINDOWS_TEST.md)에 남아 있습니다.

## 백업과 운영 이전

`./scripts/backup.sh`는 비공개 `backups/`에 SQL을 저장합니다. 실제 가져오기와 업데이트 전 백업은 `.local/import-backups/`에도 저장하며 Git에서 제외합니다. 클라우드 작업 공간·Codespace 보존 자체를 백업으로 간주하지 말고 별도 안전한 위치에 보관하세요. 복원은 기존 자료를 덮어쓸 수 있으므로 사용자 명시 요청 후 별도 DB에서 먼저 검증합니다. `scripts/restore-import.php`는 같은 DB의 백업인지 확인하고 복원 직전 안전 백업도 만듭니다.

Hostinger는 아직 배포하지 않았습니다. 운영 전 PHP·pdo_mysql·MySQL 호환성과 HTTPS를 확인하고 document root를 public/으로 지정합니다. DB 접속 정보는 서버의 안전한 환경 변수 DB_HOST, DB_NAME, DB_USER, DB_PASSWORD로 제공합니다. `.env`를 웹으로 노출하지 않습니다. 초기 계정 설정을 끄고 사용자 계정, 접근 통제, DB와 로고 이전·백업·복원·최종 가져오기 잠금을 검증합니다.

## 검증

```sh
python tests/workflow.py
python tests/sales.py
python tests/migration.py
python tests/excel-import.py
python tests/import-approved.py
NODE_PATH=/workspace/browser-check/node_modules node tests/browser-cleanup.cjs
NODE_PATH=/workspace/browser-check/node_modules node tests/browser-preferences.cjs
NODE_PATH=/workspace/browser-check/node_modules node tests/browser-workflow.cjs
```

실제 가져오기는 별도 시험 DB와 HTTP 컨테이너에서 실행하고 정리합니다. 고객·주문 흐름은 고유 가상 자료만 만들어 검증 후 정리하며 기존 실고객 자료를 변경하지 않습니다. 로고 시험은 별도 저장 경로를 사용합니다. 현재 승인 규칙보다 오래된 단계별 테스트는 과거 파서·분류 구현의 검증 자료이며 최신 가져오기 화면은 import-approved.py를 기준으로 검증합니다.
