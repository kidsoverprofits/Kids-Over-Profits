/**
 * "Available Resources & Documentation" boxes filled from the media library.
 *
 * kop/v1/library-resources (kop_dt_facility_resources() in inc/doc-types.php)
 * says, per facility, how many public documents of each type are filed on its
 * page: a student handbook ticks Student or Resident Manual, an inspection
 * report ticks Inspection Reports, and so on (kop_dt_resource_flags()). Those
 * boxes show ticked and locked with "N in the library"; the stored flag is left
 * as it is, so a box the library proves never needs ticking by hand and one it
 * does not prove still works as before.
 */
(function() {
    if (window.KOP_LibraryResources) return;

    const PREFIX = 'resources.';
    let byFacility = null;

    function endpoint() {
        const cfg = window.KOP_DATA_FORM_CONFIG || window.dataFormConfig || {};
        const root = (cfg.api && cfg.api.root) || '/wp-json/kop/v1/';
        return root.replace(/\/?$/, '/') + 'library-resources';
    }

    function currentCounts() {
        if (!byFacility || !window.formData || !Array.isArray(window.formData.facilities)) return {};
        const facility = window.formData.facilities[window.currentFacilityIndex || 0];
        const id = facility && facility.facility_id;
        return (id && byFacility[id]) || {};
    }

    function apply() {
        const counts = currentCounts();
        document.querySelectorAll('.facility-checkbox[data-field^="' + PREFIX + 'has"]').forEach(box => {
            const flag = box.dataset.field.slice(PREFIX.length);
            const n = counts[flag] || 0;
            const group = box.closest('.checkbox-group') || box.parentNode;
            let badge = group.querySelector('.kop-library-count');
            if (box.dataset.kopLibrary) {
                // Undo the last facility's lock before looking at this one.
                box.disabled = box.dataset.kopLibrary === 'was-disabled';
                box.removeAttribute('title');
                delete box.dataset.kopLibrary;
            }
            if (n <= 0) {
                if (badge) badge.remove();
                return;
            }
            box.dataset.kopLibrary = box.disabled ? 'was-disabled' : 'locked';
            box.checked = true;
            box.disabled = true;
            box.title = 'Ticked because the media library holds ' + n + (n === 1 ? ' document' : ' documents') + ' of this kind for this facility';
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'kop-library-count';
                badge.style.cssText = 'margin-left:6px;font-size:12px;color:var(--kop-teal-ink);';
                group.appendChild(badge);
            }
            badge.textContent = n + ' in the library';
        });
    }

    function load() {
        if (byFacility) return Promise.resolve();
        return fetch(endpoint(), { credentials: 'same-origin' })
            .then(r => (r.ok ? r.json() : null))
            .then(data => { byFacility = (data && data.facilities) || {}; })
            .catch(e => { byFacility = {}; console.error('Library resources failed to load:', e); });
    }

    function init() {
        // Re-apply whenever the form re-renders: updateAllUI on project loads,
        // loadFacilityData when moving between facilities.
        ['updateAllUI', 'loadFacilityData'].forEach(name => {
            const original = window[name];
            if (typeof original !== 'function' || original.__kopLibrary) return;
            const wrapped = function() {
                const result = original.apply(this, arguments);
                try { apply(); } catch (e) { console.error('Library resources failed:', e); }
                return result;
            };
            wrapped.__kopLibrary = true;
            window[name] = wrapped;
        });
        load().then(apply);
    }

    window.KOP_LibraryResources = { apply, load, init };

    if (!document.getElementById('resources-section')) return;
    if (window.formReady) init();
    else document.addEventListener('formReady', init, { once: true });
})();
