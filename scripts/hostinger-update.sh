#!/bin/sh
# Run from an extracted staging release, never from the installed application.
set -eu
umask 077
release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
domain_root=${1:?Usage: sh hostinger-update.sh /absolute/domain/folder}
case "$domain_root" in /*) ;; *) echo '도메인 폴더는 절대 경로여야 합니다.' >&2; exit 1;; esac
domain_root=$(CDPATH= cd -- "$domain_root" && pwd)
installed="$domain_root/kimchibang"
web_root="$domain_root/public_html/kimchibang"
release_web="$(dirname "$release_root")/public_html/kimchibang"
[ "$release_root" != "$installed" ] || { echo '설치된 폴더가 아닌 별도 압축 해제 폴더에서 실행하세요.' >&2; exit 1; }
for item in "$installed/config/local.php" "$installed/scripts/apply-cloud-update.php" "$release_root/database/production.php" "$release_web/assets/app.js"; do
    [ -f "$item" ] || { echo "필수 파일 없음: $item" >&2; exit 1; }
done
[ -d "$web_root" ] || { echo '공개 폴더 경로를 확인해 주세요.' >&2; exit 1; }
# Syntax-check all incoming PHP before touching the installation.
find "$release_root" -name '*.php' -type f -exec php -l {} \; > "$release_root/php-lint.log"
if grep -q 'Errors parsing\|Parse error\|Fatal error' "$release_root/php-lint.log"; then
    echo '새 PHP 코드 문법 점검에 실패했습니다.' >&2; exit 1
fi
cd "$installed"
php scripts/apply-cloud-update.php
backup="$installed/.local/code-before-update-$(date +%Y%m%d-%H%M%S)-$$.tar.gz"
tar -czf "$backup" -C "$domain_root" kimchibang/app kimchibang/database kimchibang/scripts kimchibang/public public_html/kimchibang
chmod 600 "$backup"
echo "기존 코드 백업: $backup"
# Copy allowlisted code only. Keep .local, config/local.php, logo, accounts and sales data.
cp "$release_root/config/release.php" "$installed/config/release.php"
cp -R "$release_root/database/." "$installed/database/"
cp -R "$release_root/scripts/." "$installed/scripts/"
cp -R "$release_root/app/." "$installed/app/"
php scripts/apply-cloud-update.php
cp "$release_root/public/index.php" "$installed/public/index.php"
cp -R "$release_web/assets/." "$web_root/assets/"
cp "$release_web/favicon.ico" "$web_root/favicon.ico"
# Public OCR/PDF assets need explicit extensions while private paths remain denied.
php -r '$p=$argv[1];$s=file_get_contents($p);$s=str_replace(["css|js|png|ico|svg|webp|jpg|jpeg","css|js|mjs|wasm|gz|png|ico|svg|webp|jpg|jpeg"],"css|js|mjs|wasm|traineddata|png|ico|svg|webp|jpg|jpeg",$s);if(!str_contains($s,"AddType application/javascript .mjs"))$s.="\nAddType application/javascript .mjs\nAddType application/wasm .wasm\n";if(file_put_contents($p,$s)===false)exit(1);' "$web_root/.htaccess"
find "$web_root/assets" -type d -exec chmod 755 {} \;
find "$web_root/assets" -type f -exec chmod 644 {} \;

php scripts/hostinger-check.php
echo 'Hostinger 코드·생산 테이블 업데이트 완료. 브라우저에서 Ctrl+F5 후 생산 계획을 확인하세요.'
echo '엑셀을 다시 가져오지 마세요. 기존 고객·주문·계정·로고·설정은 유지합니다.'
