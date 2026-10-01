import { computed, inject, onBeforeUnmount, provide, ref, shallowRef, type ComputedRef, type InjectionKey, type Ref } from 'vue'

/**
 * Jedno společné Uložit pro celou kartu osoby.
 *
 * Karta skládá dohromady panely, které se ukládají každý jiným endpointem
 * (běžné údaje, zákonná evidence, osobní evidence, podmínky vztahů). Dřív měl
 * každý vlastní tlačítko a kdo upravil dvě sekce a klikl jen na jedno Uložit,
 * přišel o druhou změnu bez varování. Panely se proto na kartě jen přihlásí:
 * řeknou, zda mají neuložené změny a jak se uloží, a karta pod sebou drží
 * jednu lištu, která je vyjmenuje a uloží postupně.
 *
 * Mimo kartu (samostatný panel, testy) registr neexistuje a panel si nechává
 * vlastní tlačítko — `managed` pak vrací false.
 */
export interface PersonCardSaveSection {
  /** Pro dotaz „má zrovna TAHLE sekce rozdělanou práci" (sbalení panelu). */
  key?: string
  /** Název sekce v liště „Neuložené změny: …". */
  label: () => string
  dirty: () => boolean
  /** Uloží sekci; false = uložení neprošlo a chybu ukazuje panel sám. */
  save: () => Promise<boolean>
  discard: () => void
  /** Doskočení na sekci, u které se ukládání zastavilo. */
  focus?: () => void
}

export interface PersonCardSaveRegistry {
  register: (section: PersonCardSaveSection) => () => void
  dirtySections: ComputedRef<PersonCardSaveSection[]>
  hasChanges: ComputedRef<boolean>
  isDirty: (key: string) => boolean
  saving: Ref<boolean>
  /** Sekce, u které se poslední společné uložení zastavilo. */
  failedSection: Ref<PersonCardSaveSection | null>
  saveAll: () => Promise<boolean>
  discardAll: () => void
}

const KEY: InjectionKey<PersonCardSaveRegistry> = Symbol('personCardSave')

export interface PersonCardSaveOptions {
  /**
   * Ohlášení sekce, u které se společné uložení zastavilo. Patří k uložení,
   * ne k tlačítku: ať ho spustí lišta karty, nebo lišta pod rozpracovanou
   * sekcí, uživatel dostane stejnou zprávu.
   */
  onStopped?: (section: PersonCardSaveSection) => void
}

export function createPersonCardSaveRegistry(options: PersonCardSaveOptions = {}): PersonCardSaveRegistry {
  const sections = shallowRef<PersonCardSaveSection[]>([])
  const saving = ref(false)
  const failedSection = shallowRef<PersonCardSaveSection | null>(null)
  const dirtySections = computed(() => sections.value.filter(section => section.dirty()))
  const hasChanges = computed(() => dirtySections.value.length > 0)

  function register(section: PersonCardSaveSection): () => void {
    sections.value = [...sections.value, section]
    return () => {
      sections.value = sections.value.filter(item => item !== section)
      if (failedSection.value === section) failedSection.value = null
    }
  }

  /**
   * Postupně, ne paralelně: podmínky vztahu i osobní evidence nesou
   * `row_version` a souběžné zápisy by se navzájem shodily na konflikt verzí.
   * U první neúspěšné sekce se zastaví — zbytek zůstává rozepsaný a lišta
   * ho dál jmenuje, takže nic nezmizí.
   */
  async function saveAll(): Promise<boolean> {
    if (saving.value) return false
    saving.value = true
    failedSection.value = null
    try {
      for (const section of [...dirtySections.value]) {
        if (!section.dirty()) continue
        const ok = await section.save()
        if (!ok) {
          failedSection.value = section
          section.focus?.()
          options.onStopped?.(section)
          return false
        }
      }
      return true
    } finally {
      saving.value = false
    }
  }

  function discardAll() {
    for (const section of [...dirtySections.value]) section.discard()
    failedSection.value = null
  }

  function isDirty(key: string): boolean {
    return dirtySections.value.some(section => section.key === key)
  }

  return { register, dirtySections, hasChanges, isDirty, saving, failedSection, saveAll, discardAll }
}

export function providePersonCardSave(options: PersonCardSaveOptions = {}): PersonCardSaveRegistry {
  const registry = createPersonCardSaveRegistry(options)
  provide(KEY, registry)
  return registry
}

/**
 * Přihlášení panelu ke společnému Uložit. Vrací `managed` — když je true,
 * panel své vlastní Uložit nekreslí. `saveAll` je totéž uložení, jaké spouští
 * lišta karty; panel ho smí nabídnout i u rozpracované sekce, ale nesmí
 * vymýšlet vlastní.
 */
export function usePersonCardSaveSection(
  section: PersonCardSaveSection,
): { managed: boolean; saveAll?: () => Promise<boolean> } {
  const registry = inject(KEY, null)
  if (registry === null) return { managed: false }
  const unregister = registry.register(section)
  onBeforeUnmount(unregister)
  return { managed: true, saveAll: () => registry.saveAll() }
}
