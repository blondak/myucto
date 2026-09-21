import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  dimensionsApi,
  compactDimensions,
  type DimensionDocType,
  type DimensionMap,
  type DimensionPrefillParams,
} from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'

/**
 * Předvyplnění hlavičky výchozími dimenzemi (klient, zakázka, placený doklad).
 *
 * Doplní jen prázdné typy. Typ, který minulé předvyplnění vyplnilo a uživatel ho
 * nezměnil, se napřed uvolní — při změně klienta/zakázky se tak mění jen hodnoty
 * doplněné automaticky, nikdy volba uživatele.
 */
export function applyPrefill(
  header: DimensionMap,
  autoFilled: Record<number, number>,
  defaults: Record<number, number>,
): { header: DimensionMap; autoFilled: Record<number, number> } {
  const next: DimensionMap = { ...header }
  for (const [typeId, valueId] of Object.entries(autoFilled)) {
    if (next[Number(typeId)] === valueId) next[Number(typeId)] = null
  }
  const nextAuto: Record<number, number> = {}
  for (const [typeId, valueId] of Object.entries(defaults)) {
    const key = Number(typeId)
    if (!next[key] && valueId) {
      next[key] = valueId
      nextAuto[key] = valueId
    }
  }
  return { header: next, autoFilled: nextAuto }
}

/**
 * Dimenze dokladu v editoru (Firma → Dimenze): hlavička a položky.
 *
 * Dimenze položky visí na objektu položky (WeakMap), ne na jejím indexu — při
 * přesunu, přidání nebo smazání řádku jdou s ním. Do payloadu dokladu se nedostanou;
 * ukládají se zvlášť po úspěšném uložení dokladu, položky podle pořadí od 1.
 * Při vypnutých dimenzích nic nenačítá ani neukládá.
 */
export function useDocumentDimensions(docType: DimensionDocType) {
  const { t } = useI18n()
  const toast = useToast()
  const dims = useDimensions()

  const header = ref<DimensionMap>({})
  const itemDims = reactive(new WeakMap<object, DimensionMap>())
  /** Typy, které vyplnilo předvyplnění (typ → hodnota). */
  const autoFilled = ref<Record<number, number>>({})
  let prefillSeq = 0

  function itemDimsOf(item: object): DimensionMap {
    return itemDims.get(item) ?? {}
  }

  function setItemDims(item: object, map: DimensionMap) {
    itemDims.set(item, map)
  }

  async function load(docId: number, items: object[] = []): Promise<void> {
    if (!dims.enabled.value || docId <= 0) return
    try {
      await dims.load()
      const data = await dimensionsApi.getDocument(docType, docId)
      header.value = { ...data.header }
      autoFilled.value = {}
      items.forEach((item, i) => itemDims.set(item, { ...(data.items[i + 1] ?? {}) }))
    } catch {
      // Doklad se musí otevřít i bez dimenzí (např. bez práva na účetnictví).
    }
  }

  /**
   * Předvyplní prázdné typy hlavičky výchozími dimenzemi zakázky > klienta
   * (u platby dimenzemi placeného dokladu). Pozdější odpověď přebíjí dřívější.
   */
  async function applyDefaults(params: DimensionPrefillParams): Promise<void> {
    if (!dims.canEdit.value) return
    const seq = ++prefillSeq
    let defaults: Record<number, number> = {}
    if (Object.values(params).some(v => v != null && v > 0)) {
      try {
        defaults = (await dimensionsApi.prefill(params)).header
      } catch {
        return
      }
    }
    if (seq !== prefillSeq) return
    const result = applyPrefill(header.value, autoFilled.value, defaults)
    header.value = result.header
    autoFilled.value = result.autoFilled
  }

  /**
   * Hlídá klienta/zakázku editoru a při jejich změně předvyplní hlavičku.
   * `onActivate` = předvyplnit i v okamžiku, kdy editor dokončí načtení (nový
   * doklad s klientem z URL); u existujícího dokladu se hlavička při otevření nemění.
   */
  function watchDefaults(params: () => DimensionPrefillParams, active: () => boolean, onActivate: () => boolean) {
    watch(
      () => (active() ? JSON.stringify(params()) : null),
      (key, prev) => {
        if (key === null || (prev === null && !onActivate())) return
        void applyDefaults(params())
      },
    )
  }

  /** Snímek dimenzí položek v aktuálním pořadí — brát PŘED uložením dokladu. */
  function snapshot(items: object[]): Record<number, DimensionMap> {
    const out: Record<number, DimensionMap> = {}
    items.forEach((item, i) => {
      const map = compactDimensions(itemDimsOf(item))
      if (Object.keys(map).length > 0) out[i + 1] = map
    })
    return out
  }

  /**
   * Uloží dimenze po úspěšném uložení dokladu. Chyba doklad neshazuje — jen se ohlásí.
   */
  async function save(docId: number, items: Record<number, DimensionMap> = {}): Promise<void> {
    if (!dims.canEdit.value || docId <= 0) return
    try {
      const result = await dimensionsApi.saveDocument(docType, docId, { header: header.value, items })
      if (result.restamp.needs_repost) toast.warning(t('dimensions.needs_repost'))
    } catch (e: any) {
      toast.error(t('dimensions.save_failed', { message: e?.response?.data?.error?.message || e?.message || '' }))
    }
  }

  /** Aspoň jedna hodnota hlavičky pochází z předvyplnění a uživatel ji nezměnil. */
  const hasAutoFilled = computed(() =>
    Object.entries(autoFilled.value).some(([typeId, valueId]) => header.value[Number(typeId)] === valueId))

  return {
    enabled: dims.enabled,
    canEdit: dims.canEdit,
    header,
    autoFilled,
    hasAutoFilled,
    itemDimsOf,
    setItemDims,
    load,
    applyDefaults,
    watchDefaults,
    snapshot,
    save,
  }
}
