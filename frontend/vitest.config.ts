import { paraglideVitePlugin } from '@inlang/paraglide-js'
import { defineConfig } from 'vitest/config'
import solid from 'vite-plugin-solid'

export default defineConfig({
  plugins: [
    solid(),
    paraglideVitePlugin({
      project: './project.inlang',
      outdir: './src/paraglide',
      strategy: ['baseLocale'],
    }),
  ],
  resolve: {
    // Required for solid-js to resolve its dev (browser) build in tests.
    conditions: ['development', 'browser'],
  },
  test: {
    // jsdom, not happy-dom: vitest-axe is incompatible with happy-dom.
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
