// Číselník kódů platebního styku ČR (kódy bank dle ČNB) → název banky.
// Slouží k automatickému doplnění názvu banky podle zadaného 4místného kódu
// v setupu (Setup.vue) i ve správě bankovních účtů (BankAccounts.vue).
//
// Zdroj: registr kódů platebního styku ČNB. Seznam pokrývá aktivní i běžné
// historické kódy; u zaniklých/převzatých bank je v závorce poznámka. Platné
// kódy drží backend v api/resources/ciselniky/kody_bank_CR.csv (registr ČNB,
// aktualizace cmd/download-bank-codes); že jsou tu všechny, hlídá vitest.

export const CZ_BANK_CODES: Record<string, string> = {
  '0100': 'Komerční banka',
  '0300': 'ČSOB',
  '0600': 'MONETA Money Bank',
  '0710': 'Česká národní banka',
  '0800': 'Česká spořitelna',
  '2010': 'Fio banka',
  '2020': 'MUFG Bank (Europe)',
  '2060': 'Citfin, spořitelní družstvo',
  '2070': 'Trinity Bank',
  '2100': 'ČSOB Hypoteční banka',
  '2200': 'Peněžní dům, spořitelní družstvo',
  '2220': 'Artesa, spořitelní družstvo',
  '2250': 'Banka CREDITAS',
  '2260': 'NEY spořitelní družstvo',
  '2275': 'Citfin',
  '2600': 'Citibank Europe',
  '2700': 'UniCredit Bank Czech Republic and Slovakia',
  '3030': 'Air Bank',
  '3050': 'BNP Paribas Personal Finance (Hello bank!)',
  '3060': 'PKO BP',
  '3500': 'ING Bank',
  '4000': 'Max banka',
  '4300': 'Národní rozvojová banka',
  '5500': 'Raiffeisenbank',
  '5800': 'J&T Banka',
  '6000': 'PPF banka',
  '6100': 'Equa bank (převzato Raiffeisenbank)',
  '6200': 'COMMERZBANK',
  '6210': 'mBank',
  '6300': 'BNP Paribas Fortis',
  '6363': 'Partners Banka',
  '6600': 'Banking Circle',
  '6700': 'Všeobecná úverová banka (VÚB)',
  '6800': 'Sberbank CZ (zaniklá)',
  '7910': 'Deutsche Bank',
  '7950': 'Raiffeisen stavební spořitelna',
  '7960': 'ČSOB Stavební spořitelna',
  '7970': 'MONETA Stavební Spořitelna',
  '7990': 'Modrá pyramida stavební spořitelna',
  '8030': 'Volksbank Raiffeisenbank',
  '8040': 'Oberbank AG',
  '8060': 'Stavební spořitelna České spořitelny (Buřinka)',
  '8090': 'Česká exportní banka',
  '8150': 'HSBC Continental Europe',
  '8190': 'Sparkasse Oberlausitz-Niederschlesien',
  '8198': 'FAS finance company',
  '8200': 'PRIVAT BANK der Raiffeisenlandesbank Oberösterreich',
  '8220': 'Payment execution',
  '8230': 'ERB bank (EEPAYS)',
  '8240': 'Družstevní záložna Kredit',
  '8250': 'Bank of China',
  '8255': 'Bank of Communications',
  '8265': 'Industrial and Commercial Bank of China (ICBC)',
  '8270': 'Fairplay Pay',
  '8280': 'B-Efficient',
  '8293': 'Mesa Money',
  '8299': 'BESTPAY',
  '8500': 'Multitude Bank',
  '8610': 'Devizová burza',
  '8620': 'Comgate',
  '8660': 'PAYMONT',
}

/**
 * Normalizuje uživatelský vstup kódu banky na 4místný tvar (doplní vedoucí nuly).
 * Vrací prázdný řetězec, pokud vstup neobsahuje žádnou číslici.
 */
export function normalizeBankCode(raw: string | null | undefined): string {
  const digits = (raw || '').replace(/\D/g, '')
  if (!digits) return ''
  return digits.slice(0, 4).padStart(4, '0')
}

/**
 * Vrátí název banky podle kódu, nebo null pokud kód není v číselníku.
 */
export function bankNameByCode(raw: string | null | undefined): string | null {
  const code = normalizeBankCode(raw)
  return CZ_BANK_CODES[code] ?? null
}

/**
 * True pokud zadaný název banky pochází z číselníku (tj. nebyl ručně přepsán
 * uživatelem). Používá se k bezpečnému přepsání auto-doplněného názvu při
 * změně kódu, bez ztráty ručně zadaného textu.
 */
export function isKnownBankName(name: string | null | undefined): boolean {
  const n = (name || '').trim()
  if (!n) return false
  return Object.values(CZ_BANK_CODES).includes(n)
}
