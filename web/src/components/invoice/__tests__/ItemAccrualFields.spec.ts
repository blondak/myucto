import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => `T:${key}` }),
}))

import ItemAccrualFields from '@/components/invoice/ItemAccrualFields.vue'

const SUBSCRIPTION = 'Předplatné (ročně), období 28. 9. 2026 – 28. 9. 2027'

describe('ItemAccrualFields — časové rozlišení výnosu na řádku', () => {
  it('nabídne období z textu položky a vyplní ho až po kliknutí', async () => {
    const wrapper = mount(ItemAccrualFields, { props: { from: null, to: null, description: SUBSCRIPTION } })

    expect(wrapper.find('[data-test="accrual-suggestion"]').exists()).toBe(true)
    expect(wrapper.emitted('update:from')).toBeUndefined()

    await wrapper.find('[data-test="accrual-suggestion-apply"]').trigger('click')

    expect(wrapper.emitted('update:from')?.[0]).toEqual(['2026-09-28'])
    expect(wrapper.emitted('update:to')?.[0]).toEqual(['2027-09-28'])
  })

  it('u vyplněného období ani po zamítnutí návrh nezobrazí', async () => {
    const filled = mount(ItemAccrualFields, { props: { from: '2026-09-28', to: '2027-09-28', description: SUBSCRIPTION } })
    expect(filled.find('[data-test="accrual-suggestion"]').exists()).toBe(false)

    const empty = mount(ItemAccrualFields, { props: { from: null, to: null, description: SUBSCRIPTION } })
    await empty.find('[data-test="accrual-suggestion-dismiss"]').trigger('click')
    expect(empty.find('[data-test="accrual-suggestion"]').exists()).toBe(false)
  })

  it('odkaz „zrušit rozlišení" obě data vymaže', async () => {
    const wrapper = mount(ItemAccrualFields, { props: { from: '2026-09-28', to: '2027-09-28', description: 'Služba' } })
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('update:from')?.[0]).toEqual([null])
    expect(wrapper.emitted('update:to')?.[0]).toEqual([null])
  })
})
