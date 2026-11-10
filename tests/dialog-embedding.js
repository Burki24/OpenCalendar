'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    return source.slice(start, source.indexOf('\n}', start) + 2);
}
const host = {innerWidth:1000, innerHeight:700};
const context = vm.createContext({
    calendarVisualization:{mode:'ipsview'},
    window:{parent:host, innerWidth:1000, innerHeight:1200,
        frameElement:{getBoundingClientRect:()=>({left:0,top:100,right:1000,bottom:1300,width:1000,height:1200})}}
});
vm.runInContext(extract('eventDialogHostViewport'), context);
assert.strictEqual(context.eventDialogHostViewport()?.bottom, 600, 'Embedded IPSView must respect host clipping');
context.window.parent = context.window;
assert.strictEqual(context.eventDialogHostViewport(), null, 'Standalone fallback');
context.window.parent = host;
Object.defineProperty(context.window, 'frameElement', {get(){throw Error('Cross-origin');}});
assert.strictEqual(context.eventDialogHostViewport(), null, 'Frame access must be guarded');
let errorNote;
const toastContext = vm.createContext({
    eventDialog:{open:true,querySelector:()=>errorNote,append(node){errorNote=node;}},
    document:{createElement:()=>({setAttribute(){},scrollIntoView(){}})},
    toastTimer:null,clearTimeout(){},setTimeout(){}
});
vm.runInContext(extract('showToast'), toastContext);
toastContext.showToast('Request failed', 'error');
assert.strictEqual(errorNote?.textContent, 'Request failed', 'Save errors must be inside the modal top layer');
console.log('Embedded dialog bounds and modal error visibility passed.');
