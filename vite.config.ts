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
  // `three` is only loaded by the 3D screens, so Vite would otherwise discover it late, re-optimise
  // in the middle of a session and leave an open page with "Outdated Optimize Dep" (504) errors —
  // the 3D Review button then does nothing. Listing it here bundles it at start-up instead.
  optimizeDeps: {
    include: ['three'],
  },
  server: {
    port: 5173,
  },
})
