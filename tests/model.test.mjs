import test from 'node:test';
import assert from 'node:assert/strict';
import {filterClients,statistics,toCsv} from '../assets/model.js';
import {demoClients} from '../assets/demo.js';
test('accent-insensitive search and ID search',()=>{assert.equal(filterClients(demoClients,{query:'prochazkova'}).length,1);assert.equal(filterClients(demoClients,{query:'demo-18'}).length,1);assert.equal(filterClients(demoClients,{query:'not-present'}).length,0);});
test('product icon and phone filters are complementary',()=>{const a=filterClients(demoClients,{product:'insurance',state:'record'}),b=filterClients(demoClients,{product:'insurance',state:'verify'});assert.equal(a.length+b.length,demoClients.length);assert.equal(new Set([...a,...b].map(c=>c.id)).size,demoClients.length);});
test('statistics reconcile groups and six products',()=>{const s=statistics(demoClients);assert.equal(s.groups.reduce((n,g)=>n+g.count,0),s.total);assert.equal(s.products.length,6);for(const p of s.products)assert.equal(p.count+p.verify,s.total);assert.equal(s.trade,3);assert.equal(s.cooperation,2);});
test('due dates use local date and ignore empty next contacts',()=>{const a=[{...demoClients[0],nextContact:'2026-11-04'},{...demoClients[1],nextContact:'2026-11-06'},{...demoClients[2],nextContact:''}];assert.equal(filterClients(a,{priority:'due'},'2026-11-04').length,1);});
test('CSV neutralizes formula injection and escapes quotes',()=>{const s=toCsv([{...demoClients[0],firstName:'=HYPERLINK("x")'}]);assert.ok(s.includes("'=HYPERLINK"));assert.ok(s.includes('""x""'));});

test('five groups reconcile and E represents unassigned clients',()=>{
 const clients=[{...demoClients[0],group:'A',amount:100},{...demoClients[1],group:'E',amount:20}];
 const s=statistics(clients);assert.equal(s.groups.length,5);assert.equal(s.groups.find(g=>g.group==='E').count,1);assert.equal(s.active,1);assert.equal(s.amount,100);assert.equal(s.allAmount,120);
});
