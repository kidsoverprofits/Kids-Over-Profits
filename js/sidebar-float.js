/**
 * Desktop: the site sidebar (newsletter, donate) floats in the top corner of
 * the content panel instead of holding a grid column of its own, so the page
 * runs full width once the sidebar ends. Kadence's grid kept every page in a
 * narrow column beside a sidebar a few hundred pixels tall.
 *
 * The sidebar moves into the panel (the page's outer box: .kop-home, the
 * entry, or .content-wrap) and floats there. Plain text beside it wraps under
 * it; boxed sections (a background or border), image and map wrappers get
 * display: flow-root (.kop-beside-float) so they sit beside it rather than
 * running their boxes under it. Grid and flex blocks avoid the float already.
 * Below 1025px (Kadence's own breakpoint) the sidebar goes back after the
 * content, where Kadence puts it on phones.
 */
(function () {
    'use strict';

    var side = document.getElementById('secondary');
    var main = document.getElementById('main');
    var body = document.body;
    if (!side || !main || !window.matchMedia) return;
    var sideClass = body.classList.contains('has-left-sidebar') ? 'left'
        : (body.classList.contains('has-right-sidebar') ? 'right' : '');
    if (!sideClass) return;

    var home = side.parentNode;
    var homeNext = side.nextSibling;
    var mq = window.matchMedia('(min-width: 1025px)');
    var marked = [];

    var TEXT = /^(P|H[1-6]|UL|OL|DL|BLOCKQUOTE|PRE|HR|ADDRESS|BR)$/;
    var MEDIA = /^(IMG|PICTURE|IFRAME|VIDEO|CANVAS|SVG|svg|TABLE|FIGURE|OBJECT|EMBED)$/;

    function visible(el) {
        return el !== side && el.nodeType === 1 && (el.offsetHeight > 0 || el.offsetWidth > 0);
    }

    function boxed(cs) {
        return cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent'
            || cs.backgroundImage !== 'none'
            || parseFloat(cs.borderTopWidth) > 0 || parseFloat(cs.borderLeftWidth) > 0
            || parseFloat(cs.borderRightWidth) > 0 || parseFloat(cs.borderBottomWidth) > 0
            || cs.boxShadow !== 'none';
    }

    // The box holding the page's sections: down through lone wrappers (boxed
    // or not) to the first one with several children. A page that is one
    // tall box (the state report pages) gets the sidebar inside that box.
    function findPanel() {
        var el = main.querySelector(':scope > .content-wrap') || main;
        for (var i = 0; i < 8; i++) {
            var kids = Array.prototype.filter.call(el.children, visible);
            if (kids.length !== 1) break;
            var cs = getComputedStyle(kids[0]);
            if (cs.display !== 'block' || cs.overflow !== 'visible') break;
            el = kids[0];
        }
        return el;
    }

    function hasMediaChild(el) {
        return Array.prototype.some.call(el.children, function (c) {
            return MEDIA.test(c.tagName) && visible(c);
        });
    }

    function mark(el) {
        el.classList.add('kop-beside-float');
        marked.push(el);
    }

    function markBoxes(node, depth) {
        Array.prototype.forEach.call(node.children, function (c) {
            if (!visible(c)) return;
            var cs = getComputedStyle(c);
            if (cs.display !== 'block' || cs.float !== 'none' || cs.position === 'absolute' || cs.position === 'fixed') return;
            if (cs.overflow !== 'visible') return; // already beside the float
            if (TEXT.test(c.tagName)) {
                if (boxed(cs)) mark(c);
                return;
            }
            if (MEDIA.test(c.tagName) || boxed(cs) || hasMediaChild(c) || depth >= 3) {
                mark(c);
                return;
            }
            markBoxes(c, depth + 1);
        });
    }

    // A panel narrower than the sidebar plus this much text (the state report
    // pages' fixed-width box) keeps Kadence's column instead: text squeezed
    // into a strip beside the float reads worse than the grid.
    var MIN_TEXT = 440;

    function unfloat() {
        body.classList.remove('kop-sidebar-float', 'kop-sidebar-float-' + sideClass);
        var p = document.querySelector('.kop-sidebar-panel');
        if (p) p.classList.remove('kop-sidebar-panel');
        marked.forEach(function (el) { el.classList.remove('kop-beside-float'); });
        marked = [];
        home.insertBefore(side, homeNext && homeNext.parentNode === home ? homeNext : null);
    }

    function place() {
        if (mq.matches) {
            if (body.classList.contains('kop-sidebar-float')) return;
            var panel = findPanel();
            body.classList.add('kop-sidebar-float', 'kop-sidebar-float-' + sideClass);
            panel.classList.add('kop-sidebar-panel');
            panel.insertBefore(side, panel.firstChild);
            var ps = getComputedStyle(panel);
            var inner = panel.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight);
            if (inner - side.offsetWidth < MIN_TEXT) {
                unfloat();
                return;
            }
            markBoxes(panel, 0);
        } else if (body.classList.contains('kop-sidebar-float')) {
            unfloat();
        }
    }

    place();
    if (mq.addEventListener) mq.addEventListener('change', place);
    else if (mq.addListener) mq.addListener(place);
})();
