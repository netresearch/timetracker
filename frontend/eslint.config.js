import js from '@eslint/js'
import solid from 'eslint-plugin-solid/configs/typescript'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  {
    ignores: ['src/paraglide/**', 'node_modules/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['src/**/*.{ts,tsx}'],
    ...solid,
  },
)
