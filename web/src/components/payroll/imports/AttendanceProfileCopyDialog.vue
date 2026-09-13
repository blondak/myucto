<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SupplierBrief } from '@/api/auth'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  profileName: string
  suppliers: SupplierBrief[]
  busy: boolean
}>()

const emit = defineEmits<{
  close: []
  submit: [supplierId: number]
}>()

const { t } = useI18n()

const target = ref<number | null>(props.suppliers.length === 1 ? props.suppliers[0].id : null)
const blockedReason = computed(() => target.value === null ? t('payroll_imports.mapping.copy.no_target') : '')

function submit() {
  if (target.value !== null && !props.busy) emit('submit', target.value)
}
</script>

<template>
  <Modal :title="t('payroll_imports.mapping.copy.title')" width-class="max-w-lg" @close="emit('close')">
    <p class="text-sm text-neutral-600">{{ t('payroll_imports.mapping.copy.hint', { name: profileName }) }}</p>
    <label class="mt-4 block">
      <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.mapping.copy.target') }}</span>
      <select v-model="target" data-testid="attendance-profile-copy-target" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="busy">
        <option :value="null" disabled>{{ t('payroll_imports.mapping.copy.choose') }}</option>
        <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
          {{ supplier.company_name }}{{ supplier.ic ? ` · ${supplier.ic}` : '' }}
        </option>
      </select>
    </label>

    <template #footer>
      <div class="flex flex-wrap items-start justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="emit('close')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('payroll_imports.mapping.copy.cancel') }}
        </button>
        <div class="flex flex-col items-end gap-1.5">
          <button type="button" data-testid="attendance-profile-copy-submit" :class="btnFilled('primary')" :disabled="busy || blockedReason !== ''"
            :title="disabledTitle(blockedReason !== '', blockedReason)" @click="submit">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.copy" /></svg>
            {{ busy ? t('payroll_imports.common.working') : t('payroll_imports.mapping.copy.submit') }}
          </button>
          <p v-if="blockedReason" :class="BTN_DISABLED_NOTE">{{ blockedReason }}</p>
        </div>
      </div>
    </template>
  </Modal>
</template>
