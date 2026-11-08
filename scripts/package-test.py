"""Build a source-only Windows trial archive; excludes all credentials and customer data."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import sys
root=Path(__file__).resolve().parent.parent
out=Path(sys.argv[1]) if len(sys.argv)>1 else root.parent/'kimchibang-windows-test.zip'
files=[]
for folder in ['app','public','config','database','scripts','docs']:
    files.extend(p for p in (root/folder).rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.name!='local.php')
files.extend(root/p for p in ['README.md','Dockerfile','compose.yaml','.dockerignore','.gitignore','windows-start.cmd','windows-stop.cmd'])
with ZipFile(out,'w',ZIP_DEFLATED) as z:
    for p in sorted(files):
        data=p.read_bytes()
        if p.suffix in ['.cmd','.ps1']:data=data.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')
        z.writestr('kimchibang-test/'+p.relative_to(root).as_posix(),data)
    z.writestr('kimchibang-test/START-HERE.txt',(root/'docs/WINDOWS_TEST.md').read_bytes())
with ZipFile(out) as z:
    assert not any('/.local/' in n or '/backups/' in n or n.endswith('/.env') or n.endswith('.xlsx') or n.endswith('.sql') and '/database/' not in n for n in z.namelist())
    assert 'kimchibang-test/windows-start.cmd' in z.namelist()
print(f'Trial archive ready: {out} ({out.stat().st_size:,} bytes); no credentials, database files, or customer workbook')
