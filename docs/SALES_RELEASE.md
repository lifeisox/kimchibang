# 김치방 판매 운영 배포판

비용·영수증 자동 읽기와 비용 업무는 사용자 요청으로 보류했습니다. 이번 배포에는 다음 업무를 제공합니다.

- 고객·주문·전달·선입금/입금·고객 원장
- 생산 계획·배합 관리·재료 구입량 즉시 계산
- 판매 통계·최근 3년 비교
- 계정 관리·로고·기존 자료 보존

비용 메뉴, 비용 화면/API, 비용 엑셀 가져오기와 비용 저장 요청은 운영 배포판에서 비활성화됩니다. OCR/PDF 라이브러리·모델과 비용 전용 PHP/DB 스크립트는 ZIP에서 제외합니다. 기존 비용 테이블·영수증·원본 엑셀이 있는 서버에서도 삭제하지 않습니다. 개발 소스는 GitHub에 보존합니다.

운영 ZIP은 `config/release.php`의 `edition=sales`, `expenses=false`를 포함합니다. 이 플래그는 기존 환경 변수나 local.php에서 비용 기능을 켠 이력이 있어도 운영 배포에서는 비용을 막습니다. 일반 개발 소스에서도 기본 비활성화이며, 추후 별도 개발 환경에서만 APP_ENABLE_EXPENSES=1로 재개할 수 있습니다. 운영 ZIP을 만든 뒤 이 값을 수동으로 바꾸지 않습니다.

## ZIP 만들기

Codespaces 터미널:

```sh
cd /app
git -c safe.directory=/app pull --ff-only origin codex/cloud-preview && php scripts/package-hostinger.php /app/dist/kimchibang-sales-release.zip
```

파일 탐색기의 dist 폴더에서 kimchibang-sales-release.zip을 다운로드해 기존 도메인 폴더에 업로드합니다. 기존 서버 폴더에 바로 압축을 풀지 않습니다.

## Hostinger 업데이트

두 사용자가 잠시 주문·입금 입력을 멈춘 뒤 PuTTY에서:

```sh
cd /home/u870259482/domains/ottawakfood.com &&
kimchi_update_stage=$(mktemp -d ./kimchi-sales-update.XXXXXX) &&
unzip -q kimchibang-sales-release.zip -d "$kimchi_update_stage" &&
sh "$kimchi_update_stage/kimchibang/scripts/hostinger-update.sh" /home/u870259482/domains/ottawakfood.com
```

기존 DB·코드를 백업하고 기존 자료 수를 확인하며 운영 코드를 업데이트합니다. release.php만 배포 설정으로 복사하고 config/local.php의 DB 비밀번호, .local의 로고·영수증·가져오기 자료는 유지합니다. 엑셀을 다시 가져오지 않습니다.

업데이트 후 Ctrl+F5 → 비용 메뉴 없음 → 주문/고객/생산/통계 화면을 확인합니다. 잘못된 상태에서 새 자료로 초기화하거나 임의 DB 복원을 하지 않고 오류와 백업 파일명을 확인합니다.

검증: tests/sales-release.py는 비용 비활성화·직접 주소/API/저장 요청 차단·기존 비용 자료 보존·핵심 운영 화면을 확인합니다. 패키지 검증은 비용 코드/모델 제외와 강제 운영 플래그를 확인하고, 기존 업무·생산 계획·판매 통계·업데이트 보존 검증도 수행합니다.
