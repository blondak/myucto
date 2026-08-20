<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accountingApi, type JournalDocumentLink, type LinkCandidate } from '@/api/accounting'
import DocumentLinkPicker from '@/components/accounting/DocumentLinkPicker.vue'
import { ICONS, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import type { PermissionKey } from '@/security/permissions'

/**
 * Správa měkkých vazeb účetního zápisu na doklady (migrace 1514).
 *
 * Vazba je INFORMATIVNÍ: říká „tenhle interní zápis souvisí s tímhle dokladem"
 * (dohad, kurzový rozdíl, přeúčtování, oprava). Se `source_type`/`source_id`
 * zápisu nemá nic společného — ta dvojice znamená „zápis JE zaúčtování dokladu"
 * a drží na ní idempotence deníku, takže ruční zápis do ní sáhnout nesmí.
 *
 * Navázané doklady se objeví i v panelu „Souvisí", a to z OBOU stran — proto se
 * po každé změně emituje `changed`, ať si ho volající překreslí.
 */
const props = defineProps<{
  entryId: number
  /** Vazby z detailu zápisu — ušetří první request. */
  initialLinks?: JournalDocumentLink[] | null
}>()

const emit = defineEmits<{ (e: 'changed', links: JournalDocumentLink[]): void }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const links = ref<JournalDocumentLink[]>(props.initialLinks ?? [])
const loading = ref(false)
const saving = ref(false)
const adding = ref(false)
const note = ref('')

const canWrite = computed(() => auth.canWrite('accounting'))
const linkedKeys = computed(() => links.value.map(l => `${l.doc_type}:${l.doc_id}`))

// Vazby z detailu zápisu platí jen pro zápis, se kterým přišly — při přepnutí
// na jiný zápis se musí dotáhnout znovu. Když je detail nedodal (starší odpověď
// bez pole `links`), načtou se hned: prázdný seznam by jinak tvrdil „bez vazby".
onMounted(() => { if (!props.initialLinks) void load() })
watch(() => props.entryId, async (id, old) => {
  if (id === old) return
  await load()
})

async function load(): Promise<void> {
  loading.value = true
  try {
    links.value = await accountingApi.listJournalLinks(props.entryId)
  } catch {
    // Vazby jsou doplňková informace — jejich selhání nesmí shodit detail zápisu.
    links.value = []
  } finally {
    loading.value = false
  }
}

async function add(candidate: LinkCandidate): Promise<void> {
  if (blockDemoMutation()) return
  saving.value = true
  try {
    const res = await accountingApi.createJournalLink(props.entryId, {
      doc_type: candidate.doc_type,
      doc_id: candidate.doc_id,
      ...(note.value.trim() ? { note: note.value.trim() } : {}),
    })
    links.value = res.items
    note.value = ''
    toast.success(t('accounting.journal.links.added'))
    emit('changed', res.items)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

async function remove(link: JournalDocumentLink): Promise<void> {
  if (blockDemoMutation()) return
  saving.value = true
  try {
    const res = await accountingApi.deleteJournalLink(props.entryId, link.id)
    links.value = res.items
    emit('changed', res.items)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

function typeLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
}

function canOpen(link: JournalDocumentLink): boolean {
  const doc = link.document
  return !!doc?.route && auth.canRead((doc.permission || 'accounting') as PermissionKey)
}
</script>

<template>
  <div class="border-t border-neutral-200 pt-3">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
      <h4 class="text-xs font-medium text-neutral-500 inline-flex items-center gap-1.5">
        <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
        </svg>
        {{ t('accounting.journal.links.title') }}
        <span v-if="links.length" class="text-neutral-400">({{ links.length }})</span>
      </h4>
      <button v-if="canWrite" type="button" :class="btnOutline('primary')" :disabled="saving"
        @click="adding = !adding">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="adding ? ICONS.x : ICONS.plus" />
        </svg>
        <span class="whitespace-nowrap">
          {{ adding ? t('common.cancel') : t('accounting.journal.links.add') }}
        </span>
      </button>
    </div>

    <div v-if="adding" class="mb-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
      <p class="mb-2 text-xs text-neutral-500">{{ t('accounting.journal.links.hint') }}</p>
      <input v-model="note" type="text" maxlength="255"
        :placeholder="t('accounting.journal.links.note_placeholder')"
        class="mb-2 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
      <DocumentLinkPicker :excluded="linkedKeys" :disabled="saving" @select="add" />
    </div>

    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="!links.length" class="text-sm text-neutral-500">{{ t('accounting.journal.links.empty') }}</p>

    <ul v-else class="divide-y divide-neutral-200 rounded-lg border border-neutral-200">
      <li v-for="l in links" :key="l.id" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-3 py-2 text-sm">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-xs text-neutral-500">{{ typeLabel(l.doc_type) }}</span>
            <span class="font-medium">{{ l.document?.title || `#${l.doc_id}` }}</span>
            <span v-if="l.document?.date" class="text-xs text-neutral-500">{{ formatDate(l.document.date) }}</span>
            <!-- Doklad smazaný pod rukama (doc_id nemá FK) — vazbu ukazujeme dál,
                 ať ji uživatel vidí a může ji zrušit, ne aby tiše zmizela. -->
            <span v-if="!l.document" class="rounded bg-warning-50 px-1.5 py-0.5 text-xs font-medium text-warning-600">
              {{ t('accounting.journal.links.missing_document') }}
            </span>
          </div>
          <p v-if="l.document?.subtitle" class="mt-0.5 truncate text-xs text-neutral-500">{{ l.document.subtitle }}</p>
          <p v-if="l.document" class="mt-0.5 font-mono text-xs text-neutral-600">
            {{ formatMoney(l.document.amount ?? 0, l.document.currency || 'CZK') }}
          </p>
          <p v-if="l.note" class="mt-0.5 text-xs text-neutral-600 italic">{{ l.note }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-1.5">
          <RouterLink v-if="canOpen(l)"
            :to="{ name: l.document!.route!.name, params: l.document!.route!.params, query: l.document!.route!.query }"
            :class="btnOutlineSm('neutral')">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            {{ t('accounting.journal.related.open_document') }}
          </RouterLink>
          <button v-if="canWrite" type="button" :class="btnOutlineSm('danger')" :disabled="saving" @click="remove(l)">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
            </svg>
            {{ t('accounting.journal.links.remove') }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
