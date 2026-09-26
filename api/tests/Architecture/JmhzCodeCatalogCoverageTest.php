<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Každý kód `jmhz_…` a `eldp_…`, který backend vydává, má druh nápravy
 * v `JmhzBlockerCatalog` a popisek v češtině i angličtině
 * (`payroll.jmhz_gate.codes.<kód>`).
 *
 * Dřív UI u padesáti kódů psalo „neznámá blokace" a posílalo na podporu,
 * přestože server věděl, co chybí a kde se to doplňuje. Nový kód bez druhu
 * nápravy nebo bez popisku tady spadne dřív, než se k účetní dostane.
 *
 * Literály se stejnou předponou, které kódem nejsou (klíče polí v datech,
 * názvy tabulek, hodnoty výčtů), vyjmenovává `NON_CODES` po jednom symbolu.
 */
#[Group('architecture')]
final class JmhzCodeCatalogCoverageTest extends TestCase
{
    private const SCAN = 'api/src';

    /** Katalog sám kódy vyjmenovává, takže by jinak kryl sám sebe. */
    private const CATALOG_FILE = 'JmhzBlockerCatalog.php';

    /**
     * Literál s předponou kódu, který kódem není, a čím ve skutečnosti je:
     * field = klíč pole v datech, key = klíč pole nebo úložiště,
     * table = sloupec či tabulka, enum = hodnota výčtu (agenda, druh podání),
     * route = segment routy, config = konfigurační klíč, other = identifikátor kroku.
     *
     * @var array<string,string>
     */
    private const NON_CODES = [
        'eldp_code' => 'field',
        'eldp_code_evidence' => 'field',
        'eldp_code_row_sha256' => 'field',
        'eldp_codebook_content_sha256' => 'field',
        'eldp_control_export' => 'key',
        'eldp_evidence_id' => 'field',
        'eldp_manual_completion' => 'key',
        'eldp_sections' => 'field',
        'eldp_snapshot_fingerprint' => 'field',
        'eldp_source_manifest_sha256' => 'field',
        'eldp_statement' => 'key',
        'eldp_submission' => 'key',
        'eldp_type' => 'field',
        'jmhz_activity' => 'field',
        'jmhz_amount' => 'field',
        'jmhz_amount_minor' => 'field',
        'jmhz_apz_contribution_status' => 'field',
        'jmhz_apz_instrument_code' => 'field',
        'jmhz_codebook' => 'field',
        'jmhz_correction_evidence' => 'field',
        'jmhz_deep_mining_work_applies' => 'table',
        'jmhz_default_interpretations' => 'field',
        'jmhz_employment_external_identifier' => 'field',
        'jmhz_employment_identifier' => 'key',
        'jmhz_environment' => 'field',
        'jmhz_export' => 'config',
        'jmhz_external_codebook_manifest_sha256' => 'field',
        'jmhz_external_codebook_overlay_key' => 'field',
        'jmhz_external_codebooks_verified_for_period' => 'field',
        'jmhz_functional_benefits_status' => 'field',
        'jmhz_identity' => 'route',
        'jmhz_import_form' => 'other',
        'jmhz_import_step' => 'other',
        'jmhz_mapping' => 'key',
        'jmhz_mappings' => 'table',
        'jmhz_monthly_report' => 'enum',
        'jmhz_orchard_discount_eligible' => 'field',
        'jmhz_ozp_employment_support_applies' => 'field',
        'jmhz_preparation' => 'enum',
        'jmhz_preparation_snapshot' => 'enum',
        'jmhz_relationship_detail_code' => 'field',
        'jmhz_rules' => 'key',
        'jmhz_special_scenarios' => 'key',
        'jmhz_specific_legal_fact_applies' => 'field',
        'jmhz_submission' => 'enum',
        'jmhz_submission_correction' => 'enum',
        'jmhz_takeover_detail' => 'other',
        'jmhz_temporary_assignment_status' => 'field',
        'jmhz_transport' => 'key',
        'jmhz_treatment' => 'field',
        'jmhz_unsent' => 'enum',
        'jmhz_validation_external_codebook_manifest_sha256' => 'field',
        'jmhz_validation_external_codebook_overlay_key' => 'field',
        'jmhz_work_summary' => 'field',
        'jmhz_work_summary_hash' => 'field',
        'jmhz_work_summary_status' => 'field',
        'jmhz_workplace_country_code' => 'field',
        'jmhz_workplace_municipality_code' => 'field',
        'jmhz_xml' => 'enum',
        // Počty a upozornění v protokolu převodu PAMICA (PohodaPayrollJmhzWriter),
        // ne kódy blokací podání.
        'jmhz_data_failed' => 'other',
        'jmhz_eldp_days_compared' => 'other',
        'jmhz_eldp_days_differ' => 'other',
        'jmhz_failed' => 'other',
        'jmhz_form_unreadable' => 'other',
        'jmhz_forms' => 'other',
        'jmhz_forms_unreadable' => 'other',
        'jmhz_identifiers_unconfirmed' => 'other',
        'jmhz_messages_truncated' => 'other',
        'jmhz_month_not_sent' => 'other',
        'jmhz_months_not_sent' => 'other',
        'jmhz_oic_invalid' => 'other',
        'jmhz_registrations' => 'other',
        'jmhz_submissions' => 'other',
        'jmhz_terms_closed_employment' => 'other',
    ];

    public function testEveryEmittedCodeHasRemediationAndBothLabels(): void
    {
        $present = $this->emittedLiterals();
        $catalog = array_flip(JmhzBlockerCatalog::codes());
        $locales = $this->codeLabels();

        $missing = [];
        foreach (array_keys($present) as $literal) {
            if (isset(self::NON_CODES[$literal])) {
                continue;
            }
            if (!isset($catalog[$literal])) {
                $missing[] = "{$literal}: chybí druh nápravy v JmhzBlockerCatalog ({$present[$literal]})";
            }
            foreach ($locales as $locale => $labels) {
                $label = $labels[$literal] ?? null;
                if (!is_string($label) || trim($label) === '') {
                    $missing[] = "{$literal}: chybí popisek {$locale} payroll.jmhz_gate.codes";
                }
            }
        }

        self::assertGreaterThan(400, count($present), 'Výčet kódů je podezřele krátký, sken nejspíš nic nenašel.');
        self::assertSame([], $missing);
    }

    public function testCatalogLabelsAndAllowlistHaveNoStaleEntries(): void
    {
        $present = $this->emittedLiterals();
        $stale = [];
        foreach (JmhzBlockerCatalog::codes() as $code) {
            if (!isset($present[$code])) {
                $stale[] = "katalog: {$code} už backend nevydává";
            }
            if (isset(self::NON_CODES[$code])) {
                $stale[] = "{$code} je zároveň v katalogu i mezi ne-kódy";
            }
        }
        foreach (array_keys(self::NON_CODES) as $literal) {
            if (!isset($present[$literal])) {
                $stale[] = "NON_CODES: {$literal} v api/src už není";
            }
        }
        foreach ($this->codeLabels() as $locale => $labels) {
            foreach (array_keys($labels) as $code) {
                if (!isset($present[$code])) {
                    $stale[] = "{$locale}: popisek {$code} patří kódu, který backend nevydává";
                }
            }
        }

        self::assertSame([], $stale);
    }

    public function testEveryRemediationKindIsKnownAndLabelled(): void
    {
        $problems = [];
        foreach (JmhzBlockerCatalog::codes() as $code) {
            $kind = JmhzBlockerCatalog::remediation($code)['kind'];
            if (!in_array($kind, JmhzBlockerCatalog::KINDS, true)) {
                $problems[] = "{$code}: neznámý druh nápravy {$kind}";
            }
        }
        foreach (['cs', 'en'] as $locale) {
            $gate = $this->locale($locale)['payroll']['jmhz_gate'] ?? [];
            foreach (JmhzBlockerCatalog::KINDS as $kind) {
                foreach (['remediation', 'steps'] as $group) {
                    $label = $gate[$group][$kind] ?? null;
                    if (!is_string($label) || trim($label) === '') {
                        $problems[] = "{$locale}: chybí payroll.jmhz_gate.{$group}.{$kind}";
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    /** @return array<string,string> literál => první soubor, kde se vyskytl */
    private function emittedLiterals(): array
    {
        $root = dirname(__DIR__, 3);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/' . self::SCAN, \FilesystemIterator::SKIP_DOTS),
        );
        $present = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getFilename() === self::CATALOG_FILE) {
                continue;
            }
            preg_match_all("/'((?:jmhz|eldp)_[a-z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $literal) {
                if (str_ends_with($literal, '_')) {
                    continue;
                }
                $present[$literal] ??= $file->getFilename();
            }
        }
        ksort($present);

        return $present;
    }

    /** @return array<string,array<string,mixed>> */
    private function codeLabels(): array
    {
        $labels = [];
        foreach (['cs', 'en'] as $locale) {
            $codes = $this->locale($locale)['payroll']['jmhz_gate']['codes'] ?? [];
            $labels[$locale] = is_array($codes) ? $codes : [];
        }

        return $labels;
    }

    /** @return array<string,mixed> */
    private function locale(string $locale): array
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . "/web/src/i18n/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return is_array($decoded) ? $decoded : [];
    }
}
