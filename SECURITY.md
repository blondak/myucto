# Security Policy

## Reporting a Vulnerability

Pokud objevíš bezpečnostní zranitelnost v MyÚčto.cz, **prosíme nezveřejňuj
ji v public GitHub Issues**. Místo toho ji nahlaš přímo:

- **Web:** [https://mywebdesign.cz/](https://mywebdesign.cz/) — kontaktní formulář
  s předmětem `[SECURITY] MyÚčto.cz`
- **Předmět e-mailu:** `[SECURITY] <stručný popis>`

### Co nahlásit

- SQL injection / XSS / CSRF
- Authentication bypass (session, JWT, brute-force lockout obejití)
- Privilege escalation (accountant → admin)
- Cross-supplier data leak (čtení / zápis cizího dodavatele)
- Information disclosure (email enumeration, timing attacks)
- Denial of Service umožňující delší výpadek
- Citlivé údaje v logu / cache (hesla, secret keys, tokens)

### Co očekávat

- **Potvrzení přijetí** do **48 hodin** (pracovní dny)
- **Initial assessment** (severity, exploitability) do **5 pracovních dní**
- **Fix nebo mitigaci** dle severity:
  - **Critical** (full compromise, data leak): patch do 7 dní
  - **High** (privilege escalation, bypass): patch do 14 dní
  - **Medium** (info disclosure, DoS): patch do 30 dní
  - **Low** (best-practice): součástí příští minor release

### Coordinated Disclosure

Pokud nahlásíš v dobré víře a dáš nám čas na fix, **nepublikuj detaily před
release patche**. Po vydání jsi vítaný v `CHANGELOG.md` jako reportér
(opt-in, můžeš zůstat v anonymitě).

### Bug Bounty

Komerční bug bounty zatím nemáme. Pro kvalitní reporty a kritické nálezy
nabízíme uvedení v `CHANGELOG.md` + `SECURITY-HALL-OF-FAME.md` (chystá se).

## Supported Versions

Patche dostává **nejnovější minor release** větve:

| Verze | Status |
|-------|--------|
| 1.x   | ✅ Aktivně udržovaná |
| < 1.0 | ❌ Pre-release, bez podpory |
