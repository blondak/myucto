<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { archiveApi, type ArchiveItem } from '@/api/closing'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const toast = useToast()

const items = ref<ArchiveItem[]>([])
const loading = ref(false)
const creating = ref(false)
const downloadingId = ref<number | null>(null)

async function load() {
  loading.value = true
  try { items.value = await archiveApi.list() }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { loading.value = false }
}
onMounted(load)

async function createArchive() {
  creating.value = true
  try {
    await archiveApi.export()
    toast.success(t('accounting.closing.archive.created'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    creating.value = false
  }
}

async function download(item: ArchiveItem) {
  downloadingId.value = item.id
  try {
    const r = await archiveApi.download(item.id)
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = item.filename
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    downloadingId.value = null
  }
}

async function remove(item: ArchiveItem) {
  if (!confirm(t('accounting.closing.archive.delete_confirm', { file: item.filename }))) return
  try {
    await archiveApi.remove(item.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'kB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div v-if="!embedded">
        <h1 class="text-2xl font-semibold">{{ t('accounting.closing.archive.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.closing.archive.subtitle') }}</p>
      </div>
      <button @click="createArchive" :disabled="creating" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
        {{ creating ? t('accounting.closing.archive.creating') : t('accounting.closing.archive.create') }}
      </button>
    </div>

    <div class="bg-primary-50/50 border border-primary-100 rounded-lg p-3 mb-4 text-sm text-neutral-600">
      {{ t('accounting.closing.archive.info') }}
      <span class="block text-xs text-neutral-500 mt-1">{{ t('accounting.closing.archive.restore_note') }}</span>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="items.length === 0" boxed icon="archive"
      :title="t('accounting.closing.archive.empty')"
      :cta="t('accounting.closing.archive.create')" @action="createArchive" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.archive.col_created') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.archive.col_file') }}</th>
              <th class="px-3 py-2 text-right font-medium w-28">{{ t('accounting.closing.archive.col_size') }}</th>
              <th class="px-3 py-2 text-left font-medium w-40">SHA-256</th>
              <th class="px-3 py-2 text-right font-medium w-44"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in items" :key="item.id">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(item.created_at) }}</td>
              <td class="px-3 py-2 font-mono text-xs break-all">{{ item.filename }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatBytes(item.size_bytes) }}</td>
              <td class="px-3 py-2 font-mono text-xs" :title="item.sha256">{{ item.sha256.slice(0, 12) }}…</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button @click="download(item)" :disabled="downloadingId === item.id"
                  class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium disabled:opacity-40">
                  {{ t('accounting.closing.archive.download') }}
                </button>
                <button @click="remove(item)"
                  class="cursor-pointer text-xs text-danger-500 hover:text-danger-600 font-medium ml-3">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
