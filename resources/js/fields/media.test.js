import { describe, expect, it } from 'vitest'
import { addUploads, currentUrl, extensionOf, formatSize, initialPayload, isExpired, isPreviewPending, move, normalize, otherErrors, precheck, removeUpload, toggleKeep, uploadErrors } from './media'

const gallery = { multiple: true, maxFiles: 3, maxSize: 1024, types: ['jpg', 'png'], value: { present: 1, known: [1, 2], keep: [1, 2] } }
const file = (name, size = 10) => ({ name, size, type: 'image/jpeg' })

describe('payload', () => {
    it('starts from the server value and never edits known', () => {
        const payload = initialPayload(gallery)

        expect(payload).toEqual({ present: 1, known: [1, 2], keep: [1, 2], upload: [] })
        expect(toggleKeep(payload, 1)).toEqual({ present: 1, known: [1, 2], keep: [2], upload: [] })
        expect(toggleKeep(toggleKeep(payload, 1), 1).keep).toEqual([2, 1])
    })

    it('normalizes a missing value', () => {
        expect(normalize(null, gallery)).toEqual(initialPayload(gallery))
        expect(normalize({ keep: [2] }, gallery)).toEqual({ present: 1, known: [1, 2], keep: [2], upload: [] })
    })

    it('moves kept ids within bounds', () => {
        const payload = initialPayload(gallery)

        expect(move(payload, 2, -1).keep).toEqual([2, 1])
        expect(move(payload, 1, -1)).toBe(payload)
        expect(move(payload, 9, 1)).toBe(payload)
    })

    it('replaces the pending upload of a single field and appends for galleries', () => {
        const a = file('a.jpg')
        const b = file('b.jpg')

        expect(addUploads({ upload: [a] }, [b], { multiple: false }).upload).toEqual([b])
        expect(addUploads({ upload: [a] }, [b], gallery).upload).toEqual([a, b])
        expect(removeUpload({ upload: [a, b] }, 0).upload).toEqual([b])
    })
})

describe('precheck', () => {
    it('rejects size, type and count', () => {
        const payload = initialPayload(gallery)
        const { accepted, rejected } = precheck([file('ok.jpg'), file('big.jpg', 5000), file('x.gif'), file('two.png')], gallery, payload)

        expect(accepted.map((f) => f.name)).toEqual(['ok.jpg'])
        expect(rejected.map((r) => r.reason)).toEqual(['too_large', 'type_not_allowed', 'too_many'])
    })

    it('reads extensions case-insensitively', () => {
        expect(extensionOf('A.B.JPG')).toBe('jpg')
        expect(extensionOf('noext')).toBe('')
    })
})

it('formats sizes and detects expired URLs', () => {
    expect(formatSize(1536, 'en')).toBe('1.5 kB')
    expect(formatSize(-1)).toBe('—')
    expect(isExpired({ urlsExpireAt: '2020-01-01T00:00:00Z' })).toBe(true)
    expect(isExpired({ urlsExpireAt: null })).toBe(false)
})

describe('errorsByKey', () => {
    const bag = {
        gallery: 'Gallery: no more than 3 files.',
        'gallery.upload.0': 'a.exe is not allowed',
        'gallery.upload.2': 'too big',
        'gallery.keep.0': 'invalid id',
        'gallery.upload.x': 'odd key',
    }

    it('maps per-upload messages to upload indexes', () => {
        expect(uploadErrors(bag, 'gallery')).toEqual({ 0: 'a.exe is not allowed', 2: 'too big' })
        expect(uploadErrors(undefined, 'gallery')).toEqual({})
    })

    it('lists the other nested messages, without the field-level ones the panel shows', () => {
        expect(otherErrors(bag, 'gallery')).toEqual(['invalid id', 'odd key'])
    })
})

it('treats only an explicit previewPending flag as pending', () => {
    expect(isPreviewPending({ previewPending: true })).toBe(true)
    expect(isPreviewPending({ previewPending: 1 })).toBe(false)
    expect(isPreviewPending({})).toBe(false)
    expect(isPreviewPending(null)).toBe(false)
})

it('finds the current URL of a media item in reloaded props', () => {
    const media = [{ id: 1, url: '/new/1', downloadUrl: '/new/d1' }]

    expect(currentUrl(media, 1, 'url')).toBe('/new/1')
    expect(currentUrl(media, 1, 'downloadUrl')).toBe('/new/d1')
    expect(currentUrl(media, 2, 'url')).toBe(null)
    expect(currentUrl(undefined, 1, 'url')).toBe(null)
})
