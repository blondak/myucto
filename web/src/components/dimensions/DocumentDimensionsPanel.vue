<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionFields from './DimensionFields.vue'
import { dimensionsApi, compactDimensions, type DimensionDocType, type DimensionMap, type DimensionPrefillParams } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { applyPrefill } from '@/composables/useDocumentDimensions'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

/**
 * Dimenze hlavičky dokladu na jeho detailu (bankovní pohyb, pokladní doklad, faktura).
 * Uložení promítne dimenze i do už zaúčtovaných řádků dokladu — mění se jen
 * analytika, proto to jde i u dokladu v uzavřeném období.
 *
 * `prefill` (spárovaná faktura / klient) doplní prázdné typy jako návrh — uloží
 * se až tlačítkem, doklad se sám nemění.
 */
const props = withDefaults(defineProps<{
  docType: DimensionDocType
  docId: number
  readonly?: boolean
  /** Bez rámečku a nadpisu (vložení do existující karty). */
  bare?: boolean
  prefill?: DimensionPrefillParams | null
}>(), { readonly: false, bare: false, prefill: null })

const emit = defineEmits<{ saved: [header: DimensionMap] }>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()

const header = ref<DimensionMap>({})
const saved = ref<string>('{}')
const loading = ref(false)
const saving = ref(false)
const needsRepost = ref(false)
const autoFilled = ref<Record<number, number>>({})

const dirty = computed(() => JSON.stringify(compactDimensions(header.value)) !== saved.value)
const editable = computed(() => !props.readonly && dims.canEdit.value)
const hasAutoFilled = computed(() =>
  Object.entries(autoFilled.value).some(([typeId, valueId]) => header.value[Number(typeId)] === valueId))

async function load() {
  if (!dims.enabled.value || props.docId <= 0) return
  loading.value = true
  try {
    await dims.load()
    const data = await dimensionsApi.getDocument(props.docType, props.docId)
    header.value = { ...data.header }
    saved.value = JSON.stringify(compactDimensions(data.header))
    autoFilled.value = {}
  } catch {
    header.value = {}
  } finally {
    loading.value = false
  }
  await applyPrefillParams()
}

let prefillSeq = 0
async function applyPrefillParams() {
  if (!editable.value || !dims.enabled.value) return
  const params = props.prefill ?? {}
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

async function save() {
  saving.value = true
  try {
    const result = await dimensionsApi.saveDocument(props.docType, props.docId, { header: header.value })
    saved.value = JSON.stringify(compactDimensions(result.header))
    autoFilled.value = {}
    needsRepost.value = result.restamp.needs_repost
    toast.success(result.restamp.lines > 0
      ? t('dimensions.saved_restamped', { count: result.restamp.lines })
      : t('dimensions.saved'))
    emit('saved', result.header)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
watch(() => [props.docType, props.docId], load)
watch(() => JSON.stringify(props.prefill ?? {}), () => { if (!loading.value) void applyPrefillParams() })
</script>

<template>
  <section v-if="dims.enabled.value && dims.documentTypes.value.length > 0"
           :class="bare ? '' : 'bg-surface border border-neutral-200 rounded-lg shadow-sm p-4'"
           data-test="document-dimensions">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dimensions.panel_title') }}</h3>
      <button v-if="editable && dirty" type="button" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('dimensions.save') }}
      </button>
    </div>
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <DimensionFields v-else v-model="header" :disabled="!editable" />
    <p v-if="!loading && hasAutoFilled" class="mt-2 text-xs text-neutral-500" data-test="dimension-autofilled">{{ t('dimensions.defaults.autofilled') }}</p>
    <p v-if="needsRepost" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-800">
      {{ t('dimensions.needs_repost') }}
    </p>
  </section>
</template>
