import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { router } from './router'
import { i18n, ensureInitialLocaleReady } from './i18n'
import App from './App.vue'
import { vMath } from './directives/vMath'
import './styles/main.css'
import { setForbiddenPermissionHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

await ensureInitialLocaleReady()

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
let permissionRefreshRunning = false
setForbiddenPermissionHandler(async () => {
  if (permissionRefreshRunning) return
  permissionRefreshRunning = true
  try { await useAuthStore(pinia).refresh() }
  finally { permissionRefreshRunning = false }
})
app.use(router)
app.use(i18n)
app.directive('math', vMath)
app.mount('#app')

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/service-worker.js', {
      scope: '/',
      updateViaCache: 'none',
    }).catch((error) => {
      console.error('Service worker registration failed:', error)
    })
  })
}
