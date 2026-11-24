'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const el=document.getElementById('mfa-qr');
 if(!el||!window.qrcodegen) return;
 const qr=qrcodegen.QrCode.encodeText(el.dataset.uri,qrcodegen.QrCode.Ecc.MEDIUM);
 const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');
 svg.setAttribute('viewBox', '0 0 '+(qr.size+8)+' '+(qr.size+8));
 svg.setAttribute('role','img');svg.setAttribute('aria-label','QR kód pro Google Authenticator');
 const bg=document.createElementNS(ns,'rect');bg.setAttribute('width','100%');bg.setAttribute('height','100%');bg.setAttribute('fill','white');svg.append(bg);
 let d='';for(let y=0;y<qr.size;y++) for(let x=0;x<qr.size;x++) if(qr.getModule(x,y)) d+='M'+(x+4)+','+(y+4)+'h1v1h-1z';
 const path=document.createElementNS(ns,'path');path.setAttribute('d',d);path.setAttribute('fill','black');svg.append(path);el.append(svg);
});
