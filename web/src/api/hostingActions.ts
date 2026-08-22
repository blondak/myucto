/**
 * Co je s provozem instalace k řešení — jako položky do seznamu úkolů.
 *
 * Stejný seznam čte dashboard („Akce pro tebe", nahoře a barevně) i postranní
 * menu (tečka u položky Hosting). Kdyby si každý počítal svoje, jedno by
 * svítilo a druhé mlčelo — a uživatel by nevěděl, kterému věřit.
 *
 * Vrací i18n KLÍČE a syrové hodnoty, ne hotový text: formátování data i překlad
 * patří komponentě, rozhodování sem. Díky tomu jde pravidlo otestovat bez i18n.
 *
 * ── ⚠️ Tři pravidla, na kterých to stojí ──────────────────────────────────
 *
 *  1. **Self-hosted nedostane nic.** Když v odpovědi chybí blok `instance`,
 *     vrací se prázdný seznam a na dashboardu se neobjeví ani řádek. Kvótu ani
 *     předplatné tam nikdo nespravuje.
 *  2. **Zaplacené rozšíření NENÍ výzva k nákupu.** Dokud se zavádí
 *     (`change_pending`), je to informace — nikdy pobídka koupit něco, co už
 *     zákazník zaplatil.
 *  3. **Nezměřené místo mlčí.** `measured: false` nebo `percent: null` = žádná
 *     položka. Procenta se nedopočítávají (viz {@link resolveStorageLevel}).
 */

import type { ManagedInstanceInfo } from './license'
import { resolveBillingNarrative, resolveStorageLevel } from './instanceHealth'

/**
 * Priorita položky.
 *  - `high` — červená, něco už nejde nebo brzy nepůjde.
 *  - `medium` — jantarová, řeš to, ale nehoří.
 *  - `info` — jen oznámení; ŽÁDNÁ výzva k akci.
 */
export type HostingActionSeverity = 'high' | 'medium' | 'info'

export type HostingActionKind =
  | 'unpaid'
  | 'storage_exhausted'
  | 'storage_low'
  | 'storage_provisioning'

export interface HostingAction {
  kind: HostingActionKind
  severity: HostingActionSeverity
  /** i18n klíč nadpisu. */
  titleKey: string
  /** i18n klíč popisu; `*_nodate` varianta znamená, že termín NEZNÁME. */
  hintKey: string
  /** Termín k popisu (unix); `null` ⇒ v textu žádné datum nebude. */
  at: number | null
  /** Obsazení v procentech, když ho známe. */
  percent: number | null
  /** Objednaný objem u zaváděného rozšíření. */
  quotaGb: number | null
  /** Kam vede proklik — tam, kde se to řeší. */
  link: string
}

const LINK_BILLING = '/hosting#platba'
const LINK_STORAGE = '/hosting#misto'

/**
 * Sestaví seznam. Prázdný seznam = není co řešit, a pak se nikde nic nekreslí.
 *
 * ⚠️ Pořadí je součást zadání: platba je vždy první. Když nejde zaplatit,
 * je jedno, kolik zbývá místa.
 */
export function resolveHostingActions(instance: ManagedInstanceInfo | null | undefined): HostingAction[] {
  // Self-hosted / neznámý stav → ani řádek.
  if (!instance) return []

  const actions: HostingAction[] = []

  // ── Platba ───────────────────────────────────────────────────────────────
  const narrative = resolveBillingNarrative(instance.billing)
  if (narrative) {
    actions.push({
      kind: 'unpaid',
      // Neuhrazená platba i pozastavení mají nejvyšší prioritu. Zrušená obnova
      // (zaplaceno, jen to doběhne) je vážná, ale nehoří.
      severity: instance.billing.unpaid || narrative.severity === 'critical' ? 'high' : 'medium',
      titleKey: narrative.happenedKey,
      hintKey: narrative.nextKey,
      at: narrative.nextAt,
      percent: null,
      quotaGb: null,
      link: LINK_BILLING,
    })
  }

  // ── Místo ────────────────────────────────────────────────────────────────
  const storage = instance.storage
  const level = resolveStorageLevel(storage)
  const pending = storage.change_pending === true

  if (level === 'exhausted') {
    actions.push({
      kind: 'storage_exhausted',
      severity: 'high',
      titleKey: 'hosting.action.storage_exhausted_title',
      // Když je rozšíření už zaplacené, nesmí popis pobízet ke koupi.
      hintKey: pending ? 'hosting.action.storage_exhausted_pending_hint' : 'hosting.action.storage_exhausted_hint',
      at: null,
      percent: storage.percent,
      quotaGb: storage.quota_gb_ordered,
      link: LINK_STORAGE,
    })
  } else if (pending) {
    // ⚠️ Zaplaceno — informace, ne výzva. Druhé kliknutí by strhlo podruhé.
    actions.push({
      kind: 'storage_provisioning',
      severity: 'info',
      titleKey: 'hosting.action.storage_provisioning_title',
      hintKey: storage.quota_gb_ordered ? 'hosting.action.storage_provisioning_hint' : 'hosting.action.storage_provisioning_hint_nogb',
      at: null,
      percent: storage.percent,
      quotaGb: storage.quota_gb_ordered,
      link: LINK_STORAGE,
    })
  } else if (level === 'notice' || level === 'warning') {
    actions.push({
      kind: 'storage_low',
      severity: 'medium',
      titleKey: 'hosting.action.storage_low_title',
      hintKey: 'hosting.action.storage_low_hint',
      at: null,
      percent: storage.percent,
      quotaGb: null,
      link: LINK_STORAGE,
    })
  }

  return actions
}

/**
 * Odstín tečky u položky Hosting v menu.
 *
 * `null` = nic k řešení; položka zůstane jen barevně odlišená a NEKŘIČÍ.
 * Oznámení (`info`) tečku nedostane — zaváděné rozšíření není úkol.
 */
export function hostingNavAttention(actions: HostingAction[]): 'danger' | 'warning' | null {
  if (actions.some((a) => a.severity === 'high')) return 'danger'
  if (actions.some((a) => a.severity === 'medium')) return 'warning'

  return null
}
