import { describe, expect, it, vi } from 'vitest'
import { announceApprovalRequested, missingApprovalTypes } from '../purchaseApproval'
import { approvalErrorMessage, isApprovalNoApprover } from '@/api/purchaseApprovals'
import type { DimensionType, DimensionValue } from '@/api/dimensions'

const t = (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key

function type(id: number, over: Partial<DimensionType> = {}): DimensionType {
  return {
    id, supplier_id: 1, supplier_group_id: null, level: 'company', code: `T${id}`, name: `Typ ${id}`,
    kind: 'cost_center', is_active: true, show_on_documents: true, sort_order: 1, ...over,
  }
}

function value(id: number, typeId: number): DimensionValue {
  return {
    id, type_id: typeId, supplier_id: 1, supplier_group_id: null, level: 'company', parent_id: null,
    code: `V${id}`, name: `Hodnota ${id}`, is_active: true, responsible_user_id: null, responsible_note: null,
    car_id: null, project_id: null, cost_center_id: null, note: null, sort_order: 1,
  }
}

describe('announceApprovalRequested', () => {
  it('při approval_requested ukáže toast „odesláno ke schválení" a vrátí true', () => {
    const toast = { info: vi.fn() }
    expect(announceApprovalRequested({ approval_requested: true }, toast, t)).toBe(true)
    expect(toast.info).toHaveBeenCalledWith('purchase_approval.toast.requested')
  })

  it('bez příznaku nic neukazuje a vrátí false', () => {
    const toast = { info: vi.fn() }
    expect(announceApprovalRequested({}, toast, t)).toBe(false)
    expect(announceApprovalRequested({ approval_requested: false }, toast, t)).toBe(false)
    expect(announceApprovalRequested(null, toast, t)).toBe(false)
    expect(toast.info).not.toHaveBeenCalled()
  })
})

describe('approvalErrorMessage', () => {
  const noApprover = (error: Record<string, unknown>) => ({ response: { data: { error: { code: 'approval_no_approver', message: 'x', ...error } } } })

  it('approval_no_approver se jménem střediska z dimension_value', () => {
    const err = noApprover({ dimension_value: { id: 3, name: 'Provoz' } })
    expect(isApprovalNoApprover(err)).toBe(true)
    expect(approvalErrorMessage(err, t)).toBe('purchase_approval.no_approver:{"name":"Provoz"}')
  })

  it('approval_no_approver bez jména spadne na obecnou hlášku', () => {
    expect(approvalErrorMessage(noApprover({}), t)).toBe('purchase_approval.no_approver_generic')
  })

  it('ostatní chyby jdou přes běžnou hlášku serveru', () => {
    const err = { response: { data: { error: { code: 'forbidden', message: 'Zakázáno' } } } }
    expect(isApprovalNoApprover(err)).toBe(false)
    expect(approvalErrorMessage(err, t)).toBe('Zakázáno')
  })
})

describe('missingApprovalTypes', () => {
  const types = [type(1, { requires_approval: true }), type(2), type(3, { requires_approval: true, is_active: false })]

  it('vrací jen aktivní typy se schvalováním, které v hlavičce nemají hodnotu', () => {
    expect(missingApprovalTypes(types, {}).map(ty => ty.id)).toEqual([1])
  })

  it('vyplněná hlavička nebo hodnota na položce chybu zruší', () => {
    expect(missingApprovalTypes(types, { 1: 10 })).toEqual([])
    expect(missingApprovalTypes(types, {}, [11], [value(11, 1)])).toEqual([])
    expect(missingApprovalTypes(types, {}, [12], [value(12, 2)]).map(ty => ty.id)).toEqual([1])
  })
})
