import { codebooksApi, type Country } from '@/api/codebooks'

let cachedCountries: Country[] | null = null
let pendingCountries: Promise<Country[]> | null = null

export function loadCountries(): Promise<Country[]> {
  if (cachedCountries !== null) return Promise.resolve(cachedCountries)
  if (pendingCountries === null) {
    pendingCountries = codebooksApi.countries()
      .then((items) => {
        cachedCountries = items
        return items
      })
      .finally(() => {
        pendingCountries = null
      })
  }

  return pendingCountries
}
