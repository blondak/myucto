<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accountingApi, type LinkCandidate, type LinkableDocType } from '@/api/accounting'
import { ICONS } from '@/components/ui/buttonStyles'

/**
 * Našeptávač dokladu k navázání na účetní zápis.
 *
 * Hledá napříč vydanými i přijatými fakturami, pokladními doklady a bankovními
 * pohyby (`GET /accounting/journal/link-candidates`). Sám nic neukládá — jen
 * ohlásí vybraný doklad; co se s ním stane, řeší volající (nový zápis si ho drží
 * lokálně, existující ho rovnou uloží).
 */
const props = withDefaults(defineProps<{
  /** Klíče `typ:id` už navázané — v nabídce se zobrazí jako neaktivní. */
  excluded?: string[]
  disabled?: boolean
  types?: LinkableDocType[]
}>(), { excluded: () => [], disabled: false, types: undefined })

const emit = defineEmits<{ (e: 'select', candidate: LinkCandidate): void }>()

const { t } = useI18n()

const query = ref('')
const results = ref<LinkCandidate[]>([])
const searching = ref(false)
const searched = ref(false)
const failed = ref(false)

// Server pod dva znaky nevrací nic; hlídáme to i tady, ať se na každé písmeno
// nestřílí dotaz, který stejně skončí prázdný.
const MIN_CHARS = 2
const DEBOUNCE_MS = 300
let timer: ReturnType<typeof setTimeout> | null = null

const excludedSet = computed(() => new Set(props.excluded))

function isLinked(c: LinkCandidate): boolean {
  return excludedSet.value.has(`${c.doc_type}:${c.doc_id}`)
}

function onInput(): void {
  if (timer) clearTimeout(timer)
  failed.value = false
  if (query.value.trim().length < MIN_CHARS) {
    results.value = []
    searched.value = false
    return
  }
  timer = setTimeout(() => { void search() }, DEBOUNCE_MS)
}

async function search(): Promise<void> {
  const q = query.value.trim()
  if (q.length < MIN_CHARS) return
  searching.value = true
  try {
    results.value = await accountingApi.searchLinkCandidates(q, props.types)
    searched.value = true
  } catch {
    results.value = []
    failed.value = true
  } finally {
    searching.value = false
  }
}

function pick(c: LinkCandidate): void {
  if (isLinked(c) || props.disabled) return
  emit('select', c)
  // Dotaz zůstává: účetní typicky naváže víc dokladů téhož partnera za sebou.
  // Nabídka se překreslí, právě vybraný doklad v ní zešedne jako „už navázaný".
}

function typeLabel(type: LinkableDocType): string {
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
}

onBeforeUnmount(() => { if (timer) clearTimeout(timer) })
</script>

<template>
  <div>
    <div class="relative">
      <input v-model="query" type="text" :disabled="disabled"
        :placeholder="t('accounting.journal.links.search_placeholder')"
        class="w-full h-9 pl-8 pr-3 border border-neutral-300 rounded-md text-sm bg-surface disabled:opacity-50"
        @input="onInput" @keydown.enter.prevent="search" />
      <svg class="w-4 h-4 absolute left-2.5 top-2.5 text-neutral-400" fill="none" viewBox="0 0 24 24"
        stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
      </svg>
    </div>

    <p v-if="searching" class="mt-1.5 text-xs text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="failed" class="mt-1.5 text-xs text-danger-500">{{ t('common.error') }}</p>
    <p v-else-if="searched && !results.length" class="mt-1.5 text-xs text-neutral-500">
      {{ t('accounting.journal.links.no_candidates') }}
    </p>

    <ul v-if="results.length" class="mt-1.5 max-h-64 overflow-y-auto divide-y divide-neutral-200 rounded-lg border border-neutral-200">
      <li v-for="c in results" :key="`${c.doc_type}:${c.doc_id}`">
        <button type="button" :disabled="isLinked(c) || disabled"
          class="w-full cursor-pointer px-3 py-2 text-left text-sm hover:bg-primary-50 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
          @click="pick(c)">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-xs text-neutral-500">{{ typeLabel(c.doc_type) }}</span>
            <span class="font-medium">{{ c.label }}</span>
            <span v-if="c.date" class="text-xs text-neutral-500">{{ formatDate(c.date) }}</span>
            <span class="ml-auto font-mono text-xs text-neutral-600">
              {{ formatMoney(c.amount ?? 0, c.currency || 'CZK') }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <p v-if="c.sublabel" class="mt-0.5 truncate text-xs text-neutral-500">{{ c.sublabel }}</p>
            <span v-if="isLinked(c)" class="mt-0.5 text-xs text-success-600">
              {{ t('accounting.journal.links.already_linked') }}
            </span>
          </div>
        </button>
      </li>
    </ul>
  </div>
</template>
