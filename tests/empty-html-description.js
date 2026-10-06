'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');

function extract(name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start < 0) return '';
    const end = source.indexOf('\n    }\n', start);
    assert(end > start, `${name} must have a complete function body`);
    return source.slice(start, end + 6);
}

const context = vm.createContext({
    detailsTarget: null,
    htmlDescriptionSource: value => /<\/?(?:html|body|div|p|img|hr)\b/i.test(value) ? value : '',
    htmlDescriptionToPlainText: value => value
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
        .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;|&#160;/gi, ' ')
        .trim(),
    sanitizeImageSource: value => /^data:image\/(?:png|gif|jpe?g|webp);base64,/i.test(value) ? value : '',
    DOMParser: class {
        parseFromString(value) {
            const images = [...value.matchAll(/<img\b[^>]*\bsrc=["']([^"']+)["'][^>]*>/gi)]
                .map(match => ({getAttribute: () => match[1]}));
            return {
                body: {
                    querySelectorAll: () => images,
                    querySelector: selector => selector === 'hr' && /<hr\b/i.test(value) ? {} : null
                }
            };
        }
    },
    renderHtmlDescription: () => { context.rendered = true; }
});

vm.runInContext(
    [extract('htmlDescriptionHasDisplayableContent'), extract('updateDescriptionRendering')].filter(Boolean).join('\n'),
    context
);

function inspect(description) {
    const hidden = new Set();
    context.detailsTarget = {
        textContent: description,
        querySelector: () => null,
        parentElement: {classList: {add: name => hidden.add(name)}}
    };
    context.rendered = false;
    context.updateDescriptionRendering();
    return {
        hidden: hidden.has('hidden'),
        text: context.detailsTarget.textContent,
        rendered: context.rendered
    };
}

for (const description of [
    '<html><head></head><body></body></html>',
    '<html><body><div><br>&nbsp;</div></body></html>',
    '<html><body><script>ignored()</script></body></html>',
    '<html><body><img src="https://example.com/blocked.png"></body></html>'
]) {
    assert.deepStrictEqual(
        inspect(description),
        {hidden: true, text: '', rendered: false},
        'An HTML wrapper without displayable description content must not create a blank detail frame.'
    );
}

assert.deepStrictEqual(
    inspect('<html><body><p>Meeting notes</p></body></html>'),
    {hidden: false, text: '<html><body><p>Meeting notes</p></body></html>', rendered: true},
    'A real HTML description must still be rendered.'
);
assert.strictEqual(
    inspect('<html><body><img src="data:image/png;base64,AAAA"></body></html>').rendered,
    true,
    'A permitted image-only description must remain visible.'
);

console.log('Empty HTML description detail rendering passed.');
