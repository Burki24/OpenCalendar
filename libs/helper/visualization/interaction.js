(function (global) {
    'use strict';
    const channel = 'burki24.wheel.v1';
    const finite = value => typeof value === 'number' && Number.isFinite(value);

    function createRouter(environment) {
        const document = environment.document || {};
        const regions = new Map();
        const children = new Set();
        const redirected = new WeakSet();
        const listeners = [];
        let pointer = null;
        function listen(target, name, callback, options) {
            if (!target || typeof target.addEventListener !== 'function') return;
            target.addEventListener(name, callback, options);
            listeners.push(() => target.removeEventListener(name, callback, options));
        }
        function clearPointer() { pointer = null; }
        function blur() {
            const active = document.activeElement;
            // Focusing our child iframe is not leaving this visualization.
            if (active && active.tagName === 'IFRAME' && children.has(active.contentWindow)
                && typeof document.hasFocus === 'function' && document.hasFocus()) return;
            clearPointer();
        }
        function sendParent(type, x, y) {
            try {
                if (environment.parent && environment.parent !== environment) environment.parent.postMessage([channel, type, x, y], '*');
            } catch (_) { /* A host without a frame bridge keeps local interaction. */ }
        }
        function rememberPointer(event) {
            if (event.pointerType && event.pointerType !== 'mouse') { clearPointer(); return; }
            if (!finite(event.clientX) || !finite(event.clientY)) return;
            pointer = {clientX: event.clientX, clientY: event.clientY};
            if (environment.innerWidth > 0 && environment.innerHeight > 0) {
                sendParent('pointer', pointer.clientX / environment.innerWidth, pointer.clientY / environment.innerHeight);
            }
        }
        function point(event) {
            if (event.clientX === 0 && event.clientY === 0 && pointer) return {...pointer};
            return {clientX: event.clientX, clientY: event.clientY};
        }
        function delta(event, target) {
            let x = event.deltaX, y = event.deltaY;
            if (!finite(x)) x = finite(event.wheelDeltaX) ? -event.wheelDeltaX / 3 : 0;
            if (!finite(y)) y = finite(event.wheelDeltaY) ? -event.wheelDeltaY / 3 : (finite(event.wheelDelta) ? -event.wheelDelta / 3 : 0);
            const line = event.deltaMode === 1 ? 16 : 1;
            x *= event.deltaMode === 2 ? (target.clientWidth || environment.innerWidth || 1) : line;
            y *= event.deltaMode === 2 ? (target.clientHeight || environment.innerHeight || 1) : line;
            if (event.shiftKey && x === 0) { x = y; y = 0; }
            return {deltaX: Math.max(-100000, Math.min(100000, x)), deltaY: Math.max(-100000, Math.min(100000, y))};
        }
        function consume(event) {
            if (event.preventDefault) event.preventDefault();
            if (event.stopImmediatePropagation) event.stopImmediatePropagation();
            else if (event.stopPropagation) event.stopPropagation();
        }
        function scroll(event, target) {
            if (!target || event.ctrlKey || event.metaKey) return false;
            const movement = delta(event, target);
            if (!movement.deltaX && !movement.deltaY) return false;
            target.scrollLeft += movement.deltaX;
            target.scrollTop += movement.deltaY;
            consume(event);
            return true;
        }
        function scrollable(target) {
            if (!target || !target.tagName || typeof environment.getComputedStyle !== 'function') return false;
            const style = environment.getComputedStyle(target);
            return ((target.scrollHeight > target.clientHeight) && /auto|scroll|overlay/.test(style.overflowY))
                || ((target.scrollWidth > target.clientWidth) && /auto|scroll|overlay/.test(style.overflowX));
        }
        function resolve(event) {
            if (event.clientX === 0 && event.clientY === 0 && pointer && typeof document.elementFromPoint === 'function') {
                const hit = document.elementFromPoint(pointer.clientX, pointer.clientY);
                if (hit) return hit;
            }
            const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
            path.push(event.target);
            const direct = path.find(node => node && node.tagName && !/^(HTML|BODY|DIALOG)$/.test(node.tagName));
            if (direct) return direct;
            const position = point(event);
            if (finite(position.clientX) && finite(position.clientY) && typeof document.elementFromPoint === 'function') {
                return document.elementFromPoint(position.clientX, position.clientY);
            }
            return event.target;
        }
        function handle(event) {
            if (!event || event.ctrlKey || event.metaKey || event.defaultPrevented || redirected.has(event)) return false;
            let target = resolve(event);
            const hit = target;
            while (target && target !== document) {
                if (target.tagName === 'IFRAME' && children.has(target.contentWindow)) {
                    const p = point(event), box = target.getBoundingClientRect();
                    if (finite(p.clientX) && finite(p.clientY) && box.width > 0 && box.height > 0) {
                        if (p.clientX < box.left || p.clientX > box.left + box.width || p.clientY < box.top || p.clientY > box.top + box.height) return false;
                        const d = delta(event, target);
                        target.contentWindow.postMessage([channel, 'wheel', (p.clientX - box.left) / box.width,
                            (p.clientY - box.top) / box.height, d.deltaX, d.deltaY], '*');
                        consume(event);
                        return true;
                    }
                }
                if (scrollable(target)) return scroll(event, target);
                const handler = regions.get(target);
                if (handler) {
                    const position = point(event), movement = delta(event, target);
                    let prevented = false;
                    const normalized = {...position, ...movement, deltaMode: 0,
                        preventDefault() { prevented = true; }, stopPropagation() {}};
                    if (handler(normalized) === true || prevented) { consume(event); return true; }
                }
                target = target.parentElement;
            }
            // Let existing chart/component handlers receive corrected native input.
            if (hit && hit !== event.target && typeof hit.dispatchEvent === 'function' && typeof environment.WheelEvent === 'function') {
                const movement = delta(event, hit), position = point(event);
                if (movement.deltaX || movement.deltaY) {
                    const copy = new environment.WheelEvent('wheel', {...movement, ...position, bubbles: true, cancelable: true});
                    redirected.add(copy);
                    hit.dispatchEvent(copy);
                    if (copy.defaultPrevented) { consume(event); return true; }
                }
            }
            return false;
        }
        function receive(event) {
            const data = event.data;
            if (!Array.isArray(data) || data[0] !== channel) return;
            const [, type, x, y, deltaX, deltaY] = data;
            // Only the actual direct parent or an opted-in direct child may send UI input.
            if (environment.parent && event.source === environment.parent && environment.parent !== environment) {
                if (type !== 'wheel' || !finite(x) || !finite(y) || x < 0 || x > 1 || y < 0 || y > 1
                    || !finite(deltaX) || !finite(deltaY) || Math.abs(deltaX) > 100000 || Math.abs(deltaY) > 100000) return;
                handle({type: 'wheel', clientX: x * environment.innerWidth, clientY: y * environment.innerHeight,
                    deltaX: deltaX, deltaY: deltaY, deltaMode: 0});
                return;
            }
            const frame = typeof document.querySelectorAll === 'function'
                ? Array.from(document.querySelectorAll('iframe')).find(item => item.contentWindow === event.source) : null;
            if (!frame) return;
            if (type === 'ready') children.add(event.source);
            if (type === 'pointer' && children.has(event.source) && finite(x) && finite(y) && x >= 0 && x <= 1 && y >= 0 && y <= 1) {
                const box = frame.getBoundingClientRect();
                rememberPointer({clientX: box.left + x * box.width, clientY: box.top + y * box.height});
            }
        }
        listen(document, 'wheel', handle, {capture: true, passive: false});
        listen(document, 'pointermove', rememberPointer, {capture: true, passive: true});
        listen(document, 'mousemove', rememberPointer, {capture: true, passive: true});
        listen(document.documentElement, 'pointerleave', clearPointer);
        listen(environment, 'blur', blur);
        listen(environment, 'message', receive);
        sendParent('ready');
        return {point, delta, scroll, handle, rememberPointer, clearPointer,
            registerWheelRegion(element, handler) { regions.set(element, handler); return () => regions.delete(element); },
            dispose() { listeners.forEach(remove => remove()); regions.clear(); children.clear(); clearPointer(); }};
    }
    const api = {createRouter};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (global.document && !global.SymconVisualizationInteraction) global.SymconVisualizationInteraction = createRouter(global);
}(typeof window === 'undefined' ? globalThis : window));
