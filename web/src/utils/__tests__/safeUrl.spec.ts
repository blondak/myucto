import { describe, it, expect } from 'vitest'
import { safeExternalUrl, SAFE_URL_MAX_LENGTH } from '@/utils/safeUrl'

// SEC-10 — hodnota se renderuje do href, takže projde jen absolutní http(s) URL.
// PHP zrcadlo: api/tests/Unit/Security/SafeUrlTest.php — stejné případy, obě
// implementace musí zůstat 1:1.

// Řídicí znaky skládáme přes fromCharCode, ať se nedostanou doslova do zdrojáku.
const TAB = String.fromCharCode(9)
const LF = String.fromCharCode(10)
const CR = String.fromCharCode(13)
const NUL = String.fromCharCode(0)

describe('safeExternalUrl — platné adresy', () => {
  it.each([
    ['https projde', 'https://example.com', 'https://example.com'],
    ['http projde včetně query', 'http://example.com/a?b=C#d', 'http://example.com/a?b=C#d'],
    ['schéma se sníží, cesta ne', 'HTTPS://Example.com/Path', 'https://Example.com/Path'],
    ['bez schématu doplní https', 'example.com', 'https://example.com'],
    ['okrajové mezery se ořežou', '  https://example.com  ', 'https://example.com'],
    ['punycode IDN', 'https://xn--mjdomna-l1ab.cz', 'https://xn--mjdomna-l1ab.cz'],
    ['IDN v UTF-8 zůstává', 'https://mojedoména.cz', 'https://mojedoména.cz'],
    ['port projde', 'https://example.com:8443/x', 'https://example.com:8443/x'],
    ['localhost je výjimka', 'http://localhost:5173', 'http://localhost:5173'],
  ])('%s', (_label, input, expected) => {
    expect(safeExternalUrl(input)).toBe(expected)
  })
})

describe('safeExternalUrl — nebezpečné adresy vrací null', () => {
  it.each([
    // aktivní obsah — jádro nálezu
    ['javascript', 'javascript:alert(1)'],
    ['javascript mixed-case', 'JaVaScRiPt:alert(1)'],
    ['javascript s tabulátorem', `java${TAB}script:alert(1)`],
    ['javascript s newline', `java${LF}script:alert(1)`],
    ['javascript s CR', `java${CR}script:alert(1)`],
    ['javascript s NUL uprostřed', `java${NUL}script:alert(1)`],
    ['vedoucí NUL', `${NUL}javascript:alert(1)`],
    ['data URI', 'data:text/html,<script>alert(1)</script>'],
    ['data URI base64', 'data:text/html;base64,PHNjcmlwdD4='],
    ['vbscript', 'vbscript:msgbox(1)'],
    ['file', 'file:///c:/windows/win.ini'],
    // URL-encoded / entity varianty schématu se nesmí "uzdravit"
    ['procentem kódované schéma', '%6aavascript:alert(1)'],
    ['HTML entita ve schématu', '&#106;avascript:alert(1)'],
    // relativní a protokolově-relativní
    ['protokolově relativní', '//evil.com'],
    ['relativní cesta', '/relative'],
    ['relativní bez lomítka', 'javascript'],
    // userinfo mate o cílové doméně
    ['userinfo s heslem', 'https://user:pass@evil.com'],
    ['userinfo bez hesla', 'https://duveryhodna.cz@evil.com'],
    ['zpětné lomítko v autoritě', 'https://evil.com\\@duveryhodna.cz'],
    // degenerované vstupy
    ['prázdný host', 'https://'],
    ['prázdný řetězec', ''],
    ['jen mezery', '   '],
    ['mezera uvnitř', 'https://example.com/a b'],
  ])('%s', (_label, input) => {
    expect(safeExternalUrl(input)).toBeNull()
  })

  it('null i undefined vrací null', () => {
    expect(safeExternalUrl(null)).toBeNull()
    expect(safeExternalUrl(undefined)).toBeNull()
  })

  it('adresa delší než sloupec neprojde', () => {
    // Doplnění "https://" nesmí přetéct VARCHAR(255) (db/migrations/1028_eshop.sql:60)
    const host = `${'a'.repeat(248)}.com` // 252 znaků; + "https://" = 260
    expect(safeExternalUrl(host)).toBeNull()
    expect((safeExternalUrl('example.com') as string).length).toBeLessThanOrEqual(SAFE_URL_MAX_LENGTH)
  })
})
