'use strict';
const formatMoney = cents => new Intl.NumberFormat('en-CA', {style:'currency',currency:'CAD'}).format(cents/100);
const form = document.querySelector('#order-form');
if (form) {
  const cards = [...document.querySelectorAll('.product-card')];
  const discount = document.querySelector('#discount');
  function recalculate() {
    let subtotal = 0;
    const summary = document.querySelector('#cart-summary');
    summary.replaceChildren();
    cards.forEach(card => {
      const qty = Number(card.querySelector('input').value) || 0;
      const cost = Number(card.dataset.price) * qty;
      subtotal += cost;
      card.classList.toggle('chosen', qty > 0);
      if (qty > 0) {
        const line = document.createElement('p');
        line.className = 'summary-line';
        const label = document.createElement('span');
        label.textContent = card.querySelector('.product-code').textContent + ' × ' + qty;
        const price = document.createElement('strong'); price.textContent = formatMoney(cost);
        line.append(label, price); summary.append(line);
      }
    });
    if (!summary.children.length) { const p = document.createElement('p'); p.className='hint'; p.textContent='선택한 제품이 여기에 표시됩니다.'; summary.append(p); }
    document.querySelector('#subtotal').textContent = formatMoney(subtotal);
    const netProducts = subtotal - Math.round((Number(discount.value)||0)*100);
    const delivery = form.querySelector('[name=fulfillment]:checked').value === 'delivery';
    const hasItems = cards.some(c => Number(c.querySelector('input').value) > 0);
    const fee = hasItems && delivery && netProducts >= 0 && subtotal < Number(form.dataset.freeDeliveryMin) ? Number(form.dataset.deliveryFee) : 0;
    discount.setCustomValidity(netProducts < 0 ? '할인액은 제품 합계를 넘을 수 없습니다.' : '');
    document.querySelector('#delivery-fee').textContent = formatMoney(fee);
    document.querySelector('#total').textContent = formatMoney(Math.max(0, netProducts) + fee);
    document.querySelector('#delivery-fee-hint').textContent = !delivery ? '직접 픽업은 배달비가 없습니다.' : !hasItems ? '배달은 할인 전 제품 금액 $80 미만이면 $5, 픽업은 무료입니다.' : fee ? '할인 전 제품 금액이 $80 미만이므로 배달비 $5가 포함됩니다.' : netProducts < 0 ? '할인액을 확인해 주세요.' : '할인 전 제품 금액이 $80 이상이므로 무료 배달입니다.';
  }
  cards.forEach(card => {
    const input = card.querySelector('input');
    card.querySelectorAll('[data-step]').forEach(button => button.addEventListener('click', () => {
      input.value = Math.max(0,Math.min(999,(Number(input.value)||0)+Number(button.dataset.step))); recalculate();
    }));
    input.addEventListener('input', recalculate);
  });
  discount.addEventListener('input',recalculate); recalculate();
  const search = document.querySelector('#customer-search');
  const results = document.querySelector('#customer-results');
  let timer, request;
  search.addEventListener('input', () => {
    clearTimeout(timer); if (request) request.abort(); results.replaceChildren();
    if (!search.value.trim()) return;
    timer=setTimeout(async () => {
      request = new AbortController();
      try {
        const response = await fetch('/?api=customers&q='+encodeURIComponent(search.value),{signal:request.signal});
        if(!response.ok) throw new Error('search');
        const customers = await response.json();
        results.replaceChildren();
        if(!customers.length) { results.textContent='찾는 고객이 없습니다. 위의 고객 등록 버튼으로 등록해 주세요.'; return; }
        customers.forEach(c => {
          const button=document.createElement('button'); button.type='button'; button.className='customer-result';
          const name=document.createElement('strong'); name.textContent=c.name+(c.nickname?' ('+c.nickname+')':'');
          const detail=document.createElement('span'); detail.textContent=[c.phone,c.address,c.payer_name?'입금자: '+c.payer_name:''].filter(Boolean).join(' · ');
          button.append(name,detail);
          button.addEventListener('click',()=> {
            document.querySelector('#customer-id').value=c.id;
            const selected=document.querySelector('#selected-customer'); selected.replaceChildren();
            const title=document.createElement('strong'); title.textContent=c.name+(c.nickname?' · '+c.nickname:'');
            const address=document.createElement('p'); address.textContent=c.address; selected.append(title,address);
            document.querySelector('#delivery-address').value=c.address;
            search.value=''; results.replaceChildren();
          });
          results.append(button);
        });
      } catch(err) { if(err.name!=='AbortError') results.textContent='고객 검색에 실패했습니다. 로그인 상태를 확인하고 다시 시도해 주세요.'; }
    },200);
  });
  function updateMethod() {
    const delivery=form.querySelector('[name=fulfillment]:checked').value==='delivery';
    document.querySelector('#address-label').hidden=!delivery;
    document.querySelector('#delivery-address').required=delivery;
    recalculate();
  }
  form.querySelectorAll('[name=fulfillment]').forEach(r=>r.addEventListener('change',updateMethod)); updateMethod();
  form.addEventListener('submit',event => {
    if(!document.querySelector('#customer-id').value) {event.preventDefault();search.focus();window.alert('먼저 고객을 검색해서 선택해 주세요.');return;}
    if(!cards.some(c=>Number(c.querySelector('input').value)>0)) {event.preventDefault();window.alert('제품 수량을 하나 이상 선택해 주세요.');return;}
    document.querySelector('#save-order').disabled=true;
    document.querySelector('#save-order').textContent='주문을 저장하고 있어요…';
  });
}
document.querySelector('#print-order')?.addEventListener('click',()=>window.print());
document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click',event=>{
 if(!window.confirm(button.dataset.confirm))event.preventDefault();
}));
const reviewForm=document.querySelector('#preview-review-form');
if(reviewForm){
 const checks=[...reviewForm.querySelectorAll('input[name="selected[]"]')];
 const update=()=>{document.querySelector('#review-selection-count').textContent='선택 '+checks.filter(c=>c.checked).length+'개';};
 checks.forEach(c=>c.addEventListener('change',update));
 document.querySelector('#review-select-all').addEventListener('click',()=>{checks.forEach(c=>c.checked=true);update();});
 document.querySelector('#review-select-none').addEventListener('click',()=>{checks.forEach(c=>c.checked=false);update();});
 reviewForm.addEventListener('submit',e=>{if(!checks.some(c=>c.checked)){e.preventDefault();window.alert('처리할 그룹을 하나 이상 선택해 주세요.');}});
}
