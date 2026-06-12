import { paraglideVitePlugin } from '@inlang/paraglide-js'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'
import solid from 'vite-plugin-solid'
import symfony from 'vite-plugin-symfony'

// Built assets land in public/build/ui and are referenced by the Symfony
// pentatrion/vite-bundle Twig helpers via entrypoints.json (see
// config/packages/pentatrion_vite.yaml). The legacy ExtJS app under
// assets/ is untouched by this build.
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
  base: '/build/ui/',
  build: {
    outDir: '../public/build/ui',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        app: './src/main.tsx',
      },
    },
  },
})
