(function() {
    if (window.KOP_FacilityFields) {
        return; // Already loaded
    }

    /**
     * One facility field by its v2 path (docs/FACILITY-SCHEMA.md), on a facility
     * of either shape: a v2 document, or the legacy shape the form edited before
     * the move to v2 (and the server still sends until data model phase 5).
     *
     *   KOP_FacilityFields.read(facility, 'location.city')
     *   KOP_FacilityFields.write(facility, 'identification.otherOperators', ['X'])
     *
     * A legacy facility is read without the full normalizer: the fields as the
     * form keeps them (KOP_DataNormalizer.facilityToV2() stays the authority on
     * save). Writes go to the field each shape keeps the value in.
     */

    const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
    const str = (v) => (v === null || v === undefined ? '' : String(v).trim());

    // v2 path -> the legacy path the form keeps it in, where they differ.
    const V2_TO_LEGACY = {
        'identification.otherOperators': 'otherOperators',
        'identification.pastOperators': 'pastOperators',
        'identification.investors': 'investors',
        'location.raw': 'address',
        'location.text': 'location',
        'location.street': 'addressParts.street',
        'location.city': 'locationDetails.city',
        'location.state': 'locationDetails.state',
        'location.zip': 'locationDetails.zip',
        'location.country': 'locationDetails.country',
        'location.additionalLocations': 'locationDetails.additionalLocations',
        'location.formerLocations': 'locationDetails.formerLocations',
        'facilityDetails.isPrivatelyOwned': 'isPrivatelyOwned'
    };
    const LEGACY_TO_V2 = {};
    Object.keys(V2_TO_LEGACY).forEach((k) => { LEGACY_TO_V2[V2_TO_LEGACY[k]] = k; });
    // Other legacy spellings of the same fields.
    Object.assign(LEGACY_TO_V2, {
        'addressParts.city': 'location.city',
        'addressParts.state': 'location.state',
        'addressParts.zip': 'location.zip',
        'identification.currentOwner': 'identification.currentOwners'
    });

    function isV2(facility) {
        const N = window.KOP_DataNormalizer;
        if (N && typeof N.isV2Facility === 'function') return N.isV2Facility(facility);
        return isPlainObject(facility) && facility.schema_version !== undefined && Number(facility.schema_version) >= 2;
    }

    function getPath(obj, path) {
        let node = obj;
        for (const key of path.split('.')) {
            if (!isPlainObject(node) && !Array.isArray(node)) return undefined;
            node = node[key];
        }
        return node;
    }

    function setPath(obj, path, value) {
        const keys = path.split('.');
        let node = obj;
        keys.slice(0, -1).forEach((key) => {
            if (!isPlainObject(node[key])) node[key] = {};
            node = node[key];
        });
        node[keys[keys.length - 1]] = value;
    }

    function stateCode(value) {
        const N = window.KOP_DataNormalizer;
        const s = str(value);
        if (s === '') return null;
        return N && typeof N.facilityStateCode === 'function' ? N.facilityStateCode(s) : s.toUpperCase();
    }

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    // Reads of a legacy facility that are not a plain path.
    const LEGACY_READERS = {
        'identification.currentOwners': (f) => {
            const ident = isPlainObject(f.identification) ? f.identification : {};
            const owners = list(ident.currentOwners).slice();
            const single = str(ident.currentOwner);
            if (single !== '' && !owners.includes(single)) owners.push(single);
            return owners;
        },
        'identification.otherOperators': (f) => list(f.otherOperators !== undefined ? f.otherOperators : (f.identification || {}).otherOperators),
        'identification.pastOperators': (f) => list(f.pastOperators !== undefined ? f.pastOperators : (f.identification || {}).pastOperators),
        'identification.investors': (f) => list(f.investors !== undefined ? f.investors : (f.identification || {}).investors),
        'location.raw': (f) => (typeof f.address === 'string' ? f.address.trim() : ''),
        'location.text': (f) => (typeof f.location === 'string' ? f.location.trim() : ''),
        'location.street': (f) => str((f.addressParts || {}).street),
        'location.city': (f) => str((f.addressParts || {}).city) || str((f.locationDetails || {}).city),
        'location.state': (f) => stateCode((f.addressParts || {}).state) || stateCode((f.locationDetails || {}).state),
        'location.zip': (f) => str((f.addressParts || {}).zip) || str((f.locationDetails || {}).zip),
        'location.country': (f) => str((f.locationDetails || {}).country) || null,
        'location.additionalLocations': (f) => list((f.locationDetails || {}).additionalLocations).map((alt) => (
            isPlainObject(alt)
                ? { raw: str(alt.raw !== undefined ? alt.raw : alt.address), city: str(alt.city), state: stateCode(alt.state), zip: str(alt.zip), country: str(alt.country) || null }
                : { raw: str(alt), city: '', state: null, zip: '', country: null }
        )),
        'location.formerLocations': (f) => list((f.locationDetails || {}).formerLocations).filter(isPlainObject).map((fl) => ({
            raw: str(fl.raw !== undefined ? fl.raw : fl.address), city: str(fl.city), state: stateCode(fl.state),
            fromYear: str(fl.fromYear) === '' ? null : parseInt(fl.fromYear, 10), toYear: str(fl.toYear) === '' ? null : parseInt(fl.toYear, 10)
        })),
        'facilityDetails.isPrivatelyOwned': (f) => {
            if (typeof f.isPrivatelyOwned === 'boolean') return f.isPrivatelyOwned;
            const v = (f.facilityDetails || {}).isPrivatelyOwned;
            return typeof v === 'boolean' ? v : null;
        }
    };

    /** The field at a v2 path, on either shape (undefined when absent). */
    function read(facility, v2Path) {
        if (!isPlainObject(facility)) return undefined;
        if (isV2(facility)) return getPath(facility, v2Path);
        for (const prefix of Object.keys(LEGACY_READERS)) {
            if (v2Path === prefix) return LEGACY_READERS[prefix](facility);
            if (v2Path.startsWith(prefix + '.')) return getPath(LEGACY_READERS[prefix](facility), v2Path.slice(prefix.length + 1));
        }
        return getPath(facility, v2Path);
    }

    /** Set the field at a v2 path, where the facility's own shape keeps it. */
    function write(facility, v2Path, value) {
        if (!isPlainObject(facility)) return;
        if (isV2(facility)) {
            setPath(facility, v2Path, value);
            return;
        }
        if (v2Path === 'identification.currentOwners') {
            setPath(facility, 'identification.currentOwners', value);
            setPath(facility, 'identification.currentOwner', Array.isArray(value) && value.length ? value[0] : '');
            return;
        }
        setPath(facility, V2_TO_LEGACY[v2Path] || v2Path, value);
    }

    /** A form path in either spelling as its v2 path ('locationDetails.city' -> 'location.city'). */
    function toV2Path(path) {
        if (LEGACY_TO_V2[path]) return LEGACY_TO_V2[path];
        for (const legacy of Object.keys(LEGACY_TO_V2)) {
            if (path.startsWith(legacy + '.')) return LEGACY_TO_V2[legacy] + path.slice(legacy.length);
        }
        return path;
    }

    /** A v2 path as the legacy form spelled it. */
    function toLegacyPath(path) {
        if (V2_TO_LEGACY[path]) return V2_TO_LEGACY[path];
        for (const v2 of Object.keys(V2_TO_LEGACY)) {
            if (path.startsWith(v2 + '.')) return V2_TO_LEGACY[v2] + path.slice(v2.length);
        }
        return path;
    }

    window.KOP_FacilityFields = {
        read: read,
        write: write,
        isV2: isV2,
        toV2Path: toV2Path,
        toLegacyPath: toLegacyPath,
        V2_TO_LEGACY: V2_TO_LEGACY
    };
})();
