// All processing stays in the browser. Original uploads are never modified.
export function rotateCanvas(source,angle,maxSide=2400){const scale=Math.min(1,maxSide/Math.max(source.width,source.height)),w=Math.round(source.width*scale),h=Math.round(source.height*scale),swap=angle%180!==0;const c=document.createElement('canvas');c.width=swap?h:w;c.height=swap?w:h;const x=c.getContext('2d');x.fillStyle='white';x.fillRect(0,0,c.width,c.height);x.translate(c.width/2,c.height/2);x.rotate(angle*Math.PI/180);x.drawImage(source,-w/2,-h/2,w,h);return c;}
export function paperCrop(source){
 const thumb=rotateCanvas(source,0,300),ctx=thumb.getContext('2d'),{data}=ctx.getImageData(0,0,thumb.width,thumb.height),w=thumb.width,h=thumb.height,n=w*h,gray=new Uint8Array(n),hist=new Uint32Array(256);let sum=0;
 for(let i=0;i<n;i++){const g=Math.round(data[i*4]*.299+data[i*4+1]*.587+data[i*4+2]*.114);gray[i]=g;hist[g]++;sum+=g;}
 let best=0,threshold=130,left=0,leftSum=0;for(let t=0;t<255;t++){left+=hist[t];leftSum+=t*hist[t];if(!left||left===n)continue;const d=leftSum/left-(sum-leftSum)/(n-left),v=left*(n-left)*d*d;if(v>best){best=v;threshold=t;}}threshold=Math.max(95,Math.min(190,threshold));
 const seen=new Uint8Array(n),queue=new Int32Array(n);let region=null;
 for(let i=0;i<n;i++){if(seen[i]||gray[i]<=threshold)continue;let a=0,b=1;queue[0]=i;seen[i]=1;let count=0,minX=w,minY=h,maxX=0,maxY=0;
 while(a<b){const p=queue[a++],x=p%w,y=Math.floor(p/w);count++;minX=Math.min(minX,x);maxX=Math.max(maxX,x);minY=Math.min(minY,y);maxY=Math.max(maxY,y);for(const q of [x>0?p-1:-1,x<w-1?p+1:-1,y>0?p-w:-1,y<h-1?p+w:-1])if(q>=0&&!seen[q]&&gray[q]>threshold){seen[q]=1;queue[b++]=q;}}
 if(!region||count>region.count)region={count,minX,minY,maxX,maxY};}
 if(!region||region.count<n*.12)return source;
 const padX=Math.ceil(w*.06),padY=Math.ceil(h*.06),x=Math.max(0,region.minX-padX),y=Math.max(0,region.minY-padY),right=Math.min(w,region.maxX+padX),bottom=Math.min(h,region.maxY+padY);
 if((right-x)*(bottom-y)>n*.92)return source;const sx=source.width/w,sy=source.height/h,c=document.createElement('canvas');c.width=Math.round((right-x)*sx);c.height=Math.round((bottom-y)*sy);c.getContext('2d').drawImage(source,x*sx,y*sy,c.width,c.height,0,0,c.width,c.height);return c;
}
export function normalizeLighting(source){const c=rotateCanvas(source,0),x=c.getContext('2d'),im=x.getImageData(0,0,c.width,c.height),w=c.width,h=c.height,stride=w+1,gray=new Uint8Array(w*h),integral=new Float64Array((w+1)*(h+1));
 for(let y=0;y<h;y++){let sum=0;for(let j=0;j<w;j++){const i=y*w+j,g=Math.round(im.data[i*4]*.299+im.data[i*4+1]*.587+im.data[i*4+2]*.114);gray[i]=g;sum+=g;integral[(y+1)*stride+j+1]=integral[y*stride+j+1]+sum;}}
 const r=35;for(let y=0;y<h;y++)for(let j=0;j<w;j++){const a=Math.max(0,j-r),b=Math.min(w,j+r+1),t=Math.max(0,y-r),d=Math.min(h,y+r+1),mean=(integral[d*stride+b]-integral[t*stride+b]-integral[d*stride+a]+integral[t*stride+a])/((b-a)*(d-t));const g=Math.max(0,Math.min(255,(gray[y*w+j]/Math.max(1,mean)*220-160)*1.7+160)),i=(y*w+j)*4;im.data[i]=im.data[i+1]=im.data[i+2]=g;im.data[i+3]=255;}x.putImageData(im,0,0);return c;
}
export function pdfLines(items){const rows=[];for(const item of items){if(!item.str?.trim())continue;const y=item.transform[5];let row=rows.find(r=>Math.abs(r.y-y)<=2);if(!row){row={y,items:[]};rows.push(row);}row.items.push(item);}return rows.sort((a,b)=>b.y-a.y).map(r=>r.items.sort((a,b)=>a.transform[4]-b.transform[4]).map(i=>i.str).join(' ')).join('\n');}
