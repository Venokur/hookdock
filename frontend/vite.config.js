import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// The dev server proxies to the published app container so the API stays single-origin.
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      // Websocket upgrade for the realtime channel (must come before '/api').
      '/api/ws': { target: 'http://localhost:8080', ws: true },
      '/api': 'http://localhost:8080',
      '/hook': 'http://localhost:8080',
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
})
