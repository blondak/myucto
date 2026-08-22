import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Položka „Hosting" v menu smí existovat JEN na spravované (hostované)
 * instalaci (H-31).
 *
 * ⚠️ PROČ TENHLE TEST: na self-hosted instalaci by odkaz vedl na stránku, která
 * mluví o zaplaceném prostoru, tarifu a předplaceném provozu — tedy o věcech,
 * které si tam zákazník obstarává sám. Zmizení podmínky `auth.isManagedInstallation`
 * je jednořádková regrese, kterou v menu nikdo nezahlédne, dokud si nestěžuje
 * zákazník s vlastním serverem.
 *
 * Čte se zdroj, ne vykreslená komponenta: `AppLayout` táhne celý řetěz storů,
 * routeru a API volání a montovat ho jen kvůli jedné položce menu by bylo dražší
 * i křehčí — stejný důvod jako u `payrollNavOrder.spec.ts`.
 */

// vitest běží s cwd = web/, proto cesty od něj.
const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const routes = readFileSync(resolve(process.cwd(), 'src/router/workspaceRoutes.ts'), 'utf8')

describe('položka Hosting v menu', () => {
  it('je v menu právě jednou', () => {
    const hits = [...appLayout.matchAll(/to: '\/hosting'/g)]
    expect(hits).toHaveLength(1)
  })

  it('je podmíněná spravovaným režimem — self-hosted ji nedostane', () => {
    const index = appLayout.indexOf("to: '/hosting'")
    expect(index).toBeGreaterThan(-1)

    // Podmínka musí stát TĚSNĚ před položkou, ne kdesi o dvě stě řádků výš.
    const before = appLayout.slice(Math.max(0, index - 200), index)
    expect(before).toContain('auth.isManagedInstallation ?')
  })

  it('vede na stránku Hostingu, která je jen pro superadmina', () => {
    const line = routes.split('\n').find(row => row.includes("path: 'hosting'"))
    expect(line, 'routa /hosting v workspaceRoutes.ts chybí').toBeDefined()
    expect(line).toContain("name: 'hosting'")
    expect(line).toContain('pages/hosting/Hosting.vue')
    expect(line).toContain('superadminOnly: true')
  })

  it('používá překladový klíč, ne natvrdo napsaný text', () => {
    const index = appLayout.indexOf("to: '/hosting'")
    const item = appLayout.slice(index, index + 200)
    expect(item).toContain("t('nav.hosting')")
  })
})
