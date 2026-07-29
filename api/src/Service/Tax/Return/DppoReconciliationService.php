<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;

/**
 * Featura A — „Rekonciliace proti PODANÉMU přiznání" (`private/REAL_data_followup_UX.md` §A,
 * epic issue #18 follow-up). Read-only: nahraje se EPO XML DPPDP9 od účetní (to, co bylo
 * REÁLNĚ podáno), aplikace ho naparsuje ({@see DppoEpoXmlParser}) a porovná řádek-po-řádku
 * ({@see DppoReconciliationDiffBuilder}) s vlastním výpočtem ({@see DppoReturnCalculator}
 * nad podklady {@see DppoReturnDataProvider} a uloženými ručními vstupy draftu/finále přiznání
 * za dané období). NIC neukládá, nic neúčtuje — čistě diagnostický náhled.
 *
 * Provozně ověřeno (2026-07, DPH varianta stejného principu): najde reálné vady v datech
 * dřív, než je odhalí kontrola/účetní. Doplňkově kontroluje IČO/DIČ a zdaňovací období
 * podaného XML proti aktuální firmě — časté selhání je omylem nahraný soubor jiné firmy/roku.
 *
 * VADA 2 (audit `private/AUDIT-*`): rok podaného XML (zdobd_do/od) proti vybranému roku na
 * obrazovce je TVRDÁ kontrola ({@see parsedYear()}) — při neshodě se vyhodí {@see TaxReturnException}
 * ('reconcile_year_mismatch', 422) PŘED výpočtem, nikdy se nespočítá ani nevykreslí nesmyslný
 * řádkový diff (2024 XML na obrazovce 2025 dřív tiše ukazovalo fiktivní rozdíly). IČO/DIČ a
 * účetní období oproti tomu zůstávají jen nezávazným varováním (`warnings`) — firma i účetní
 * období se dá legitimně lišit (např. kontrola cizí firmy), rok podání ne.
 */
final class DppoReconciliationService
{
    public function __construct(
        private readonly TaxReturnRepository $returns,
        private readonly TaxConstantsRepository $constants,
        private readonly DppoReturnDataProvider $data,
        private readonly DppoReturnCalculator $calc,
        private readonly DppoEpoXmlParser $parser,
        private readonly DppoReconciliationDiffBuilder $diffBuilder,
        private readonly Connection $db,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function reconcile(int $supplierId, int $year, string $xml, string $variant = 'radne', int $variantSeq = 1): array
    {
        $parsed = $this->parser->parse($xml);

        $uploadedYear = $this->parsedYear($parsed);
        if ($uploadedYear !== null && $uploadedYear !== $year) {
            throw new TaxReturnException(
                'reconcile_year_mismatch',
                sprintf(
                    'Nahrané přiznání je za rok %d, ale porovnáváš rok %d — nahraj přiznání za rok %d.',
                    $uploadedYear,
                    $year,
                    $year
                ),
                422
            );
        }

        try {
            $const = $this->constants->forExactYear($year);
        } catch (\OutOfRangeException $e) {
            throw new TaxReturnException('missing_tax_constants', $e->getMessage(), 422);
        }

        $row = $this->returns->find($supplierId, $year, 'po', $variant, $variantSeq);
        $inputs = $row !== null ? (array) $row['inputs'] : [];

        $gathered = $this->data->gather($supplierId, $year);
        $result = $this->calc->compute($gathered, $inputs, $const);

        $diff = $this->diffBuilder->build((array) $result['lines'], $parsed['lines']);

        $warnings = [];
        $supplier = $this->loadSupplierIdentity($supplierId);
        if ($parsed['supplier']['ic'] !== '' && $supplier['ic'] !== '' && $parsed['supplier']['ic'] !== $supplier['ic']) {
            $warnings[] = sprintf(
                'Nahrané přiznání patří IČO %s (%s), ale aktuální firma má IČO %s — zkontrolujte, že jste nahráli správný soubor.',
                $parsed['supplier']['ic'],
                $parsed['supplier']['name'] !== '' ? $parsed['supplier']['name'] : '?',
                $supplier['ic']
            );
        }
        $ourPeriod = $gathered['period'] ?? null;
        if ($ourPeriod !== null && $parsed['zdobd_od'] !== null && $parsed['zdobd_do'] !== null) {
            $ourStarts = substr((string) ($ourPeriod['starts_on'] ?? ''), 0, 10);
            $ourEnds = substr((string) ($ourPeriod['ends_on'] ?? ''), 0, 10);
            if ($ourStarts !== $parsed['zdobd_od'] || $ourEnds !== $parsed['zdobd_do']) {
                $warnings[] = sprintf(
                    'Zdaňovací období v nahraném XML (%s–%s) neodpovídá vybranému účetnímu období v aplikaci (%s–%s).',
                    $parsed['zdobd_od'],
                    $parsed['zdobd_do'],
                    $ourStarts !== '' ? $ourStarts : '?',
                    $ourEnds !== '' ? $ourEnds : '?'
                );
            }
        }
        if (in_array($parsed['dapdpp_forma'], ['D', 'E'], true)) {
            $warnings[] = 'Nahrané přiznání je DODATEČNÉ (forma ' . $parsed['dapdpp_forma'] . ') — řádky VetaO '
                . 'už nesou PLNÉ přepočtené hodnoty (ne jen rozdíl); rozdílový blok V. oddílu '
                . '(kc_dppiv1/2/3) je vrácen zvlášť v poli „amendment".';
        }

        return [
            'filing' => [
                'verze_pis' => $parsed['verze_pis'],
                'dapdpp_forma' => $parsed['dapdpp_forma'],
                'typ_zo' => $parsed['typ_zo'],
                'zdobd_od' => $parsed['zdobd_od'],
                'zdobd_do' => $parsed['zdobd_do'],
                'supplier' => $parsed['supplier'],
                'rate_pct' => $parsed['rate_pct'],
            ],
            'amendment' => $parsed['amendment'],
            'extra' => $parsed['extra'],
            'diff' => $diff,
            'warnings' => $warnings,
            'variant' => $variant,
            'variant_seq' => $variantSeq,
            'return_status' => $row['status'] ?? null,
        ];
    }

    /**
     * Rok podaného přiznání odvozený z konce (příp. začátku) zdaňovacího období XML —
     * VADA 2 (audit): bez tvrdé kontroly by se spočítal a vykreslil nesmyslný řádkový
     * diff i pro nahrané XML úplně jiného roku, než jaký je vybraný na obrazovce.
     */
    private function parsedYear(array $parsed): ?int
    {
        $date = $parsed['zdobd_do'] ?? $parsed['zdobd_od'] ?? null;
        if (!is_string($date) || $date === '') {
            return null;
        }
        return (int) substr($date, 0, 4);
    }

    /** @return array{ic:string,dic:string} */
    private function loadSupplierIdentity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        return ['ic' => (string) ($row['ic'] ?? ''), 'dic' => (string) ($row['dic'] ?? '')];
    }
}
