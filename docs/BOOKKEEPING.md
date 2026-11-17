# 기장 (Bookkeeping) 개발·시험 안내

`codex/bookkeeping`에서 개발하며 판매 운영 릴리스 `sales-release-20261115`와 분리합니다. 현재 Hostinger에 올리는 패키지는 판매용으로 유지되며 기장 코드·OCR 자원을 포함하지 않습니다. 이 브랜치를 시험했다고 운영 서버의 DB가 바뀌지는 않습니다.

## 클라우드에서 시작

1. GitHub의 `lifeisox/kimchibang`에서 브랜치를 **codex/bookkeeping**으로 고릅니다.
2. Code → Codespaces → 새 Codespace를 만듭니다. 기존 판매 시험 환경의 브랜치를 강제로 바꾸거나 미저장 변경을 지우지 않습니다.
3. 준비가 끝나면 **PORTS → 8000 → Open in Browser**를 누릅니다. 다른 임시 포트에서 `ok`만 보이는 주소는 앱 주소가 아닙니다.
4. 새 환경은 초기 관리자 비밀번호를 직접 정합니다. 로그인하면 접힌 **기장 (Bookkeeping)** 메뉴를 펼칩니다.

개발 설정은 `.devcontainer/compose.yaml`의 `APP_ENABLE_EXPENSES=1`입니다. 기존 Codespace에 적용할 때에는 컨테이너 재빌드가 필요합니다. 새 환경에는 실제 영수증·엑셀·고객 DB가 자동으로 복사되지 않습니다.

## 사용 순서

- **기장 엑셀 가져오기**: 2025 / 2026 선택 → 파일 분석·미리보기 → 확인된 거래만 추가. 2024는 사용하지 않습니다. 판매 데이터와 기존 기장은 삭제하지 않습니다. 같은 연도는 중복 가져오기를 차단합니다. 보류 행의 시트·행번호·이유를 보여주며 원본을 비공개로 보관합니다. 해당 행은 엑셀과 대조한 후 직접 입력할 수 있습니다.
- **영수증·거래 입력**: 사진 촬영 / 사진·PDF 선택 → 자동 읽기 → 결과 확인 → 읽은 값 적용 → Account·금액 확인 → 저장. 직접 입력 후 증빙만 첨부해도 됩니다.
- **구매처 관리**: 신규 등록, 이름 수정, 별칭 추가, 통합, 사용 중지·복원. Costco 지점과 Amazon 표기는 기본 대표 구매처에 연결합니다. 원래 영수증 표시명은 거래에 보존합니다. 그 외 유사한 이름을 추측해서 합치지 않습니다. 자주 사용한 구매처부터 나옵니다.
- **Chart of Accounts**: 엑셀 17개 Account와 추가 신고용 Account를 제공합니다. 처음은 두 엑셀의 사용 빈도, 이후는 실제 거래 횟수 순입니다. 수정·추가·사용 중지·복원이 가능하며 과거 거래 금액은 유지합니다.
- **Tax Reports**: 연간 Account / 월별 Amount, GST/HST Paid, ITCs, Income Tax Review Amount를 인쇄하거나 월별 탭이 있는 연간 XLSX로 내보냅니다.

## 금액과 신고 기준

금액은 CAD 정수 센트로 보관합니다. **Amount (Tax Included) − HST − Other Tax = Net Amount**를 입력 즉시 계산하고 서버에서도 검사합니다. Account의 기본 비율은 새 입력에만 적용하며 기존 거래 비율을 소급 변경하지 않습니다. Tax settings를 펼쳐 Business Use, Income Tax Deductible, ITC Limit, ITC Eligibility를 확인합니다. Meals 기본 비율은 Income Tax / ITC Limit 각각 50%이며 거래별 예외를 확인합니다.

신규 엑셀 가져오기는 Meals가 포함되면 ITC Review Required, 그 외는 ITC Eligible로 시작합니다. 확인된 Eligible 거래에만 HST × Business Use × ITC Limit을 ITCs로 넣습니다. Inventory Purchases, Capital Assets, Tax Review Required는 Operating Expenses에서 분리합니다. Opening/Closing Inventory에 따른 COGS 및 CCA, 별도 마트·케이터링 매출, GST/HST Collected, 이미 신고한 세액은 자동 확정하지 않습니다. 현재 보고서는 신고 준비 자료입니다.

여러 Account로 분할된 엑셀 거래는 원본 분할을 보존합니다. 금액·분류 정정은 취소 후 다시 입력하고, 증빙·비율·ITC 검토 상태는 수정할 수 있습니다. 분할 거래의 비율은 거래 전체에 적용하므로 Account마다 사업/공제 비율이 다르면 별도 거래로 정정합니다. 취소한 거래는 신고 집계에서 제외하며 이력을 보존합니다.

## 영수증 읽기·보관

유료 API 없이 이 기기의 브라우저에서 Tesseract OCR과 PDF.js로 읽습니다. 텍스트 PDF를 먼저 읽고, 이미지 PDF는 페이지를 그려 OCR합니다. 원본 영수증은 저장할 때 로그인한 사용자만 접근할 수 있는 비공개 폴더에 보관합니다. 자동 읽기를 위해 외부로 영수증을 보내지 않습니다. 사진 방향 변경과 크기 조정은 읽기용으로만 적용합니다.

영어 인쇄 영수증이 우선입니다. 흐린 사진·복잡한 표·손글씨·한국어/중국어 OCR의 정확도는 보장하지 않으므로 후보를 확인하고 적용합니다. 모호한 날짜는 확정하지 않습니다. 외화가 감지되면 금액을 CAD로 자동 적용하지 않으며 카드 청구 CAD 금액을 직접 입력합니다. JPG/PNG/WebP/PDF, 파일당 10MB·PDF 15페이지까지 지원합니다. HEIC는 JPG로 변환합니다.

DB 백업과 `.local/receipts/`, `.local/expense-imports/`를 함께 안전하게 보관해야 원본과 거래를 복원할 수 있습니다. Git에는 영수증·엑셀·DB·비밀번호를 넣지 않습니다.

## 향후 확장

구매처 ID, 영수증 ID, 거래와 Account 분할을 분리해 원재료 구매 행(재료·수량·단위·단가)을 추가 연결할 수 있게 했습니다. 원재료 가격 추이·제품별 원가계산은 다음 단계이며 아직 구현하지 않았습니다.

## 검증

`node tests/receipt-parser.mjs`, `python tests/finance.py`로 계산·보안·신규 엑셀·구매처/Account 관리·사진 OCR·텍스트/스캔 PDF·모바일 화면·인쇄를 검증합니다. 운영 판매 패키지는 `tests/sales-release.py`, `tests/hostinger-package.py`로 기장 기능이 제외되는지 확인합니다.

## 영수증 읽기 개선 (2026-11-16)

사진의 밝은 종이 영역을 찾아 불필요한 배경을 줄이고 네 방향에서 읽어 봅니다. 원본·밝기 보정본을 비교하여 Amount/HST/Date를 더 잘 찾은 후보를 표시합니다. 이는 OCR의 정답 확률이 아니므로 반드시 증빙과 대조합니다. 배경과 종이가 비슷하거나 여러 영수증이 겹치면 분리되지 않을 수 있습니다. 한 사진에 영수증 한 장만 넣고, 방향·조명·초점을 맞춰 촬영하면 정확도가 좋아집니다. 자동 처리로 원본 사진은 변경하지 않습니다.

Costco 주유의 Fuel Sale/Amount due/HST Included와 식료품의 명시적 Tax 0을 읽습니다. 세금이 표시되지 않은 OK Mart/Green Fresh 사진은 품목 가격만으로 HST 0을 추정하지 않습니다. Amazon Canada PDF는 Invoice date를 우선하고 마지막 페이지 Federal/Provincial Tax 합계 행을 검산합니다. 구매한 물품이 사업용인지 여부는 영수증 인식으로 결정하지 않습니다.

시험은 전사한 4개 매장 영수증의 필드 규칙, 합성 회전 사진의 실제 브라우저 OCR, 첨부 Amazon PDF의 실제 PDF.js 추출로 구분합니다. 전사한 텍스트 시험을 실제 사진 전체의 OCR 정확도로 해석하지 않습니다. 사진 원본은 이번 작업 파일시스템에 제공되지 않았으며 채팅에 표시된 사진을 시각적으로 확인했습니다. 실제 인보이스는 비공개 파일로만 시험하며 Git에 넣지 않습니다. 선택 시험: `TEST_INVOICE=/비공개/invoice.pdf NODE_PATH=/설치경로/node_modules node tests/browser-invoice.cjs`.

## 월별 기장 UI (2026-11-16)

기장 내역에서 연도·월·검색어로 조회하고 해당 월의 전체 행과 합계를 표시합니다. 취소 거래는 제외합니다. 엑셀파일 다운로드는 검색어나 월과 관계없이 선택 연도의 전체 기장을 1월~12월 탭으로 받습니다. 메뉴의 독립 입력 링크를 없애고 기장 내역의 영수증 입력 버튼으로 진입합니다.

새 거래의 ITC Eligibility는 Eligible로 시작하고 Meals 선택 시 Review Required로 바뀝니다. 기존 거래·엑셀 가져오기 내역의 상태는 소급 변경하지 않습니다. 지불 방법 입력 UI는 제거하며 기존 값은 보존합니다. 브라우저에서 스캐너 드라이버를 직접 실행할 수 없으므로 PC 스캐너 프로그램에서 PDF/JPG로 저장한 후 파일을 선택합니다. 같은 연도 엑셀은 개발·운영 모두 중복 가져오기를 DB의 UNIQUE 제약과 실행 전 확인으로 차단합니다.

## 구매처 원장과 기본값

구매처 목록의 이름을 누르면 전체 기간의 취소되지 않은 거래와 Amount/HST/Net Amount 합계, 영수증 링크를 보여줍니다. 영수증은 로그인한 직원에게만 열리며 이미지는 별도 창의 확대·축소·맞춤 버튼, PDF는 브라우저 PDF 뷰어의 확대 기능으로 확인합니다. 팝업을 막은 브라우저에서는 새 탭으로 열 수 있습니다.

신규 입력의 Account는 Material입니다. 기존 거래의 Account는 유지합니다. 월별 탭 XLSX의 Account에는 계정명만 표시하며 복수 계정은 `/`로 연결합니다. Tax Reports의 다운로드도 동일한 연간 XLSX를 사용합니다.

이 변경 이후 새로 가져오는 엑셀 거래는 Meals가 포함되면 Review Required, 그렇지 않으면 ITC Eligible로 시작합니다. 기존에 가져온 거래의 상태는 자동 변경하지 않습니다. 이미 가져온 연도를 다시 가져오는 기능은 계속 차단합니다.
