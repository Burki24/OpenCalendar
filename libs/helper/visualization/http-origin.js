(function (global) {
    'use strict';

    function httpOrigin(value) {
        try {
            const url = new URL(value);
            return /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.origin : null;
        } catch (_) { return null; }
    }

    function resolve(environment) {
        const location = environment.location || {};
        let origin = httpOrigin(location.origin) || httpOrigin(location.href);
        if (origin) return origin;
        // Accessible parents also cover about:blank/srcdoc documents.
        try {
            const parent = environment.parent;
            if (parent && parent !== environment) {
                origin = httpOrigin(parent.location.href);
                if (origin) return origin;
            }
        } catch (_) { /* Sandboxed/cross-origin parent: use browser-provided metadata. */ }
        const ancestors = location.ancestorOrigins || [];
        for (let index = 0; index < ancestors.length; index++) {
            origin = httpOrigin(ancestors[index]);
            if (origin) return origin;
        }
        return httpOrigin((environment.document || {}).referrer);
    }

    const api = {resolve, httpOrigin};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    global.SymconHTTPOrigin = api;
}(typeof window === 'undefined' ? globalThis : window));
