<?php
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(!in_array($path,['/','/index.php'],true)) {http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo '페이지를 찾을 수 없습니다.';exit;}
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/actions.php';
require __DIR__.'/../app/setup.php';
$error='';
try {
    if($_SERVER['REQUEST_METHOD']==='POST') handleAction();
    if(isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        if(empty($_SESSION['user_id'])) {http_response_code(401); echo json_encode(['error'=>'로그인이 필요합니다.']); exit;}
        $term=textfield($_GET,'q',100);
        $like='%'.strtr($term,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $rows=query("SELECT id,name,nickname,phone,address,area,contact_name,affiliation,payer_name FROM customers WHERE ".customerSearchCondition()." ORDER BY name LIMIT 20",array_fill(0,8,$like))->fetchAll();
        echo json_encode($rows,JSON_UNESCAPED_UNICODE); exit;
    }
} catch(InvalidArgumentException $err) { $error=$err->getMessage(); http_response_code(422); }
catch(Throwable $err) { error_log((string)$err); $error='저장 또는 조회 중 문제가 생겼습니다. 잠시 후 다시 시도해 주세요.'; http_response_code(500); }
$page=is_string($_GET['page']??null)?$_GET['page']:'home';
$logged=isset($_SESSION['user_id']);
$titles=['home'=>'이번 주 업무','sales'=>'판매 통계','excel-preview'=>'엑셀 가져오기 미리보기','new-order'=>'새 주문 넣기','orders'=>'주문 목록','order'=>'주문 상세','order-edit'=>'주문 수정·대기 제품 취소','customers'=>'고객 찾기','customer'=>'고객 원장','customer-edit'=>'고객 등록·수정','products'=>'제품과 가격','product-edit'=>'제품 등록·수정'];
$title=$logged?($titles[$page]??'페이지를 찾을 수 없습니다'):'로그인';
function input(string $label,string $name,string $value='',string $type='text',bool $required=false): void { echo '<label>'.e($label).'<input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'" '.($required?'required':'').'></label>'; }
function orderTable(array $rows): void {
 if(!$rows) {echo '<div class="empty"><strong>아직 주문이 없습니다</strong><p>새 주문 넣기로 첫 주문을 기록해 보세요.</p><a class="button" href="/?page=new-order">＋ 새 주문 넣기</a></div>';return;}
 echo '<div class="table-wrap"><table><thead><tr><th>주문</th><th>고객</th><th>배달·픽업일</th><th>받는 방법</th><th>금액</th><th>완료 상태</th></tr></thead><tbody>';
 foreach($rows as $o) echo '<tr><td><a href="/?page=order&id='.(int)$o['id'].'">#'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT).'</a><small>'.e($o['order_date']).'</small></td><td><a href="/?page=order&id='.(int)$o['id'].'">'.e($o['customer_name']).'</a></td><td>'.e($o['delivery_date']??'미기록').'</td><td>'.(['pickup'=>'직접 픽업','delivery'=>'집으로 배달','unknown'=>'미기록'][$o['fulfillment']]??'미기록').'</td><td class="amount">'.money((int)$o['total_cents']).'</td><td><span class="badge '.($o['delivered']?'done':'').'">'.(!$o['delivery_state_known']?'배송 미확인':($o['cancelled']?'취소':($o['delivered']?'전달 완료':($o['shipped_units']?'일부 전달':'전달 전')))).'</span> <span class="badge '.($o['paid']?'done':'pending').'">'.(!$o['payment_state_known']?'입금 미확인':($o['cancelled']?'취소':($o['paid']?'입금 완료':($o['received_cents']?'일부 입금':'입금 전')))).'</span></td></tr>';
 echo '</tbody></table></div>';
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · 로뎀나무 김치방</title><link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" href="/assets/favicon-32.png" sizes="32x32"><link rel="icon" type="image/png" href="/assets/favicon-16.png" sizes="16x16"><link rel="apple-touch-icon" href="/assets/sprout.png"><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head><body>
<?php if($logged): ?>
<aside class="sidebar"><a class="brand" href="/"><img class="brand-image" src="/assets/brand-logo.png" alt="로뎀나무 · RODEM NAMU 김치방" width="204" height="52"></a><div class="sidebar-label">우리 가게 관리</div><nav><?php foreach(['home'=>['◷','이번 주 업무'],'new-order'=>['＋','새 주문 넣기'],'orders'=>['▤','주문 목록'],'customers'=>['♧','고객 찾기'],'sales'=>['▥','판매 통계'],'products'=>['▦','제품과 가격'],'excel-preview'=>['⇩','엑셀 미리보기']] as $key=>[$icon,$label]): ?><a class="<?=$page===$key?'active':''?>" href="/?page=<?=$key?>"><span><?=$icon?></span><?=$label?></a><?php endforeach ?></nav><div class="sidebar-bottom"><span class="local-dot"></span> 개발용 · CAD / 오타와 시간<p>두 분의 하루가 조금 더 편하도록</p></div></aside>
<div class="workspace"><header><span>로뎀나무 판매관리 <span class="version">첫 버전</span></span><form method="post"><?=csrf()?><input type="hidden" name="action" value="logout"><span><?=e($_SESSION['username'])?> 님</span><button class="text-button">로그아웃</button></form></header><main><div class="page-heading"><div><p class="eyebrow">차근차근, 오늘도 맛있는 김치</p><h1><?=e($title)?></h1></div><span class="today"><?=date('Y년 n월 j일')?></span></div>
<?php else: ?><main class="login-page"><div class="login-card"><div class="brand"><img class="brand-image" src="/assets/brand-logo.png" alt="로뎀나무 · RODEM NAMU 김치방" width="204" height="52"></div><h1>로뎀나무 김치방</h1><p class="muted">우리 가게의 주문과 고객을 한곳에서</p><?php endif ?>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?> <span>입력한 내용은 아래에서 다시 확인해 주세요.</span></div><?php endif ?>
<?php if(isset($_SESSION['flash'])): ?><div class="alert success" role="status"><?=e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif ?>
<?php if(!$logged && initialSetupAvailable()): ?><h2>처음 한 번, 관리자 비밀번호 정하기</h2><p>아이디는 <strong>admin</strong>입니다. 테스트에 사용할 비밀번호를 정해 주세요.</p><form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="initial_setup"><label>새 비밀번호 (12자 이상)<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>비밀번호 한 번 더<input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required></label><button class="button large">비밀번호 저장하고 시작하기</button></form></div>
<?php elseif(!$logged): ?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="login"><?php input('아이디','username','','text',true); input('비밀번호','password','','password',true); ?><button class="button large">로그인하기</button></form><p class="hint">계정은 관리자가 등록합니다. 실제 고객 자료를 넣기 전 로그인 정보를 안전하게 보관해 주세요.</p></div>
<?php else: try { require __DIR__.'/../app/views.php'; } catch(Throwable $err) {error_log((string)$err); http_response_code(500); echo '<div class="alert error">화면을 불러오지 못했습니다. 데이터베이스 연결을 확인해 주세요.</div>';} endif ?>
</main><?php if($logged): ?><footer>로뎀나무 · 금액 CAD · 날짜 America/Toronto</footer></div><?php endif ?></body></html>
