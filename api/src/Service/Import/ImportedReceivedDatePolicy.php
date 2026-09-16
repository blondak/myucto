<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use Throwable;

/**
 * Datum přijetí u dokladu založeného IMPORTEM — jedno pravidlo pro všechny kanály.
 *
 * Pravidlo žije tady a volají ho všechny cesty, které zakládají přijatý doklad z cizího
 * vstupu, aby se nerozešly: AI extrakce z PDF ({@see AiPdfExtractor} — a přes ni admin
 * dropzone, podatelna, účtenka z karty i scan inbox), strukturovaný import ISDOC / ISDOCX /
 * PDF-A3 / Pohoda XML ({@see IsdocToPurchaseInvoiceMapper}) a API importy
 * ({@see IdokladImportService}, {@see FakturoidImportService}).
 *
 * ── Proč z dokladu, a ne dnešek ─────────────────────────────────────────────────────
 * Import dřív psal `received_at = date('Y-m-d')`, takže po migraci historie měla celá
 * firma ve sloupci „Datum přijetí" den importu. Skutečné datum převzetí dokladu import
 * nezná, ale datum z dokladu je mu nesrovnatelně blíž než dnešek.
 *
 * ── Vystavení, ne DUZP ──────────────────────────────────────────────────────────────
 * Rozhoduje DATUM VYSTAVENÍ, teprve při jeho nečitelnosti DUZP. DUZP bývá DŘÍV než
 * vystavení (plnění 30. 6., doklad vystavený 2. 7.) a doklad nelze držet dřív, než vůbec
 * vznikl — totéž pravidlo už používá § 73 logika ve {@see \MyInvoice\Service\Report\VatLedgerService}.
 * Mapper ISDOC měl historicky pořadí opačné (DUZP → vystavení); sjednoceno sem, aby se
 * všechny formáty chovaly stejně.
 *
 * ── Na DPH to nesahá ────────────────────────────────────────────────────────────────
 * Importovaný doklad si drží `received_at_source = 'import'` (migrace 1037), takže do
 * období nároku na odpočet datum přijetí nevstupuje — o zařazení rozhoduje DUZP/vystavení.
 * Tahle třída tedy mění jen EVIDENČNÍ údaj, ne daňové zařazení.
 *
 * Budoucí datum se nedosazuje nikdy: doklad, jehož datum ještě nenastalo, jsme převzít
 * nemohli → ořez na dnešek.
 */
final class ImportedReceivedDatePolicy
{
    /** Datum přijetí = datum z dokladu (vystavení, jinak DUZP). Výchozí od migrace 1848. */
    public const MODE_DOCUMENT = 'issue_date';

    /** Datum přijetí = den importu (chování do migrace 1848). */
    public const MODE_IMPORT_DAY = 'import_date';

    /** @var list<string> */
    public const MODES = [self::MODE_DOCUMENT, self::MODE_IMPORT_DAY];

    public const DEFAULT_MODE = self::MODE_DOCUMENT;

    /**
     * Volba firmy (`supplier.purchase_import_received_at`, migrace 1848).
     *
     * Nedoběhlá migrace nesmí shodit import — chybějící sloupec znamená výchozí režim,
     * stejně jako to dělá {@see \MyInvoice\Action\Admin\Import\AiProviderCredentialsAction::tuning()}.
     */
    public static function modeForSupplier(Connection $db, int $supplierId): string
    {
        try {
            $stmt = $db->pdo()->prepare('SELECT purchase_import_received_at FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $mode = (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable) {
            return self::DEFAULT_MODE;
        }

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Datum přijetí pro importovaný doklad.
     *
     * `fell_back = true` znamená, že doklad nenesl JEDINÉ čitelné datum a zbyl dnešek —
     * volající to musí dát uživateli najevo ({@see self::fallbackWarning()}), ne spolknout.
     *
     * @param  string $mode      {@see self::MODES}; neznámá hodnota se bere jako výchozí
     * @param  mixed  $issueDate datum vystavení z dokladu ('Y-m-d', případně s časem)
     * @param  mixed  $taxDate   DUZP — záloha pro doklad bez čitelného data vystavení
     * @param  string|null $today  dnešek (kvůli testovatelnosti); null = date('Y-m-d')
     * @return array{date:string, fell_back:bool}
     */
    public static function resolve(string $mode, mixed $issueDate, mixed $taxDate = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');

        if ($mode === self::MODE_IMPORT_DAY) {
            return ['date' => $today, 'fell_back' => false];
        }

        foreach ([$issueDate, $taxDate] as $candidate) {
            $date = self::normalize($candidate);
            if ($date === null) {
                continue;
            }

            // Budoucí datum na dokladu (překlep dodavatele) by vyrobilo doklad přijatý
            // dřív, než nastal — ořez na dnešek, shodně s dřívějším chováním ISDOC.
            return ['date' => $date <= $today ? $date : $today, 'fell_back' => false];
        }

        return ['date' => $today, 'fell_back' => true];
    }

    /**
     * Text do `extraction_warning`, když doklad žádné čitelné datum nenesl. Žluté
     * upozornění v UI je jediné místo, kde uživatel pozná, že dnešek je NÁHRADA,
     * ne údaj z dokladu.
     */
    public static function fallbackWarning(): string
    {
        return 'Datum přijetí jsme nastavili na den importu: doklad nenese čitelné datum '
            . 'vystavení ani DUZP. Zkontrolujte ho a případně opravte podle originálu. '
            . 'Na období nároku na odpočet DPH to vliv nemá, dokud datum přijetí nezadáte ručně '
            . '(§ 73 odst. 1 písm. a ZDPH).';
    }

    /** Kanonický 'Y-m-d', nebo null když hodnota není použitelné datum. */
    private static function normalize(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        $date = trim((string) ($value ?? ''));
        if ($date === '') {
            return null;
        }
        $date = substr($date, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }
}
