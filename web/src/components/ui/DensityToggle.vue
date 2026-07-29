<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'

const props = defineProps<{ ctrl: TablePrefsCtrl }>()
const { t } = useI18n()

const compact = computed(() => props.ctrl.density.value === 'compact')

function toggle() {
  props.ctrl.setDensity(compact.value ? 'comfortable' : 'compact')
}
</script>

<template>
  <!-- Wrapper drží fallthrough class z volajících (hidden md:block) — na buttonu by
       display utility přepsala inline-flex a ikona s textem se složily pod sebe. -->
  <div>
  <button
    type="button"
    @click="toggle"
    :aria-pressed="compact"
    :title="compact ? t('common.density_compact') : t('common.density_comfortable')"
    class="cursor-pointer shrink-0 whitespace-nowrap h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface text-sm text-neutral-700 hover:bg-neutral-50 transition-colors"
    :class="compact ? 'bg-primary-50 text-primary-700 border-primary-200' : ''"
  >
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <template v-if="compact">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
      </template>
      <template v-else>
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
      </template>
    </svg>
    <span class="hidden lg:inline">{{ t('common.density') }}</span>
  </button>
  </div>
</template>
