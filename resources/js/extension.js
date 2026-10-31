import { defineExtension } from 'larsadmin'
import '../css/extension.css'
import MediaInput from './fields/MediaInput.vue'
import MediaDisplay from './fields/MediaDisplay.vue'

export default defineExtension({
    name: 'maxcxam/larsadmin-medialibrary',
    requires: '^1.5',
    fields: {
        'maxcxam/media': { form: MediaInput, display: MediaDisplay },
        'maxcxam/media-gallery': { form: MediaInput, display: MediaDisplay },
    },
})
