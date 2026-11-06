const {chromium}=require('playwright');const fs=require('fs');const path=require('path');const root=path.resolve(__dirname,'..');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8080');await page.getByLabel('아이디',{exact:true}).fill('admin');await page.getByLabel('비밀번호',{exact:true}).fill(fs.readFileSync(root+'/.local/admin-password.txt','utf8').trim());await page.getByRole('button',{name:'로그인하기',exact:true}).click();
 await page.goto('http://127.0.0.1:8080/?page=excel-preview&tab=import');await page.getByRole('button',{name:'저장할 내용 확인하기',exact:true}).click();
 await page.getByText('아직 저장하지 않았습니다.',{exact:true}).waitFor();let text=await page.locator('main').textContent();
 if(!text.includes('새 고객 193명') && !text.includes('새 고객 0명'))throw Error('Customer count');
 if(!text.includes('새 주문 946건') && !text.includes('기존 주문 건너뛰기 946건'))throw Error('Order count');
 for(const expected of ['$77,847.00','$82.00','$180.00'])if(!text.includes(expected))throw Error('Missing '+expected);
 if(await page.getByRole('checkbox').isChecked())throw Error('Confirmation prechecked');
 await page.setViewportSize({width:390,height:844});if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Mobile overflow');
 await page.goto('http://127.0.0.1:8080/?page=customer-edit');await page.getByLabel('고객 종류').selectOption('organization');await page.getByLabel('단체 담당자 (선택)',{exact:true}).fill('가상 담당자');
 if(errors.length)throw Error(errors.join('\n'));console.log('PASS 실제 가져오기 준비 화면·금액·확인란·모바일·단체 담당자 입력 (실제 저장 안 함)');
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
