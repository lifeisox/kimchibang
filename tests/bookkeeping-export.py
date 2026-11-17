from pathlib import Path
import subprocess,io,zipfile,xml.etree.ElementTree as ET
root=Path(__file__).resolve().parent.parent
code='''require 'app/expense-xlsx.php';echo expenseXlsxBytes([['expense_date'=>'2026-02-03','supplier_name'=>'=UNTRUSTED & <store>','account_text'=>'Material','total_cents'=>11300,'hst_cents'=>1300,'other_tax_cents'=>0,'subtotal_cents'=>10000,'description'=>'메모']],2026);'''
r=subprocess.run(['docker','compose','exec','-T','web','php','-r',code],cwd=root,capture_output=True,check=True)
ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
with zipfile.ZipFile(io.BytesIO(r.stdout)) as z:
 assert z.testzip() is None
 for name in z.namelist():ET.fromstring(z.read(name))
 book=ET.fromstring(z.read('xl/workbook.xml'));assert [x.attrib['name'] for x in book.find('s:sheets',ns)]==[str(i)+'월' for i in range(1,13)]
 sheet=ET.fromstring(z.read('xl/worksheets/sheet2.xml'));assert sheet.find('.//s:c[@r="D2"]/s:v',ns).text=='113.00'
 assert sheet.find('.//s:c[@r="C2"]/s:is/s:t',ns).text=='Material'
 assert sheet.find('.//s:c[@r="B2"]',ns).attrib['t']=='inlineStr'
 assert not sheet.findall('.//s:f',ns)
 assert len(ET.fromstring(z.read('xl/worksheets/sheet1.xml')).findall('.//s:row',ns))==2
print('PASS XLSX 12 monthly sheets, totals, numeric amounts, XML escaping, formula-safe strings')
