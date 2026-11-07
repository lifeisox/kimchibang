"""Cloud initial-admin UI: disposable database and HTTP container, no real data touched."""
from pathlib import Path
import subprocess,uuid,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,time,json
ROOT=Path(__file__).resolve().parent.parent
name='kimchi_setup_'+uuid.uuid4().hex[:12]
container=None
password=uuid.uuid4().hex
base='http://127.0.0.1:18081'
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def cmd(args,data=None):
    r=subprocess.run(['docker','compose',*args],cwd=ROOT,input=data,text=True,capture_output=True)
    if r.returncode:raise RuntimeError(r.stderr[-2000:])
    return r.stdout

def admin(sql):return cmd(['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'],sql)
def php(code):return cmd(['exec','-T','-e','DB_NAME='+name,'web','php'],'<?php require "app/bootstrap.php";'+code)
def req(data=None):
    try:
        with client.open(base+'/',urllib.parse.urlencode(data).encode() if data is not None else None) as r:return r.status,r.read().decode()
    except urllib.error.HTTPError as r:return r.code,r.read().decode()
def check(ok,label):
    assert ok,label
    print('PASS',label)
try:
    admin('CREATE DATABASE '+name+' CHARACTER SET utf8mb4; GRANT ALL ON '+name+".* TO 'kimchi'@'%';")
    cmd(['exec','-T','-e','DB_NAME='+name,'web','php','scripts/init.php'])
    container=cmd(['run','--rm','-d','--no-deps','-p','127.0.0.1:18081:8000','-e','DB_NAME='+name,'-e','APP_ALLOW_INITIAL_SETUP=1','-e','APP_SECURE_COOKIE=0','web','sleep','infinity']).strip()
    for i in range(2):
        started=subprocess.run(['docker','exec',container,'sh','/app/scripts/cloud-start.sh'],cwd=ROOT,text=True,capture_output=True,check=True)
        check('김치방 앱 준비 완료' in started.stdout,'컨테이너 CMD가 서버가 아닐 때 자동 시작' if i==0 else '다시 실행해도 기존 서버 유지')
    for _ in range(30):
        try:status,html=req();break
        except (OSError,urllib.error.URLError):time.sleep(.2)
    check(status==200 and '처음 한 번, 관리자 비밀번호 정하기' in html,'빈 클라우드 DB에만 초기 설정 표시')
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
    check(req({'action':'initial_setup','csrf':'bad','password':password,'password_confirmation':password})[0]==422,'초기 설정 CSRF 검사')
    check(req({'action':'initial_setup','csrf':csrf,'password':'short','password_confirmation':'short'})[0]==422,'짧은 비밀번호 거부')
    check(req({'action':'initial_setup','csrf':csrf,'password':password,'password_confirmation':'different'})[0]==422,'비밀번호 재입력 확인')
    status,html=req({'action':'initial_setup','csrf':csrf,'password':password,'password_confirmation':password})
    check(status==200 and '이번 주 업무' in html,'비밀번호 설정 후 로그인')
    check(php('echo query("SELECT COUNT(*) FROM users")->fetchColumn();').strip()=='1','관리자 한 명만 생성')
    check(req({'action':'initial_setup','csrf':re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1],'password':password,'password_confirmation':password})[0]==422,'계정 생성 후 초기 설정 재사용 차단')
    check(json.loads(php('require "app/setup.php";echo json_encode(initialSetupAvailable());')) is False,'일반 개발 환경에서 초기 설정 비활성')
    print('PASS 임시 HTTP 서버와 시험 DB만 사용')
finally:
    if container:subprocess.run(['docker','rm','-f',container],cwd=ROOT,capture_output=True)
    admin('DROP DATABASE IF EXISTS '+name+'; REVOKE ALL ON '+name+".* FROM 'kimchi'@'%';")
