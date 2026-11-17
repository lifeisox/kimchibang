"""Build an allowlisted release without secrets, customer records or development tools."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib,sys
bookkeeping="--bookkeeping" in sys.argv
args=[a for a in sys.argv[1:] if a!="--bookkeeping"]
root=Path(__file__).resolve().parent.parent
out=Path(args[0]) if args else root/('dist/kimchibang-bookkeeping-hostinger.zip' if bookkeeping else 'dist/kimchibang-hostinger.zip')
out.parent.mkdir(parents=True,exist_ok=True)
files=[]
for folder in ['app','database']:
    files.extend(p for p in (root/folder).rglob('*.php') if p.is_file() and (bookkeeping or not p.name.startswith(('expense','bookkeeping'))))
files += [root/'database/schema.sql',root/'config/excel-decisions.json',root/'config/hostinger.example.php',root/'public/index.php']
files += [root/'scripts'/n for n in ['init.php','create-user.php','hostinger-check.php','apply-cloud-update.php','restore-import.php','hostinger-update.sh']]
with ZipFile(out,'w',ZIP_DEFLATED) as z:
    for p in sorted(set(files)):z.write(p,'kimchibang/'+p.relative_to(root).as_posix())
    z.writestr('kimchibang/config/release.php',"<?php\nreturn ['edition'=>'"+("bookkeeping" if bookkeeping else "sales")+"','expenses'=>"+("true" if bookkeeping else "false")+"];\n")
    # Both folders live under domains/ottawakfood.com; only public_html/kimchibang is public.
    z.writestr('public_html/kimchibang/index.php',"<?php\nrequire __DIR__.'/../../kimchibang/public/index.php';\n")
    for p in (root/'public/assets').rglob('*'):
        if p.is_file() and (bookkeeping or ('vendor' not in p.relative_to(root/'public/assets').parts and not p.name.startswith('receipt-'))):z.write(p,'public_html/kimchibang/assets/'+p.relative_to(root/'public/assets').as_posix())
    z.write(root/'public/favicon.ico','public_html/kimchibang/favicon.ico')
    z.writestr('public_html/kimchibang/.htaccess','''Options -Indexes
DirectoryIndex index.php
AddType application/javascript .mjs
AddType application/wasm .wasm
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://k.ottawakfood.com%{REQUEST_URI} [R=302,L]
RewriteRule ^(?:index\\.php)?$ - [L]
RewriteRule ^favicon\\.ico$ - [L]
RewriteRule ^assets/[A-Za-z0-9_./-]+\\.(?:css|js|mjs|wasm|traineddata|png|ico|svg|webp|jpg|jpeg)$ - [L,NC]
RewriteRule ^ - [F,L]
''')
    z.writestr('public_html/kimchibang/.user.ini','display_errors=Off\nlog_errors=On\nexpose_php=Off\nupload_max_filesize=10M\npost_max_size=12M\n')
    z.write(root/('docs/HOSTINGER_BOOKKEEPING.md' if bookkeeping else 'docs/HOSTINGER.md'),'START-HERE.md')
with ZipFile(out) as z:
    assert not any('/.local/' in n or n.endswith('/local.php') or n.endswith('/.env') or n.endswith('.xlsx') for n in z.namelist())
print(f'Release ready: {out} ({out.stat().st_size:,} bytes)')
print('SHA256:',hashlib.sha256(out.read_bytes()).hexdigest())
