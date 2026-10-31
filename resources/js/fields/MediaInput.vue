<script setup>
// Form control of `maxcxam/media` and `maxcxam/media-gallery` (design 1.5 §5.4 form contract). The panel draws the
// label, help and the first error; this component draws the control and the per-file list.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Icon, useI18n } from 'larsadmin'
import { addUploads, formatSize, isPreviewPending, move, normalize, otherErrors, precheck, removeUpload, toggleKeep, uploadErrors } from './media'

const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: Object, default: null },
    invalid: { type: Boolean, default: false },
    errors: { type: Array, default: () => [] },
    // { 'gallery.upload.1': '…' } for `attribute` and `attribute.*` (stable since 1.5, §18 A6b).
    errorsByKey: { type: Object, default: () => ({}) },
    describedBy: { type: String, default: null },
    context: { type: String, default: 'update' },
    resourceId: { type: [String, Number], default: null },
})

const emit = defineEmits(['update:modelValue'])
const { t, locale } = useI18n()
const ns = (key, replacements) => t(`larsadmin-medialibrary::${key}`, replacements)

const payload = computed(() => normalize(props.modelValue, props.field))
const stored = computed(() => props.field.media ?? [])
const byId = computed(() => new Map(stored.value.map((item) => [item.id, item])))
const kept = computed(() => payload.value.keep.map((id) => byId.value.get(id)).filter(Boolean))
const removed = computed(() => stored.value.filter((item) => !payload.value.keep.includes(item.id)))
const rejected = ref([])
const announcement = ref('')
const input = ref(null)
const hintId = computed(() => `${props.field.attribute}-media-hint`)
const describedByIds = computed(() => [props.describedBy, hintId.value].filter(Boolean).join(' '))
const readonly = computed(() => !!props.field.readonly)
const replacing = computed(() => !props.field.multiple && payload.value.upload.length > 0)
const perUpload = computed(() => uploadErrors(props.errorsByKey, props.field.attribute))
const nested = computed(() => otherErrors(props.errorsByKey, props.field.attribute))

// Stored thumbnails that failed to load fall back to the file icon.
const broken = ref(new Set())
const thumbnail = (item) => (item.isImage && item.thumbnailUrl && !broken.value.has(item.id) ? item.thumbnailUrl : null)
const markBroken = (item) => (broken.value = new Set(broken.value).add(item.id))

// Object URLs of pending image uploads, revoked when the file leaves the list or the component unmounts.
const previews = new Map()

function previewOf(file) {
    if (!/^image\/(jpeg|png|gif|webp|avif)$/.test(file.type) || typeof URL?.createObjectURL !== 'function') return null
    if (!previews.has(file)) previews.set(file, URL.createObjectURL(file))

    return previews.get(file)
}

watch(
    () => payload.value.upload,
    (files) => {
        for (const [file, url] of previews) {
            if (!files.includes(file)) {
                URL.revokeObjectURL?.(url)
                previews.delete(file)
            }
        }
    },
)

onBeforeUnmount(() => {
    for (const url of previews.values()) URL.revokeObjectURL?.(url)
    previews.clear()
})

function update(next, message = '') {
    emit('update:modelValue', next)
    announcement.value = message
}

function pick(event) {
    const files = Array.from(event.target?.files ?? [])
    const { accepted, rejected: refused } = precheck(files, props.field, payload.value)

    rejected.value = refused
    if (accepted.length) update(addUploads(payload.value, accepted, props.field), accepted.map((file) => file.name).join(', '))
    if (event.target) event.target.value = ''
}

function reject(item) {
    const max = formatSize(props.field.maxSize, locale.value)

    return ns(item.reason, { name: item.file.name, max: item.reason === 'too_large' ? max : props.field.maxFiles })
}

const limits = computed(() => {
    const parts = []

    if (props.field.maxSize) parts.push(ns('limits', { size: formatSize(props.field.maxSize, locale.value) }))
    if (props.field.types?.length) parts.push(ns('types', { types: props.field.types.join(', ') }))
    if (props.field.multiple) parts.push(ns('too_many', { max: props.field.maxFiles }))

    return parts.join(' · ')
})
</script>

<template>
    <div class="rounded-md border border-default bg-canvas" :class="{ 'border-danger-fg': invalid }">
        <ul v-if="kept.length || removed.length || payload.upload.length" class="divide-y divide-muted">
            <li v-for="(item, index) in kept" :key="`m${item.id}`" class="flex items-center gap-3 px-3 py-2" :class="{ 'opacity-50': replacing }">
                <img v-if="thumbnail(item)" :src="thumbnail(item)" :alt="ns('preview_alt', { name: item.name })" width="40" height="40" loading="lazy" decoding="async" class="size-10 rounded object-cover" @error="markBroken(item)" />
                <Icon v-else name="file" class="size-4 text-fg-muted" />
                <span class="min-w-0 flex-1 truncate text-sm text-fg" :class="{ 'line-through': replacing }">{{ item.name }}</span>
                <span v-if="isPreviewPending(item)" class="text-xs text-fg-muted" data-preview-pending>{{ ns('preview_pending') }}</span>
                <span class="text-xs text-fg-muted">{{ formatSize(item.size, locale) }}</span>
                <template v-if="!readonly && field.multiple">
                    <button type="button" class="larsadmin-btn larsadmin-btn-sm" :disabled="index === 0" :aria-label="ns('move_up', { name: item.name })" @click="update(move(payload, item.id, -1))">
                        <Icon name="arrow-up" />
                    </button>
                    <button type="button" class="larsadmin-btn larsadmin-btn-sm" :disabled="index === kept.length - 1" :aria-label="ns('move_down', { name: item.name })" @click="update(move(payload, item.id, 1))">
                        <Icon name="arrow-down" />
                    </button>
                </template>
                <button v-if="!readonly && field.removable" type="button" class="larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger" :aria-label="ns('remove', { name: item.name })" @click="update(toggleKeep(payload, item.id), ns('removed_on_save', { name: item.name }))">
                    <Icon name="trash" />
                </button>
            </li>
            <li v-for="item in removed" :key="`r${item.id}`" class="flex items-center gap-3 px-3 py-2 text-fg-muted">
                <Icon name="x" class="size-4" />
                <span class="min-w-0 flex-1 truncate text-sm line-through">{{ item.name }}</span>
                <span class="sr-only">{{ ns('removed_on_save', { name: item.name }) }}</span>
                <button type="button" class="larsadmin-btn larsadmin-btn-sm" :aria-label="ns('undo', { name: item.name })" @click="update(toggleKeep(payload, item.id))">
                    <Icon name="history" />
                </button>
            </li>
            <li v-for="(file, index) in payload.upload" :key="`u${index}-${file.name}`" class="px-3 py-2" :data-upload-error="perUpload[index] ? 'true' : undefined">
                <div class="flex items-center gap-3">
                    <img v-if="previewOf(file)" :src="previewOf(file)" :alt="ns('preview_alt', { name: file.name })" width="40" height="40" class="size-10 rounded object-cover" />
                    <Icon v-else name="upload" class="size-4 text-fg-muted" />
                    <span class="min-w-0 flex-1 truncate text-sm text-fg">{{ file.name }}</span>
                    <span class="larsadmin-label larsadmin-label-success">{{ ns('new') }}</span>
                    <button type="button" class="larsadmin-btn larsadmin-btn-sm" :aria-label="ns('remove', { name: file.name })" @click="update(removeUpload(payload, index))">
                        <Icon name="x" />
                    </button>
                </div>
                <p v-if="perUpload[index]" :id="`${field.attribute}-upload-${index}-error`" class="mt-1 text-xs text-danger-fg">{{ perUpload[index] }}</p>
            </li>
        </ul>
        <p v-else class="px-3 py-2 text-sm text-fg-muted">{{ ns('none') }}</p>

        <div v-if="!readonly" class="flex flex-wrap items-center gap-3 border-t border-muted px-3 py-2">
            <label :for="field.attribute" class="larsadmin-btn larsadmin-btn-sm larsadmin-btn-secondary cursor-pointer focus-within:outline-2">
                <Icon name="upload" />
                {{ field.multiple ? ns('choose_multiple') : stored.length || payload.upload.length ? ns('replace') : ns('choose') }}
                <input
                    :id="field.attribute"
                    ref="input"
                    type="file"
                    class="sr-only"
                    :accept="field.accept || undefined"
                    :multiple="!!field.multiple"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedByIds || undefined"
                    @change="pick"
                />
            </label>
            <span :id="hintId" class="text-xs text-fg-muted">{{ limits }}</span>
        </div>

        <ul v-if="rejected.length || nested.length" class="border-t border-muted px-3 py-2 text-xs text-danger-fg" role="alert">
            <li v-for="item in rejected" :key="`x${item.file.name}`">{{ reject(item) }}</li>
            <li v-for="message in nested" :key="message">{{ message }}</li>
        </ul>

        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </div>
</template>
