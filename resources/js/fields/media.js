// Pure helpers of the media fields (no Vue): the form payload, client-side prechecks, sizes, error mapping.
//
// Payload (v-model of MediaInput, sent as multipart by the panel because the field has `multipart: true`):
//   { present: 1, known: [id...], keep: [id...], upload: [File...] }
// `known` is never changed by the client; `keep` order is the display order of stored media.

export function initialPayload(field) {
    const value = field?.value ?? {}
    const known = Array.isArray(value.known) ? [...value.known] : []

    return { present: 1, known, keep: Array.isArray(value.keep) ? [...value.keep] : [...known], upload: [] }
}

export function normalize(value, field) {
    if (!value || typeof value !== 'object') return initialPayload(field)

    return {
        present: 1,
        known: Array.isArray(value.known) ? value.known : initialPayload(field).known,
        keep: Array.isArray(value.keep) ? value.keep : [],
        upload: Array.isArray(value.upload) ? value.upload : [],
    }
}

export function toggleKeep(payload, id) {
    const keep = payload.keep.includes(id) ? payload.keep.filter((item) => item !== id) : [...payload.keep, id]

    return { ...payload, keep }
}

// Moves a kept id by `delta` (-1 up, +1 down) within `keep`.
export function move(payload, id, delta) {
    const keep = [...payload.keep]
    const from = keep.indexOf(id)
    const to = from + delta

    if (from === -1 || to < 0 || to >= keep.length) return payload

    keep.splice(from, 1)
    keep.splice(to, 0, id)

    return { ...payload, keep }
}

export function removeUpload(payload, index) {
    return { ...payload, upload: payload.upload.filter((_, i) => i !== index) }
}

// Adds accepted files. Single fields: the new file replaces any pending upload (the server replaces the stored one).
export function addUploads(payload, files, field) {
    if (!field?.multiple) return { ...payload, upload: files.slice(0, 1) }

    return { ...payload, upload: [...payload.upload, ...files] }
}

export function extensionOf(name) {
    const index = String(name ?? '').lastIndexOf('.')

    return index === -1 ? '' : String(name).slice(index + 1).toLowerCase()
}

// Splits picked files into accepted and rejected ({ file, reason: 'too_large' | 'type_not_allowed' | 'too_many' }).
export function precheck(files, field, payload) {
    const accepted = []
    const rejected = []
    const types = Array.isArray(field?.types) ? field.types : []
    const max = field?.multiple ? field.maxFiles ?? Infinity : 1
    let count = field?.multiple ? payload.keep.length + payload.upload.length : 0

    for (const file of files) {
        if (field?.maxSize && file.size > field.maxSize) {
            rejected.push({ file, reason: 'too_large' })
        } else if (types.length && !types.includes(extensionOf(file.name))) {
            rejected.push({ file, reason: 'type_not_allowed' })
        } else if (count >= max) {
            rejected.push({ file, reason: 'too_many' })
        } else {
            accepted.push(file)
            count++
        }
    }

    return { accepted, rejected }
}

const UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte']

export function formatSize(bytes, locale = 'en') {
    if (!Number.isFinite(bytes) || bytes < 0) return '—'

    let value = bytes
    let unit = 0

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024
        unit++
    }

    return new Intl.NumberFormat(locale, { style: 'unit', unit: UNITS[unit], unitDisplay: 'short', maximumFractionDigits: unit === 0 ? 0 : 1 }).format(value)
}

// Per-upload messages from the form's `errorsByKey` prop (design §5.4, §18 A6b: { key: message } for `attribute`
// and `attribute.*`): { 0: '…', 2: '…' } from `gallery.upload.0`, `gallery.upload.2`. The client sends uploads in
// display order, so index N is the N-th pending upload.
export function uploadErrors(errorsByKey, attribute) {
    const prefix = `${attribute}.upload.`
    const out = {}

    for (const [key, message] of Object.entries(errorsByKey ?? {})) {
        const index = key.startsWith(prefix) ? key.slice(prefix.length) : ''

        if (/^\d+$/.test(index)) out[Number(index)] = message
    }

    return out
}

// Other nested messages (`gallery.keep.0`, ...). The panel shows one field-level message under the label itself
// (`attribute`, `.upload`, `.keep`, `.present`, else the first `.upload.N`), so those keys are not repeated here.
export function otherErrors(errorsByKey, attribute) {
    const own = new Set([attribute, `${attribute}.upload`, `${attribute}.keep`, `${attribute}.present`])
    const prefix = `${attribute}.upload.`
    const messages = Object.entries(errorsByKey ?? {})
        .filter(([key]) => !own.has(key) && !(key.startsWith(prefix) && /^\d+$/.test(key.slice(prefix.length))))
        .map(([, message]) => message)

    return [...new Set(messages)]
}

export function isExpired(field, now = Date.now(), skew = 5000) {
    if (!field?.urlsExpireAt) return false

    const at = Date.parse(field.urlsExpireAt)

    return Number.isFinite(at) && at - skew <= now
}

// A queued preview conversion that is not generated yet (design §4: until then the preview is the original or an icon).
// `previewPending` is optional in the item props; without it the item is treated as ready.
export function isPreviewPending(item) {
    return item?.previewPending === true
}

// The current URL (`key`: 'url' | 'downloadUrl') of a media item in freshly reloaded props, or null if it is gone.
export function currentUrl(media, id, key) {
    const item = (Array.isArray(media) ? media : []).find((other) => other.id === id)

    return item?.[key] ?? null
}
