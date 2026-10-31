import { describe, expect, it } from 'vitest'
import { mountDisplay } from 'larsadmin-kit/testing.js'
import MediaDisplay from './MediaDisplay.vue'

const translations = { 'larsadmin-medialibrary': { more: '+:count', none: 'None', remove: 'Remove :name', move_up: 'Up :name', move_down: 'Down :name', download: 'Download :name', preview_alt: 'Preview of :name' } }

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
})
