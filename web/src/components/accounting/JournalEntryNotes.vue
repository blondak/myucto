<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { accountingApi, type JournalNote } from '@/api/accounting'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Poznámky k účetnímu zápisu (1:N).
 *
 * Načítá se LAZY až po rozbalení sekce (vzor JournalEntryHistory) — rozbalení
 * řádku deníku už dělá tři requesty, čtvrtý blokující by ho zbytečně zpomalil.
 *
 * Na rozdíl od `description` (§35) jdou poznámky psát u KAŽDÉHO zápisu včetně
 * těch generovaných ze zdrojového dokladu, kde je popis read-only — v tom je
 * celý smysl téhle featury.
 */
const props = defineProps<{ entryId: number }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const open = ref(false)
const loading = ref(false)
const loaded = ref(false)
const notes = ref<JournalNote[]>([])

const draft = ref('')
const draftPinned = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const editDraft = ref('')

const canWrite = computed(() => auth.canWrite('accounting'))
const MAX_LENGTH = 5000

async function toggle() {
  open.value = !open.value
  if (open.value && !loaded.value) await reload(true)
}

async function reload(withSpinner = false) {
  if (withSpinner) loading.value = true
  try {
    notes.value = await accountingApi.listJournalNotes(props.entryId)
    loaded.value = true
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function apiError(e: any) {
  const code = e?.response?.data?.error?.code
  const key = `accounting.journal.notes.error.${code}`
  const translated = code ? t(key) : null
  toast.error(translated && translated !== key ? translated : (e?.response?.data?.error?.message || t('common.error')))
}

async function addNote() {
  const body = draft.value.trim()
  if (!body || saving.value) return
  saving.value = true
  try {
    const created = await accountingApi.createJournalNote(props.entryId, body, draftPinned.value)
    // Připnuté patří nahoru — pořadí drží backend, tak po zápisu prostě přenačti.
    if (created.pinned) await reload()
    else notes.value = [...notes.value.filter(n => n.pinned), created, ...notes.value.filter(n => !n.pinned)]
    draft.value = ''
    draftPinned.value = false
    toast.success(t('common.saved'))
  } catch (e: any) {
    apiError(e)
  } finally {
    saving.value = false
  }
}

function startEdit(n: JournalNote) {
  editingId.value = n.id
  editDraft.value = n.body
}

function cancelEdit() {
  editingId.value = null
  editDraft.value = ''
}

async function saveEdit(n: JournalNote) {
  const body = editDraft.value.trim()
  if (!body || saving.value) return
  saving.value = true
  try {
    const updated = await accountingApi.updateJournalNote(props.entryId, n.id, { body })
    const i = notes.value.findIndex(x => x.id === n.id)
    if (i >= 0) notes.value[i] = updated
    cancelEdit()
    toast.success(t('common.saved'))
  } catch (e: any) {
    apiError(e)
  } finally {
    saving.value = false
  }
}

async function togglePin(n: JournalNote) {
  try {
    await accountingApi.updateJournalNote(props.entryId, n.id, { pinned: !n.pinned })
    await reload()
  } catch (e: any) {
    apiError(e)
  }
}

async function removeNote(n: JournalNote) {
  if (!confirm(t('accounting.journal.notes.delete_confirm'))) return
  try {
    await accountingApi.deleteJournalNote(props.entryId, n.id)
    notes.value = notes.value.filter(x => x.id !== n.id)
  } catch (e: any) {
    apiError(e)
  }
}

function metaLine(n: JournalNote): string {
  const who = n.created_by_name || t('accounting.journal.notes.unknown_user')
  const base = `${who} · ${formatDate(n.created_at)}`
  if (!n.updated_at) return base
  const editor = n.updated_by_name || t('accounting.journal.notes.unknown_user')
  return `${base} · ${t('accounting.journal.notes.edited', { user: editor, date: formatDate(n.updated_at) })}`
}
</script>

<template>
  <div class="border-t border-neutral-200 pt-3">
    <button type="button" class="cursor-pointer text-xs font-medium text-neutral-500 inline-flex items-center gap-1.5 hover:text-neutral-700"
      @click="toggle">
      <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
      </svg>
      {{ t('accounting.journal.notes.title') }}
      <span v-if="loaded && notes.length" class="rounded-full bg-neutral-200 px-1.5 text-[11px] text-neutral-700">{{ notes.length }}</span>
      <span class="inline-block transition-transform text-neutral-400" :class="{ 'rotate-90': open }">▸</span>
    </button>

    <div v-if="open" class="mt-3">
      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

      <template v-else>
        <p v-if="!notes.length" class="text-sm text-neutral-500">{{ t('accounting.journal.notes.empty') }}</p>

        <ul v-else class="space-y-2">
          <li v-for="n in notes" :key="n.id"
            class="group rounded-md border border-neutral-200 px-3 py-2"
            :class="n.pinned ? 'bg-warning-50 border-warning-200' : 'bg-surface'">
            <div v-if="editingId === n.id">
              <textarea v-model="editDraft" rows="3" :maxlength="MAX_LENGTH"
                class="w-full rounded-md border border-neutral-300 px-2 py-1 text-sm"></textarea>
              <div class="mt-2 flex gap-2">
                <button type="button" :class="btnFilled('primary')" :disabled="saving || !editDraft.trim()" @click="saveEdit(n)">
                  {{ t('common.save') }}
                </button>
                <button type="button" :class="btnOutline('neutral')" @click="cancelEdit">{{ t('common.cancel') }}</button>
              </div>
            </div>
            <div v-else>
              <div class="flex items-start justify-between gap-2">
                <p class="whitespace-pre-wrap text-sm text-neutral-800">{{ n.body }}</p>
                <div v-if="canWrite" class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                  <button type="button" class="cursor-pointer text-neutral-400 hover:text-warning-600"
                    :title="n.pinned ? t('accounting.journal.notes.unpin') : t('accounting.journal.notes.pin')"
                    @click="togglePin(n)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" />
                    </svg>
                  </button>
                  <button type="button" class="cursor-pointer text-neutral-400 hover:text-primary-600"
                    :title="t('common.edit')" @click="startEdit(n)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" />
                    </svg>
                  </button>
                  <button type="button" class="cursor-pointer text-neutral-400 hover:text-danger-600"
                    :title="t('common.delete')" @click="removeNote(n)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
                    </svg>
                  </button>
                </div>
              </div>
              <p class="mt-1 text-[11px] text-neutral-400">{{ metaLine(n) }}</p>
            </div>
          </li>
        </ul>

        <div v-if="canWrite" class="mt-3">
          <textarea v-model="draft" rows="2" :maxlength="MAX_LENGTH"
            :placeholder="t('accounting.journal.notes.placeholder')"
            class="w-full rounded-md border border-neutral-300 px-2 py-1 text-sm"></textarea>
          <div class="mt-2 flex items-center gap-3">
            <button type="button" :class="btnFilled('primary')" :disabled="saving || !draft.trim()" @click="addNote">
              {{ saving ? '…' : t('accounting.journal.notes.add') }}
            </button>
            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-neutral-500">
              <input v-model="draftPinned" type="checkbox" class="rounded border-neutral-300" />
              {{ t('accounting.journal.notes.pin') }}
            </label>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
