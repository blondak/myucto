import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { dimensionsApi, compactDimensions, type DimensionDocType, type DimensionMap } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'

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
      items.forEach((item, i) => itemDims.set(item, { ...(data.items[i + 1] ?? {}) }))
    } catch {
      // Doklad se musí otevřít i bez dimenzí (např. bez práva na účetnictví).
    }
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

  return { enabled: dims.enabled, canEdit: dims.canEdit, header, itemDimsOf, setItemDims, load, snapshot, save }
}
