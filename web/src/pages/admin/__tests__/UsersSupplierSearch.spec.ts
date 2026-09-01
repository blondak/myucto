import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listUsers: vi.fn(),
  listUserSuppliers: vi.fn(),
  searchSuppliers: vi.fn(),
  listRoles: vi.fn(),
}))

vi.mock('@/api/admin', () => ({
  adminApi: {
    listUsers: m.listUsers,
    listUserSuppliers: m.listUserSuppliers,
    searchSuppliers: m.searchSuppliers,
  },
}))

vi.mock('@/api/roles', () => ({ rolesApi: { list: m.listRoles } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ newUserBlocked: null }) }))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { plus: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import Users from '../Users.vue'

const clientRole = {
  id: 2,
  system_key: null,
  name: 'Klient – Admin',
  role_type: 'client',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  default_usage: 1,
  override_usage: 0,
}

const user = {
  id: 7,
  email: 'klient@example.test',
  name: 'Testovací klient',
  role_id: clientRole.id,
  role: { id: clientRole.id, name: clientRole.name, type: 'client', system_key: null },
  locale: 'cs',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
  last_login_at: null,
}

describe('Uživatelé — vyhledání přiřazené firmy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listUsers.mockResolvedValue([user])
    m.listRoles.mockResolvedValue([clientRole])
    m.listUserSuppliers.mockResolvedValue([{
      supplier_id: 10,
      name: 'Přiřazená firma s.r.o.',
      ic: '12345678',
      role_id: null,
      effective_role: user.role,
    }])
    m.searchSuppliers.mockResolvedValue({
      data: [{ id: 20, name: 'Další firma s.r.o.', ic: '87654321' }],
      next_cursor: null,
    })
  })

  it('nabídku zobrazí až po focusu a po opuštění pole ji zase skryje', async () => {
    const wrapper = mount(Users)
    await flushPromises()

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    await flushPromises()

    const input = wrapper.get('input[placeholder="users.supplier_search"]')
    const result = () => wrapper.findAll('button').find(button => button.text().includes('Další firma s.r.o.'))

    expect(result()).toBeUndefined()
    expect(m.searchSuppliers).not.toHaveBeenCalled()

    await input.trigger('focus')
    await flushPromises()
    expect(result()).toBeDefined()
    expect(m.searchSuppliers).toHaveBeenCalledTimes(1)

    await input.trigger('blur')
    expect(result()).toBeUndefined()
  })
})
