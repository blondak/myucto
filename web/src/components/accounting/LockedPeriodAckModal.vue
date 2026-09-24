<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

/**
 * Varování před smazáním v uzamčeném období (typicky po podání přiznání k DPH).
 * Server odpoví 409 `date_locked` s `can_acknowledge`; volající zavolá `ask()`
 * a při potvrzení zopakuje požadavek s `ack_locked=1`. Potvrdit jde jen se
 * zaškrtnutou odpovědností účetního.
 */
const { t } = useI18n()

const open = ref(false)
const message = ref('')
const accepted = ref(false)
let resolver: ((ok: boolean) => void) | null = null

function ask(serverMessage: string): Promise<boolean> {
  message.value = serverMessage
  accepted.value = false
  open.value = true
  return new Promise(resolve => { resolver = resolve })
}

function finish(ok: boolean) {
  open.value = false
  resolver?.(ok)
  resolver = null
}

defineExpose({ ask })
</script>

<template>
  <Modal v-if="open" :title="t('accounting.locked_ack.title')" widthClass="max-w-lg" @close="finish(false)">
    <div class="rounded-md border border-danger-500/40 bg-danger-50 p-4 text-sm text-danger-700">
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94 1.82 18a1.875 1.875 0 0 0 1.6 2.82h17.16a1.875 1.875 0 0 0 1.6-2.82L13.66 3.94a1.875 1.875 0 0 0-3.32 0Z" />
        </svg>
        <div class="space-y-2">
          <p class="font-semibold">{{ t('accounting.locked_ack.headline') }}</p>
          <p>{{ message }}</p>
          <p>{{ t('accounting.locked_ack.vat_hint') }}</p>
        </div>
      </div>
    </div>
    <label class="mt-4 flex items-start gap-2 text-sm text-neutral-800">
      <input v-model="accepted" type="checkbox" class="mt-0.5 rounded border-neutral-300" data-test="locked-ack-checkbox" />
      <span>{{ t('accounting.locked_ack.responsibility') }}</span>
    </label>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="finish(false)">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="[btnFilled('danger'), 'whitespace-nowrap']" :disabled="!accepted"
          data-test="locked-ack-confirm" @click="finish(true)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('accounting.locked_ack.confirm') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
