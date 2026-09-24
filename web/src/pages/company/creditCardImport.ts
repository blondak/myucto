import { creditCardsApi, type CreditCardImportResult } from '@/api/creditCards'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'

/**
 * Načtení PDF výpisu kreditní karty - sdílené přehledem i detailem úvěrového účtu.
 *
 * Když import odmítne účet, který firma vede jako BĚŽNÝ bankovní účet s historií
 * (`account_is_bank_account`), nabídne převod na kreditní kartu a po něm načte výpis znovu.
 */
export async function importCreditCardStatement(
  file: File,
  creditCardAccountId: number | null,
  confirmConvert: (message: string) => boolean,
): Promise<{ result: CreditCardImportResult; reposted: number | null }> {
  try {
    return { result: await creditCardsApi.importStatement(file, creditCardAccountId), reposted: null }
  } catch (e: any) {
    const bankAccountId = Number(e?.response?.data?.error?.bank_account_id ?? 0)
    if (apiErrorCode(e) !== 'account_is_bank_account' || bankAccountId <= 0 || !confirmConvert(apiErrorMessage(e))) {
      throw e
    }
    const converted = await creditCardsApi.convert(bankAccountId)
    return { result: await creditCardsApi.importStatement(file, creditCardAccountId), reposted: converted.reposted }
  }
}
