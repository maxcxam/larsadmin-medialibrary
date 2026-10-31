import { existsSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// The larsadmin kit (Vite preset, SDK for tests, Tailwind theme) ships in the Composer package: this package's own
// vendor/ after `composer install` (path repository to ../larsadmin in the monorepo).
const kit = ['./vendor/maxcxam/larsadmin/kit', '../larsadmin/kit']
    .map((dir) => fileURLToPath(new URL(dir, import.meta.url)))
    .find((dir) => existsSync(join(dir, 'vite.js')))

if (!kit) {
    throw new Error('[larsadmin] The kit was not found: run `composer install` here first.')
}

const { default: larsadmin } = await import(pathToFileURL(join(kit, 'vite.js')).href)

export default defineConfig({
    plugins: [vue(), tailwindcss(), larsadmin()],
    resolve: {
        // "larsadmin-kit/testing.js" and "larsadmin-kit/theme.css"; their own imports resolve from this package.
        alias: { 'larsadmin-kit': kit },
        dedupe: ['@vue/test-utils'],
    },
})
