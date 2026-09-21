'use strict';
const assert = require('assert'), fs = require('fs'), path = require('path'), vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing ' + name);
    return source.slice(source.slice(Math.max(0, start - 6), start) === 'async ' ? start - 6 : start,
        source.indexOf('\n}', start) + 2);
}
const requests = [];
let baseHref = null;
const context = vm.createContext({
    URL, URLSearchParams,
    calendarIPSViewConfig: {endpoint:'/hook/opencalendar/view/12345', token:'test-token'},
    calendarClientContractVersion: 1,
    window:{location:{href:'data:text/html,test'}},
    document:{baseURI:'data:text/html,test', referrer:'http://192.0.2.10:3777/tile/',
        querySelector:()=>baseHref === null ? null : {getAttribute:()=>baseHref}},
    t:value=>value,
    fetch:async (url, options)=>{
        requests.push({url, options});
        return {ok:true,json:async()=>({type:'state'})};
    }
});
Object.defineProperty(context.window, 'parent', {configurable:true, get(){throw Error('Cross-origin');}});
for (const name of ['hasIPSViewActionBridge', 'calendarIPSViewRequest']) vm.runInContext(extract(name), context);
if (source.includes('function calendarIPSViewEndpoint(')) vm.runInContext(extract('calendarIPSViewEndpoint'), context);
(async()=>{
    await context.calendarIPSViewRequest('CreateEvent', {summary:'Test'});
    assert.strictEqual(requests[0].url, 'http://192.0.2.10:3777/hook/opencalendar/view/12345',
        'A tile data: frame must resolve the existing connection without configuration');
    assert.strictEqual(requests[0].options.redirect, 'error', 'Never redirect a request containing the token');
    const body = new URLSearchParams(requests[0].options.body);
    assert.strictEqual(body.get('token'), 'test-token');
    assert.strictEqual(body.get('action'), 'CreateEvent');
    assert.deepStrictEqual(JSON.parse(body.get('value')), {summary:'Test'});
    context.window.location.href = 'about:blank';
    context.document.baseURI = 'about:blank';
    baseHref = 'http://192.0.2.20:3777/';
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'http://192.0.2.20:3777/hook/opencalendar/view/12345',
        'Windows IPSView must prefer its injected base over a referrer');
    baseHref = 'https://test.ipmagic.de/';
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'https://test.ipmagic.de/hook/opencalendar/view/12345');
    baseHref = 'http://user:password@192.0.2.20:3777/path?x=1#fragment';
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'http://192.0.2.20:3777/hook/opencalendar/view/12345',
        'Do not propagate client login details');
    baseHref = null;
    context.document.baseURI = 'https://symcon.example/tile/';
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'https://symcon.example/hook/opencalendar/view/12345');
    context.document.baseURI = 'about:srcdoc';
    context.document.referrer = '';
    Object.defineProperty(context.window, 'parent', {configurable:true,value:{location:{href:'http://192.0.2.30:3777/tile/'}}});
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'http://192.0.2.30:3777/hook/opencalendar/view/12345');
    context.window.location.href = 'https://direct.example/view';
    await context.calendarIPSViewRequest('GetState', null);
    assert.strictEqual(requests.at(-1).url, 'https://direct.example/hook/opencalendar/view/12345');
    Object.defineProperty(context.window, 'parent', {get(){throw Error('Cross-origin');}});
    context.window.location.href = 'file:///view.html';
    const count = requests.length;
    for (const invalid of ['', 'javascript:alert(1)', 'ftp://example.com', '//example.com', 'https://[']) {
        baseHref = invalid;
        context.document.referrer = invalid;
        await assert.rejects(context.calendarIPSViewRequest('CreateEvent', {}), /connection address is unavailable/);
    }
    baseHref = 'https://symcon.example';
    context.calendarIPSViewConfig.endpoint = '//untrusted.example/hook';
    await assert.rejects(context.calendarIPSViewRequest('CreateEvent', {}), /Action failed/);
    assert.strictEqual(requests.length, count, 'Unavailable or invalid destinations must not send credentials');
    context.calendarIPSViewConfig.endpoint = '/hook/opencalendar/view/12345';
    context.fetch = async()=>({ok:false,json:async()=>({Error:'Unauthorized.'})});
    await assert.rejects(context.calendarIPSViewRequest('CreateEvent', {}), /Unauthorized/);
    console.log('Automatic IPSView endpoint tests passed (tile, Windows, Connect, srcdoc, direct HTTP and failure cases).');
})().catch(error=>{console.error(error);process.exitCode=1;});

