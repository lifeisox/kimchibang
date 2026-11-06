const {chromium}=require('playwright');const fs=require('fs');const {execFileSync}=require('child_process');const root=require('path').resolve(__dirname,'..');
const nonce=require('crypto').randomBytes(8).toString('hex');const relative='.local/browser-groups-'+nonce+'.xlsx';let sha;
(async()=>{
 execFileSync('python3',['-c',"import sys;sys.path.insert(0,'tests');from excel_fixture import fixture,write_book;p=sys.argv[1];c,o=fixture(p);write_book(p,{'고객명단':c,'26':o,'검증':{1:{1:sys.argv[2]}}},shared=['가상김치고객'])",relative,nonce],{cwd:root});
 const bytes=fs.readFileSync(root+'/'+relative);sha=require('crypto').createHash('sha256').update(bytes).digest('hex');
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1080}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const shot=async name=>{await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:root+'/.local/screenshots/'+name,fullPage:true});};
 try{
 await page.goto('http://127.0.0.1:8080');await page.getByLabel('아이디',{exact:true}).fill('admin');await page.getByLabel('비밀번호',{exact:true}).fill(fs.readFileSync(root+'/.local/admin-password.txt','utf8').trim());await page.getByRole('button',{name:'로그인하기',exact:true}).click();
 await page.goto('http://127.0.0.1:8080/?page=products');
 const white=page.locator('tr').filter({hasText:'백포기김치'});if(await white.count()!==1 || !(await white.textContent()).includes('$50.00'))throw Error('White kimchi product');
 await shot('15-white-kimchi-product.png');
 await page.goto('http://127.0.0.1:8080/?page=excel-preview&tab=groups');await page.locator('#preview-review-form').waitFor();
 await shot('16-group-review.png');
 const checks=page.locator('input[name="selected[]"]');const count=await checks.count();if(count<1 || !(await page.locator('main').textContent()).includes('전체 '+count+'그룹'))throw Error('Original groups count');
 await page.getByRole('button',{name:'모든 그룹 선택',exact:true}).click();if(await checks.evaluateAll(els=>els.filter(e=>e.checked).length)!==count)throw Error('Select all');
 await page.getByRole('button',{name:'선택 해제',exact:true}).click();if(await checks.evaluateAll(els=>els.filter(e=>e.checked).length)!==0)throw Error('Clear selection');
 // Only a fictional workbook is submitted for the mutating review operation.
 await page.goto('http://127.0.0.1:8080/?page=excel-preview');
 await page.getByLabel('엑셀 파일 선택 (.xlsx)',{exact:true}).setInputFiles({name:relative.split('/').pop(),mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:bytes});
 await page.getByRole('button',{name:'미리보기 만들기',exact:true}).click();await page.getByText('엑셀 분석을 마쳤습니다.',{exact:false}).waitFor();
 await page.locator('.preview-tabs').getByRole('link',{name:'그룹별 처리',exact:true}).click();
 await page.getByRole('button',{name:'모든 그룹 선택',exact:true}).click();await page.getByRole('button',{name:'선택한 그룹에 적용',exact:true}).click();
 await page.getByText('개 그룹의 검토 기준을 반영했습니다.',{exact:false}).waitFor();
 if(!(await page.locator('main').textContent()).includes('가져오기 보류'))throw Error('Held groups label');
 await shot('17-groups-applied-example.png');
 await page.setViewportSize({width:390,height:844});await shot('18-groups-mobile.png');
 if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw Error('Mobile overflow');
 await page.setViewportSize({width:1440,height:1080});
 const first=page.locator('.review-group-card').first();await first.locator('select').selectOption('reset');await first.locator('input[type=checkbox]').check();
 await page.getByRole('button',{name:'선택한 그룹에 적용',exact:true}).click();await page.getByText('1개 그룹의 검토 기준을 반영했습니다.',{exact:false}).waitFor();
 if(!(await page.locator('.review-group-card').first().textContent()).includes('선택 전'))throw Error('Reset group');
 await page.getByRole('link',{name:'이 그룹의 자료 보기 →',exact:true}).first().click();
 if(!page.url().includes('review_group='))throw Error('Group member link');
 if(errors.length)throw Error(errors.join('\n'));
 console.log('PASS 브라우저: 백포기김치 등록·실제 그룹 표시·전체 선택/해제·가상 자료 일괄 적용·보류·되돌리기·모바일 / JS 오류 0');
 }finally{
 const name64=Buffer.from(relative.split('/').pop()).toString('base64');
 execFileSync('docker',['compose','exec','-T','web','php'],{cwd:root,input:`<?php require 'app/bootstrap.php';require 'app/preview-storage.php';$file=previewReviewPath('${sha}');foreach([$file,$file.'.lock'] as $f)if(is_file($f))unlink($f);$name=base64_decode('${name64}');foreach(glob(previewDirectory().'/*.json')?:[] as $f){if(basename($f)==='provided.json')continue;$r=json_decode(file_get_contents($f),true);if(($r['filename']??null)===$name)unlink($f);}`});
 fs.rmSync(root+'/'+relative,{force:true});await browser.close();
 }
})().catch(e=>{console.error(e);process.exit(1)});
