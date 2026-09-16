<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'

defineProps<{ message: string }>()
const emit = defineEmits<{ confirm: []; cancel: [] }>()
const { t } = useI18n()
</script>

<template>
  <Modal
    :title="t('payroll.production_send.title')"
    width-class="max-w-md"
    @close="emit('cancel')"
  >
    <div data-test="production-send-confirm">
      <p class="text-sm text-neutral-900" data-test="production-send-confirm-message">
        {{ message }}
      </p>
      <p class="mt-2 text-xs text-neutral-600">
        {{ t('payroll.production_send.irreversible') }}
      </p>
    </div>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button
          type="button"
          :class="btnOutline('neutral')"
          data-test="production-send-confirm-no"
          @click="emit('cancel')"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          :class="btnFilled('danger')"
          data-test="production-send-confirm-yes"
          @click="emit('confirm')"
        >
          {{ t('payroll.production_send.confirm') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
