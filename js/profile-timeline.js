/**
 * Year-by-year timelines on company and facility pages (inc/profile-timeline.php).
 *
 * - "More about this" opens the event's card in place (phones, keyboards, a click).
 * - With a mouse, pointing at an event shows the same card beside it after a
 *   moment; it stays while the pointer is on the event or the card.
 * - The filter buttons show one kind of event; the strip of years jumps to a year.
 * - Long timelines show their first years and a button for the rest.
 * Without this script every card is printed open under its event.
 */
(function () {
    'use strict';

    var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var pop = null;
    var popFor = null;
    var showTimer = 0;
    var hideTimer = 0;

    function ensurePop() {
        if (pop) return pop;
        pop = document.createElement('div');
        pop.className = 'kop-ptl-pop';
        pop.hidden = true;
        pop.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
        pop.addEventListener('mouseleave', function () { scheduleHide(); });
        document.body.appendChild(pop);
        return pop;
    }

    function hidePop() {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        if (!pop || pop.hidden) return;
        pop.classList.remove('is-shown');
        pop.hidden = true;
        popFor = null;
    }

    function scheduleHide() {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hidePop, 220);
    }

    function showPop(ev) {
        if (ev.classList.contains('is-open')) return;
        var card = ev.querySelector('.kop-ptl-card');
        if (!card) return;
        var p = ensurePop();
        clearTimeout(hideTimer);
        if (popFor !== ev) {
            p.innerHTML = card.innerHTML;
            p.style.setProperty('--ptl-c', getComputedStyle(ev).getPropertyValue('--ptl-c'));
            popFor = ev;
        }
        p.hidden = false;
        place(p, ev);
        requestAnimationFrame(function () { p.classList.add('is-shown'); });
    }

    // Beside the event when the window has room on the right, else under it.
    function place(p, ev) {
        var r = ev.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        var w = p.offsetWidth;
        var h = p.offsetHeight;
        var left;
        var top;
        if (vw - r.right >= w + 24) {
            left = r.right + 12;
            top = Math.min(Math.max(r.top, 12), Math.max(12, vh - h - 12));
        } else {
            left = Math.min(Math.max(12, r.left + 24), vw - w - 12);
            top = r.bottom + 8;
            if (top + h > vh - 12 && r.top - h - 8 > 12) top = r.top - h - 8;
        }
        p.style.left = (left + window.pageXOffset) + 'px';
        p.style.top = (top + window.pageYOffset) + 'px';
    }

    function init(root) {
        if (root.classList.contains('is-js')) return;
        root.classList.add('is-js');

        var later = root.querySelectorAll('.kop-ptl-later');
        var allBtn = root.querySelector('.kop-ptl-all');
        function showAll() {
            for (var i = 0; i < later.length; i++) later[i].classList.remove('kop-ptl-later');
            if (allBtn) allBtn.hidden = true;
        }
        if (allBtn && later.length) {
            allBtn.hidden = false;
            allBtn.addEventListener('click', showAll);
        }

        // Filters.
        var filters = root.querySelectorAll('.kop-ptl-filter');
        Array.prototype.forEach.call(filters, function (btn) {
            btn.addEventListener('click', function () {
                var g = btn.getAttribute('data-group');
                Array.prototype.forEach.call(filters, function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-on', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-ev'), function (ev) {
                    ev.hidden = g !== 'all' && ev.getAttribute('data-group') !== g;
                });
                Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-year'), function (y) {
                    y.classList.toggle('is-empty', !y.querySelector('.kop-ptl-ev:not([hidden])'));
                });
                Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-tick'), function (t) {
                    var gs = (t.getAttribute('data-groups') || '').split(' ');
                    t.classList.toggle('is-dim', g !== 'all' && gs.indexOf(g) < 0);
                });
                // One kind is short enough to show whole.
                if (g !== 'all') showAll();
                hidePop();
            });
        });

        // The strip: a year further down may be one of the later ones.
        Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-tick'), function (t) {
            t.addEventListener('click', function () {
                var target = document.getElementById((t.getAttribute('href') || '').slice(1));
                if (target && target.classList.contains('kop-ptl-later')) showAll();
            });
        });

        // "More about this": the card in place.
        Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-more'), function (btn) {
            btn.addEventListener('click', function () {
                var ev = btn.closest('.kop-ptl-ev');
                var open = !ev.classList.contains('is-open');
                ev.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                var label = btn.querySelector('span');
                if (label) label.textContent = open ? 'Less' : 'More about this';
                hidePop();
            });
        });

        // Pointing at an event with a mouse shows its card beside it.
        if (canHover) {
            Array.prototype.forEach.call(root.querySelectorAll('.kop-ptl-ev.has-card'), function (ev) {
                ev.addEventListener('mouseenter', function () {
                    clearTimeout(hideTimer);
                    clearTimeout(showTimer);
                    showTimer = setTimeout(function () { showPop(ev); }, popFor ? 60 : 280);
                });
                ev.addEventListener('mouseleave', scheduleHide);
            });
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hidePop();
    });
    window.addEventListener('scroll', function () { if (popFor && pop && !pop.matches(':hover')) hidePop(); }, { passive: true });
    window.addEventListener('resize', hidePop);

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kop-ptl]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
