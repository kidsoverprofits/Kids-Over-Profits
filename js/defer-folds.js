/**
 * Closed folds sent with their contents in <template data-kop-defer>
 * (inc/defer-folds.php): the contents are drawn when the fold opens, when the
 * address points at something inside one, and before printing. Each fold
 * drawn fires 'kop:stamped' (bubbles) so scripts that set up their pieces once
 * can set up the new ones. window.KOPDeferFolds.stampAll(root) draws every fold
 * under root (the document type filter uses it before showing one type).
 */
(function () {
    'use strict';

    function stamp(fold) {
        var tpl = null;
        for (var c = fold.firstElementChild; c; c = c.nextElementSibling) {
            if (c.tagName === 'TEMPLATE' && c.hasAttribute('data-kop-defer')) { tpl = c; break; }
        }
        if (!tpl) return false;
        tpl.parentNode.replaceChild(document.importNode(tpl.content, true), tpl);
        var ev;
        try {
            ev = new CustomEvent('kop:stamped', { bubbles: true });
        } catch (e) {
            ev = document.createEvent('CustomEvent');
            ev.initCustomEvent('kop:stamped', true, false, null);
        }
        fold.dispatchEvent(ev);
        return true;
    }

    function stampAll(root) {
        var list = (root || document).querySelectorAll('template[data-kop-defer]');
        Array.prototype.forEach.call(list, function (tpl) {
            if (tpl.parentNode && tpl.parentNode.tagName === 'DETAILS') stamp(tpl.parentNode);
        });
    }

    // 'toggle' does not bubble; caught on the way down.
    document.addEventListener('toggle', function (e) {
        var fold = e.target;
        if (fold && fold.tagName === 'DETAILS' && fold.open) stamp(fold);
    }, true);

    // A link or an address pointing inside a closed fold: open it and go there.
    function reveal() {
        var id;
        try {
            id = decodeURIComponent((location.hash || '').slice(1));
        } catch (e) {
            return;
        }
        if (!id || document.getElementById(id)) return;
        var list = document.querySelectorAll('template[data-kop-defer]');
        for (var i = 0; i < list.length; i++) {
            if (!list[i].content.getElementById(id)) continue;
            var fold = list[i].parentNode;
            stamp(fold);
            fold.open = true;
            var el = document.getElementById(id);
            if (el && el.scrollIntoView) el.scrollIntoView();
            return;
        }
    }
    window.addEventListener('hashchange', reveal);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', reveal);
    else reveal();

    window.addEventListener('beforeprint', function () { stampAll(document); });

    window.KOPDeferFolds = { stamp: stamp, stampAll: stampAll };
})();
