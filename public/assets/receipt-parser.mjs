export function normalizeSupplier(value){return value.toLocaleLowerCase().replace(/[^\p{L}\p{N}]/gu,'');}
export function matchSupplier(display,suppliers=[]){const key=normalizeSupplier(display);const canonical=/^costco\b/i.test(display)?'costco':/^(amazon|amzn)\b/i.test(display)?'amazon':key;return suppliers.find(s=>[s.name,...s.aliases].some(n=>{const k=normalizeSupplier(n);return k===key||k===canonical;}))||null;}
function amount(line){const values=line.match(/(?:\d[\d,]*\.\d{2}|\d+,\d{2})(?!\d)/g);if(!values)return null;const s=values.at(-1);const n=s.includes('.')?Number(s.replace(/,/g,'')):Number(s.replace(',','.'));return Number.isFinite(n)?n.toFixed(2):null;}
function validDate(y,m,d){const n=new Date(Date.UTC(+y,+m-1,+d));return n.getUTCFullYear()===+y&&n.getUTCMonth()===+m-1&&n.getUTCDate()===+d?`${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`:null;}
export function parseReceipt(text,suppliers=[]){
 const lines=text.split(/\r?\n/).map(s=>s.replace(/\s+/g,' ').trim()).filter(Boolean);const out={fields:{},warnings:[]};let date;
 const iso=text.match(/\b(20\d{2})[-/.](\d{1,2})[-/.](\d{1,2})\b/);if(iso)date=validDate(...iso.slice(1));
 if(!date){const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};let m=text.match(/\b(Jan\w*|Feb\w*|Mar\w*|Apr\w*|May|Jun\w*|Jul\w*|Aug\w*|Sep\w*|Oct\w*|Nov\w*|Dec\w*)\s+(\d{1,2}),?\s+(20\d{2})\b/i);if(m)date=validDate(m[3],months[m[1].slice(0,3).toLowerCase()],m[2]);m=text.match(/\b(\d{1,2})\s+(Jan\w*|Feb\w*|Mar\w*|Apr\w*|May|Jun\w*|Jul\w*|Aug\w*|Sep\w*|Oct\w*|Nov\w*|Dec\w*)\s+(20\d{2})\b/i);if(!date&&m)date=validDate(m[3],months[m[2].slice(0,3).toLowerCase()],m[1]);
  m=text.match(/\b(\d{1,2})[/-](\d{1,2})[/-](20\d{2})\b/);if(!date&&m){if(+m[1]>12)date=validDate(m[3],m[2],m[1]);else if(+m[2]>12)date=validDate(m[3],m[1],m[2]);else out.warnings.push('Date 형식이 모호합니다. 날짜를 직접 선택하세요.');}}
 if(date)out.fields.expense_date=date;else if(!out.warnings.length)out.warnings.push('Date를 찾지 못했습니다. 직접 선택하세요.');
 const head=lines.slice(0,25);let vendor=head.find(l=>/^costco\b/i.test(l))||head.find(l=>/^(amazon|amzn)\b/i.test(l));if(!vendor)vendor=head.find(l=>suppliers.some(s=>[s.name,...s.aliases].some(a=>normalizeSupplier(l)===normalizeSupplier(a))));if(!vendor)vendor=head.find(l=>/[a-z\p{L}]/iu.test(l)&&!/^((tax\s*)?invoice|receipt|order confirmation|page\b|date\b|invoice\s*#|tel\b|phone\b|www\.|https?:)/i.test(l)&&!/^\d/.test(l));if(vendor)out.fields.vendor=vendor.slice(0,150);
 let totalScore=-1;for(let i=0;i<lines.length;i++){const line=lines[i];const nearby=amount(line)??(/^(?:grand total|order total|total|amount due|hst|gst(?:\/hst)?)\s*:?$/i.test(line)?amount(lines[i+1]||''):null);if(nearby===null)continue;
  if(/sub\s*total|saving|discount|taxable|registration|\breg\b|\bnumber\b|\b(tip|change)\b/i.test(line))continue;
  if(/\b(hst|gst|gst\/hst|tvh|tps)\b/i.test(line)&&!/(?:#|[A-Z]{2}\d{6})/.test(line)){out.fields.hst=nearby;continue;}
  if(/\b(pst|qst|tvq)\b/i.test(line)){out.fields.other_tax=nearby;continue;}
  const score=/grand total|order total|total charged|amount paid|amount due/i.test(line)?3:/\btotal\b/i.test(line)?2:0;if(score&&score>=totalScore){out.fields.total=nearby;totalScore=score;}}
 if(/\b(USD|US\s*dollars?|CNY|RMB|KRW|EUR|GBP)\b|[￥¥€£₩]/i.test(text)){delete out.fields.total;delete out.fields.hst;delete out.fields.other_tax;out.warnings.push('외화 금액은 CAD로 적용하지 않습니다. 카드에 청구된 CAD Amount를 입력하세요.');}
 if(!out.fields.total)out.warnings.push('Amount를 확인하세요.');if(!out.fields.hst)out.warnings.push('HST가 없거나 읽히지 않았습니다. 영수증과 대조하세요.');
 out.supplier=out.fields.vendor?matchSupplier(out.fields.vendor,suppliers):null;return out;
}
