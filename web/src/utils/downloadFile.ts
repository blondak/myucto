import { api } from '@/api/client'

function responseFilename(disposition: string | undefined, fallback: string): string {
  const utf8 = disposition?.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  if (utf8) {
    try {
      return decodeURIComponent(utf8)
    } catch {
      return utf8
    }
  }
  return disposition?.match(/filename="?([^";]+)"?/i)?.[1] ?? fallback
}

export async function downloadApiFile(url: string, fallbackFilename = 'export.xml'): Promise<void> {
  const requestUrl = url.startsWith('/api/') ? url.slice(4) : url
  const response = await api.get<Blob>(requestUrl, { responseType: 'blob' })
  const objectUrl = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = responseFilename(response.headers['content-disposition'], fallbackFilename)
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}
