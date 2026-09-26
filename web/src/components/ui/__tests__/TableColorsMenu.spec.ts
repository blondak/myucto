import { mount } from '@vue/test-utils'
import { computed, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import TableColorsMenu from '@/components/ui/TableColorsMenu.vue'
import type { TablePrefsCtrl } from '@/composables/useTablePrefs'
import type { TableColorsCtrl } from '@/composables/useTableColors'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: { column: string }) => params ? `${key}: ${params.column}` : key }) }))

function setup(ready = true) {
  const ctrl = {
    ready: ref(ready),
    columns: [
      { key: 'amount', labelKey: 'amount', required: true },
      { key: 'due', labelKey: 'due', defaultHidden: true },
      { key: 'dimensions', labelKey: 'dimensions', available: () => false },
    ],
  } as unknown as TablePrefsCtrl
  const colors = {
    color: (key: string) => key === 'amount' ? '#123456' : undefined,
    cellStyle: (key: string) => key === 'amount' ? { '--cell-bg': '#123456', '--cell-fg': '#ffffff' } : undefined,
    setColor: vi.fn(), reset: vi.fn(), hasColors: computed(() => true),
  } as TableColorsCtrl
  return { wrapper: mount(TableColorsMenu, { props: { ctrl, colors } }), colors }
}

describe('TableColorsMenu', () => {
  it('lets users color required and hidden columns, reset one or all, and close with Escape', async () => {
    const { wrapper, colors } = setup()
    await wrapper.get('button').trigger('click')
    expect(wrapper.findAll('input[type=color]')).toHaveLength(2)
    const input = wrapper.get('input[aria-label="common.item_color_for: due"]')
    await input.setValue('#abcdef')
    expect(colors.setColor).toHaveBeenCalledWith('due', '#abcdef')
    expect(wrapper.get('[data-custom-color="true"]').attributes('style')).toContain('--cell-fg: #ffffff')
    await wrapper.get('button[aria-label="common.item_color_reset: amount"]').trigger('click')
    expect(colors.setColor).toHaveBeenCalledWith('amount', null)
    expect(wrapper.get('button[aria-label="common.item_color_reset: due"]').attributes('disabled')).toBeDefined()
    await wrapper.findAll('button').at(-1)!.trigger('click')
    expect(colors.reset).toHaveBeenCalledOnce()
    await input.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('input[type=color]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('waits for existing preferences before allowing edits', () => {
    const { wrapper } = setup(false)
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
