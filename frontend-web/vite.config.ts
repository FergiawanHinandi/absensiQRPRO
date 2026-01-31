import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  server: {
    port: 5173,
    strictPort: true, // Throw error if port is already in use
    host: true, // Listen on all addresses
  },
  preview: {
    port: 5173,
    strictPort: true,
  },
})
