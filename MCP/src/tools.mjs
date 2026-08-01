/**
 * Katalog MCP nástrojů nad veřejným API MyÚčta.
 *
 * ZÁMĚRNÝ ROZSAH: fakturace (vč. vystavování), přehled zaplacených a nezaplacených
 * dokladů, statistika a reporting, daně a účetnictví ke čtení, e-shop se skladem.
 *
 * ÚČETNÍ A DAŇOVÁ VRSTVA JE JEDNOSMĚRNÁ — jen čtení, nikdy zápis. Odhad DPH,
 * obratovka, rozvaha, výsledovka i saldo jsou přesně ta čísla, kvůli kterým se
 * integrace staví, takže je nabízíme. Ale zaúčtování, storno zápisu, uzavření
 * období, zaevidování opravy podle § 46 / § 74b ani odeslání podání na EPO
 * v katalogu nejsou a nikdy nesmí být: jsou to úkony s daňovou odpovědností,
 * kde chyba znamená opravné podání, a model nemá jak doložit jejich správnost.
 * Vynucuje to i server ({@see \MyInvoice\Middleware\ApiScopeMiddleware}), takže
 * i kdyby sem někdo zápisový nástroj přidal, dostane 403.
 *
 * Každý nástroj: { name, title, description, inputSchema, write, run(client, args) }
 * `write: true` = mění data; klient je odmítne v režimu MYUCTO_READ_ONLY.
 */

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const date = (description) => ({ type: 'string', format: 'date', description });

const schema = (properties = {}, required = []) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});

const PAGING = {
  page: int('Stránka, od 1.', { minimum: 1 }),
  per_page: int('Počet záznamů na stránku (5–200, výchozí 50).', { minimum: 5, maximum: 200 }),
};

// ────────────────────────────────────────────────────────────────────────────
// Pomocné funkce pro výkazy práce
// ────────────────────────────────────────────────────────────────────────────

const rows = (payload) => {
  if (Array.isArray(payload)) return payload;
  return payload?.data ?? payload?.items ?? payload?.projects ?? [];
};

/**
 * Hodinová sazba pro řádek výkazu: zakázka → odběratel → výchozí sazba firmy.
 *
 * Stejné pořadí, v jakém se sazba dědí v aplikaci. Nulová hodnota se bere jako
 * „nevyplněno" a jde se o úroveň výš — jinak by prázdná sazba na zakázce tiše
 * vyrobila výkaz za nula korun.
 */
async function resolveHourlyRate(client, { projectId, clientId }, tool) {
  const source = [];

  if (projectId) {
    const project = await client.get(`/projects/${projectId}`, null, tool);
    const rate = Number(project?.hourly_rate ?? 0);
    if (rate > 0) return { rate, from: `zakázka „${project.name ?? projectId}"` };
    source.push('zakázka nemá sazbu');
  }
  if (clientId) {
    const c = await client.get(`/clients/${clientId}`, null, tool);
    const rate = Number(c?.hourly_rate ?? 0);
    if (rate > 0) return { rate, from: `odběratel „${c.company_name ?? clientId}"` };
    source.push('odběratel nemá sazbu');
  }

  const supplier = await client.get('/settings/supplier', null, tool);
  const rate = Number(supplier?.default_hourly_rate ?? 0);
  if (rate > 0) return { rate, from: 'výchozí sazba firmy' };

  throw new Error(
    'Nepodařilo se určit hodinovou sazbu ('
    + [...source, 'firma nemá výchozí sazbu'].join(', ')
    + '). Zadejte ji parametrem `rate`, nebo ji doplňte na zakázce.',
  );
}

/**
 * Najde koncept faktury, do jehož výkazu se má zapisovat.
 *
 * Když uživatel jmenuje jen zakázku nebo odběratele („výkaz pro AVYX"), musíme
 * doklad dohledat. Při víc než jednom konceptu se ZÁMĚRNĚ nehádá — vrátí se
 * seznam kandidátů a rozhodne uživatel. Zapsat hodiny na cizí doklad je horší
 * než se doptat.
 */
async function resolveDraftInvoice(client, args, tool) {
  if (args.invoice_id) {
    return { invoiceId: Number(args.invoice_id) };
  }

  const needle = String(args.project ?? args.client ?? '').trim();
  if (needle === '') {
    throw new Error('Zadejte `invoice_id`, nebo název zakázky (`project`) či odběratele (`client`).');
  }

  let projectId = null;
  let clientId = null;

  if (args.project) {
    const found = rows(await client.get('/projects', { per_page: 200 }, tool))
      .filter((p) => String(p.name ?? '').toLowerCase().includes(needle.toLowerCase()));
    if (found.length === 0) throw new Error(`Zakázka „${needle}" nenalezena.`);
    if (found.length > 1) {
      throw new Error(
        `Názvu „${needle}" odpovídá víc zakázek: `
        + found.map((p) => `${p.name} (id ${p.id})`).join(', ')
        + '. Upřesněte prosím, o kterou jde.',
      );
    }
    projectId = found[0].id;
    clientId = found[0].client_id ?? null;
  } else {
    const found = rows(await client.get('/clients', { q: needle, per_page: 50 }, tool));
    if (found.length === 0) throw new Error(`Odběratel „${needle}" nenalezen.`);
    if (found.length > 1) {
      throw new Error(
        `Názvu „${needle}" odpovídá víc odběratelů: `
        + found.map((c) => `${c.company_name} (id ${c.id})`).join(', ')
        + '. Upřesněte prosím, o kterého jde.',
      );
    }
    clientId = found[0].id;
  }

  // Výkaz jde uložit jen do konceptu — vystavený doklad je uzamčený.
  const grouped = await client.get('/invoices', {
    per_page: 200,
    filter: { status: 'draft', client_id: clientId, project_id: projectId },
  }, tool);

  const drafts = rows(grouped).flatMap((g) => g.invoices ?? [g]);
  if (drafts.length === 0) {
    throw new Error(
      `Pro „${needle}" není žádný koncept faktury, do kterého by šlo výkaz zapsat. `
      + 'Založte koncept v aplikaci, nebo použijte `create_invoice`.',
    );
  }
  if (drafts.length > 1) {
    throw new Error(
      `Konceptů je víc: `
      + drafts.map((i) => `#${i.id}${i.varsymbol ? ` (${i.varsymbol})` : ''}`).join(', ')
      + '. Zadejte prosím `invoice_id`.',
    );
  }

  return { invoiceId: Number(drafts[0].id), projectId, clientId };
}

/** Název výkazu podle období dokladu — stejný tvar, jaký nabízí aplikace. */
function defaultReportTitle(invoice) {
  const period = String(invoice?.tax_date || invoice?.issue_date || '').slice(0, 7);
  return period ? `Výkaz práce ${period}` : 'Výkaz práce';
}

export const TOOLS = [
  // ──────────────────────────────────────────────────────────────────────────
  // Diagnostika
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'whoami',
    title: 'Ověření připojení',
    description:
      'Ověří API token a vrátí, pod jakým uživatelem, rolí a firmou (supplier) se volá '
      + 'a jaký má token rozsah (čtení / čtení a zápis). Volej jako první, když si nejsi '
      + 'jistý připojením nebo když jiný nástroj vrátí chybu oprávnění.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/auth/api-me', null, tool),
  },
  {
    name: 'list_suppliers',
    title: 'Seznam firem',
    description:
      'Firmy (supplier), ke kterým má token přístup. Když je token vázaný na jednu firmu, '
      + 'vrátí jen ji. ID použij v proměnné MYUCTO_SUPPLIER_ID, pokud chceš pracovat s jinou firmou.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/suppliers', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Odběratelé
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search_clients',
    title: 'Hledat odběratele',
    description:
      'Najde odběratele/dodavatele podle názvu, IČO nebo e-mailu. Používej k získání '
      + '`client_id` před vystavením faktury.',
    inputSchema: schema({
      query: str('Hledaný text — název firmy, IČO, e-mail.'),
      role: str('Filtr na roli.', { enum: ['all', 'customers', 'vendors'] }),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/clients', {
      q: a.query, role: a.role, page: a.page, per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_client',
    title: 'Detail odběratele',
    description: 'Kompletní karta odběratele včetně fakturačních údajů a splatnosti.',
    inputSchema: schema({ id: int('ID odběratele.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/clients/${a.id}`, null, tool),
  },
  {
    name: 'lookup_company_in_ares',
    title: 'Vyhledat firmu v ARES',
    description:
      'Načte údaje firmy z rejstříku ARES podle IČO — název, adresu, DIČ, právní formu '
      + 'a registraci k DPH. Nic neukládá, jen vrací data.\n\n'
      + 'Používej k ověření údajů před založením odběratele; `create_client` si ale '
      + 'ARES zavolá sám, když mu dáš IČO.\n\n'
      + 'Pozn.: endpoint je v API řešený jako POST, takže i tenhle čtecí dotaz '
      + 'vyžaduje token s rozsahem „čtení a zápis".',
    inputSchema: schema({ ic: str('IČO — 8 číslic.') }, ['ic']),
    write: false,
    run: (c, a, tool) => c.post('/clients/lookup-ares', { ic: String(a.ic).replace(/\D/g, '') }, tool),
  },
  {
    name: 'create_client',
    title: 'Založit odběratele',
    description:
      'Založí nového odběratele (nebo dodavatele). Typické zadání: '
      + '„založ klienta podle IČO 12345678".\n\n'
      + 'KDYŽ ZADÁŠ `ic`, ÚDAJE SE DOTÁHNOU Z ARES — název, adresa, DIČ i registrace '
      + 'k DPH. Cokoli vyplníš ručně má přednost před tím, co vrátí ARES.\n\n'
      + 'Bez IČO je nutné vyplnit `company_name`, `street`, `city` a `zip`.\n\n'
      + 'Před založením se kontroluje, jestli odběratel se stejným IČO nebo DIČ už '
      + 'neexistuje — pokud ano, nic se nevytvoří a vrátí se odkaz na stávající '
      + 'kartu. Vědomý duplicitní zápis povolíš přes `allow_duplicate`.',
    inputSchema: schema({
      ic: str('IČO (8 číslic). Když je vyplněné, zbytek se dotáhne z ARES.'),
      company_name: str('Firma nebo jméno. Povinné, pokud se nedotáhne z ARES.'),
      street: str('Ulice a číslo popisné.'),
      city: str('Město.'),
      zip: str('PSČ.'),
      country_iso2: str('Kód země, dvě písmena. Výchozí CZ.', { minLength: 2, maxLength: 2 }),
      dic: str('DIČ.'),
      main_email: str('Hlavní e-mail pro zasílání dokladů.'),
      phone: str('Telefon.'),
      language: str('Jazyk dokladů.', { enum: ['cs', 'en'] }),
      hourly_rate: num('Hodinová sazba pro výkazy práce.', { minimum: 0 }),
      is_customer: bool('Je odběratel (výchozí ano).'),
      is_vendor: bool('Je i dodavatel (výchozí ne).'),
      note: str('Interní poznámka.'),
      allow_duplicate: bool('Založit i když už odběratel se stejným IČO/DIČ existuje.'),
    }),
    write: true,
    run: async (c, a, tool) => {
      const ic = String(a.ic ?? '').replace(/\D/g, '');
      if (a.ic && ic.length !== 8) {
        throw new Error(`IČO musí mít 8 číslic, dostal jsem „${a.ic}".`);
      }

      // ── ARES ────────────────────────────────────────────────────────────
      let ares = null;
      let aresNote = 'nepoužit (IČO nezadáno)';
      if (ic !== '') {
        try {
          const res = await c.post('/clients/lookup-ares', { ic }, tool);
          if (res?.found === false || !res?.data) {
            aresNote = `IČO ${ic} v ARES nenalezeno — údaje se berou jen ze zadání`;
          } else {
            ares = res.data;
            aresNote = `údaje doplněny z ARES (${ares.company_name ?? ''})`;
          }
        } catch (e) {
          // Výpadek rejstříku nesmí zablokovat založení, když má uživatel údaje sám.
          aresNote = `ARES nedostupný (${e.message}) — údaje se berou jen ze zadání`;
        }
      }

      // Ruční zadání má přednost před rejstříkem: uživatel může vědět
      // o změně, která se do ARES ještě nepropsala.
      const pick = (own, fromAres) => {
        const v = own ?? fromAres ?? '';
        return typeof v === 'string' ? v.trim() : v;
      };

      const payload = {
        company_name: pick(a.company_name, ares?.company_name),
        street: pick(a.street, ares?.street),
        city: pick(a.city, ares?.city),
        zip: pick(a.zip, ares?.zip),
        country_iso2: pick(a.country_iso2, ares?.country_iso2) || 'CZ',
        ic: ic || '',
        dic: pick(a.dic, ares?.dic),
        main_email: String(a.main_email ?? '').trim(),
        phone: a.phone ?? null,
        language: a.language ?? 'cs',
        is_customer: a.is_customer !== false,
        is_vendor: a.is_vendor === true,
        note: a.note ?? '',
      };
      if (a.hourly_rate !== undefined) payload.hourly_rate = Number(a.hourly_rate);
      // ARES je pro CZ autoritativní zdroj registrace k DPH.
      if (ares && typeof ares.is_vat_payer === 'boolean') payload.is_vat_payer = ares.is_vat_payer;

      const missing = ['company_name', 'street', 'city', 'zip'].filter((f) => payload[f] === '');
      if (missing.length > 0) {
        throw new Error(
          `Chybí povinné údaje: ${missing.join(', ')}. `
          + (ic === ''
            ? 'Zadej je, nebo předej `ic` a doplní se z ARES.'
            : `Z ARES se nedotáhly (${aresNote}), doplň je ručně.`),
        );
      }

      // ── Kontrola duplicity ──────────────────────────────────────────────
      if (!a.allow_duplicate) {
        for (const [field, value] of [['IČO', payload.ic], ['DIČ', payload.dic]]) {
          if (!value) continue;
          const found = rows(await c.get('/clients', { q: value, per_page: 10 }, tool));
          const hit = found.find((x) => String(x[field === 'IČO' ? 'ic' : 'dic'] ?? '').trim() === value);
          if (hit) {
            throw new Error(
              `Odběratel se stejným ${field} už existuje: „${hit.company_name}" (id ${hit.id}). `
              + 'Nic jsem nezakládal. Pokud má vzniknout druhá karta, zavolej znovu '
              + 's `allow_duplicate: true`.',
            );
          }
        }
      }

      const created = await c.post('/clients', payload, tool);
      return { ares: aresNote, client: created };
    },
  },
  {
    name: 'update_client',
    title: 'Upravit odběratele',
    description:
      'Změní údaje existujícího odběratele. Uveď jen to, co se má změnit — zbytek '
      + 'karty se zachová (nástroj si ji načte a pošle zpět kompletní).\n\n'
      + '`refresh_from_ares: true` znovu natáhne název, adresu a DIČ z rejstříku '
      + 'podle IČO na kartě. Ručně zadané hodnoty mají i tady přednost před tím, '
      + 'co vrátí ARES. Plátcovství DPH (`is_vat_payer`) se mění VÝHRADNĚ '
      + 'explicitním zadáním — ani ARES ho tady tiše nepřepíše.',
    inputSchema: schema({
      id: int('ID odběratele.'),
      refresh_from_ares: bool('Přenačíst údaje z ARES podle IČO na kartě.'),
      company_name: str('Firma nebo jméno.'),
      street: str('Ulice a číslo popisné.'),
      city: str('Město.'),
      zip: str('PSČ.'),
      country_iso2: str('Kód země, dvě písmena.', { minLength: 2, maxLength: 2 }),
      ic: str('IČO (8 číslic).'),
      dic: str('DIČ.'),
      is_vat_payer: bool('Plátce DPH. Bez zadání se stávající hodnota na kartě nemění.'),
      main_email: str('Hlavní e-mail pro zasílání dokladů.'),
      phone: str('Telefon.'),
      language: str('Jazyk dokladů.', { enum: ['cs', 'en'] }),
      hourly_rate: num('Hodinová sazba pro výkazy práce.', { minimum: 0 }),
      payment_due_days: int('Splatnost ve dnech.', { minimum: 0 }),
      is_customer: bool('Je odběratel.'),
      is_vendor: bool('Je dodavatel.'),
      note: str('Interní poznámka.'),
    }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const current = await c.get(`/clients/${a.id}`, null, tool);
      if (!current?.id) throw new Error(`Odběratel #${a.id} nenalezen.`);

      let ares = null;
      let aresNote = 'nepoužit';
      if (a.refresh_from_ares) {
        const ic = String(a.ic ?? current.ic ?? '').replace(/\D/g, '');
        if (ic.length !== 8) {
          throw new Error('Přenačtení z ARES vyžaduje platné IČO — karta ho nemá a nebylo zadané.');
        }
        try {
          const res = await c.post('/clients/lookup-ares', { ic }, tool);
          if (res?.found === false || !res?.data) aresNote = `IČO ${ic} v ARES nenalezeno`;
          else { ares = res.data; aresNote = 'údaje přenačteny z ARES'; }
        } catch (e) {
          throw new Error(`ARES je nedostupný (${e.message}), údaje jsem neměnil.`);
        }
      }

      // Pořadí priorit: zadání uživatele → ARES → současný stav karty.
      // Server validuje celý payload (company_name, street, city, zip jsou povinné),
      // takže neposíláme jen změněná pole, ale kompletní kartu.
      const pick = (key, aresKey = key) => a[key] ?? ares?.[aresKey] ?? current[key];

      const payload = {
        company_name: pick('company_name'),
        street: pick('street'),
        city: pick('city'),
        zip: pick('zip'),
        country_iso2: pick('country_iso2') || 'CZ',
        ic: a.ic ?? current.ic ?? '',
        dic: pick('dic'),
        main_email: a.main_email ?? current.main_email ?? '',
        phone: a.phone ?? current.phone ?? null,
        language: a.language ?? current.language ?? 'cs',
        currency_default_id: current.currency_default_id,
        is_customer: a.is_customer ?? current.is_customer,
        is_vendor: a.is_vendor ?? current.is_vendor,
        // Jen explicitně poslaná hodnota — refresh_from_ares plátcovství NEPŘEPISUJE
        // (tichá změna flagu by se propsala do nových dokladů; stávající nesou snapshot).
        is_vat_payer: a.is_vat_payer ?? current.is_vat_payer,
        note: a.note ?? current.note ?? '',
      };
      if (a.hourly_rate !== undefined) payload.hourly_rate = Number(a.hourly_rate);
      else if (current.hourly_rate !== undefined) payload.hourly_rate = current.hourly_rate;
      if (a.payment_due_days !== undefined) payload.payment_due_days = Number(a.payment_due_days);
      else if (current.payment_due_days !== undefined) payload.payment_due_days = current.payment_due_days;

      const updated = await c.put(`/clients/${a.id}`, payload, tool);
      return { ares: aresNote, client: updated };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Fakturace
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_invoices',
    title: 'Seznam vystavených faktur',
    description:
      'Vystavené doklady seskupené po měsících. Filtruje se přes stav, typ, odběratele, '
      + 'období a měnu. Pro dotazy typu „co nám nezaplatili" použij raději '
      + '`list_unpaid_invoices` — má správně nastavené filtry včetně záloh.',
    inputSchema: schema({
      query: str('Fulltext — variabilní symbol, odběratel, popis.'),
      status: str('Stav dokladu, více hodnot čárkou.', { examples: ['draft,issued', 'paid'] }),
      type: str('Typ dokladu, více hodnot čárkou.', { examples: ['invoice,proforma'] }),
      client_id: int('Jen doklady tohoto odběratele.'),
      project_id: int('Jen doklady této zakázky.'),
      year: int('Rok podle data zdanitelného plnění (jinak data vystavení).'),
      month: int('Měsíc 1–12; dává smysl spolu s `year`.', { minimum: 1, maximum: 12 }),
      date_from: date('Doklady od tohoto data (RRRR-MM-DD).'),
      date_to: date('Doklady do tohoto data (RRRR-MM-DD).'),
      currency: str('Kód měny, např. CZK nebo EUR.'),
      unpaid_only: bool('Jen neuhrazené doklady.'),
      overdue: bool('Jen doklady po splatnosti.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/invoices', {
      q: a.query,
      page: a.page,
      per_page: a.per_page,
      filter: {
        status: a.status,
        type: a.type,
        client_id: a.client_id,
        project_id: a.project_id,
        year: a.year,
        month: a.month,
        date_from: a.date_from,
        date_to: a.date_to,
        currency: a.currency,
        unpaid_only: a.unpaid_only,
        overdue: a.overdue,
      },
    }, tool),
  },
  {
    name: 'list_unpaid_invoices',
    title: 'Nezaplacené faktury',
    description:
      'Přehled neuhrazených vystavených dokladů — volitelně jen ty po splatnosti. '
      + 'Tohle je správný nástroj na dotazy „kdo nám dluží", „co je po splatnosti" '
      + 'a „kolik máme v pohledávkách".',
    inputSchema: schema({
      overdue_only: bool('Jen doklady po splatnosti (výchozí ne).'),
      client_id: int('Omezit na jednoho odběratele.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/invoices', {
      page: a.page,
      per_page: a.per_page ?? 200,
      filter: {
        unpaid_only: true,
        overdue: a.overdue_only,
        client_id: a.client_id,
      },
    }, tool),
  },
  {
    name: 'get_invoice',
    title: 'Detail faktury',
    description: 'Hlavička, položky, rozpis DPH, stav úhrady a historie dokladu.',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.id}`, null, tool),
  },
  {
    name: 'list_invoice_payments',
    title: 'Úhrady faktury',
    description: 'Zaevidované úhrady konkrétního dokladu (částka, datum, zdroj).',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.id}/payments`, null, tool),
  },
  {
    name: 'create_invoice',
    title: 'Vytvořit fakturu (koncept)',
    description:
      'Založí NOVÝ DOKLAD JAKO KONCEPT. Číslo dokladu se přiděluje až při vystavení '
      + '(`issue_invoice`), takže je pořád možné koncept zkontrolovat nebo smazat.\n\n'
      + 'Před voláním si zjisti `client_id` (`search_clients`) a `vat_rate_id` '
      + '(`list_vat_rates`). Ceny položek se zadávají BEZ DPH, pokud nenastavíš '
      + '`prices_include_vat`.',
    inputSchema: schema({
      client_id: int('ID odběratele.'),
      items: {
        type: 'array',
        description: 'Fakturované položky, alespoň jedna.',
        minItems: 1,
        items: schema({
          description: str('Text položky.'),
          quantity: num('Množství. Nesmí být nula.'),
          unit: str('Měrná jednotka, výchozí "ks".'),
          unit_price_without_vat: num('Jednotková cena bez DPH.'),
          vat_rate_id: int('ID sazby DPH — viz `list_vat_rates`.'),
          stock_item_id: int('Volitelná vazba na skladovou kartu.'),
          warehouse_id: int('Sklad k výdeji; jen spolu se `stock_item_id`.'),
        }, ['description', 'quantity', 'unit_price_without_vat', 'vat_rate_id']),
      },
      invoice_type: str('Typ dokladu, výchozí "invoice".', {
        enum: ['invoice', 'proforma', 'credit_note', 'cancellation'],
      }),
      project_id: int('Zakázka; musí patřit odběrateli.'),
      issue_date: date('Datum vystavení (výchozí dnes).'),
      due_date: date('Datum splatnosti (výchozí podle nastavení odběratele).'),
      tax_date: date('Datum zdanitelného plnění. U proformy se ignoruje.'),
      currency: str('Kód měny, např. CZK. Výchozí je měna odběratele.'),
      language: str('Jazyk dokladu.', { enum: ['cs', 'en'] }),
      prices_include_vat: bool('Ceny položek jsou včetně DPH (DPH se dopočítá shora).'),
      reverse_charge: bool('Přenesená daňová povinnost.'),
      discount_percent: num('Sleva z celé faktury v procentech (0–100). Slevovou položku NEposílej v `items`.', { minimum: 0, maximum: 100 }),
      payment_method: str('Způsob úhrady.', { enum: ['bank_transfer', 'card', 'cash', 'other'] }),
      note: str('Poznámka na dokladu.'),
      varsymbol: str('Ruční variabilní symbol; jinak se přidělí automaticky při vystavení.'),
    }, ['client_id', 'items']),
    write: true,
    run: (c, a, tool) => c.post('/invoices', a, tool),
  },
  {
    name: 'issue_invoice',
    title: 'Vystavit fakturu',
    description:
      'Vystaví koncept — přidělí číslo dokladu a uzamkne ho pro editaci. '
      + 'NEVRATNÝ KROK: vystavený doklad už nelze prostě smazat, jen stornovat nebo dobropisovat. '
      + 'Vystavuj až po potvrzení uživatelem.',
    inputSchema: schema({ id: int('ID konceptu faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/issue`, {}, tool),
  },
  {
    name: 'send_invoice',
    title: 'Odeslat fakturu e-mailem',
    description:
      'Odešle vystavenou fakturu e-mailem odběrateli. Bez `to` se použijí fakturační '
      + 'adresy z karty odběratele. ODESLANÝ E-MAIL SE NEDÁ VZÍT ZPĚT — posílej jen na výslovný pokyn.',
    inputSchema: schema({
      id: int('ID faktury.'),
      to: { type: 'array', items: { type: 'string' }, description: 'Přepis příjemců; jinak adresy odběratele.' },
      cc: { type: 'array', items: { type: 'string' }, description: 'Kopie.' },
      bcc: { type: 'array', items: { type: 'string' }, description: 'Skrytá kopie.' },
      subject_override: str('Vlastní předmět zprávy.'),
      note: str('Text doplněný do těla e-mailu.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/send`, {
      to: a.to, cc: a.cc, bcc: a.bcc, subject_override: a.subject_override, note: a.note,
    }, tool),
  },
  {
    name: 'mark_invoice_paid',
    title: 'Označit fakturu jako uhrazenou',
    description:
      'Zaeviduje úhradu dokladu. Používej jen tam, kde platba nepřijde z bankovního '
      + 'výpisu (hotovost, ruční dohledání) — spárované platby z výpisu se evidují samy.',
    inputSchema: schema({
      id: int('ID faktury.'),
      paid_at: date('Datum úhrady (RRRR-MM-DD). Výchozí dnes.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/mark-paid`, { paid_at: a.paid_at }, tool),
  },
  {
    name: 'send_invoice_reminder',
    title: 'Poslat upomínku',
    description:
      'Odešle upomínku k nezaplacené faktuře po splatnosti. Stejně jako `send_invoice` '
      + 'jde o nevratné odeslání e-mailu zákazníkovi — jen na výslovný pokyn.',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/reminder`, {}, tool),
  },
  // ──────────────────────────────────────────────────────────────────────────
  // Výkazy práce a materiálu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_projects',
    title: 'Seznam zakázek',
    description:
      'Zakázky s hodinovou sazbou, měnou a splatností. Používej k dohledání '
      + '`project_id` a k ověření sazby před zápisem do výkazu práce.',
    inputSchema: schema({
      status: str('Stav zakázky.', { enum: ['active', 'paused', 'closed'] }),
      client_id: int('Jen zakázky tohoto odběratele.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/projects', {
      page: a.page,
      per_page: a.per_page,
      filter: { status: a.status, client_id: a.client_id },
    }, tool),
  },
  {
    name: 'get_work_report',
    title: 'Výkaz práce a materiálu',
    description:
      'Výkaz navázaný na konkrétní fakturu — řádky práce (popis, datum, hodiny, sazba), '
      + 'řádky materiálu a součty. Vrátí `null`, pokud faktura výkaz zatím nemá.',
    inputSchema: schema({ invoice_id: int('ID faktury.') }, ['invoice_id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.invoice_id}/work-report`, null, tool),
  },
  {
    name: 'add_work_report_entry',
    title: 'Přidat práci do výkazu',
    description:
      'Přidá řádek odvedené práce do výkazu u KONCEPTU faktury. Typické zadání: '
      + '„přidej do výkazu pro AVYX 3 hodiny práce na MCP serveru".\n\n'
      + 'Doklad určíš buď přímo `invoice_id`, nebo názvem zakázky (`project`) '
      + 'či odběratele (`client`) — nástroj si koncept dohledá sám. Když je konceptů '
      + 'víc, nehádá a vypíše je, ať vybereš.\n\n'
      + 'HODINOVOU SAZBU DOPLNÍ SÁM v pořadí zakázka → odběratel → výchozí sazba firmy. '
      + 'Parametrem `rate` ji lze přebít. Existující řádky výkazu zůstávají beze změny.\n\n'
      + 'Funguje jen na konceptu — u vystaveného dokladu vrátí server chybu.',
    inputSchema: schema({
      description: str('Co se dělalo, např. „Vývoj MCP serveru".'),
      hours: num('Počet hodin. Musí být větší než 0.', { exclusiveMinimum: 0 }),
      invoice_id: int('ID konceptu faktury. Když chybí, dohledá se podle `project` / `client`.'),
      project: str('Název zakázky — alternativa k `invoice_id`.'),
      client: str('Název odběratele — alternativa k `invoice_id`.'),
      work_date: date('Datum provedení práce (RRRR-MM-DD). Nepovinné.'),
      rate: num('Hodinová sazba. Bez zadání se převezme ze zakázky / odběratele / firmy.', { minimum: 0 }),
    }, ['description', 'hours']),
    write: true,
    run: async (c, a, tool) => {
      const hours = Number(a.hours);
      if (!(hours > 0)) throw new Error('Počet hodin musí být větší než 0.');
      if (String(a.description ?? '').trim() === '') throw new Error('Zadejte popis práce.');

      const target = await resolveDraftInvoice(c, a, tool);
      const invoiceId = target.invoiceId;

      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${invoiceId}`, null, tool),
        c.get(`/invoices/${invoiceId}/work-report`, null, tool),
      ]);

      const projectId = report?.project_id ?? invoice?.project_id ?? target.projectId ?? null;
      const clientId = invoice?.client_id ?? target.clientId ?? null;

      let rate = Number(a.rate ?? 0);
      let rateFrom = 'zadáno ručně';
      if (!(rate > 0)) {
        // Existující řádky mají přednost před číselníkem: když se výkaz už jednou
        // vyplnil jinou sazbou, nová hodina má sedět s ním, ne s ceníkem.
        const previous = Number(report?.items?.at(-1)?.rate ?? 0);
        if (previous > 0) {
          rate = previous;
          rateFrom = 'poslední řádek výkazu';
        } else {
          const resolved = await resolveHourlyRate(c, { projectId, clientId }, tool);
          rate = resolved.rate;
          rateFrom = resolved.from;
        }
      }

      const items = (report?.items ?? []).map((it) => ({
        description: it.description,
        work_date: it.work_date ?? null,
        hours: Number(it.hours),
        rate: Number(it.rate),
      }));

      items.push({
        description: String(a.description).trim(),
        work_date: a.work_date ?? null,
        hours,
        rate,
      });

      const saved = await c.put(`/invoices/${invoiceId}/work-report`, {
        project_id: projectId,
        title: report?.title || defaultReportTitle(invoice),
        vat_rate_id: report?.vat_rate_id ?? null,
        items,
      }, tool);

      return {
        added: { description: String(a.description).trim(), hours, rate, work_date: a.work_date ?? null },
        rate_source: rateFrom,
        invoice_id: invoiceId,
        work_report: saved,
      };
    },
  },
  {
    name: 'add_work_report_material',
    title: 'Přidat materiál do výkazu',
    description:
      'Přidá řádek materiálu do výkazu u KONCEPTU faktury (spotřebovaný materiál, '
      + 'nakoupené díly). Doklad se určuje stejně jako u `add_work_report_entry`.\n\n'
      + 'Sazbu DPH materiálu nástroj NEHÁDÁ: převezme ji z už existujícího výkazu, '
      + 'jinak ji musíš zadat v `vat_rate_id` (seznam vrátí `list_vat_rates`). '
      + 'Špatná sazba by se propsala do přiznání k DPH.',
    inputSchema: schema({
      description: str('Název materiálu.'),
      quantity: num('Množství. Musí být větší než 0.', { exclusiveMinimum: 0 }),
      unit_price: num('Cena za jednotku bez DPH.', { minimum: 0 }),
      unit: str('Měrná jednotka, výchozí „ks".'),
      invoice_id: int('ID konceptu faktury. Když chybí, dohledá se podle `project` / `client`.'),
      project: str('Název zakázky — alternativa k `invoice_id`.'),
      client: str('Název odběratele — alternativa k `invoice_id`.'),
      vat_rate_id: int('Sazba DPH materiálu. Povinná, pokud výkaz zatím žádnou nemá.'),
    }, ['description', 'quantity', 'unit_price']),
    write: true,
    run: async (c, a, tool) => {
      const quantity = Number(a.quantity);
      if (!(quantity > 0)) throw new Error('Množství musí být větší než 0.');
      if (String(a.description ?? '').trim() === '') throw new Error('Zadejte název materiálu.');

      const target = await resolveDraftInvoice(c, a, tool);
      const invoiceId = target.invoiceId;

      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${invoiceId}`, null, tool),
        c.get(`/invoices/${invoiceId}/work-report`, null, tool),
      ]);

      const vatRateId = a.vat_rate_id ?? report?.material_vat_rate_id ?? null;
      if (!vatRateId) {
        throw new Error(
          'Výkaz materiálu zatím nemá sazbu DPH. Zavolej `list_vat_rates` a předej '
          + '`vat_rate_id` — sazbu nelze odvodit automaticky.',
        );
      }

      const materials = (report?.materials ?? []).map((m) => ({
        description: m.description,
        quantity: Number(m.quantity),
        unit: m.unit,
        unit_price: Number(m.unit_price),
      }));

      materials.push({
        description: String(a.description).trim(),
        quantity,
        unit: String(a.unit ?? 'ks').trim() || 'ks',
        unit_price: Number(a.unit_price ?? 0),
      });

      const saved = await c.put(`/invoices/${invoiceId}/work-report/materials`, {
        project_id: report?.project_id ?? invoice?.project_id ?? target.projectId ?? null,
        material_title: report?.material_title || 'Materiál',
        material_vat_rate_id: vatRateId,
        materials,
      }, tool);

      return { invoice_id: invoiceId, work_report: saved };
    },
  },
  {
    name: 'remove_work_report_entry',
    title: 'Odebrat řádek z výkazu práce',
    description:
      'Smaže jeden řádek práce z výkazu podle pořadí (1 = první řádek). '
      + 'Pořadí zjistíš z `get_work_report`. Ostatní řádky zůstanou beze změny.',
    inputSchema: schema({
      invoice_id: int('ID konceptu faktury.'),
      row: int('Pořadí řádku, od 1.', { minimum: 1 }),
    }, ['invoice_id', 'row']),
    write: true,
    run: async (c, a, tool) => {
      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${a.invoice_id}`, null, tool),
        c.get(`/invoices/${a.invoice_id}/work-report`, null, tool),
      ]);

      const items = report?.items ?? [];
      const row = Number(a.row);
      if (row < 1 || row > items.length) {
        throw new Error(`Výkaz má ${items.length} řádků práce, řádek ${row} neexistuje.`);
      }

      const kept = items
        .filter((_, i) => i !== row - 1)
        .map((it) => ({
          description: it.description,
          work_date: it.work_date ?? null,
          hours: Number(it.hours),
          rate: Number(it.rate),
        }));

      const saved = await c.put(`/invoices/${a.invoice_id}/work-report`, {
        project_id: report?.project_id ?? invoice?.project_id ?? null,
        title: report?.title || defaultReportTitle(invoice),
        vat_rate_id: report?.vat_rate_id ?? null,
        items: kept,
      }, tool);

      return { removed: items[row - 1], work_report: saved };
    },
  },
  {
    name: 'list_vat_rates',
    title: 'Sazby DPH',
    description:
      'Číselník sazeb DPH s jejich ID. `vat_rate_id` z tohoto seznamu se používá '
      + 'v položkách faktury.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/settings/vat-rates', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Statistika a přehledy
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'dashboard_summary',
    title: 'Souhrn nástěnky',
    description:
      'Rychlý přehled: tržby, pohledávky, doklady po splatnosti, stav za aktuální období. '
      + 'Dobrý první dotaz na „jak jsme na tom".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/dashboard/summary', null, tool),
  },
  {
    name: 'revenue_overview',
    title: 'Přehled tržeb a zisku',
    description: 'Souhrnné ukazatele obratu, nákladů a zisku za zvolené období.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/overview', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'revenue_monthly',
    title: 'Tržby po měsících',
    description: 'Časová řada obratu a zisku po měsících — pro trendy a meziroční srovnání.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/monthly', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'top_clients',
    title: 'Nejvýznamnější odběratelé',
    description: 'Žebříček odběratelů podle obratu za období — kdo dělá tržby.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
      limit: int('Kolik odběratelů vrátit.', { minimum: 1, maximum: 100 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/top-clients', { from: a.from, to: a.to, limit: a.limit }, tool),
  },
  {
    name: 'aging_receivables',
    title: 'Pohledávky podle stáří',
    description:
      'Rozpad neuhrazených pohledávek do pásem po splatnosti (do 30 / 30–60 / 60–90 / 90+ dní). '
      + 'Používej na otázky typu „jak staré jsou naše nedoplatky".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/aging-receivables', null, tool),
  },
  {
    name: 'cash_flow_forecast',
    title: 'Výhled cash flow',
    description: 'Očekávané příjmy a výdaje podle splatností vystavených a přijatých dokladů.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/cash-flow-forecast', null, tool),
  },
  {
    name: 'payment_punctuality',
    title: 'Platební morálka odběratelů',
    description: 'Jak rychle kdo platí — průměrné zpoždění a podíl včasných úhrad.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/payment-punctuality', null, tool),
  },
  {
    name: 'revenue_yearly',
    title: 'Tržby po letech',
    description: 'Meziroční srovnání obratu, nákladů a zisku.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/yearly', null, tool),
  },
  {
    name: 'revenue_breakdown',
    title: 'Rozpad tržeb podle kategorií',
    description: 'Z čeho se skládá obrat — podle kategorií tržeb.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/revenue-breakdown', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'expense_breakdown',
    title: 'Rozpad nákladů podle kategorií',
    description: 'Za co utrácíme — náklady po kategoriích. Pro dotazy „kde nám utíkají peníze".',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/expense-breakdown', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'top_vendors',
    title: 'Největší dodavatelé',
    description: 'Žebříček dodavatelů podle objemu nákupů za období.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
      limit: int('Kolik dodavatelů vrátit.', { minimum: 1, maximum: 100 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/top-vendors', { from: a.from, to: a.to, limit: a.limit }, tool),
  },
  {
    name: 'aging_payables',
    title: 'Závazky podle stáří',
    description: 'Neuhrazené přijaté faktury v pásmech po splatnosti — komu dlužíme my.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/aging-payables', null, tool),
  },
  {
    name: 'dso_dpo',
    title: 'Doba inkasa a doba splatnosti (DSO / DPO)',
    description:
      'DSO = za jak dlouho v průměru inkasujeme pohledávky, DPO = za jak dlouho platíme závazky. '
      + 'Vrací obojí najednou, protože se čtou spolu.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => ({
      dso: await c.get('/crm/dso', null, tool),
      dpo: await c.get('/crm/dpo', null, tool),
    }),
  },
  {
    name: 'client_concentration',
    title: 'Koncentrace odběratelů a dodavatelů',
    description:
      'Jak moc obrat závisí na několika málo partnerech — riziko koncentrace na obou stranách.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => ({
      clients: await c.get('/crm/concentration', null, tool),
      vendors: await c.get('/crm/vendor-concentration', null, tool),
    }),
  },
  {
    name: 'churn_risk',
    title: 'Odběratelé s rizikem odchodu',
    description: 'Zákazníci, kteří přestali objednávat oproti svému zvyku.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/churn-risk', null, tool),
  },
  {
    name: 'late_payment_risk',
    title: 'Riziko pozdní úhrady',
    description: 'Odhad, které vystavené faktury se pravděpodobně zaplatí pozdě.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/late-risk', null, tool),
  },
  {
    name: 'reminder_effectiveness',
    title: 'Účinnost upomínek',
    description: 'Kolik upomínek vede k úhradě a jak rychle — má smysl upomínat?',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/reminder-effectiveness', null, tool),
  },
  {
    name: 'payment_time_histogram',
    title: 'Rozložení doby úhrady',
    description: 'Histogram — za kolik dní od vystavení nám kdo platí.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/payment-time-histogram', null, tool),
  },
  {
    name: 'action_items',
    title: 'Na co si dát pozor',
    description:
      'Automaticky odvozené položky k řešení — nezaplacené doklady, blížící se termíny, '
      + 'nesrovnalosti. Dobrý nástroj na dotaz „co bych měl dneska řešit".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/action-items', null, tool),
  },
  {
    name: 'tax_calendar',
    title: 'Daňový kalendář',
    description: 'Blížící se daňové termíny a povinnosti firmy.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/tax-calendar', null, tool),
  },
  {
    name: 'purchase_summary',
    title: 'Souhrn nákupů',
    description: 'Přehled přijatých faktur — objem, závazky, stav úhrad.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/dashboard/purchase-summary', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Hledání
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search',
    title: 'Globální vyhledávání',
    description:
      'Prohledá najednou odběratele, vystavené faktury a přijaté faktury. '
      + 'Použij, když uživatel jmenuje doklad nebo firmu a nevíš, kde ji hledat. '
      + 'Dotaz musí mít alespoň 2 znaky.',
    inputSchema: schema({ query: str('Hledaný text — název firmy, číslo dokladu, IČO.') }, ['query']),
    write: false,
    run: (c, a, tool) => c.get('/search', { q: a.query }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Přijaté faktury (závazky)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_purchase_invoices',
    title: 'Seznam přijatých faktur',
    description: 'Přijaté faktury (závazky) s filtry na stav, dodavatele a období.',
    inputSchema: schema({
      query: str('Fulltext — dodavatel, číslo dokladu, popis.'),
      status: str('Stav dokladu, více hodnot čárkou.'),
      vendor_id: int('Jen doklady tohoto dodavatele.'),
      date_from: date('Doklady od tohoto data (RRRR-MM-DD).'),
      date_to: date('Doklady do tohoto data (RRRR-MM-DD).'),
      unpaid_only: bool('Jen neuhrazené závazky.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/purchase-invoices', {
      q: a.query,
      page: a.page,
      per_page: a.per_page,
      filter: {
        status: a.status,
        vendor_id: a.vendor_id,
        date_from: a.date_from,
        date_to: a.date_to,
        unpaid_only: a.unpaid_only,
      },
    }, tool),
  },
  {
    name: 'get_purchase_invoice',
    title: 'Detail přijaté faktury',
    description: 'Hlavička, položky, rozpis DPH a stav úhrady přijaté faktury.',
    inputSchema: schema({ id: int('ID přijaté faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/purchase-invoices/${a.id}`, null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Daně — VÝHRADNĚ KE ČTENÍ (server zápis odmítne, viz hlavička souboru)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'vat_return_preview',
    title: 'Odhad DPH za období',
    description:
      'Náhled přiznání k DPH za zvolené období — jednotlivé řádky i výsledná daňová '
      + 'povinnost či nadměrný odpočet. Tohle je správný nástroj na dotazy typu '
      + '„kolik letos v červenci zaplatíme na DPH" nebo „jak vychází DPH za tenhle kvartál".\n\n'
      + 'U čtvrtletního plátce zadej `period: "quarterly"` a jako `month` libovolný '
      + 'měsíc daného kvartálu. Bez `period` se použije nastavení firmy.\n\n'
      + 'Jde o NÁHLED z aktuálních dat, ne o podané přiznání — podání se dělá v aplikaci.',
    inputSchema: schema({
      year: int('Rok, např. 2026.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12. U čtvrtletního plátce stačí libovolný měsíc kvartálu.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_trend',
    title: 'Vývoj DPH po měsících',
    description: 'Časová řada daňové povinnosti a odpočtů — kolik DPH odvádíme v čase.',
    inputSchema: schema({
      months: int('Kolik měsíců zpět (1–36, výchozí 12).', { minimum: 1, maximum: 36 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/trend', { months: a.months }, tool),
  },
  {
    name: 'vat_drafts_prediction',
    title: 'Dopad konceptů na DPH',
    description:
      'O kolik se změní DPH, až se vystaví doklady, které jsou zatím v konceptu. '
      + 'Pro dotaz „kolik nám ještě přibude do přiznání".',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/drafts-prediction', { year: a.year, month: a.month }, tool),
  },
  {
    name: 'vat_control_statement_preview',
    title: 'Náhled kontrolního hlášení',
    description: 'Kontrolní hlášení za období po sekcích (A1–A5, B1–B3) — náhled, ne podání.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphkh1/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_ledger',
    title: 'Záznamní evidence DPH',
    description:
      'Doklady, ze kterých se DPH za období skládá — na dohledání, proč vychází taková částka.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dph-book/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_summary_report_preview',
    title: 'Náhled souhrnného hlášení',
    description: 'Souhrnné hlášení k plnění do EU za období — náhled, ne podání.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphshv/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'income_tax_analysis',
    title: 'Odhad daně z příjmů',
    description:
      'Rozbor základu daně a odhad daňové povinnosti za rok, včetně záloh. '
      + 'Pro dotazy „kolik letos zaplatíme na dani z příjmů".',
    inputSchema: schema({
      year: int('Rok, výchozí aktuální.', { minimum: 2020, maximum: 2050 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/tax/analysis', { year: a.year }, tool),
  },
  {
    name: 'list_tax_submissions',
    title: 'Historie daňových podání',
    description: 'Odeslaná i připravená podání (DPH, KH, SH) s jejich stavem.',
    inputSchema: schema({ ...PAGING }),
    write: false,
    run: (c, a, tool) => c.get('/reports/submissions', { page: a.page, per_page: a.per_page }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Účetnictví — VÝHRADNĚ KE ČTENÍ (server zápis odmítne, viz hlavička souboru)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_accounting_periods',
    title: 'Účetní období',
    description:
      'Seznam účetních období s jejich ID a stavem (otevřené / uzavřené). '
      + 'Většina účetních sestav potřebuje `period_id` — začni tímhle nástrojem.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/accounting/periods', null, tool),
  },
  {
    name: 'trial_balance',
    title: 'Obratová předvaha',
    description:
      'Obratovka za období — počáteční stavy, obraty MD/D a konečné zůstatky po účtech. '
      + 'Základní pohled na to, co se v účetnictví za období stalo.',
    inputSchema: schema({
      period_id: int('ID účetního období — viz `list_accounting_periods`.'),
      analytics: bool('Rozpad na analytické účty (jinak jen syntetika).'),
      after_closing: bool('Stav po uzávěrkových operacích.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/trial-balance', {
      period_id: a.period_id,
      analytics: a.analytics ? 1 : undefined,
      after_closing: a.after_closing ? 1 : undefined,
    }, tool),
  },
  {
    name: 'balance_sheet',
    title: 'Rozvaha',
    description: 'Aktiva a pasiva k datu — majetek firmy a jak je financovaný.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD). Bez zadání konec období.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/balance-sheet', {
      period_id: a.period_id, as_of: a.as_of,
    }, tool),
  },
  {
    name: 'income_statement',
    title: 'Výsledovka',
    description: 'Výkaz zisku a ztráty — výnosy, náklady a hospodářský výsledek za období.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD). Bez zadání konec období.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/income-statement', {
      period_id: a.period_id, as_of: a.as_of,
    }, tool),
  },
  {
    name: 'general_ledger',
    title: 'Hlavní kniha',
    description: 'Zápisy po účtech za období — pro dohledání, z čeho se zůstatek účtu skládá.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      analytics: bool('Rozpad na analytické účty.'),
      client: str('Filtr na odběratele.'),
      vendor: str('Filtr na dodavatele.'),
      item: str('Filtr na text položky.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/general-ledger', {
      period_id: a.period_id,
      analytics: a.analytics ? 1 : undefined,
      client: a.client,
      vendor: a.vendor,
      item: a.item,
    }, tool),
  },
  {
    name: 'account_statement',
    title: 'Výpis z účtu',
    description: 'Všechny pohyby na jednom účtu účtové osnovy za období.',
    inputSchema: schema({
      account_id: int('ID účtu — viz `chart_of_accounts`.'),
      period_id: int('ID účetního období.'),
    }, ['account_id', 'period_id']),
    write: false,
    run: (c, a, tool) => c.get(`/accounting/reports/account-statement/${a.account_id}`, {
      period_id: a.period_id,
    }, tool),
  },
  {
    name: 'saldo',
    title: 'Saldo (nespárované pohledávky a závazky)',
    description:
      'Saldokonto — nevyrovnané zůstatky vůči odběratelům a dodavatelům podle účetnictví. '
      + 'Doplňuje `aging_receivables`, které počítá z dokladů.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD).'),
      account: str('Omezení na účet, jinak všechny saldokontní účty.'),
      partner_id: int('Jen tento obchodní partner.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/saldo', {
      period_id: a.period_id, as_of: a.as_of, account: a.account, partner_id: a.partner_id,
    }, tool),
  },
  {
    name: 'chart_of_accounts',
    title: 'Účtová osnova',
    description: 'Účty firmy s jejich ID a názvy — potřeba pro `account_statement`.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/accounting/accounts', null, tool),
  },
  {
    name: 'list_journal_entries',
    title: 'Účetní deník',
    description:
      'Účetní zápisy s filtry na období, datum, zdrojový doklad a text. '
      + 'Pro dotazy „jak se zaúčtovala tahle faktura" použij `source_type` + `source_id`.',
    inputSchema: schema({
      query: str('Fulltext přes popis zápisu.'),
      period_id: int('ID účetního období.'),
      date_from: date('Zápisy od data (RRRR-MM-DD).'),
      date_to: date('Zápisy do data (RRRR-MM-DD).'),
      document_no: str('Číslo účetního dokladu.'),
      source_type: str('Typ zdrojového dokladu.', { examples: ['invoice', 'purchase_invoice'] }),
      source_id: int('ID zdrojového dokladu.'),
      posted: bool('Jen zaúčtované (true) nebo jen nezaúčtované (false).'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/accounting/journal', {
      q: a.query,
      period_id: a.period_id,
      date_from: a.date_from,
      date_to: a.date_to,
      document_no: a.document_no,
      source_type: a.source_type,
      source_id: a.source_id,
      posted: a.posted === undefined ? undefined : String(a.posted),
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_journal_entry',
    title: 'Detail účetního zápisu',
    description: 'Řádky zápisu (MD / D), účty, částky a vazba na zdrojový doklad.',
    inputSchema: schema({ id: int('ID účetního zápisu.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/accounting/journal/${a.id}`, null, tool),
  },
  {
    name: 'cash_journal',
    title: 'Peněžní deník (daňová evidence)',
    description:
      'Peněžní deník OSVČ v daňové evidenci — příjmy a výdaje na hotovostní bázi. '
      + 'Dává smysl jen u firem, které vedou daňovou evidenci, ne účetnictví.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      date_from: date('Od data (RRRR-MM-DD).'),
      date_to: date('Do data (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/tax-evidence/cash-journal', {
      year: a.year, date_from: a.date_from, date_to: a.date_to,
    }, tool),
  },
  {
    name: 'get_vat_status_history',
    title: 'Historie plátcovství DPH firmy',
    description:
      'Historie plátcovství DPH vlastní firmy (tabulka supplier_vat_status_history) — '
      + 'řádky {effective_from, is_vat_payer, annual_deduction_percent} vzestupně podle '
      + 'účinnosti. Stav k datu D = poslední řádek s effective_from <= D. Pole '
      + '`is_vat_payer` na firmě je jen cache dnešního stavu; pro otázky „byla firma '
      + 'plátce v roce X / k datu Y" použij tuhle historii.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => {
      const supplier = await c.get('/settings/supplier', null, tool);
      const history = Array.isArray(supplier?.vat_status_history) ? supplier.vat_status_history : [];
      return {
        is_vat_payer_today: Boolean(supplier?.is_vat_payer),
        history: [...history].sort(
          (x, y) => String(x?.effective_from ?? '').localeCompare(String(y?.effective_from ?? '')),
        ),
      };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop a sklad
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search_products',
    title: 'Rychlé hledání zboží',
    description:
      'Našeptávač nad skladovými kartami — hledá podle názvu a kódu/SKU. '
      + 'Pro filtrování a stránkování použij `list_products`.',
    inputSchema: schema({
      query: str('Hledaný text — název nebo kód zboží.'),
      limit: int('Maximální počet výsledků (1–200, výchozí 50).', { minimum: 1, maximum: 200 }),
    }, ['query']),
    write: false,
    run: (c, a, tool) => c.get('/stock/items/search', { q: a.query, limit: a.limit }, tool),
  },
  {
    name: 'list_products',
    title: 'Seznam zboží',
    description:
      'Skladové karty s filtry. `type: "goods"` vrátí jen e-shopové zboží, '
      + '`only_below_min: true` položky pod minimální zásobou (kandidáti na doobjednání).',
    inputSchema: schema({
      query: str('Fulltext přes název a kód.'),
      type: str('Typ karty.', { enum: ['goods', 'material', 'product', 'service'] }),
      active: bool('Jen aktivní (true) nebo jen neaktivní (false) karty.'),
      only_below_min: bool('Jen položky pod minimální zásobou.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/items', {
      q: a.query,
      type: a.type,
      active: a.active === undefined ? undefined : (a.active ? 1 : 0),
      only_below_min: a.only_below_min,
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_product',
    title: 'Karta zboží',
    description:
      'Kompletní e-shopová karta zboží — popis, kategorie, výrobce, parametry, štítky a média.',
    inputSchema: schema({ id: int('ID zboží (skladové karty).') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}`, null, tool),
  },
  {
    name: 'get_product_prices',
    title: 'Cenotvorba zboží',
    description: 'Prodejní ceny, marže a cenové hladiny konkrétního zboží.',
    inputSchema: schema({ id: int('ID zboží.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}/prices`, null, tool),
  },
  {
    name: 'set_product_prices',
    title: 'Změnit ceny zboží',
    description:
      'Uloží cenotvorbu zboží. MĚNÍ PRODEJNÍ CENY V OSTRÉM E-SHOPU — nejdřív si přečti '
      + 'aktuální stav přes `get_product_prices`, pošli kompletní strukturu (ne jen změněné '
      + 'pole) a změnu si nech potvrdit uživatelem.',
    inputSchema: {
      type: 'object',
      properties: {
        id: int('ID zboží.'),
        prices: {
          type: 'object',
          description:
            'Kompletní objekt cenotvorby ve tvaru, který vrací `get_product_prices`. '
            + 'Struktura se liší podle nastavení firmy, proto se neověřuje tady, ale na serveru.',
          additionalProperties: true,
        },
      },
      required: ['id', 'prices'],
      additionalProperties: false,
    },
    write: true,
    run: (c, a, tool) => c.put(`/eshop/products/${a.id}/prices`, a.prices, tool),
  },
  {
    name: 'list_categories',
    title: 'Kategorie e-shopu',
    description: 'Strom kategorií zboží.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/eshop/categories', null, tool),
  },
  {
    name: 'list_manufacturers',
    title: 'Výrobci / značky',
    description: 'Číselník výrobců a značek e-shopu.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/eshop/manufacturers', null, tool),
  },
  {
    name: 'stock_levels',
    title: 'Stav zásob',
    description:
      'Aktuální množství na skladech s filtrem na sklad, typ karty a podlimitní zásobu.',
    inputSchema: schema({
      query: str('Fulltext přes název a kód.'),
      warehouse_id: int('Jen tento sklad.'),
      item_type: str('Typ karty.', { enum: ['goods', 'material', 'product', 'service'] }),
      below_min: bool('Jen položky pod minimální zásobou.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/levels', {
      q: a.query,
      warehouse_id: a.warehouse_id,
      item_type: a.item_type,
      below_min: a.below_min,
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'stock_availability',
    title: 'Dostupnost konkrétního zboží',
    description:
      'Dostupné množství pro vyjmenované karty — na rozdíl od `stock_levels` počítá '
      + 'i s rezervacemi. Pro dotazy „můžeme to hned expedovat?".',
    inputSchema: schema({
      item_ids: {
        type: 'array',
        items: { type: 'integer' },
        description: 'ID skladových karet.',
        minItems: 1,
      },
      warehouse_id: int('Jen tento sklad.'),
    }, ['item_ids']),
    write: false,
    run: (c, a, tool) => c.get('/stock/availability', {
      item_ids: a.item_ids, warehouse_id: a.warehouse_id,
    }, tool),
  },
  {
    name: 'stock_valuation',
    title: 'Ocenění skladu',
    description: 'Hodnota zásob k datu — pro otázky „kolik máme uloženo ve skladu".',
    inputSchema: schema({
      warehouse_id: int('Jen tento sklad.'),
      to: date('Ocenění k datu (RRRR-MM-DD). Výchozí dnes.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/reports/valuation', {
      warehouse_id: a.warehouse_id, to: a.to,
    }, tool),
  },
  {
    name: 'list_warehouses',
    title: 'Seznam skladů',
    description: 'Sklady firmy s jejich ID — pro filtrování v ostatních skladových nástrojích.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/stock/warehouses', null, tool),
  },
];

export const TOOLS_BY_NAME = new Map(TOOLS.map((t) => [t.name, t]));
