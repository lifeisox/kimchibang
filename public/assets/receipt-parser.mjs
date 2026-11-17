export function normalizeSupplier(value){return value.toLocaleLowerCase().replace(/[^\p{L}\p{N}]/gu,'');}
export function matchSupplier(display,suppliers=[]){const key=normalizeSupplier(display);const canonical=/costco\b/i.test(display)?'costco':/^(amazon|amzn)\b/i.test(display)?'amazon':key;return suppliers.find(s=>[s.name,...(s.aliases||[])].some(n=>{const k=normalizeSupplier(n);return k===key||k===canonical;}))||null;}
function amounts(line){return [...line.matchAll(/(?<![\d.])\d[\d,]*\.\d{2}(?!\d)|(?<![\d,])\d+,\d{2}(?!\d)/g)].map(m=>Number(m[0].includes('.')?m[0].replace(/,/g,''):m[0].replace(',','.')));}
function validDate(y,m,d){const n=new Date(Date.UTC(+y,+m-1,+d));return n.getUTCFullYear()===+y&&n.getUTCMonth()===+m-1&&n.getUTCDate()===+d?`${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`:null;}
function receiptDate(text,warnings){let m=text.match(/\b(20\d{2})[-/.](\d{1,2})[-/.](\d{1,2})\b/);if(m)return validDate(...m.slice(1));const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};const word='(Jan\\w*|Feb\\w*|Mar\\w*|Apr\\w*|May|Jun\\w*|Jul\\w*|Aug\\w*|Sep\\w*|Oct\\w*|Nov\\w*|Dec\\w*)';m=text.match(new RegExp('\\b'+word+'\\s+(\\d{1,2}),?\\s+(20\\d{2})\\b','i'));if(m)return validDate(m[3],months[m[1].slice(0,3).toLowerCase()],m[2]);m=text.match(new RegExp('\\b(\\d{1,2})\\s+'+word+'\\s+(20\\d{2})\\b','i'));if(m)return validDate(m[3],months[m[2].slice(0,3).toLowerCase()],m[1]);m=text.match(/\b(\d{1,2})[/-](\d{1,2})[/-](20\d{2})\b/);if(m){if(+m[1]>12)return validDate(m[3],m[2],m[1]);if(+m[2]>12)return validDate(m[3],m[1],m[2]);warnings.push('Date 형식이 모호합니다. 날짜를 직접 선택하세요.');}return null;}
export function receiptScore(text){const p=parseReceipt(text);return (p.fields.total?8:0)+(p.fields.hst?4:0)+(p.fields.expense_date?4:0)+Math.min(5,(text.match(/\b(total|amount|hst|costco|amazon|mart|market|invoice|purchase|subtotal)\b/gi)||[]).length);}
function ruleLabel(value){return value.toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();}
export function applyReceiptRule(out,lines,supplier){
 const rule=supplier?.rule;if(!rule)return;const notes=[];
 for(const [field,key] of [['total','total_label'],['hst','hst_label']]){if(!rule[key])continue;const values=[];
 for(let i=0;i<lines.length;i++){const line=lines[i];const m=line.match(/(?:\$\s*)?\d[\d,]*[.,]\d{2}(?!\d)/);const label=m?line.slice(0,m.index):line;
 if(ruleLabel(label)!==ruleLabel(rule[key]))continue;const n=amounts(m?line:lines[i+1]||'');if(n.length===1)values.push(n[0].toFixed(2));}
 const distinct=[...new Set(values)];if(distinct.length===1){out.fields[field]=distinct[0];notes.push(field==='total'?'Amount 표기':'HST 표기');}else if(distinct.length>1){delete out.fields[field];out.warnings.push('기억한 '+rule[key]+' 표기의 금액이 여러 개입니다. 직접 확인하세요.');}
 }
 const taxEvidence=lines.some(l=>/\b(hst|gst|tvh|tps|tax|taxe)\b/i.test(l)&&!( /#|registration|\bRT\s*\d{4}\b/i.test(l)&&amounts(l).length===0));
 if(Number(rule.missing_hst_zero)===1&&!out.fields.hst&&!taxEvidence){out.fields.hst='0.00';notes.push('HST 표기 없음 → 0');}
 if(notes.length)out.warnings.push('구매처 기억 규칙 적용: '+notes.join(', ')+'. 영수증과 대조하세요.');
}
export function parseReceipt(text,suppliers=[],selectedSupplierId=0){
 const lines=text.split(/\r?\n/).map(s=>s.replace(/\s+/g,' ').trim()).filter(Boolean);const out={fields:{},warnings:[]};
 // Invoice date wins over order/shipping dates. Do not use a purchaser/address as the merchant.
 const invoiceLine=lines.find(l=>/invoice date|date de facturation/i.test(l));const date=receiptDate(invoiceLine||text,out.warnings);if(date)out.fields.expense_date=date;else if(!out.warnings.length)out.warnings.push('Date를 찾지 못했습니다. 직접 선택하세요.');
 const head=lines.slice(0,35),all=head.join('\n');let vendor;
 if(/amazon\.(?:com\.ca|ca|com)\b/i.test(text))vendor='Amazon';else if(/costco\b/i.test(all))vendor='Costco';else if(/\bok\s*mart\b/i.test(all))vendor='OK Mart';else if(/green\s*fresh/i.test(all))vendor='Green Fresh Supermarket';
 if(!vendor)vendor=head.find(l=>suppliers.some(s=>[s.name,...(s.aliases||[])].some(a=>normalizeSupplier(l)===normalizeSupplier(a))));
 if(!vendor){const sold=head.find(l=>/sold by|vendu par/i.test(l));if(sold)vendor=sold.replace(/^.*?(?:sold by\s*\/\s*vendu par|sold by|vendu par)\s*:?\s*/i,'');}
 if(!vendor)vendor=head.find(l=>/[\p{L}]/u.test(l)&&!/^((tax\s*)?invoice|receipt|paid\b|payé\b|transaction|order confirmation|page\b|date\b|invoice\s*#|tel\b|phone\b|www\.|https?:)/i.test(l)&&!/^\d/.test(l));if(vendor)out.fields.vendor=vendor.slice(0,150);
 let totalScore=-1;const totals=[],taxes=[];for(let i=0;i<lines.length;i++){const line=lines[i];let nums=amounts(line);if(!nums.length&&/^(?:\W*(?:grand total|order total|total payable|total|amount due|hst(?: included)?|gst(?:\/hst)?))\s*:?$/i.test(line))nums=amounts(lines[i+1]||'');if(!nums.length)continue;
  if(/sub\s*total|saving|discount|taxable|registration|\breg\b|\bnumber\b|\b(tip|change)\b/i.test(line))continue;
  if(/\b(hst|gst|tvh|tps)\b/i.test(line)&&!/(?:#|\bRT\s*\d{4}\b)/i.test(line)){if(nums.length===1){taxes.push(nums[0]);out.fields.hst=nums[0].toFixed(2);}continue;}
  if(/\b(pst|qst|tvq)\b/i.test(line)){if(nums.length===1)out.fields.other_tax=nums[0].toFixed(2);continue;}
  if(/\b(?:total\s+tax|tax)\b/i.test(line)){if(nums.length===1&&nums[0]===0)out.fields.hst='0.00';else out.warnings.push('Tax가 GST/HST인지 확인하세요.');continue;}
  const score=/total payable|grand total|order total|total charged|amount paid|amount due/i.test(line)?5:/\btotal\b/i.test(line)?3:/^(?:amount|fuel sale)\s*:/i.test(line)?2:0;
  if(score&&nums.length===1){totals.push(nums[0]);if(score>totalScore){out.fields.total=nums[0].toFixed(2);totalScore=score;}}
 }
 // Amazon Canada invoices put tax totals in a multi-column summary on the last page.
 // Require the explicit header and arithmetic; do not infer HST from a provincial rate.
 if(vendor==='Amazon'&&/federal tax/i.test(text)&&/provincial tax/i.test(text)&&/tax\s*subtotal|sous-total\s*de la taxe/i.test(text)){
  const candidates=lines.filter(l=>/^Total\b/i.test(l)).map(amounts).filter(n=>n.length===4);
  const valid=candidates.filter(n=>Math.abs(n[1]+n[2]-n[3])<0.005&&out.fields.total&&Math.abs(n[0]+n[3]-Number(out.fields.total))<0.005);
  if(valid.length===1){out.fields.hst=valid[0][1].toFixed(2);out.fields.other_tax=valid[0][2].toFixed(2);}else out.warnings.push('PDF의 Federal / Provincial Tax 합계 표를 직접 확인하세요.');
 }
 if(new Set(taxes.map(n=>n.toFixed(2))).size>1){delete out.fields.hst;out.warnings.push('서로 다른 HST 금액이 있습니다. 합계 세액을 확인하세요.');}
 if(new Set(totals.map(n=>n.toFixed(2))).size>1)out.warnings.push('여러 합계 금액이 있습니다. 선택한 Amount가 영수증 전체 금액인지 확인하세요.');
 out.supplier=suppliers.find(s=>Number(s.id)===Number(selectedSupplierId))||(out.fields.vendor?matchSupplier(out.fields.vendor,suppliers):null);applyReceiptRule(out,lines,out.supplier);
 if(/\b(USD|US\s*dollars?|CNY|RMB|KRW|EUR|GBP)\b|[￥¥€£₩]/i.test(text)){delete out.fields.total;delete out.fields.hst;delete out.fields.other_tax;out.warnings.push('외화 금액은 CAD로 적용하지 않습니다. 카드에 청구된 CAD Amount를 입력하세요.');}
 if(out.fields.total&&out.fields.hst&&Number(out.fields.hst)>Number(out.fields.total)){delete out.fields.hst;out.warnings.push('HST가 Amount보다 커서 적용하지 않았습니다.');}
 if(!out.fields.total)out.warnings.push('Amount를 확인하세요.');if(!out.fields.hst)out.warnings.push('HST가 없거나 읽히지 않았습니다. 영수증과 대조하세요.');
 return out;
}
