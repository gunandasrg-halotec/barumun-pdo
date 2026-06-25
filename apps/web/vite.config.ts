import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { loadEnv } from 'vite'
import path from 'path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, __dirname, '')
  const allowedHosts = env.VITE_ALLOWED_HOSTS
    ?.split(',')
    .map((host) => host.trim())
    .filter(Boolean)

  return {
    plugins: [
      react(),
    ],
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: './src/test/setup.ts',
    },
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      port: 5173,
      ...(allowedHosts && allowedHosts.length > 0 ? { allowedHosts } : {}),
      proxy: {
        '/api': {
          // Di Docker: VITE_API_TARGET=http://api:8000 (nama service di docker-compose)
          // Di lokal tanpa Docker: default ke localhost:8000
          target: env.VITE_API_TARGET || 'http://localhost:8000',
          changeOrigin: true,
        },
      },
    },
  }
})
