import { describe, expect, it } from 'vitest'
import { mountField } from 'larsadmin-kit/testing.js'
import MediaInput from './MediaInput.vue'

const translations = { 'larsadmin-medialibrary': { remove: 'Remove :name', undo: 'Undo :name', move_up: 'Up :name', move_down: 'Down :name', new: 'New', none: 'None' } }

const gallery = {
    attribute: 'gallery',
    component: 'maxcxam/media-gallery',
    multipart: true,
    multiple: true,
    removable: true,
    maxFiles: 3,
    maxSize: 1024 * 1024,
    types: ['jpg'],
    accept: '.jpg',
    value: { present: 1, known: [1, 2], keep: [1, 2] },
    media: [
        { id: 1, name: 'a.jpg', size: 10, isImage: true, thumbnailUrl: '/t/1' },
        { id: 2, name: 'b.jpg', size: 10, isImage: true, thumbnailUrl: '/t/2' },
    ],
}

function mount(field = gallery, options = {}) {
    return mountField(MediaInput, { field, panel: { translations }, ...options })
}

const last = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]

describe('MediaInput', () => {
    it('labels the native file input with the attribute for the panel label', () => {
        const input = mount().find('input[type="file"]')

        expect(input.attributes('id')).toBe('gallery')
        expect(input.attributes('accept')).toBe('.jpg')
        expect(input.attributes('multiple')).toBeDefined()
    })

    it('removes and restores a stored file through keep, leaving known untouched', async () => {
        const wrapper = mount()

        await wrapper.find('button[aria-label="Remove a.jpg"]').trigger('click')
        expect(last(wrapper)).toEqual({ present: 1, known: [1, 2], keep: [2], upload: [] })

        await wrapper.find('button[aria-label="Undo a.jpg"]').trigger('click')
        expect(last(wrapper).keep).toEqual([2, 1])
    })

    it('reorders stored files', async () => {
        const wrapper = mount()

        await wrapper.find('button[aria-label="Up b.jpg"]').trigger('click')

        expect(last(wrapper).keep).toEqual([2, 1])
    })

    it('adds picked files that pass the precheck', async () => {
        const wrapper = mount()
        const input = wrapper.find('input[type="file"]')
        const ok = new File(['x'], 'c.jpg', { type: 'image/jpeg' })
        const bad = new File(['x'], 'd.pdf', { type: 'application/pdf' })

        Object.defineProperty(input.element, 'files', { value: [ok, bad], configurable: true })
        await input.trigger('change')

        expect(last(wrapper).upload).toEqual([ok])
        expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    })

    it('has no controls when readonly', () => {
        const wrapper = mount({ ...gallery, readonly: true })

        expect(wrapper.find('input[type="file"]').exists()).toBe(false)
        expect(wrapper.findAll('button')).toHaveLength(0)
    })
})

describe('MediaInput errors', () => {
    it('shows per-upload messages from errorsByKey next to the pending upload', async () => {
        const ok = new File(['x'], 'ok.jpg', { type: 'image/jpeg' })
        const bad = new File(['x'], 'bad.jpg', { type: 'image/jpeg' })
        const wrapper = mountField(MediaInput, {
            field: gallery,
            modelValue: { present: 1, known: [1, 2], keep: [1, 2], upload: [ok, bad] },
            errors: ['bad.jpg is too small', 'invalid id'],
            panel: { translations },
            options: { props: { errorsByKey: { 'gallery.upload.1': 'bad.jpg is too small', 'gallery.keep.0': 'invalid id' } } },
        })

        const rows = wrapper.findAll('li[data-upload-error]')

        expect(rows).toHaveLength(1)
        expect(rows[0].text()).toContain('bad.jpg')
        expect(rows[0].find('#gallery-upload-1-error').text()).toBe('bad.jpg is too small')
        expect(wrapper.find('[role="alert"]').text()).toBe('invalid id')
    })
})
