import assert from 'node:assert/strict';
import {parseReceipt} from '../public/assets/receipt-parser.mjs';
const suppliers=[{id:7,name:'Costco',aliases:['Costco Business Center']}];
let p=parseReceipt('Costco Merivale\n2026-11-16\nSUBTOTAL 100.00\nHST 13.00\nTOTAL 113.00\nCHANGE 0.00',suppliers);
assert.equal(p.supplier.id,7);assert.equal(p.fields.total,'113.00');assert.equal(p.fields.hst,'13.00');assert.equal(p.fields.expense_date,'2026-11-16');
p=parseReceipt('Amazon.ca\nNovember 16, 2026\nOrder Total: $1,130.00\nGST/HST: $130.00');assert.equal(p.fields.total,'1130.00');assert.equal(p.fields.hst,'130.00');assert.equal(p.fields.expense_date,'2026-11-16');
p=parseReceipt('TEST STORE\n11/12/2026\nTOTAL 113.00\nHST 13.00');assert.equal(p.fields.expense_date,undefined);assert(p.warnings.some(x=>x.includes('모호')));
p=parseReceipt('TEST STORE\n2026-02-30\nTotal 30.00 USD\nHST 0.00');assert.equal(p.fields.expense_date,undefined);assert.equal(p.fields.total,undefined);assert.equal(p.fields.hst,undefined);
p=parseReceipt('TEST STORE\n2026-11-16\nTOTAL\n113.00\nHST\n13.00');assert.equal(p.fields.total,'113.00');assert.equal(p.fields.hst,'13.00');
console.log('PASS 영수증 Amount·HST·일자·구매처 별칭·모호한 날짜·외화 보수적 처리');
// Transcribed labels/amounts from the supplied receipt layouts, not OCR accuracy fixtures.
for(const [text,total,hst,date,vendor] of [
 ['OK Mart\nNovember 16, 2026\nGST/HST # 799016134RT0001\nTotal $28.40\nMastercard 28.40','28.40',undefined,'2026-11-16','OK Mart'],
 ['COSTCO\n2026/11/16 10:02:33\nFuel Sale: $52.84\nHST Included: $6.08\nHST #121476329\nAmount: $52.84\nAmount due: $52.84','52.84','6.08','2026-11-16','Costco'],
 ['Green Fresh\nSupermarket\n11/13/2026 10:26:03 AM\nTOTAL $19.12\nMaster $19.12\nYou saved: $0.40','19.12',undefined,'2026-11-13','Green Fresh Supermarket'],
 ['Costco WHOLESALE\nSUBTOTAL 86.21\nTAX 0.00\n**** TOTAL 86.21\n2026/11/13 10:48:25\nTOTAL TAX 0.00','86.21','0.00','2026-11-13','Costco']
]){const r=parseReceipt(text);assert.equal(r.fields.total,total);assert.equal(r.fields.hst,hst);assert.equal(r.fields.expense_date,date);assert.equal(r.fields.vendor,vendor);}
p=parseReceipt('Invoice / Facture\nPaid / Payé\nSold by / Vendu par: Amazon.com.ca ULC\nGST/HST # / # de TPS/TVH: 123 RT0001\nInvoice date / Date de facturation: 16 November 2026\nTotal payable / Total à payer: $29.36\nOrder date: 01 November 2026\nFederal tax / Provincial tax / Tax subtotal\n[GST/HST/TPS/TVH] [PST/RST/QST/TVP/TVD/TVQ]\nTotal $25.98 $3.38 $0.00 $3.38');assert.deepEqual(p.fields,{vendor:'Amazon',expense_date:'2026-11-16',total:'29.36',hst:'3.38',other_tax:'0.00'});
p=parseReceipt('Costco\nHST #121476329\nTOTAL TAX 0.00\nTOTAL 86.21');assert.equal(p.fields.hst,'0.00');assert.equal(p.fields.total,'86.21');
p=parseReceipt('Amazon.ca\nTotal payable $29.36\nFederal tax / Provincial tax / Tax subtotal\nTotal $25.98 $9.38 $0.00 $3.38');assert.equal(p.fields.hst,undefined);
console.log('PASS 주유 HST Included·식료품 Tax 0·구매처·Amazon Tax 합계 검산·Invoice date 우선');
const learned=[{id:22,name:'Learning Store',aliases:['Learning Branch'],rule:{total_label:'Balance payable',hst_label:'',missing_hst_zero:1}}];
p=parseReceipt('Learning Branch\n2026-11-16\nTotal 999.00\nBalance payable 40.00',learned);assert.equal(p.fields.total,'40.00');assert.equal(p.fields.hst,'0.00');assert(p.warnings.some(x=>x.includes('기억 규칙')));
p=parseReceipt('Other Store\nBalance payable 40.00',learned);assert.equal(p.fields.hst,undefined);
p=parseReceipt('Learning Branch\nBalance payable 40.00\nHST unreadable',learned);assert.equal(p.fields.hst,undefined);
p=parseReceipt('Learning Branch\nBalance payable 40.00\nBalance payable 45.00',learned);assert.equal(p.fields.total,undefined);
p=parseReceipt('Learning Branch\nBalance payable 40.00 USD',learned);assert.equal(p.fields.total,undefined);assert.equal(p.fields.hst,undefined);
console.log('PASS 구매처별 기억 규칙·다른 구매처 격리·명시적 세금 보호·충돌·외화 보호');
