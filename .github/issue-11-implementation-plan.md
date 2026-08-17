# Issue #11 — vlastní domény klientských portálů

## Cíl

Přidat volitelné domény jednotlivých firem tak, aby firma bez vlastní
domény nadále používala globální `app.url`, zatímco aktivní doména
autoritativně určí tenant klientského portálu nebo veřejného odkazu.
Implementace musí zabránit přepsání tenantového kontextu hlavičkou,
query parametrem nebo API tokenem a zachovat plnou podporu passkeys.

## Rozhodnutí

- Datový model podporuje více aliasů firmy a jednu primární doménu pro
  každý účel `portal` a `public_links`; hodnota `all` pokrývá oba účely.
- Hostname se ukládá pouze jako normalizované ASCII/IDNA bez schématu,
  portu, koncové tečky nebo wildcardu a je globálně unikátní.
- Globální `app.url` je canonical origin a zůstává zpětně kompatibilní
  pro portál i všechny existující veřejné tokeny.
- Vlastní doména zamkne `supplier_id`; konfliktní `X-Supplier-Id`,
  `supplier_id` v query nebo tenant-bound PAT se odmítne.
- Session cookie zůstává host-only. Přihlášení přes passkey proběhne na
  canonical WebAuthn originu a na cílovou doménu se předá jednorázový,
  krátkodobý autorizační kód svázaný s PKCE, uživatelem, tenantem a
  přesným cílovým hostnamem. Skutečný session token se do URL nedává.
- DNS a TLS zajišťuje reverse proxy. Aplikace aktivuje doménu až po
  ověření DNS challenge a HTTPS dostupnosti; neověřený host nesmí obsloužit
  tenantová data.
- Reset hesla a interní odkazy účetní zůstávají na canonical `app.url`.

## Implementační kroky

1. **Databáze a doménové jádro**
   - idempotentní migrace `1405_supplier_domains.sql` pro domény a
     jednorázové domain-login requesty/kódy a kompatibilní převod původního
     jediného primárního příznaku v `1406_supplier_domain_primary_flags.sql`;
   - DB unikátnost hostname a primární domény pro každý účel;
   - `HostnameNormalizer`, `SupplierDomainRepository`, cache invalidace a
     `TenantUrlResolver` bez závislosti na aktuální hlavičce `Host`.
2. **Request context a izolace tenanta**
   - `TenantDomainMiddleware` před supplier scope a CSRF;
   - canonical, active custom, pending-verification a unknown-host režimy;
   - zapojení forced supplieru do `SupplierAccessResolver` včetně membershipu;
   - přesná origin kontrola v CSRF a 404 při nesouladu veřejného tokenu.
3. **Správa, ověření a audit**
   - tenant-scoped CRUD, rotace challenge, kontrola DNS/TLS, aktivace a
     deaktivace;
   - samostatné oprávnění, MFA step-up pro aktivaci a audit všech změn;
   - okamžitá cache invalidace při aktivaci/deaktivaci.
4. **Passkey SSO**
   - start na vlastní doméně vytvoří opaque login request s PKCE challenge;
   - canonical login použije stávající password/TOTP/passkey implementaci;
   - po ověření membershipu vydá jednorázový kód s krátkou expirací;
   - callback na přesné cílové doméně atomicky spotřebuje kód a vystaví
     novou host-only session; replay, jiný host, tenant nebo PKCE selže.
5. **URL a veřejné odkazy**
   - faktury, schvalování, pracovní výkazy a klientské požadavky použijí
     centrální resolver;
   - canonical odkazy zůstanou platné, custom doména smí zobrazit jen token
     stejné firmy.
6. **Frontend a branding**
   - `domain_context` v pre-auth i `/auth/me` kontraktu;
   - locked supplier store bez přepínače a bez stale localStorage hodnoty;
   - login redirect/callback pro centrální passkey tok;
   - sekce Klientské domény v nastavení firmy, stav ověření, DNS/TLS
     instrukce, účel, aktivace a výsledné URL;
   - existující název, logo a accent firmy na klientské doméně.
7. **Provoz, kontrakty a dokumentace**
   - OpenAPI, české i anglické i18n, manuál;
   - shodný SPA/host fallback pro Apache, IIS a Docker nginx;
   - dokumentace reverse proxy, DNS, TLS, Turnstile hostname allowlistu a
     centrálního passkey toku.

## Povinné regrese

- Firma bez domény se chová beze změny.
- Unknown nebo disabled hostname je odmítnut.
- Domain supplier nelze přepsat headerem, query ani PAT a membership se stále
  ověřuje.
- CSRF přijme pouze přesný canonical nebo custom origin requestu.
- Token firmy A na doméně firmy B vrátí 404; canonical odkaz zůstane funkční.
- Aktivace bez dokončeného DNS/TLS ověření selže a deaktivace se projeví
  okamžitě.
- Passkey SSO projde pouze pro oprávněného uživatele, přesný host a správný
  PKCE verifier; kód je jednorázový a po expiraci neplatný.
- Migrace projde na čisté MariaDB 11.8 i opakovaně, backendové testy,
  Architecture/Invariants, Vitest a produkční frontend build jsou zelené.
