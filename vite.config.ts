import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Laravel renders the HTML document; Vite only builds/serves the assets.
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.tsx'],
      refresh: ['resources/views/**', 'routes/**', 'app/Http/**'],
    }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  server: {
    port: 5173,
  },
})
