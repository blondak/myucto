<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use PDO;

/**
 * Výchozí zařazení mzdových složek do JMHZ — JEDINÉ místo, kde se rozhoduje.
 *
 * Výchozí číselník složek ({@see PayrollComponentDefaults}) dodáváme my, takže
 * u složek, kde je cílový atribut jednoznačný, nemá smysl nutit každou účetní
 * vyplnit totéž ručně. Doplňuje se JEN tam, kde ještě žádná volba neexistuje;
 * rozhodnutí účetní — včetně vědomě zrušeného (deaktivovaného) mapování — se
 * nikdy nepřepisuje ani neobnovuje.
 *
 * Mapuje se na NEJPODROBNĚJŠÍ uzel, který složce odpovídá, ne na sběrný součet:
 * součty do nadřazených atributů se dopočítají samy přes `ancestor_attribute_ids`
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder}),
 * takže detail naplní i catch-all total nad sebou, kdežto opačně to nejde.
 *
 * ── Kde se to volá ──────────────────────────────────────────────────────────
 * Dřív se výchozí zařazení doplňovalo jen při otevření obrazovky zařazení
 * a při přípravě hlášení. Kontrola před mzdovým během proto u firmy, jejíž
 * účetní tu obrazovku neotevřela, hlásila „složka nemá zařazení" u Základní
 * měsíční mzdy, Úkolové mzdy i Odměny — složek, které aplikace založila sama.
 * Teď se zařazení doplní tam, kde složka VZNIKÁ
 * ({@see \MyInvoice\Repository\Payroll\PayrollComponentRepository::ensureDefaults()}
 * a {@see \MyInvoice\Repository\Payroll\PayrollComponentRepository::create()},
 * kudy jdou výchozí číselník, vstupní brána, import docházky i ruční založení),
 * a u existujících firem ho dorovná migrace 1839 podle téhož pravidla.
 */
final class PayrollComponentJmhzMappingDefaults
{
    /**
     * Kód výchozí složky → cílový atribut JMHZ. Kód má přednost před druhem.
     *
     * Záměrně tu NEJSOU složky, u kterých je zařazení úsudek účetní a špatný
     * default by byl horší než žádný — PROVIZE, ODSTUPNE,
     * NAHRADA_KONKURENCNI_DOLOZKA, DOPLATEK_MZDY, NEPENEZNI_PRIJEM,
     * CESTOVNI_NAHRADA*, PRISPEVEK_RIZIKOVE_SPORENI a všechny benefity
     * (PRISPEVEK_*, VZDELAVANI, REKREACE_VOLNY_CAS, ZDRAVOTNI_BENEFIT,
     * PRECHODNE_UBYTOVANI, SOUKROME_VOZIDLO). Předvyplněná chybná hodnota by
     * prošla do hlášení tiše, kdežto prázdné zařazení obrazovka viditelně
     * hlásí jako `missing` a účetní ho musí vyřešit.
     *
     * Totéž platí pro rozpad příspěvku zaměstnavatele na produkty spoření na
     * stáří (10292–10296): která smlouva to je — penzijní připojištění,
     * doplňkové penzijní spoření, penzijní pojištění, životní pojištění nebo
     * DIP — z číselníku složek nijak neplyne. Společná složka
     * PRISPEVEK_PENZE_ZIVOTNI nese všechny produkty naráz, takže default by byl
     * hádání; zařazení dělá účetní podle konkrétní smlouvy.
     *
     * @var array<string,string>
     */
    private const DEFAULTS = [
        // Tarifní mzdy — základ mzdy bez ohledu na způsob odměňování.
        'MZDA_MESICNI' => '10329',
        'MZDA_HODINOVA' => '10329',
        'MZDA_UKOLOVA' => '10329',
        // Prémie a odměny nepravidelné.
        'ODMENA' => '10331',
        // Příplatky celkem. Společná složka „Prémie a příplatky" nese obojí,
        // ale zákonné příplatky mají od migrace vlastní kódy, takže sem patří
        // jako sběrný uzel příplatků.
        'PREMIE_PRIPLATKY' => '10332',
        'PRIPLATEK_PRESCAS' => '10333',
        'PRIPLATEK_NOCNI' => '10334',
        'PRIPLATEK_VIKEND' => '10335',
        'PRIPLATEK_SVATEK' => '10336',
        // Příplatek za ztížené pracovní prostředí vlastní detailní uzel v JMHZ
        // nemá, zůstává tedy na sběrném součtu příplatků.
        'PRIPLATEK_ZTIZENE_PROSTREDI' => '10332',
        // Náhrady mzdy zúčtované; náhrada při DPN má vlastní detailní uzel.
        'NAHRADA_MZDY' => '10337',
        'NAHRADA_MZDY_DOVOLENA' => '10338',
        'NAHRADA_MZDY_DPN' => '10342',
        // Jediné jednoznačné zařazení z rozpadu příspěvku zaměstnavatele:
        // příspěvek na dlouhodobou péči má vlastní detailní uzel a žádný jiný
        // produkt do něj nespadá. Ostatní produkty spoření na stáří tu záměrně
        // nejsou — viz komentář nad konstantou.
        'PRISPEVEK_DLOUHODOBA_PECE' => '10418',
    ];

    /**
     * Druh složky → cílový atribut, pro složky BEZ vlastního kódu v {@see self::DEFAULTS}
     * (import docházky, ručně založené složky).
     *
     * Každý řádek je doslova totéž rozhodnutí, jaké už výchozí číselník dělá pro
     * svou složku téhož druhu — nic nového se tu nevymýšlí:
     *
     *  - `base_wage`, `hourly_wage`, `task_wage` → 10329 Tarifní mzdy, jako
     *    MZDA_MESICNI / MZDA_HODINOVA / MZDA_UKOLOVA;
     *  - `premium` → 10332 Příplatky celkem, jako PREMIE_PRIPLATKY
     *    a PRIPLATEK_ZTIZENE_PROSTREDI. Detailní uzly 10333–10336 mají jen
     *    zákonné příplatky s vlastním kódem; příplatek neznámého obsahu jde na
     *    sběrný uzel, ne na detail, který by mohl tvrdit něco nepravdivého;
     *  - `compensation` → 10337 Náhrady mzdy zúčtované, jako NAHRADA_MZDY —
     *    jen u ZDANĚNÉ náhrady. Osvobozená náhrada je náhrada při DPN a ta
     *    v hlášení stojí vedle 10337 na 10342
     *    ({@see PayrollComponentJmhzTargetCatalog}), takže ji druh sám neurčí.
     *
     * `bonus` řeší {@see self::bonusTarget()}: rozdělení 10330/10331 je podle
     * pravidelnosti, a tu nese četnost složky, ne její druh.
     *
     * Ostatní druhy (`commission`, `allowance`, `severance`, `backpay`,
     * `other`, benefity, …) záměrně nemají nic — z druhu jejich zařazení
     * neplyne. Proto zůstává nezařazená i složka „podle hlavičky" z importu
     * docházky (DOCH_<SLUG>, druh `other`): čím je, ví jen účetní.
     *
     * @var array<string,string>
     */
    private const KIND_DEFAULTS = [
        'base_wage' => '10329',
        'hourly_wage' => '10329',
        'task_wage' => '10329',
        'premium' => '10332',
        'compensation' => '10337',
    ];

    /** Hodnoty `payroll_component_definitions.frequency_kind` (migrace 1210). */
    private const FREQUENCY_KINDS = ['regular', 'one_off'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentJmhzMappingRepository $mappings,
    ) {}

    /**
     * Doplní chybějící výchozí mapování a vrátí jen ta, která opravdu vznikla.
     *
     * Opakované volání nic nemění: složka, která už jakýkoli záznam mapování má
     * (aktivní i deaktivovaný), se přeskočí. Selhání u jedné složky nesmí shodit
     * celý průchod — cíl může v nainstalovaném balíčku specifikace chybět nebo
     * si účetní mohla složku přeřadit mimo JMHZ; takovou složku přeskočíme
     * a zbytek dokončíme.
     *
     * `created_by` zůstává NULL: předvyplnění udělala aplikace, ne účetní, a
     * podle prázdného autora se to pozná i zpětně.
     *
     * Předtím se převezmou zařazení ze staršího balíku specifikace
     * ({@see PayrollComponentJmhzMappingRepository::adoptLegacy()}) — výchozí
     * zařazení platí jen pro složky, které žádné rozhodnutí nemají v žádném
     * balíku. Převzetí je fail-soft jako zbytek průchodu: instalace bez
     * registru specifikace nesmí shodit obrazovku zařazení ani přípravu hlášení.
     *
     * @param list<int>|null $componentIds jen tyto složky; `null` = celá firma
     * @return list<array<string,mixed>> nově založená mapování
     */
    public function apply(int $supplierId, ?array $componentIds = null): array
    {
        if ($componentIds === []) {
            return [];
        }
        try {
            $this->mappings->adoptLegacy($supplierId);
        } catch (\Throwable) {
        }
        $existing = $this->mappings->listForSupplier($supplierId);
        $applied = [];
        foreach ($this->definitions($supplierId, $componentIds) as $component) {
            if (PayrollTimeValue::string($component['jmhz_treatment'] ?? null, 'jmhz_treatment') !== 'included') {
                continue;
            }
            $target = self::targetFor(
                PayrollTimeValue::string($component['code'] ?? null, 'code'),
                PayrollTimeValue::string($component['component_kind'] ?? null, 'component_kind'),
                PayrollTimeValue::string($component['frequency_kind'] ?? null, 'frequency_kind'),
                PayrollTimeValue::string($component['tax_treatment'] ?? null, 'tax_treatment'),
            );
            if ($target === null) {
                continue;
            }
            $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
            if (isset($existing[$componentId])) {
                continue;
            }
            try {
                $applied[] = $this->mappings->put($supplierId, $componentId, $target, null, null);
            } catch (\Throwable) {
                continue;
            }
        }

        return $applied;
    }

    /**
     * Výchozí zařazení celé firmy JEDNÍM příkazem — pro zakládání číselníku.
     *
     * {@see \MyInvoice\Repository\Payroll\PayrollComponentRepository::ensureDefaults()}
     * běží na každém čtení složek a stránky, které ho volají, mají hlídanou
     * cenu v počtu dotazů (rychlý vstup pro stovky lidí). Zápis po jednom přes
     * `put()` tam proto nejde: první čtení by stálo o stovky dotazů víc než
     * každé další. Příkaz je vždy právě jeden, ať je co doplnit, nebo ne.
     *
     * Pravidlo se do SQL NEOPISUJE: podmínky vznikají voláním
     * {@see self::targetFor()} nad všemi hodnotami druhu, četnosti a daňového
     * zacházení, SQL je jen dohledá. Rozhodnutí účetní platí stejně jako
     * v {@see self::apply()} — složka s jakýmkoli záznamem zařazení se přeskočí.
     *
     * Zařazuje se do balíku specifikace, který aplikace čte
     * ({@see PayrollComponentJmhzTargetCatalog::PACKAGE_KEY}); chybí-li, nainstaluje
     * se ({@see PayrollComponentJmhzMappingRepository::currentPackageId()}).
     * Nejdřív se do něj převezmou zařazení ze staršího balíku
     * ({@see PayrollComponentJmhzMappingRepository::adoptLegacy()}), aby firma
     * po přechodu na nový balík nepřišla o rozhodnutí účetní. V ustáleném stavu
     * jsou to vždy čtyři příkazy: dohledání balíku, převzetí (dva) a zápis.
     *
     * @return int počet nově založených zařazení
     */
    public function seed(int $supplierId): int
    {
        $packageId = $this->mappings->currentPackageId();
        $this->mappings->adoptLegacy($supplierId, $packageId);
        [$sql, $params] = self::seedStatement($supplierId, $packageId);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Dohledávací tabulka pro {@see self::seed()}, spočítaná z {@see self::targetFor()}.
     *
     * @return array{
     *   codes:array<string,string>,
     *   kinds:list<array{component_kind:string,frequency_kind:string,tax_treatment:string,target:string}>
     * }
     */
    public static function seedTable(): array
    {
        $kinds = [];
        foreach ([...array_keys(self::KIND_DEFAULTS), 'bonus'] as $kind) {
            foreach (self::FREQUENCY_KINDS as $frequency) {
                foreach (PayrollComponentTaxTreatment::cases() as $tax) {
                    $target = self::targetFor('', $kind, $frequency, $tax->value);
                    if ($target !== null) {
                        $kinds[] = [
                            'component_kind' => $kind,
                            'frequency_kind' => $frequency,
                            'tax_treatment' => $tax->value,
                            'target' => $target,
                        ];
                    }
                }
            }
        }

        return ['codes' => self::DEFAULTS, 'kinds' => $kinds];
    }

    /** @return array{0:string,1:list<int|string>} */
    private static function seedStatement(int $supplierId, int $packageId): array
    {
        $table = self::seedTable();
        $params = [$packageId];
        $byCode = [];
        foreach ($table['codes'] as $code => $target) {
            $byCode[] = 'WHEN ? THEN ?';
            array_push($params, $code, $target);
        }
        $byKind = [];
        foreach ($table['kinds'] as $row) {
            $byKind[] = 'WHEN definition.component_kind = ? AND definition.frequency_kind = ?'
                . ' AND definition.tax_treatment = ? THEN ?';
            array_push($params, $row['component_kind'], $row['frequency_kind'], $row['tax_treatment'], $row['target']);
        }
        $params[] = $supplierId;

        // CASE s parametry, ne odvozená tabulka: parametr se podřídí collation
        // sloupce, kdežto sloupec odvozené tabulky by na instalaci s jinou
        // výchozí collation skončil „Illegal mix of collations".
        $sql = 'INSERT IGNORE INTO payroll_component_jmhz_mappings
                    (supplier_id, component_definition_id, spec_package_id,
                     target_attribute_id, created_by, updated_by)
                SELECT definition.supplier_id, definition.id, attribute.package_id,
                       attribute.attribute_id, NULL, NULL
                  FROM payroll_component_definitions definition
                  JOIN payroll_jmhz_dictionary_attributes attribute
                    ON attribute.package_id = ?
                   AND attribute.attribute_id = COALESCE(
                         CASE definition.code ' . implode(' ', $byCode) . ' END,
                         CASE ' . implode(' ', $byKind) . ' END
                       )
                 WHERE definition.supplier_id = ?
                   AND definition.jmhz_treatment = \'included\'
                   AND NOT EXISTS (
                         SELECT 1
                           FROM payroll_component_jmhz_mappings existing
                          WHERE existing.supplier_id = definition.supplier_id
                            AND existing.component_definition_id = definition.id
                       )';

        return [$sql, $params];
    }

    /**
     * Výchozí zařazení složky: podle kódu výchozího číselníku, jinak podle druhu.
     * `null` = zařazení je úsudek účetní a nepředvyplňuje se.
     */
    public static function targetFor(
        string $code,
        string $componentKind,
        string $frequencyKind,
        string $taxTreatment,
    ): ?string {
        $byCode = self::DEFAULTS[$code] ?? null;
        if ($byCode !== null) {
            return $byCode;
        }
        if ($componentKind === 'bonus') {
            return self::bonusTarget($frequencyKind);
        }
        if ($componentKind === 'compensation' && $taxTreatment !== 'included') {
            return null;
        }

        return self::KIND_DEFAULTS[$componentKind] ?? null;
    }

    /**
     * Prémie a odměny se v hlášení dělí na PRAVIDELNÉ (10330) a NEPRAVIDELNÉ
     * (10331). Druh `bonus` to nerozliší, četnost složky ano: jednorázová
     * složka je nepravidelná (tak je zařazená i výchozí ODMENA), opakující se
     * složka z podmínek vztahu je pravidelná.
     */
    private static function bonusTarget(string $frequencyKind): ?string
    {
        return match ($frequencyKind) {
            'one_off' => '10331',
            'regular' => '10330',
            default => null,
        };
    }

    /**
     * Výchozí zařazení pro daný kód složky. Slouží testům a případné kontrole
     * číselníku proti katalogu cílů.
     */
    public static function targetForCode(string $code): ?string
    {
        return self::DEFAULTS[$code] ?? null;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::DEFAULTS;
    }

    /** @return array<string,string> */
    public static function kindDefaults(): array
    {
        return self::KIND_DEFAULTS;
    }

    /**
     * Složky čtené přímo, ne přes repozitář složek: repozitář tuhle třídu volá
     * při zakládání složek, takže závislost opačným směrem by byla kruh.
     *
     * @param list<int>|null $componentIds
     * @return list<array<string,mixed>>
     */
    private function definitions(int $supplierId, ?array $componentIds): array
    {
        $sql = 'SELECT id, code, component_kind, frequency_kind, tax_treatment, jmhz_treatment
                  FROM payroll_component_definitions
                 WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($componentIds !== null) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($componentIds), '?')) . ')';
            array_push($params, ...$componentIds);
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_component_definitions');
    }
}
