import { paraglideVitePlugin } from '@inlang/paraglide-js'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'
import solid from 'vite-plugin-solid'
import symfony from 'vite-plugin-symfony'

// Built assets land in public/build-ui (NOT inside public/build — Encore's
// cleanupOutputBeforeBuild() wipes that whole directory) and are referenced
// by the Symfony pentatrion/vite-bundle Twig helpers via entrypoints.json
// (see config/packages/pentatrion_vite.yaml).
export default defineConfig({
  plugins: [
    solid(),
    tailwindcss(),
    paraglideVitePlugin({
      project: './project.inlang',
      outdir: './src/paraglide',
      strategy: ['baseLocale'],
    }),
    symfony(),
  ],
  base: '/build-ui/',
  build: {
    outDir: '../public/build-ui',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        app: './src/main.tsx',
        login: './src/login.tsx',
      },
    },
  },
})
