<script setup>
// Display of `maxcxam/media` and `maxcxam/media-gallery` (design 1.5 §5.4): index cell (first thumbnail + count) and
// detail list. On the detail page, users who may update the record get immediate reorder / delete through the
// extension's own routes (Larsadmin::routes() + findRecord('update') on the server).
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { ConfirmDialog, Icon, useI18n, useJsonRequest, useToasts } from 'larsadmin'
import { currentUrl, formatSize, isExpired, isPreviewPending } from './media'

const props = defineProps({
    field: { type: Object, required: true },
    context: { type: String, default: 'detail' },
})

const { t, locale } = useI18n()
const ns = (key, replacements) => t(`larsadmin-medialibrary::${key}`, replacements)
const { send } = useJsonRequest()
const toasts = useToasts()

const items = ref([...(props.field.media ?? [])])
watch(
    () => props.field.media,
    (media) => {
        items.value = [...(media ?? [])]
        broken.value = new Set()
    },
)

const index = computed(() => props.context === 'index')
const count = computed(() => props.field.count ?? items.value.length)
const busy = ref(false)
const confirming = ref(null)

// Thumbnails that failed to load (expired link, preview removed meanwhile) fall back to the file icon.
const broken = ref(new Set())
const thumbnail = (item) => (item.isImage && item.thumbnailUrl && !broken.value.has(item.id) ? item.thumbnailUrl : null)
const markBroken = (item) => (broken.value = new Set(broken.value).add(item.id))

// Signed URLs expire: refresh the page props before opening a stale link, then open the fresh URL of the same media
// (`key` is 'url' or 'downloadUrl'). Media removed meanwhile is not opened.
function open(event, item, key) {
    if (!isExpired(props.field)) return

    event.preventDefault()
    router.reload({
        preserveScroll: true,
        onSuccess: () => {
            const url = currentUrl(props.field.media, item.id, key)

            if (url) window.open(url, '_blank', 'noopener,noreferrer')
        },
    })
}

async function reorder(position, delta) {
    const next = [...items.value]
    const [moved] = next.splice(position, 1)
    next.splice(position + delta, 0, moved)

    busy.value = true
    const response = await send('POST', props.field.reorderUrl, { ids: next.map((item) => item.id) })
    busy.value = false

    if (response.ok) {
        items.value = next
        toasts.push({ type: 'success', message: ns('reordered') })
    } else {
        toasts.push({ type: 'error', message: response.data?.message ?? ns('failed') })
        router.reload({ preserveScroll: true })
    }
}

async function destroy(item) {
    confirming.value = null
    busy.value = true
    const response = await send('DELETE', item.destroyUrl)
    busy.value = false

    if (response.ok) {
        items.value = items.value.filter((other) => other.id !== item.id)
        toasts.push({ type: 'success', message: ns('removed', { name: item.name }) })
    } else {
        toasts.push({ type: 'error', message: response.data?.message ?? ns('failed') })
    }
}
</script>

<template>
    <span v-if="index" class="inline-flex items-center gap-1">
        <template v-if="items.length">
            <a v-if="items[0].url && thumbnail(items[0])" :href="items[0].url" target="_blank" rel="noopener noreferrer" @click.stop="open($event, items[0], 'url')">
                <img :src="thumbnail(items[0])" :alt="ns('preview_alt', { name: items[0].name })" width="32" height="32" loading="lazy" decoding="async" class="size-8 rounded object-cover" @error="markBroken(items[0])" />
            </a>
            <span v-else class="max-w-40 truncate text-sm text-fg">{{ items[0].name }}</span>
            <span v-if="count > 1" class="larsadmin-counter">{{ ns('more', { count: count - 1 }) }}</span>
        </template>
        <span v-else class="text-fg-muted">—</span>
    </span>

    <div v-else>
        <p v-if="!items.length" class="text-sm text-fg-muted">{{ ns('none') }}</p>
        <ul v-else class="flex flex-wrap gap-3">
            <li v-for="(item, position) in items" :key="item.id" class="flex w-40 flex-col gap-1 rounded-md border border-default bg-canvas p-2">
                <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="block" @click="open($event, item, 'url')">
                    <img v-if="thumbnail(item)" :src="thumbnail(item)" :alt="ns('preview_alt', { name: item.name })" width="144" height="108" loading="lazy" decoding="async" class="h-27 w-36 rounded object-cover" @error="markBroken(item)" />
                    <span v-else class="flex h-27 w-36 items-center justify-center rounded bg-canvas-subtle"><Icon name="file" /></span>
                </a>
                <span class="truncate text-xs text-fg" :title="item.name">{{ item.name }}</span>
                <span v-if="isPreviewPending(item)" class="text-xs text-fg-muted" data-preview-pending>{{ ns('preview_pending') }}</span>
                <span class="text-xs text-fg-muted">{{ formatSize(item.size, locale) }}</span>
                <div class="flex items-center gap-1">
                    <a v-if="item.downloadUrl" :href="item.downloadUrl" download class="larsadmin-btn larsadmin-btn-sm" :aria-label="ns('download', { name: item.name })" @click="open($event, item, 'downloadUrl')">
                        <Icon name="download" />
                    </a>
                    <template v-if="field.reorderUrl">
                        <button type="button" class="larsadmin-btn larsadmin-btn-sm" :disabled="busy || position === 0" :aria-label="ns('move_up', { name: item.name })" @click="reorder(position, -1)">
                            <Icon name="arrow-up" />
                        </button>
                        <button type="button" class="larsadmin-btn larsadmin-btn-sm" :disabled="busy || position === items.length - 1" :aria-label="ns('move_down', { name: item.name })" @click="reorder(position, 1)">
                            <Icon name="arrow-down" />
                        </button>
                    </template>
                    <button v-if="item.destroyUrl" type="button" class="larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger" :disabled="busy" :aria-label="ns('remove', { name: item.name })" @click="confirming = item">
                        <Icon name="trash" />
                    </button>
                </div>
            </li>
        </ul>
        <ConfirmDialog v-if="confirming" :message="ns('remove_confirm', { name: confirming.name })" @confirm="destroy(confirming)" @cancel="confirming = null" />
    </div>
</template>
