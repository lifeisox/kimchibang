# Hostinger 판매·기장 통합 업데이트

이 배포판은 `k.ottawakfood.com`의 기존 판매 기능에 기장 기능을 추가합니다. 공개 폴더 `public_html/kimchibang`과 비공개 앱 `kimchibang`을 구분합니다. 계정·비밀번호·고객 DB·엑셀·영수증은 ZIP에 포함하지 않습니다. 기장용 테이블을 추가하고 기존 가격·로고·고객·주문·입금·기장 자료를 보존합니다. `config/release.php`가 기장 기능을 활성화하므로 DB 비밀번호나 local.php를 수정할 필요가 없습니다.

## 기존 운영 서버 업데이트

1. 두 사용자 모두 입력을 잠시 멈춥니다. 기존 앱 폴더에 ZIP을 바로 풀지 않습니다.
2. ZIP을 **비공개** `/home/u870259482/domains/ottawakfood.com` 폴더에 업로드합니다. public_html에 두지 않습니다.
3. PuTTY/SSH에서 다음을 실행합니다. staging 폴더 이름은 매번 달라 기존 파일을 덮어쓰지 않습니다.

```sh
cd /home/u870259482/domains/ottawakfood.com
kimchi_stage="kimchi-update-bookkeeping-$(date +%Y%m%d-%H%M%S)"
mkdir "$kimchi_stage" &&
unzip -q kimchibang-bookkeeping-hostinger.zip -d "$kimchi_stage" &&
sh "$kimchi_stage/kimchibang/scripts/hostinger-update.sh" /home/u870259482/domains/ottawakfood.com
```

업데이트 전 DB 백업과 비공개 코드·설정 백업을 만들고, 문법 점검·기장 테이블 추가·정적 자원 설치·서버 점검을 진행합니다. 에러가 나면 출력 내용을 확인하며 임의로 엑셀을 다시 가져오거나 DB를 복원하지 마세요. 기존 `.local`, 로고, `config/local.php`, `.htaccess`의 사용자 설정은 보존합니다. `.user.ini`의 다른 설정을 유지하고 업로드 한도만 10M/12M으로 맞춥니다. 호스팅 PHP 설정 반영까지 몇 분 걸릴 수 있습니다.

4. `https://k.ottawakfood.com`에서 Ctrl+F5 후 기존 계정으로 로그인합니다. 새 관리자 비밀번호를 설정하거나 새 DB를 만들지 않습니다.
5. 기존 주문·가격·로고와 기장 메뉴를 확인하고 사진/PDF 저장·열람·엑셀 다운로드·인쇄를 확인합니다.

판매 엑셀은 다시 가져오지 않습니다. **기장 엑셀**은 운영 DB에 아직 가져오지 않은 2025/2026년만 가져올 수 있습니다. Meals 포함 거래는 Review Required, 나머지는 ITC Eligible로 들어옵니다.

Codespaces의 시험 거래·시험 영수증·시험 계정은 자동으로 운영 서버에 옮겨지지 않습니다. 시험 환경의 실제 기장 자료를 옮기려면 별도 이전 작업이 필요하므로 서버 엑셀 가져오기와 중복시키지 않습니다.

## 보관과 복구

`.local/import-backups`의 SQL과 `.local/receipts`, `.local/expense-imports`, `.local/branding`, `config/local.php`를 함께 비공개로 백업하세요. SQL만으로 영수증 이미지가 복구되지는 않습니다. 코드 백업에는 설정도 포함되므로 공개 폴더로 옮기지 않습니다. 복원은 별도 DB에서 먼저 확인하며, 업데이트 후 추가 거래가 있다면 과거 DB를 그대로 덮어쓰지 않습니다.

영수증은 로그인한 직원만 열 수 있습니다. OCR/PDF.js는 서버에 포함된 자원을 사용하고 외부 유료 OCR API를 호출하지 않습니다. 신고 자료는 검토용이며 Inventory/COGS, Capital Assets/CCA 및 별도 매출을 확인합니다.

## 재생성

기장 개발 Codespace에서 `php scripts/package-hostinger.php --bookkeeping`을 실행하면 `dist/kimchibang-bookkeeping-hostinger.zip`을 만듭니다. Python이 있는 환경에서는 `python scripts/package-hostinger.py --bookkeeping`도 같습니다. 옵션을 빼면 기존 판매 전용 ZIP을 만듭니다.
