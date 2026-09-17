<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  PAYROLL_PERIOD_SCOPES,
  type PayrollPeriodScope,
} from '@/pages/payroll/payrollPeriodScope'

/**
 * Přepínač rozsahu období (Měsíc / Rok / Vše) u agend zúžených na jeden vztah.
 *
 * Why: mzdové agendy ukazují měsíc, protože tak se mzdy počítají. U jednoho
 * člověka se ale účetní ptá na historii, a klikat se k ní měsíc po měsíci je
 * to samé jako ji nemít. Přepínač se proto nabízí JEN při zúžení — nad celou
 * firmou by „vše" znamenalo vypsat roky práce všech zaměstnanců najednou.
 *
 * Proč segment a ne rozbalovací seznam: jsou tři volby a je potřeba na první
 * pohled vidět, ve kterém rozsahu se člověk pohybuje. Sbalený seznam stav
 * schová a čísla na stránce pak nikdo neumí zařadit.
 */
const props = withDefaults(defineProps<{
  disabled?: boolean
  /** Rok, kterého se volba „Rok" týká — bere se z vybraného období. */
  year?: string
}>(), {
  disabled: false,
  year: undefined,
})

const model = defineModel<PayrollPeriodScope>({ default: 'month' })

const { t } = useI18n()

const options = computed(() => PAYROLL_PERIOD_SCOPES.map(scope => ({
  value: scope,
  label: scope === 'year' && props.year !== undefined
    ? props.year
    : t(`payroll.agendas.scope.${scope}`),
  title: t(`payroll.agendas.scope.${scope}_hint`),
})))

const buttons = ref<HTMLButtonElement[]>([])

function setButtonRef(el: unknown, index: number): void {
  if (el instanceof HTMLButtonElement) buttons.value[index] = el
  else buttons.value.splice(index, 1)
}

function optionClass(value: PayrollPeriodScope): string {
  if (model.value === value) return 'bg-payroll-600 text-white shadow-sm'

  return props.disabled
    ? 'text-neutral-500'
    : 'text-neutral-600 hover:bg-neutral-200 hover:text-neutral-900'
}

function select(value: PayrollPeriodScope): void {
  if (props.disabled || model.value === value) return
  model.value = value
}

async function move(offset: number): Promise<void> {
  if (props.disabled) return
  const list = options.value
  const current = list.findIndex(option => option.value === model.value)
  const next = list[(current + offset + list.length) % list.length]
  if (next === undefined) return
  model.value = next.value
  await nextTick()
  buttons.value[list.indexOf(next)]?.focus()
}

async function onKeydown(event: KeyboardEvent): Promise<void> {
  const index = options.value.findIndex(option => option.value === model.value)
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault()
      await move(1)
      break
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault()
      await move(-1)
      break
    case 'Home':
      event.preventDefault()
      await move(-index)
      break
    case 'End':
      event.preventDefault()
      await move(options.value.length - 1 - index)
      break
  }
}
</script>

<template>
  <div
    role="radiogroup"
    :aria-label="t('payroll.agendas.scope.legend')"
    :aria-disabled="disabled || undefined"
    class="inline-flex items-center gap-0.5 rounded-lg border border-neutral-300 bg-neutral-100 p-0.5"
    :class="disabled ? 'opacity-60' : ''"
    data-test="payroll-scope-picker"
    @keydown="onKeydown"
  >
    <button
      v-for="(option, index) in options"
      :key="option.value"
      :ref="el => setButtonRef(el, index)"
      type="button"
      role="radio"
      :aria-checked="model === option.value"
      :tabindex="model === option.value ? 0 : -1"
      :disabled="disabled"
      :title="option.title"
      :data-test="`payroll-scope-${option.value}`"
      class="inline-flex h-8 items-center rounded-md px-3 text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:cursor-not-allowed"
      :class="[optionClass(option.value), disabled ? '' : 'cursor-pointer']"
      @click="select(option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
