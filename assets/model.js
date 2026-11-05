export const PRODUCTS=[['gold','Zlato'],['silver','Stříbro'],['funds','Fondy'],['bitcoin','Bitcoin'],['swiss','Švýcarské stříbro'],['insurance','Pojištění']];
export const GROUPS=['A','B','C','D','E'];
export const COLORS={A:'#d6b65b',B:'#5897d0',C:'#95b181',D:'#e89536',E:'#cf8b82'};
export const isProduct=p=>!!p&&['record','confirmed'].includes(p.state);
export const fold=v=>String(v??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('cs');
export const dateToday=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Prague'}).format(new Date());
export function filterClients(clients,{query='',group='',product='',state='',priority='',sort='amount'}={},today=dateToday()){
 const q=fold(query).trim();
 return clients.filter(c=>{
  if(q&&!fold([c.id,c.firstName,c.lastName,c.sourceNote,c.nextAction,c.tradePotential,c.cooperationPotential].join(' ')).includes(q))return false;
  if(group&&c.group!==group)return false;
  if(priority==='trade'&&!c.tradePriority)return false;
  if(priority==='cooperation'&&!c.cooperationPriority)return false;
  if(priority==='due'&&(!c.nextContact||c.nextContact>today))return false;
  const selected=product?[product]:PRODUCTS.map(x=>x[0]);
  if(state==='record'&&!selected.some(k=>isProduct(c.products[k])))return false;
  if(state==='verify'&&!selected.some(k=>!isProduct(c.products[k])))return false;
  if(product&&!state&&!isProduct(c.products[product]))return false;
  return true;
 }).sort((a,b)=>sort==='name'?(a.lastName+' '+a.firstName).localeCompare(b.lastName+' '+b.firstName,'cs'):sort==='group'?GROUPS.indexOf(a.group)-GROUPS.indexOf(b.group)||b.amount-a.amount:sort==='contact'?(a.nextContact||'9999').localeCompare(b.nextContact||'9999')||b.amount-a.amount:b.amount-a.amount);
}
export function statistics(clients,entries=[],today=dateToday()){
 const active=clients.filter(c=>c.group!=='E');
 return {total:clients.length,active:active.length,amount:active.reduce((s,c)=>s+c.amount,0),allAmount:clients.reduce((s,c)=>s+c.amount,0),trade:clients.filter(c=>c.tradePriority).length,cooperation:clients.filter(c=>c.cooperationPriority).length,due:clients.filter(c=>c.nextContact&&c.nextContact<=today).length,entries:entries.length,groups:GROUPS.map(group=>({group,count:clients.filter(c=>c.group===group).length,amount:clients.filter(c=>c.group===group).reduce((s,c)=>s+c.amount,0)})),products:PRODUCTS.map(([key,name])=>({key,name,count:clients.filter(c=>isProduct(c.products[key])).length,verify:clients.filter(c=>!isProduct(c.products[key])).length}))};
}
export function csvCell(v){let s=String(v??'');if(/^[=+@\-\t\r]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"';}
export function toCsv(clients){return '\ufeff'+[['ID','Jméno','Příjmení','Skupina','Hodnota Kč',...PRODUCTS.map(x=>x[1]),'Další krok','Další kontakt'],...clients.map(c=>[c.id,c.firstName,c.lastName,c.group,c.amount,...PRODUCTS.map(([k])=>c.products[k]?.state||'verify'),c.nextAction,c.nextContact])].map(r=>r.map(csvCell).join(';')).join('\r\n');}

export function donutStatistics(clients){
 const products=PRODUCTS.map(([key,name])=>({key,name,count:clients.filter(c=>isProduct(c.products[key])).length}));
 const potential=[
  {key:'trade',name:'Pouze obchodní růst',count:clients.filter(c=>c.tradePriority&&!c.cooperationPriority).length},
  {key:'cooperation',name:'Pouze spolupráce',count:clients.filter(c=>c.cooperationPriority&&!c.tradePriority).length},
  {key:'both',name:'Růst i spolupráce',count:clients.filter(c=>c.tradePriority&&c.cooperationPriority).length},
  {key:'unmarked',name:'Zatím neoznačeno',count:clients.filter(c=>!c.tradePriority&&!c.cooperationPriority).length}
 ];
 return {products,productTotal:products.reduce((n,p)=>n+p.count,0),potential,clientTotal:clients.length};
}
