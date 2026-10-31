import { afterEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import { mountDisplay } from 'larsadmin-kit/testing.js'
import MediaDisplay from './MediaDisplay.vue'

const translations = { 'larsadmin-medialibrary': { more: '+:count', none: 'None', remove: 'Remove :name', move_up: 'Up :name', move_down: 'Down :name', download: 'Download :name', preview_alt: 'Preview of :name', preview_pending: 'Generating preview' } }

const media = [
    { id: 1, name: 'a.jpg', size: 10, isImage: true, url: '/u/1', thumbnailUrl: '/t/1', downloadUrl: '/d/1', destroyUrl: '/x/1' },
    { id: 2, name: 'b.pdf', size: 10, isImage: false, url: '/u/2', thumbnailUrl: null, downloadUrl: '/d/2', destroyUrl: null },
]

describe('MediaDisplay', () => {
    it('shows the first thumbnail and a counter on the index', () => {
        const wrapper = mountDisplay(MediaDisplay, { field: { attribute: 'gallery', media: media.slice(0, 1), count: 3 }, context: 'index', panel: { translations } })

        expect(wrapper.find('img').attributes('src')).toBe('/t/1')
        expect(wrapper.text()).toContain('+2')
    })

    it('lists the media on the detail page with management controls only when allowed', () => {
        const wrapper = mountDisplay(MediaDisplay, { field: { attribute: 'gallery', media, reorderUrl: '/order' }, panel: { translations } })

        expect(wrapper.findAll('li')).toHaveLength(2)
        expect(wrapper.find('button[aria-label="Remove a.jpg"]').exists()).toBe(true)
        expect(wrapper.find('button[aria-label="Remove b.pdf"]').exists()).toBe(false)
        expect(wrapper.find('button[aria-label="Up a.jpg"]').attributes('disabled')).toBeDefined()
        expect(wrapper.find('a[aria-label="Download b.pdf"]').attributes('href')).toBe('/d/2')
    })

    it('has no management controls without endpoints', () => {
        const wrapper = mountDisplay(MediaDisplay, { field: { attribute: 'gallery', media: media.map((item) => ({ ...item, destroyUrl: null })) }, panel: { translations } })

        expect(wrapper.findAll('button')).toHaveLength(0)
    })

    it('marks a preview that is still being generated', () => {
        const wrapper = mountDisplay(MediaDisplay, { field: { attribute: 'gallery', media: [{ ...media[0], previewPending: true }, media[1]] }, panel: { translations } })

        const pending = wrapper.findAll('[data-preview-pending]')

        expect(pending).toHaveLength(1)
        expect(pending[0].text()).toBe('Generating preview')
        expect(wrapper.find('img').attributes('src')).toBe('/t/1')
    })

    it('falls back to the file icon when a thumbnail fails to load', async () => {
        const wrapper = mountDisplay(MediaDisplay, { field: { attribute: 'gallery', media: media.slice(0, 1) }, panel: { translations } })

        await wrapper.find('img').trigger('error')

        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.find('a[href="/u/1"]').exists()).toBe(true)
    })

    describe('expired links', () => {
        afterEach(() => vi.restoreAllMocks())

        it('reloads the props and opens the fresh URL, not the expired one', async () => {
            const field = { attribute: 'gallery', media: media.slice(0, 1), urlsExpireAt: '2020-01-01T00:00:00Z' }
            const wrapper = mountDisplay(MediaDisplay, { field, panel: { translations } })
            const opened = vi.spyOn(window, 'open').mockImplementation(() => null)

            vi.spyOn(router, 'reload').mockImplementation(async (options) => {
                await wrapper.setProps({ field: { ...field, urlsExpireAt: null, media: [{ ...media[0], url: '/fresh/1', downloadUrl: '/fresh/d1' }] } })
                options.onSuccess()
            })

            await wrapper.find('a[href="/d/1"]').trigger('click')
            await vi.waitFor(() => expect(opened).toHaveBeenCalledWith('/fresh/d1', '_blank', 'noopener,noreferrer'))
            expect(opened).not.toHaveBeenCalledWith('/d/1', expect.anything(), expect.anything())
        })

        it('does not open media that disappeared meanwhile', async () => {
            const field = { attribute: 'gallery', media: media.slice(0, 1), urlsExpireAt: '2020-01-01T00:00:00Z' }
            const wrapper = mountDisplay(MediaDisplay, { field, panel: { translations } })
            const opened = vi.spyOn(window, 'open').mockImplementation(() => null)
            const reload = vi.spyOn(router, 'reload').mockImplementation(async (options) => {
                await wrapper.setProps({ field: { ...field, urlsExpireAt: null, media: [] } })
                options.onSuccess()
            })

            await wrapper.find('a[href="/u/1"]').trigger('click')
            await vi.waitFor(() => expect(reload).toHaveBeenCalled())
            expect(opened).not.toHaveBeenCalled()
        })
    })
})
