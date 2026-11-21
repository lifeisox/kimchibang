'use strict';
function googleMapUrl(address) {
  const value=(address||'').replace(/\s+/gu,' ').trim();
  if([...value].length<6||[...value].length>500||/https?:\/\/|[<>@]/iu.test(value)||!/\p{L}/u.test(value)||!/(?:^|[ ,#-])\d{1,6}[a-z]?(?:\s|,|-|$)/iu.test(value))return null;
  const url=new URL('https://www.google.com/maps/search/');url.searchParams.set('api','1');url.searchParams.set('query',value);return url.href;
}
function createMapLink(address) {
  const url=googleMapUrl(address);if(!url)return null;
  const a=document.createElement('a');a.className='map-link no-print';a.href=url;a.target='_blank';a.rel='noopener noreferrer';a.referrerPolicy='no-referrer';a.textContent='Google 지도 ↗';a.setAttribute('aria-label','Google 지도에서 주소 확인 (새 탭)');return a;
}

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
    document.querySelector('#delivery-fee-hint').textContent = !delivery ? '직접 픽업·교회 수령은 배달비가 없습니다.' : !hasItems ? '배달은 할인 전 제품 금액 $80 미만이면 $5, 픽업·교회 수령은 무료입니다.' : fee ? '할인 전 제품 금액이 $80 미만이므로 배달비 $5가 포함됩니다.' : netProducts < 0 ? '할인액을 확인해 주세요.' : '할인 전 제품 금액이 $80 이상이므로 무료 배달입니다.';
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
            const address=document.createElement('p');address.className='address-with-map';address.textContent=c.address;const map=createMapLink(c.address);if(map)address.append(' ',map);selected.append(title,address);
            document.querySelector('#delivery-address').value=c.address;
            const method=Object.hasOwn({delivery:1,pickup:1,church:1},c.preferred_fulfillment)?c.preferred_fulfillment:'delivery';
            form.querySelector('[name=fulfillment][value="'+method+'"]').checked=true;
            form.querySelector('textarea[name=notes]').value=c.notes||'';
            updateMethod();
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

const brandFile=document.querySelector('#brand-file');
brandFile?.addEventListener('change',()=>{const file=brandFile.files?.[0];if(!file||file.type!=='image/png'||file.size>2*1024*1024)return;const reader=new FileReader();reader.addEventListener('load',()=>{const preview=document.querySelector('#brand-preview');const img=document.createElement('img');img.className='brand-image original-brand';img.alt='선택한 원본 로고 미리보기';img.src=reader.result;preview.replaceChildren(img);});reader.readAsDataURL(file);});

document.querySelectorAll('[data-map-input]').forEach(group=>{const input=document.getElementById(group.dataset.mapInput);const link=group.querySelector('a');const update=()=>{const url=googleMapUrl(input.value);link.hidden=!url;if(url)link.href=url;else link.removeAttribute('href');};input.addEventListener('input',update);input.addEventListener('change',update);update();});
const productSales=document.querySelector('#product-sales-table');
if(productSales){
 const body=productSales.tBodies[0];const rows=[...body.rows];rows.forEach((row,i)=>row.dataset.originalIndex=String(i));
 const collator=new Intl.Collator('ko',{numeric:true,sensitivity:'base'});let active='amount',direction='desc';
 const buttons=[...productSales.querySelectorAll('button[data-sales-sort]')];
 const indicate=()=>buttons.forEach(button=>{const selected=button.dataset.salesSort===active;button.closest('th').setAttribute('aria-sort',selected?(direction==='asc'?'ascending':'descending'):'none');button.querySelector('.sort-icon').textContent=selected?(direction==='asc'?'↑':'↓'):'↕';button.setAttribute('aria-label',button.dataset.sortLabel+' '+(selected&&direction==='asc'?'내림차순':'오름차순')+'으로 정렬');});
 buttons.forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.salesSort;direction=key===active&&direction==='asc'?'desc':'asc';active=key;const sorted=[...rows].sort((a,b)=>{let result=key==='name'?collator.compare(a.dataset.name,b.dataset.name):Number(a.dataset[key])-Number(b.dataset[key]);if(result)result*=direction==='asc'?1:-1;return result||Number(a.dataset.originalIndex)-Number(b.dataset.originalIndex);});body.append(...sorted);indicate();}));indicate();
}

const menuToggle=document.querySelector('.menu-toggle');
if(menuToggle){
 const sidebar=menuToggle.closest('.sidebar');
 const closeMenu=()=>{sidebar.classList.remove('menu-open');menuToggle.setAttribute('aria-expanded','false');menuToggle.setAttribute('aria-label','메뉴 열기');};
 menuToggle.addEventListener('click',()=>{const open=sidebar.classList.toggle('menu-open');menuToggle.setAttribute('aria-expanded',String(open));menuToggle.setAttribute('aria-label',open?'메뉴 닫기':'메뉴 열기');});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&sidebar.classList.contains('menu-open')){closeMenu();menuToggle.focus();}});
 document.addEventListener('click',event=>{if(!sidebar.contains(event.target))closeMenu();});
 window.matchMedia('(max-width:720px)').addEventListener('change',closeMenu);
}
// Keep every value and action visible on narrow screens, with its column label.
document.querySelectorAll('.table-wrap table').forEach(table=>{
 const headers=[...table.querySelectorAll('thead tr:last-child th')];
 if(!headers.length){table.classList.add('mobile-key-values');return;}
 table.classList.add('mobile-cards');
 if(table.querySelector('thead button'))table.classList.add('mobile-sortable');
 table.querySelectorAll('tbody tr').forEach(row=>{[...row.cells].forEach((cell,index)=>{
  if(cell.colSpan>1){cell.classList.add('mobile-wide-cell');return;}
  cell.dataset.label=headers[index]?.textContent.replace(/[↑↓↕]/g,'').trim()||'';
  cell.style.setProperty('--field-rows',String(Math.max(1,[...cell.childNodes].filter(node=>node.nodeType===1||(node.nodeType===3&&node.textContent.trim())).length)));
 });});
});
const productionForm=document.querySelector('#production-form');
if(productionForm){const checks=[...productionForm.querySelectorAll('[name="orders[]"]')];const count=()=>document.querySelector('#production-count').textContent=checks.filter(c=>c.checked).length+'건 선택';checks.forEach(c=>c.addEventListener('change',count));productionForm.querySelectorAll('[data-production-select]').forEach(b=>b.addEventListener('click',()=>{checks.forEach(c=>c.checked=b.dataset.productionSelect==='all');count();}));count();productionForm.querySelectorAll('[data-pack-grams]').forEach(input=>{const show=()=>input.nextElementSibling.textContent=(Number(input.value)*Number(input.dataset.packGrams)/1000).toLocaleString('ko',{maximumFractionDigits:3})+' kg';input.addEventListener('input',show);show();});}
const ingredients=document.querySelector('#ingredient-lines');
if(ingredients){ingredients.addEventListener('click',event=>{if(event.target.closest('[data-remove-ingredient]')){const row=event.target.closest('.ingredient-row');if(ingredients.children.length>1)row.remove();else row.querySelectorAll('input').forEach(i=>i.value=i.name==='basis[]'?'제품무게':'');}});document.querySelector('#add-ingredient').addEventListener('click',()=>{const row=ingredients.firstElementChild.cloneNode(true);row.querySelectorAll('input').forEach(i=>i.value=i.name==='basis[]'?'제품무게':'');ingredients.append(row);row.querySelector('input').focus();});}
const materialTable=document.querySelector('#material-calculator');
if(materialTable){const rows=[...materialTable.tBodies[0].rows];const recalc=()=>{const totals={};let weight=0;rows.forEach(row=>{const input=row.querySelector('.material-weight');const kg=Number(input.value);const valid=Number.isFinite(kg)&&kg>=0&&kg<=100000;input.setCustomValidity(valid?'':'0~100000 kg으로 입력하세요.');const n=valid?kg:0;weight+=n;const factors=JSON.parse(row.dataset.materialFactors);row.querySelectorAll('[data-material]').forEach(cell=>{const m=cell.dataset.material;const value=n*(factors[m]||0)/1000;totals[m]=(totals[m]||0)+value;cell.textContent=value.toLocaleString('ko',{maximumFractionDigits:3});});});document.querySelector('#material-weight-total').textContent=weight.toLocaleString('ko',{maximumFractionDigits:3})+' kg';materialTable.querySelectorAll('[data-material-total]').forEach(c=>c.textContent=(totals[c.dataset.materialTotal]||0).toLocaleString('ko',{maximumFractionDigits:3}));};materialTable.addEventListener('input',recalc);document.querySelector('#clear-materials').addEventListener('click',()=>{rows.forEach(r=>r.querySelector('input').value='0');recalc();});recalc();}
const expenseWorkbook=document.querySelector('[name="workbook"]');
expenseWorkbook?.addEventListener('change',()=>{const year=expenseWorkbook.files[0]?.name.match(/\b(20\d{2})\b/);if(year){const input=expenseWorkbook.form.querySelector('[name=year]');if(input)input.value=year[1];}});

// Authenticated receipt windows; PDFs use the browser's native zoom controls.
document.addEventListener('click',event=>{const link=event.target.closest('[data-receipt-popup]');if(link){const win=window.open(link.href,'receipt_'+new URL(link.href).searchParams.get('id'),'width=1100,height=900,scrollbars=yes,resizable=yes');if(win){win.opener=null;event.preventDefault();}}const button=event.target.closest('[data-receipt-zoom]');if(!button)return;const img=document.querySelector('#receipt-full-image');if(!img)return;let zoom=Number(img.dataset.zoom||1);zoom=button.dataset.receiptZoom==='fit'?1:Math.max(.25,Math.min(8,zoom*(button.dataset.receiptZoom==='in'?1.25:.8)));img.dataset.zoom=String(zoom);img.style.width=(zoom*100)+'%';});
window.addEventListener('load',()=>{if(document.querySelector('[data-biz-auto-print]')){const url=new URL(location.href);url.searchParams.delete('print');history.replaceState(null,'',url);window.print();}});
document.querySelector('#biz-settlement-client')?.addEventListener('change',event=>{const form=event.target.form;if(event.target.selectedOptions[0]?.dataset.code!=='PT')return;const now=new Date();const end=new Date(now.getFullYear(),now.getMonth(),0);const start=new Date(end.getFullYear(),end.getMonth(),1);const fmt=d=>[d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');form.elements.start.value=fmt(start);form.elements.end.value=fmt(end);});

// Sales order grids: quantity totals and spreadsheet-like keyboard navigation.
document.querySelectorAll('[data-biz-qty-grid]').forEach(grid=>{
  const inputs=[...grid.querySelectorAll('input[type="number"]')];
  const update=()=>{let total=0;grid.querySelectorAll('.biz-quantity-category').forEach(group=>{const sum=[...group.querySelectorAll('[data-biz-qty]')].reduce((n,i)=>n+Math.max(0,Number(i.value)||0),0);group.querySelector('[data-biz-total]').textContent=sum.toLocaleString()+'개';total+=sum;if(group.querySelector('[data-biz-amount]')){let amount=0;group.querySelectorAll('[data-biz-product]').forEach(row=>{const value=Math.round((Number(row.querySelector('[data-biz-unit]').value)||0)*100)*(Number(row.querySelector('[data-biz-qty]').value)||0);amount+=value;row.querySelector('[data-biz-line-amount]').textContent=formatMoney(value);});group.querySelector('[data-biz-amount]').textContent=formatMoney(amount);}});grid.querySelector('[data-biz-grand-total]').textContent=total.toLocaleString()+'개';if(grid.querySelector('[data-biz-grand-amount]')){let amount=0;grid.querySelectorAll('[data-biz-product]:not([hidden])').forEach(row=>amount+=Math.round((Number(row.querySelector('[data-biz-unit]').value)||0)*100)*(Number(row.querySelector('[data-biz-qty]').value)||0));grid.querySelector('[data-biz-grand-amount]').textContent=formatMoney(amount);}};
  grid.addEventListener('input',update);update();
  inputs.forEach((input,index)=>{input.addEventListener('focus',()=>input.select());input.addEventListener('keydown',event=>{let target;if(event.key==='Enter'||event.key==='ArrowDown')target=index+1;else if(event.key==='ArrowUp')target=index-1;else return;event.preventDefault();const direction=target>index?1:-1;while(inputs[target]&&(inputs[target].disabled||!inputs[target].getClientRects().length))target+=direction;inputs[target]?.focus();});});
});
document.querySelectorAll('[data-biz-copy-price]').forEach(button=>button.addEventListener('click',()=>{const input=button.closest('form').querySelector('[name="price"]');input.value=button.dataset.bizCopyPrice;input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();}));
document.querySelectorAll('[data-biz-category]').forEach(select=>select.addEventListener('change',()=>{select.closest('form').querySelector('.biz-etc-tax').hidden=select.value!=='etc';}));
document.querySelectorAll('[data-biz-price-card]').forEach(card=>{
 const rows=()=>[...card.querySelectorAll('form[data-unregistered="1"]')];
 card.querySelector('[data-biz-copy-all]').addEventListener('click',()=>rows().forEach(row=>{const value=row.querySelector('[data-biz-copy-price]').dataset.bizCopyPrice;if(value!=='')row.querySelector('[name="price"]').value=value;}));
 card.querySelector('[data-biz-add-all]').addEventListener('click',()=>{
  const pending=rows().filter(row=>row.querySelector('[name="price"]').value!=='');
  if(!pending.length){window.alert('미등록 상품의 납품가를 입력하거나 일괄복사해 주세요.');return;}
  if(pending.some(row=>!row.querySelector('[name="price"]').reportValidity()))return;
  const form=document.createElement('form');form.method='post';
  const add=(name,value)=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);};
  add('csrf',card.querySelector('[name="csrf"]').value);add('action','biz_prices_add');add('client_id',card.dataset.client);add('category',card.dataset.category);
  pending.forEach(row=>add('prices['+row.dataset.product+']',row.querySelector('[name="price"]').value));document.body.append(form);form.submit();
 });
});

const orderPriceData=document.querySelector('#biz-order-prices');
if(orderPriceData){
 const data=JSON.parse(orderPriceData.textContent),form=orderPriceData.closest('form'),select=form.querySelector('[name="client_id"]'),grid=form.querySelector('[data-biz-qty-grid]');
 const update=()=>{const cid=Number(select.value),allowed=new Set(data.clients[cid]||[]);if(cid===data.original_client)data.original_items.forEach(id=>allowed.add(id));let shown=0;
  grid.querySelectorAll('[data-biz-product]').forEach(row=>{const show=allowed.has(Number(row.dataset.bizProduct));row.hidden=!show;const input=row.querySelector('input');input.disabled=!show;if(!show){input.value=0;const unit=row.querySelector('[data-biz-unit]');if(unit)unit.value=0;}else shown++;});
  grid.querySelectorAll('.biz-quantity-category').forEach(group=>group.hidden=![...group.querySelectorAll('[data-biz-product]')].some(row=>!row.hidden));
  document.querySelector('#biz-order-products-note').textContent=!cid?'거래처를 먼저 선택해 주세요.':shown?'':'등록된 납품가가 없습니다. 납품가 관리에서 먼저 등록해 주세요.';grid.dispatchEvent(new Event('input',{bubbles:true}));
 };select.addEventListener('change',update);update();
}
document.querySelectorAll('[data-biz-check]').forEach(button=>button.addEventListener('click',()=>button.closest('form').querySelectorAll('[name="batches[]"]').forEach(check=>check.checked=button.dataset.bizCheck==='1')));
