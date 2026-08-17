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
stav uživatele. Stejný centrální tok obsluhuje login, povinné MFA a odemčení
session.

## Databáze, API a provoz

- idempotentní migrace `1405_supplier_domains.sql` a kompatibilní převod
  staršího jediného primárního příznaku v
  `1406_supplier_domain_primary_flags.sql`;
- nový browserový read-only kontrakt `/api/v1/auth/domain-context` a session-only
  přehled `/api/v1/settings/domains` v OpenAPI;
- shodný tenant-aware SPA fallback pro Apache, IIS a Docker nginx;
- aktualizovaný český manuál pro portál, nastavení, Docker/reverse proxy a
  bezpečnost/passkeys;
- nové texty mají českou i anglickou lokalizaci.

## Shoda s FR a známé mezery

Pro běžné tenantové uživatele implementace pokrývá všech 15 akceptačních
scénářů z #11. Při doslovném výkladu požadavku na membership je pokryto
14/15, protože se zachovává existující globální oprávnění systémového
superadmina. Před sloučením je třeba akceptovat následující odchylky a
provozní hranice:

| Oblast FR | Stav v tomto PR | Rozdíl a dopad |
| --- | --- | --- |
| Membership na vlastní doméně | Částečná odchylka | Non-superadmin bez membershipu dostane `403`. Systémový superadmin je doménou zamčen na její firmu, ale membership nepotřebuje. Striktní požadavek FR by vyžadoval odebrat tuto globální výjimku i v domain-login toku. |
| Branding přiřazené firmy | Částečně | Veřejné faktury, schvalování a výkazy používají existující branding a portál zobrazuje název firmy. Přihlášený portál ale zatím nepřebírá firemní logo a accent do celého aplikačního layoutu. |
| Vystavení a obnova TLS | Provozní hranice | Aplikace ověřuje DNS TXT, routing a důvěryhodné HTTPS před aktivací. Certifikát ale nevystavuje a neposkytuje automatický allowlist/control-plane reverznímu proxy; provisioning zůstává na správci dle manuálu. |
| Platnost ověření domény | Navazující hardening | Aktivace vyžaduje uložený stav `verified`, ale nevyžaduje novou kontrolu bezprostředně před aktivací a aktivní domény se periodicky nerevalidují. |
| Odmítnutí neznámého hostu | Tenantová data splněna | API a SPA entrypoint vracejí `421`; webserver může přímo vydat statické assety bez tenantových dat. Absolutní odmítnutí každého souboru by vyžadovalo přísnější pravidla přímo v proxy. |
| Reset hesla | Záměrně canonical | FR jej uvádí jako volitelný. Tento PR jej nechává na `app.url`, aby nevznikl další pre-auth tok a reset fungoval i po deaktivaci domény. |
| Plný síťový E2E test | Testovací mezera | Jednotlivé guardy, repository a login flow mají unit/integration testy a host policy má HTTP smoke. Chybí jeden automatizovaný scénář se dvěma firmami a doménami přes celý HTTP stack a deterministický test DNS/TLS verifieru. |

Ostatní rozhodnutí otevřená ve FR jsou v PR uzavřena takto: domény mohou
sloužit portálu, veřejným odkazům nebo oběma účelům, firma může mít aliasy,
canonical portál zůstává funkční, interní práce účetního zůstává na
`app.url` a přihlášení na vlastní doménu používá centrální passkey tok.

## Ověření

- [x] čistá MariaDB 11.8: všechny migrace včetně `1405` a `1406`,
  následný běh bez pending
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
