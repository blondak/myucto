import { describe, expect, it } from 'vitest'
import { journalSourceLink } from '@/utils/journalSourceLink'

describe('journalSourceLink', () => {
  it('otevře ostatní pohledávku i závazek podle ID zdrojové položky', () => {
    expect(journalSourceLink({ source_type: 'other_item', source_id: 42 })).toEqual({
      name: 'other-item-detail', params: { id: 42 },
    })
    expect(journalSourceLink({ source_type: 'other_item', source_id: null, source_link_id: 43 })).toEqual({
      name: 'other-item-detail', params: { id: 43 },
    })
    expect(journalSourceLink({ source_type: 'other_item', source_id: null })).toBeNull()
  })
})
