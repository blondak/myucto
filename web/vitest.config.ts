import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Samostatná konfigurace pro testy — nedědí server proxy / tailwind z vite.config.ts,
// jen vue plugin (pro .vue SFC) + alias `@` → src (shodně s vite.config a tsconfig).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    include: ['src/**/*.{test,spec}.ts'],
  },
})
