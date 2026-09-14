interface TotpEnrollment {
  token: string
  userId: number
  expiresAt: number
}

let enrollment: TotpEnrollment | null = null

export function rememberTotpEnrollment(token: string | null | undefined, userId?: number) {
  enrollment = token && userId
    ? { token, userId, expiresAt: Date.now() + 300_000 }
    : null
}

export function takeTotpEnrollment(userId?: number) {
  const current = enrollment
  enrollment = null
  return current && current.userId === userId && current.expiresAt > Date.now() ? current : null
}
