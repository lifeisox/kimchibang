"""Small fictional OOXML workbooks. Standard library only; never contains customer data."""
from zipfile import ZipFile, ZIP_DEFLATED
from xml.sax.saxutils import escape
from pathlib import Path
NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main'
def col(n):
    s=''
    while n:
        n,r=divmod(n-1,26);s=chr(65+r)+s
    return s

def sheet_xml(rows):
    out=[f'<worksheet xmlns="{NS}"><sheetData>']
    for r,cells in rows.items():
        out.append(f'<row r="{r}">')
        for n,value in cells.items():
            ref=col(n)+str(r)
            if isinstance(value,tuple):
                kind,*args=value
                if kind=='formula':
                    formula,cache=args
                    out.append(f'<c r="{ref}"><f>{escape(formula)}</f>'+('' if cache is None else '<v>'+str(cache)+'</v>')+'</c>')
                elif kind=='shared':out.append(f'<c r="{ref}" t="s"><v>{args[0]}</v></c>')
                elif kind=='date':out.append(f'<c r="{ref}" t="d"><v>{escape(args[0])}</v></c>')
                elif kind=='error':out.append(f'<c r="{ref}" t="e"><v>{escape(args[0])}</v></c>')
            elif isinstance(value,bool):out.append(f'<c r="{ref}" t="b"><v>{int(value)}</v></c>')
            elif isinstance(value,(int,float)):out.append(f'<c r="{ref}"><v>{value}</v></c>')
            else:out.append(f'<c r="{ref}" t="inlineStr"><is><t>{escape(str(value))}</t></is></c>')
        out.append('</row>')
    out.append('</sheetData></worksheet>')
    return ''.join(out)

def write_book(path,sheets,shared=None,date1904=False,doctype=False,external=False):
    Path(path).parent.mkdir(exist_ok=True,parents=True)
    with ZipFile(path,'w',ZIP_DEFLATED) as z:
        header=f'<workbook xmlns="{NS}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        if doctype:header='<!DOCTYPE workbook [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'+header
        z.writestr('xl/workbook.xml',header+f'<workbookPr date1904="{int(date1904)}"/><sheets>'+''.join(f'<sheet name="{escape(name)}" sheetId="{i}" r:id="rId{i}"/>' for i,name in enumerate(sheets,1))+'</sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'+''.join(f'<Relationship Id="rId{i}" Target="worksheets/sheet{i}.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'+(' TargetMode="External"' if external else '')+'/>' for i in range(1,len(sheets)+1))+'</Relationships>')
        for i,rows in enumerate(sheets.values(),1):z.writestr(f'xl/worksheets/sheet{i}.xml',sheet_xml(rows))
        if shared is not None:z.writestr('xl/sharedStrings.xml',f'<sst xmlns="{NS}">'+''.join('<si><t>'+escape(t)+'</t></si>' for t in shared)+'</sst>')

def fixture(path):
    customers={1:{1:'표기성명',2:'아이디',3:'성명',4:'지역',5:'주소',9:'비고'},
    2:{1:('formula','IF(C2="",B2,C2)',None),2:'kimchi-test',3:('shared',0),4:'가상지역',5:'123 Example St (가상 주소)',9:'<script>alert("fixture")</script>'},
    3:{1:'동명이인',2:'가상별명A',3:'동명이인',5:'1 Fictional St'},
    4:{1:'동명이인',2:'가상별명B',3:'동명이인',5:'2 Fictional St'},
    5:{1:'닉네임고객',2:'닉네임고객',3:'',5:''}}
    headers={1:'주문일자',2:'주문자',3:'주문합계',4:'할인액',5:'실제주문',6:'생산',7:'배송',8:'입금',9:'포5',10:'깍',11:'겉절이',12:'오이',13:'열',14:'열',15:'맛김치',16:'기타',17:'비고',18:'주소'}
    prices={9:50,10:30,11:35,12:12,13:25,14:35,15:50,16:0}
    def row(name='kimchi-test',qty=None,subtotal=50,total=50,discount=0,date='2026-01-02'):
        return {1:('date',date),2:name,3:('formula','SUMPRODUCT(I3:P3,I$2:P$2)',subtotal),4:discount,5:('formula','C3-D3',total),6:True,7:True,8:True,**(qty or {9:1})}
    orders={1:headers,2:prices,
    3:row(qty={9:1,10:1},subtotal=80,total=75,discount=5),
    4:row(qty={9:1,10:1},subtotal=80,total=75,discount=5),
    5:row(name='동명이인',total=55,discount=-5,date='2025-12-31'),
    6:row(name='명단에없는고객',qty={12:2},subtotal=24,total=24),
    7:row(name='닉네임고객',qty={13:1,14:1},subtotal=60,total=60),
    8:row(subtotal=99,total=99),
    9:row(subtotal=None,total=None),
    10:{1:('date','2026-01-03'),2:'kimchi-test'},
    11:row(qty={9:0.5},subtotal=25,total=25),
    12:row(qty={9:True},subtotal=50,total=50),
    13:{9:0.0,3:('formula','IF(1=1,"",0)',None),5:('formula','IF(1=1,"",0)',None)}}
    write_book(path,{'고객명단':customers,'26':orders,'소요량':{1:{1:'ignored'}}},shared=['가상김치고객'])
    return customers,orders
if __name__=='__main__':
    fixture(Path(__file__).resolve().parent.parent/'.local'/'preview-fixture.xlsx')
    print('가상 미리보기 엑셀 준비 완료')
