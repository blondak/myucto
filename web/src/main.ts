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
  window.addEventListener('load', async () => {
    const ourScript = new URL('/service-worker.js', window.location.origin).href
    try {
      // Service worker je vázaný na origin, ne na aplikaci. Typický Docker běh na
      // `http://localhost:8080` ten origin sdílí s čímkoli, co na tom portu běželo
      // dřív — a cizí service worker tam zůstane registrovaný i po smazání aplikace.
      // Když takový SW cachuje /api/, dostane klient na /api/auth/me střídavě 200
      // z cizí cache a 401 ze sítě, což se projeví jako nekonečná smyčka / ↔ /login.
      // Cizí registrace proto rušíme dřív, než zaregistrujeme vlastní.
      const registrations = await navigator.serviceWorker.getRegistrations()
      await Promise.all(registrations.map((registration) => {
        const script = registration.active?.scriptURL
          ?? registration.waiting?.scriptURL
          ?? registration.installing?.scriptURL
        return script && script !== ourScript ? registration.unregister() : undefined
      }))

      await navigator.serviceWorker.register('/service-worker.js', {
        scope: '/',
        updateViaCache: 'none',
      })
    } catch (error) {
      console.error('Service worker registration failed:', error)
    }
  })
}
