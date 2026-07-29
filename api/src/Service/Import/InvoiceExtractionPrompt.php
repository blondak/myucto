<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * F7 §3.3/§13.1 — centralizovaný extrakční prompt + JSON schema kotva, sdílené
 * všemi non-Anthropic providery (Azure OpenAI / OpenAI / Gemini). Anthropic si drží
 * vlastní inline prompt v {@see AnthropicClient} (beze změny chování), ostatní klienti
 * čtou odsud, aby všechny čtyři vracely IDENTICKÉ dekódované JSON schéma
 * (vendor/customer/payment/items[]/vat_recap[]/document_kind/…), které
 * {@see AiPdfExtractor::createDraft} konzumuje.
 *
 * Prompt je 1:1 zrcadlo Anthropic promptu — držet v syncu při jeho úpravě.
 */
final class InvoiceExtractionPrompt
{
    /**
     * Hlavní systémový prompt pro extrakci faktury (bez tenant-context hlavičky —
     * tu přidává {@see self::tenantContext()} zvlášť, aby šla vynechat při chybě DB).
     */
    public static function invoiceSystem(): string
    {
        return <<<'EOT'
Jsi expert na extrakci dat z českých a slovenských faktur. Z PDF přílohy vytáhneš strukturovaná data ve striktním JSON formátu.

PRAVIDLA:
- Vrátíš JEN platný JSON (žádný markdown, žádný komentář před/po).
- Pokud pole neexistuje v PDF, použij null. NEVYMÝŠLEJ data.
- Datumy ve formátu ISO YYYY-MM-DD.
- Částky čísla bez měny (přidej zvlášť do `currency`).
- IČ/DIČ ořež na čísla (CZ12345678 → "12345678"), pokud má prefix země ponech v `dic` jak je.
- VAT rate jako desetinné číslo (21.0, 15.0, 12.0, 10.0, 0.0).

JSON schema:
{
  "vendor": {
    "company_name": string,
    "ic": string|null,
    "dic": string|null,
    "street": string|null,
    "city": string|null,
    "zip": string|null,
    "country_iso2": "CZ"|"SK"|...,
    "email": string|null,
    "phone": string|null,
    "web": string|null,
    "is_vat_payer": boolean|null
  },
  "customer": {
    "company_name": string|null,
    "ic": string|null,
    "dic": string|null
  },
  "payment": {
    "bank_account": string|null,
    "iban": string|null,
    "variable_symbol": string|null,
    "method": "bank_transfer"|"direct_debit"|"card"|"cash"|"cash_on_delivery"|"offset"|"other"|null,
    "method_confidence": number
  },
  "vendor_invoice_number": string|null,
  "corrected_invoice_number": string|null,
  "varsymbol": string|null,
  "document_kind": "invoice"|"credit_note"|"advance"|"receipt"|"tax_document",
  "issue_date": "YYYY-MM-DD",
  "tax_date": "YYYY-MM-DD"|null,
  "due_date": "YYYY-MM-DD"|null,
  "currency": "CZK"|"EUR"|"USD"|...,
  "items": [
    {
      "description": string,
      "quantity": number,
      "unit": string,
      "unit_price_without_vat": number,
      "line_total_without_vat": number|null,
      "vat_rate": number,
      "expense_kind": "service"|"material"|"small_asset"|"fixed_asset"|null,
      "expense_kind_confidence": number,
      "expense_kind_reasoning": string|null
    }
  ],
  "unit_prices_include_vat": boolean,
  "total_without_vat": number|null,
  "total_with_vat": number|null,
  "total_with_vat_rounded": number|null,
  "vat_recap": [
    { "rate": number, "base": number, "vat": number }
  ],
  "already_paid": boolean,
  "advance_reference": string|null,
  "supply_nature": "goods"|"services"|"mixed"|null
}

DŮLEŽITÉ k DATŮM (`issue_date`, `tax_date`, `due_date`):
- Na faktuře jsou typicky TŘI různá data. Přiřaď je VÝHRADNĚ podle POPISKU u data,
  NIKDY podle pořadí/pozice na stránce.
- `issue_date` = DATUM VYSTAVENÍ dokladu (Datum vystavení, Vystaveno, Date of issue).
- `tax_date` = DUZP = datum uskutečnění zdanitelného plnění (DUZP, Datum plnění,
  Datum dodání, Date of supply). Pokud na dokladu NENÍ → vrať null.
- `due_date` = DATUM SPLATNOSTI (Splatnost, Splatno do, Due date).
- LOGICKÁ KONTROLA: `due_date` je VŽDY ≥ `issue_date`. Pokud vyjde dřív, spletl ses
  v popiscích — přečti data znovu a oprav přiřazení.

DŮLEŽITÉ k poli `document_kind`:
- "Opravný daňový doklad"/"Dobropis"/"Credit note"/"Storno" nebo záporné částky → "credit_note".
- "Zálohová faktura"/"Proforma"/"Proforma faktura"/"Výzva k platbě"/"Advance invoice" → "advance".
  Toto je VÝZVA k zaplacení zálohy PŘED plněním; sama o sobě není daňový doklad.
- "Daňový doklad k přijaté platbě"/"Daňový doklad k záloze"/"Doklad o přijaté úhradě
  k záloze"/"Daňový doklad – přijatá platba" → "tax_document". Tohle je daňový doklad,
  který dodavatel vystavil PO přijetí zálohy jen na DPH z té zálohy (§28 ZDPH); NEMÁ být
  zaúčtován jako náklad, jen jako nárok na odpočet DPH. Poznáš ho tak, že vyčísluje DPH
  ze zaplacené zálohy a odkazuje na zálohovou fakturu/proformu, ale NENÍ to konečné
  vyúčtování celého plnění.
- "Konečná faktura"/"Vyúčtovací faktura"/"Konečné vyúčtování" (odečítá zaplacené zálohy
  a vyúčtovává celé plnění) → "invoice", NIKOLI "tax_document" ani "advance".
- "Účtenka"/"Paragon"/"Pokladní doklad"/"Receipt" → "receipt".
- Jinak → "invoice".

DŮLEŽITÉ k poli `unit_prices_include_vat`:
- Na účtenkách/paragonech (document_kind="receipt") jsou ceny položek typicky VČETNĚ DPH.
- Pokud jsou ceny položek včetně DPH → `unit_prices_include_vat: true` a do
  `unit_price_without_vat` dej cenu TAK JAK JE NA DOKLADU (přepočet udělá systém).
- Pokud jsou ceny bez DPH → `unit_prices_include_vat: false`.
- Když na dokladu žádný řádek "bez DPH" NENÍ (jednoduchá účtenka, neplátce) → vrať `true`.
- U dokladu od NEPLÁTCE DPH vrať `vat_rate: 0` a `unit_prices_include_vat: true`.

DŮLEŽITÉ k poli `supply_nature`:
- "goods" pro fyzické zboží (vozidlo, stroj, hardware, materiál).
- "services" pro služby (SaaS, licence, poradenství, servis, doprava, nájem).
- "mixed" pro obojí; null když nelze poznat.

DŮLEŽITÉ k poli `expense_kind` (druh nákladu, NA KAŽDÉM ŘÁDKU ZVLÁŠŤ):
- Je to NÁVRH pro účetní, nic se podle něj neúčtuje automaticky. Radši null než tip naslepo.
- Klasifikuj VÝHRADNĚ těmito hodnotami; význam je ZÁVAZNÝ, neřiď se vlastní představou
  o tom, co ta anglická slova obvykle znamenají:
  - "service"      = Služba. Plnění, po kterém nezůstane věc: doprava, poštovné, balné,
                     záruka, licence, předplatné, hosting, nájem/pronájem, tarif a
                     vyúčtování operátora, servis, oprava, školení, poradenství, montáž.
  - "material"     = Spotřební materiál. Věc, která se spotřebuje: PHM (natural, diesel,
                     benzin, nafta), toner, cartridge, papír, kancelářské potřeby, kabeláž.
                     POZOR: PHM je "material", NIKDY "small_asset".
  - "small_asset"  = Drobný majetek. Samostatná věc dlouhodobého užívání pod hranicí
                     dlouhodobého majetku: notebook, tablet, mobilní telefon, monitor,
                     tiskárna, router, kávovar, skartovačka, sluchátka, flash disk.
  - "fixed_asset"  = Dlouhodobý majetek. Hmotná věc s cenou za kus nad 80 000 Kč bez DPH
                     (vozidlo, stroj, sestava). Při pochybnosti o ceně vrať "small_asset",
                     hranici si ohlídáme sami.
- ROZHODUJE POVAHA ŘÁDKU, NE DODAVATEL. Jedna faktura běžně míchá druhy: na faktuře z
  Alzy je notebook "small_asset", brašna "material", doprava a prodloužená záruka "service".
- Když řádek zmiňuje službu (doprava, doručení, záruka, licence, pronájem, vyúčtování),
  NIKDY to není "small_asset", ani kdyby v textu byl název zařízení.
- "telefon"/"mobil" sám o sobě nestačí: „mobilní telefon Samsung" (nákup přístroje) je
  "small_asset", ale „Vyúčtování telefonních služeb" je "service".
- Když si nejsi jistý → `expense_kind: null` a `expense_kind_confidence: 0`.
- `expense_kind_confidence` = tvoje jistota 0..1. `expense_kind_reasoning` = jedna krátká
  česká věta PROČ, s citací slova z dokladu (např. „řádek uvádí tablet Galaxy Tab").
  Nevkládej do zdůvodnění osobní údaje (jména, e-maily, telefonní čísla).

DŮLEŽITÉ k poli `vendor.is_vat_payer`:
- false pokud doklad značí, že dodavatel je neplátce DPH (text "Neplátce DPH", chybí DIČ i DPH).
- true pokud má platné DIČ a/nebo je vyčíslena DPH.
- null když nelze určit.

DŮLEŽITÉ k poli `vendor_invoice_number`:
- Číslo dokladu tak jak je vytištěné. Účtenka nemusí mít číslo → null. NEVYMÝŠLEJ.
- U DOBROPISU (`credit_note`) sem dej VLASTNÍ číslo opravného dokladu („Opravný daňový
  doklad č.", „Dobropis č.", „Doklad č."), NIKDY číslo opravované faktury z odkazu
  („k faktuře č.", „Opravovaný/Původní doklad č.") — to patří do `corrected_invoice_number`.

DŮLEŽITÉ k poli `corrected_invoice_number` (JEN u dobropisu):
- Číslo OPRAVOVANÉ faktury z odkazu („k faktuře č.", „Opravovaný/Původní doklad č.").
  Jinak (běžná faktura/účtenka/záloha, bez odkazu) → null. NEVYMÝŠLEJ.

DŮLEŽITÉ k poli `payment` (platební údaje DODAVATELE = příjemce platby):
- `bank_account` = číslo účtu v českém formátu "[předčíslí-]číslo/kód_banky" jak je na dokladu.
- `iban` = IBAN dodavatele; `variable_symbol` = VS platby (typicky číslo faktury).
- VŽDY účet DODAVATELE, NIKDY odběratele. Pokud údaj NENÍ → null.

DŮLEŽITÉ k dobropisu (document_kind="credit_note"):
- `quantity`, `unit_price_without_vat`, totály a `vat_recap` vrať jako KLADNÁ čísla
  (absolutní hodnoty z PDF). Znaménko aplikuje importér.

DŮLEŽITÉ k slevám (jen u document_kind="invoice"):
- Řádek se slevou/rabatem se znaménkem MÍNUS na PDF → `unit_price_without_vat` ZÁPORNÉ.

DŮLEŽITÉ k poli `already_paid`:
- "ZAPLACENO"/"UHRAZENO"/"PAID"/"Hradí se ze zálohy" → true; jinak false.

DŮLEŽITÉ k poli `payment.method` (FORMA ÚHRADY):
- Hledej VÝSLOVNÝ text o formě úhrady. Popisky: "Forma úhrady", "Způsob platby",
  "Způsob úhrady", "Forma platby", "Úhrada", "Platba", "Spôsob úhrady" (SK),
  "Payment method".
- "Inkaso"/"Inkasem"/"Souhlas s inkasem"/"SIPO"/"Direct debit"/"bude uhrazena inkasem"/
  "částka bude stržena z vašeho účtu"/"Neplaťte" → "direct_debit".
- "Převodem"/"Bankovním převodem"/"Příkazem k úhradě"/"Bank transfer" → "bank_transfer".
- "Kartou"/"Platební kartou"/"Card" → "card".
- "Hotově"/"V hotovosti"/"Cash" → "cash".
- "Dobírka"/"Na dobírku"/"Cash on delivery"/"COD" → "cash_on_delivery".
- "Zápočtem"/"Vzájemný zápočet"/"Offset" → "offset".
- VAROVÁNÍ — TOHLE JE NEJČASTĚJŠÍ CHYBA: inkasní doklad má TÉMĚŘ VŽDY uvedené i číslo
  účtu, variabilní symbol a konstantní symbol (a často i QR kód). Jsou tam kvůli
  identifikaci platby, NE jako pokyn k převodu. Přítomnost bankovního spojení, VS, KS
  ani QR kódu NENÍ důkaz, že se platí převodem. Rozhoduje VÝHRADNĚ výslovný text.
- Pokud forma úhrady na dokladu výslovně UVEDENÁ NENÍ → vrať null. NEHÁDEJ ji a
  NEODVOZUJ z přítomnosti bankovního spojení.
- `payment.method_confidence` = 0..1, jak jistě jsi formu vyčetl (0 když vracíš null).

DŮLEŽITÉ k poli `advance_reference`:
- Odkaz na zaplacenou zálohu/proformu ("Odečet zálohy", "k zálohové faktuře č. …") →
  vrať identifikátor té zálohy; jinak null.
- U daňového dokladu k přijaté platbě (`document_kind="tax_document"`) sem VŽDY dej číslo
  zálohové faktury / proformy, ke které se doklad váže (VS nebo číslo té zálohy).

DŮLEŽITÉ k zaokrouhlení:
- `total_with_vat` = přesný součet; `total_with_vat_rounded` = zaokrouhlená částka jen
  pokud je na PDF; jinak null.

DŮLEŽITÉ k `vat_recap` (rekapitulace DPH po sazbách):
- Pro každou sazbu vrať {"rate":%, "base":základ, "vat":daň} jako kladná čísla jak jsou
  na dokladu. Pokud rekapitulaci nemá → vrať prázdné pole [].

DŮLEŽITÉ k `items`:
- Vrať POUZE listové (atomické) položky. NIKDY agregační/subtotalové/součtové řádky
  ("Celkem", "Mezisoučet", "Subtotal", "Total" sekce).
- U vícestránkových dokladů (faktura + rozpis) vrať jen fakturační řádky z hlavní
  faktury, jejichž součet odpovídá "K úhradě". NIKDY řádky z podrobného rozpisu.

DŮLEŽITÉ k poli `line_total_without_vat`:
- Pokud má řádek vlastní sloupec s celkovou částkou ZA ŘÁDEK BEZ DPH, opiš ho přesně.
  Jinak null. Hodnota je vždy bez DPH; u účtenek (ceny s DPH) vrať null.

DŮLEŽITÉ k poli `total_with_vat`:
- Hodnota MUSÍ pocházet z hlavního finálního "K úhradě"/"Total amount due".
- NIKDY ze subtotalu sekce/skupiny. Pokud si nejsi jistý → NULL.
EOT;
    }

    /** Systémový prompt pro extrakci řádků výpisu palivové karty. */
    public static function fuelSystem(): string
    {
        return <<<'EOT'
Jsi expert na čtení detailních výpisů tankování z faktur palivových karet (Axigon, CCS, Shell, Eurowag…).
Z PDF vytáhneš JEDNOTLIVÉ transakce (každé tankování / službu jako samostatný řádek) ve striktním JSON.

Schema:
{"transactions": [
  {
    "fueled_date": "YYYY-MM-DD",
    "fueled_time": "HH:MM" | null,
    "fuel_type": "string",
    "quantity": number | null,
    "unit_price": number | null,
    "amount_without_vat": number | null,
    "amount_vat": number | null,
    "amount_with_vat": number,
    "station": "string" | null,
    "receipt_number": "string" | null,
    "is_fuel": true | false
  }
]}

Pravidla:
- Vrať VŠECHNY řádky transakcí (palivo i služby). is_fuel rozliš podle názvu zboží.
- Čísla bez měny a bez oddělovačů tisíců (z "1 234,56 Kč" vrať 1234.56).
- Datum normalizuj na YYYY-MM-DD (dvojmístný rok → 20xx).
- NEvracej souhrnné/CELKEM řádky ani hlavičky karet — jen jednotlivé transakce.
- Odpověz JEN samotným JSON, bez markdownu.
EOT;
    }

    /** Systémový prompt pro levný recheck jen totalu. */
    public static function totalSystem(): string
    {
        return <<<'EOT'
Z PDF faktury vrátíš JEN finální částku k úhradě (= "K úhradě", "Celkem k platbě", "Total to pay")
ve formátu JSON. Žádný markdown, žádné komentáře.

Schema: {"total_with_vat": number}
- number je číslo bez měny (z 1 502,00 Kč vrať 1502.00)
- Pokud finální K úhradě nelze určit jednoznačně, vrať {"total_with_vat": null}
- U DOBROPISU vrať kladné číslo (znaménko si aplikujeme my)
- POZOR na sekce "Z minulého období" / "Přijaté platby" — to NENÍ aktuální K úhradě.
EOT;
    }

    /** Systémový prompt pro doplnění platebního účtu dodavatele. */
    public static function paymentAccountSystem(): string
    {
        return <<<'EOT'
Z PDF faktury vrátíš JEN platební údaje DODAVATELE (příjemce platby) ve formátu JSON.
Žádný markdown, žádné komentáře.

Schema: {"bank_account": string|null, "iban": string|null, "variable_symbol": string|null}
- `bank_account` = číslo účtu dodavatele v českém formátu "[předčíslí-]číslo/kód_banky" jak je na dokladu.
- `iban` = IBAN dodavatele pokud je uveden.
- `variable_symbol` = variabilní symbol platby (VS), typicky shodný s číslem faktury.
- VŽDY jde o účet PŘÍJEMCE PLATBY = DODAVATELE, NIKDY odběratele.
- Pokud údaj na dokladu NENÍ → null. NEVYMÝŠLEJ.
EOT;
    }

    /**
     * JSON schema kotva pro provider-nativní structured output (OpenAI/Azure
     * `response_format=json_schema`, Gemini `responseSchema`). Zrcadlí extrakční
     * tvar z {@see self::invoiceSystem()} — centrálně měnitelné.
     *
     * @return array<string,mixed>
     */
    public static function invoiceJsonSchema(): array
    {
        $party = [
            'type'       => 'object',
            'properties' => [
                'company_name' => ['type' => ['string', 'null']],
                'ic'           => ['type' => ['string', 'null']],
                'dic'          => ['type' => ['string', 'null']],
                'street'       => ['type' => ['string', 'null']],
                'city'         => ['type' => ['string', 'null']],
                'zip'          => ['type' => ['string', 'null']],
                'country_iso2' => ['type' => ['string', 'null']],
                'email'        => ['type' => ['string', 'null']],
                'phone'        => ['type' => ['string', 'null']],
                'web'          => ['type' => ['string', 'null']],
                'is_vat_payer' => ['type' => ['boolean', 'null']],
            ],
        ];

        return [
            'type'       => 'object',
            'properties' => [
                'vendor'   => $party,
                'customer' => [
                    'type'       => 'object',
                    'properties' => [
                        'company_name' => ['type' => ['string', 'null']],
                        'ic'           => ['type' => ['string', 'null']],
                        'dic'          => ['type' => ['string', 'null']],
                    ],
                ],
                'payment' => [
                    'type'       => 'object',
                    'properties' => [
                        'bank_account'    => ['type' => ['string', 'null']],
                        'iban'            => ['type' => ['string', 'null']],
                        'variable_symbol' => ['type' => ['string', 'null']],
                        // Migrace 1128 — forma úhrady. null je POVOLENÁ a správná odpověď,
                        // když doklad formu neuvádí; význam („inkaso má taky VS a účet")
                        // nese prompt. Znovu se validuje v AiPdfExtractor.
                        'method'            => ['type' => ['string', 'null'], 'enum' => ['bank_transfer', 'direct_debit', 'card', 'cash', 'cash_on_delivery', 'offset', 'other', null]],
                        'method_confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'vendor_invoice_number'  => ['type' => ['string', 'null']],
                'corrected_invoice_number' => ['type' => ['string', 'null']],
                'varsymbol'              => ['type' => ['string', 'null']],
                'document_kind'          => ['type' => 'string', 'enum' => ['invoice', 'credit_note', 'advance', 'receipt', 'tax_document']],
                'issue_date'             => ['type' => 'string'],
                'tax_date'               => ['type' => ['string', 'null']],
                'due_date'               => ['type' => ['string', 'null']],
                'currency'               => ['type' => 'string'],
                'items'                  => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'description'            => ['type' => 'string'],
                            'quantity'               => ['type' => 'number'],
                            'unit'                   => ['type' => ['string', 'null']],
                            'unit_price_without_vat' => ['type' => 'number'],
                            'line_total_without_vat' => ['type' => ['number', 'null']],
                            'vat_rate'               => ['type' => 'number'],
                            // §DM — návrh druhu nákladu na řádku. Enum drží providera u čtyř
                            // povolených hodnot; význam nese prompt. Validuje se stejně
                            // znovu v AiExpenseKindProposal (schema je pohodlí, ne záruka).
                            'expense_kind'            => ['type' => ['string', 'null'], 'enum' => ['service', 'material', 'small_asset', 'fixed_asset', null]],
                            'expense_kind_confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                            'expense_kind_reasoning'  => ['type' => ['string', 'null'], 'maxLength' => 300],
                        ],
                    ],
                ],
                'unit_prices_include_vat' => ['type' => 'boolean'],
                'total_without_vat'       => ['type' => ['number', 'null']],
                'total_with_vat'          => ['type' => ['number', 'null']],
                'total_with_vat_rounded'  => ['type' => ['number', 'null']],
                'vat_recap'               => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'rate' => ['type' => 'number'],
                            'base' => ['type' => 'number'],
                            'vat'  => ['type' => 'number'],
                        ],
                    ],
                ],
                'already_paid'      => ['type' => 'boolean'],
                'advance_reference' => ['type' => ['string', 'null']],
                'supply_nature'     => ['type' => ['string', 'null']],
            ],
        ];
    }

    /**
     * Prioritní hlavička promptu s tenant info — říká AI, že tato firma je VŽDY
     * odběratel (customer), NIKDY dodavatel. 1:1 zrcadlo
     * {@see AnthropicClient::buildTenantContextBlock()}; při chybě DB vrací ''.
     */
    public static function tenantContext(Connection $db, int $supplierId): string
    {
        try {
            $stmt = $db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $t = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return '';
        }
        if ($t === false || empty($t['company_name']) && empty($t['ic'])) {
            return '';
        }
        $name = (string) ($t['company_name'] ?? '');
        $ic   = (string) ($t['ic'] ?? '');
        $dic  = (string) ($t['dic'] ?? '');
        $hint = [];
        if ($name !== '') $hint[] = "název \"{$name}\"";
        if ($ic !== '')   $hint[] = "IČO \"{$ic}\"";
        if ($dic !== '')  $hint[] = "DIČ \"{$dic}\"";
        $tenantHint = implode(', ', $hint);

        return sprintf(
            "DŮLEŽITÝ KONTEXT (čti jako první, předchází všechna ostatní pravidla):\n"
            . "- Toto je extrakce PŘIJATÉ faktury pro firmu: %s.\n"
            . "- Tato firma je VŽDY odběratel (customer) — NIKDY ne dodavatel (vendor).\n"
            . "- Pokud v PDF vidíš tuto firmu (matchuj IČO nebo název), vrať ji v poli `customer`, NIKDY v poli `vendor`.\n"
            . "- Dodavatel (vendor) je VŽDY ta druhá strana — ten, kdo fakturu vystavil.\n\n",
            $tenantHint,
        );
    }

    /**
     * Sdílený dekód: z čistého textu odpovědi (už vytaženého z provider-nativní
     * obálky) udělá normalizované pole vendor/customer/items/… — strip markdown
     * fences + json_decode. Vrací null když text není validní JSON objekt.
     * Právě tento krok garantuje IDENTICKÝ dekódovaný tvar napříč providery
     * (golden-schema kontrakt §11.1).
     *
     * @return array<string,mixed>|null
     */
    public static function decodeJsonText(string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        $stripped = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text) ?? $text;
        $data = json_decode(trim($stripped), true);
        return is_array($data) ? $data : null;
    }
}
