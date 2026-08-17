# feat(portal): přidej vlastní domény firem

## Shrnutí

- přidává tenantové vlastní domény pro klientský portál, veřejné odkazy nebo oba
  účely, včetně aliasů a samostatné primární domény pro každý účel;
- zachovává `app.url` jako canonical fallback pro firmy bez aktivní domény a pro
  interní odkazy;
- přidává správu domén do nastavení firmy, DNS TXT + HTTPS ověření, MFA step-up
  při aktivaci, audit změn a okamžitou invalidaci cache;
- generuje odkazy na faktury, schvalování, pracovní výkazy a požadavky na doklady
  přes doménu příslušné firmy.

## Izolace a bezpečnost

- aktivní hostname je autoritativní tenantová hranice a nelze jej přepsat přes
  `X-Supplier-Id`, query parametr ani tenant-bound API token;
- neznámé a neaktivní hosty odmítá společná politika pro API i SPA fallback;
- veřejný token na vlastní doméně funguje pouze pro firmu daného hostname,
  canonical odkazy zůstávají zpětně kompatibilní;
- CSRF kontrola porovnává přesný origin aktuálního bezpečně rozpoznaného hostu;
- ověření domény vyžaduje DNS challenge i HTTPS endpoint s důvěryhodným TLS a
  chráněným outbound requestem;
- first-run setup zůstává dostupný přes LAN hostname, ale tato výjimka končí
  vytvořením prvního administrátora.

## Passkeys a přihlášení

WebAuthn ceremonie zůstává na canonical `app.url`, takže se nemění RP ID ani
platnost existujících passkeys. Přechod na vlastní doménu používá krátkodobý
jednorázový autorizační kód svázaný s PKCE, state, uživatelem, firmou a přesným
hostname. Session token se nepřenáší v URL; cílová doména vystaví novou host-only
cookie. Tok kontroluje aktuální membership, expiraci, replay, změnu hostu i
odvolání použité passkey. Stejný centrální tok obsluhuje login, povinné MFA a
odemčení session.

## Databáze, API a provoz

- nová idempotentní migrace `1403_supplier_domains.sql`;
- nový browserový read-only kontrakt `/api/v1/auth/domain-context` a session-only
  přehled `/api/v1/settings/domains` v OpenAPI;
- shodný tenant-aware SPA fallback pro Apache, IIS a Docker nginx;
- aktualizovaný český manuál pro portál, nastavení, Docker/reverse proxy a
  bezpečnost/passkeys;
- nové texty mají českou i anglickou lokalizaci.

## Ověření

- [x] čistá MariaDB 11.8: všechny migrace včetně `1403`, následný běh bez pending
  migrací;
- [x] integrační doménové/passkey testy: 4 testy, 50 assertions;
- [x] cílené tenant/security unit testy: 41 testů, 76 assertions;
- [x] Architecture suite: 188 testů, 4 823 assertions, 16 environmentálních skipů;
- [x] OpenAPI strict parse bez duplicitních klíčů a dangling `$ref`: 583 paths,
  1 788 refs;
- [x] `npm run build` včetně i18n, rout, manuálových odkazů, `vue-tsc` a Vite;
- [x] router guard Vitest: 7 testů;
- [x] HTTP smoke: canonical API/SPA `200`, neznámý API/SPA host `421`;
- [x] syntaxe IIS `web.config` a Docker nginx konfigurace;
- [x] PHP lint všech 55 změněných nebo nových PHP souborů;
- [x] regenerace HTML manuálu: 81 kapitol.

Kompletní frontendový Vitest proběhl se 601 úspěšnými testy. Runner současně
hlásí 15 již existujících unhandled errors v nezměněném `PeopleList.spec.ts`, kde
mock neobsahuje `payrollApi.capabilities`; produkční build i cílené testy této
změny jsou zelené.

Closes #11
