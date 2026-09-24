<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use PDO;

/**
 * Pravidla pro VYDANÝ doklad převzatý z cizího souboru: obecný import ISDOC/Pohoda
 * ({@see InvoiceImportService}), AI import vydaných faktur ({@see AiIssuedInvoiceExtractor})
 * a import dokladů Shoptetu, který jede přes obecný import. Pravidlo žije tady a všechny
 * tři cesty ho volají, aby se nerozešlo mezi kanálem a importem, který ho obaluje.
 *
 * ── Odpočet záloh a nesedící součet → koncept ───────────────────────────────────────
 * Import ukládá řádky dokladu a daň počítá z nich (výkazy sumují řádky). Konečná faktura,
 * která ODEČÍTÁ ZÁLOHU, ji ale v ISDOC nese mimo řádky: `PaidDepositsAmount` sníží jen
 * částku k úhradě a zdaněná záloha (`TaxedDeposits`, `AlreadyClaimed*`) sníží daň, kterou
 * už přiznal daňový doklad k přijaté platbě. Z řádků by vznikla plná tržba i plná daň
 * a spolu s importovaným DDPP by se táž úplata zdanila podruhé. Převod odpočtu na záporné
 * řádky (jak to dělá {@see \MyInvoice\Service\Invoice\FinalFromProformaCreator}) si bez
 * reálného souboru nevymýšlíme, proto takový doklad zůstane KONCEPTEM: do DPH ani do
 * pohledávek nejde a uživatel ho naváže na daňový doklad k záloze ručně.
 *
 * Totéž platí pro doklad, jehož součet řádků nesedí na celkovou částku dokladu nebo na
 * částku k úhradě o víc než {@see self::TOTAL_TOLERANCE}: rozdíl je buď nečitelný odpočet,
 * nebo vadný soubor, a v obou případech by vystavený doklad nesl jinou tržbu, než jakou
 * zákazník zaplatil. Tolerance je v měně dokladu; haléřové zaokrouhlení dokladu
 * (`PayableRoundingAmount`) se do ní vejde.
 *
 * Pohoda XML (i export z Fakturoidu) nese odpočet zálohy jako `<inv:invoiceAdvancePaymentItem>`
 * mimo položky dokladu (klíč `advance_deduction`). Odpočet NEZDANĚNÉ zálohy (proformy, DPH 0)
 * tržbu ani daň nemění, jen snižuje částku k úhradě: doklad se uloží s `advance_paid_amount`
 * ({@see self::settleAdvanceDeduction()}). Odpočet ZDANĚNÉ zálohy snižuje i daň, kterou už
 * přiznal daňový doklad k platbě — ten doklad zůstane konceptem jako u ISDOC výše.
 *
 * ── Daňový doklad k přijaté platbě je zaplacený ─────────────────────────────────────
 * DDPP (§ 28 odst. 2 ZDPH) dokumentuje úplatu, která už přišla. Aplikace ho proto zakládá
 * s `advance_paid_amount` = brutto platby, takže `amount_to_pay` (generovaný sloupec) je 0
 * ({@see \MyInvoice\Service\Invoice\PaymentTaxDocumentCreator}). Převzatý doklad dostane
 * totéž, jinak by visel v pohledávkách a po splatnosti v plné výši.
 */
final class ImportedIssuedDocumentPolicy
{
    /** Povolený rozdíl součtu řádků proti dokladu v měně dokladu (haléřové zaokrouhlení). */
    public const TOTAL_TOLERANCE = 1.00;

    private const CENT = 0.005;

    /**
     * Posouzení převzatého dokladu proti údajům ze souboru.
     *
     * `review` neprázdné = doklad musí zůstat konceptem (texty patří do varování dávky).
     * `notes` = drobnosti, které doklad nezastaví (zaokrouhlení).
     *
     * @param array<string,mixed> $inv        rozparsovaný doklad (výstup IsdocParser / PohodaXmlParser)
     * @param float               $linesTotal součet řádků s DPH po přepočtu dokladu
     * @return array{review:list<string>, notes:list<string>}
     */
    public static function assess(array $inv, float $linesTotal): array
    {
        $advance = self::advanceDeduction($inv);
        if ($advance !== null && $advance['vat'] > self::CENT) {
            return ['review' => [sprintf(
                'Doklad odečítá zdaněnou zálohu %s (z toho DPH %s). Odpočet snižuje i daň, kterou už '
                    . 'přiznal daňový doklad k záloze, takže by se úplata zdanila podruhé. Doklad jsme '
                    . 'uložili jako koncept (nejde do DPH ani do pohledávek): zkontrolujte ho a navažte '
                    . 'daňový doklad k záloze ručně.',
                self::money($advance['gross']),
                self::money($advance['vat']),
            )], 'notes' => []];
        }

        $monetary = $inv['monetary'] ?? null;
        if (!is_array($monetary)) {
            return ['review' => [], 'notes' => []];
        }
        $type = (string) ($inv['invoice_type'] ?? 'invoice');
        $review = [];
        $notes = [];

        $paidDeposits = self::amount($monetary['paid_deposits'] ?? null);
        $alreadyClaimed = self::amount($monetary['already_claimed'] ?? null);
        $taxedDeposits = is_array($inv['taxed_deposits'] ?? null) ? $inv['taxed_deposits'] : [];
        if (($paidDeposits !== null && abs($paidDeposits) > self::CENT)
            || ($alreadyClaimed !== null && abs($alreadyClaimed) > self::CENT)
            || $taxedDeposits !== []) {
            $refs = array_values(array_filter(array_map(
                static fn (mixed $d): string => is_array($d) ? trim((string) ($d['varsymbol'] ?? $d['id'] ?? '')) : '',
                $taxedDeposits,
            )));
            $review[] = sprintf(
                'Doklad odečítá zálohy%s%s. Odpočet v souboru není mezi řádky, takže by se tržba '
                    . 'i DPH zaevidovaly v plné výši a úplata zdaněná daňovým dokladem k záloze '
                    . 'podruhé. Doklad jsme uložili jako koncept (nejde do DPH ani do pohledávek): '
                    . 'zkontrolujte ho a navažte daňový doklad k záloze ručně.',
                $paidDeposits !== null && abs($paidDeposits) > self::CENT ? ' ' . self::money($paidDeposits) : '',
                $refs !== [] ? ' (' . implode(', ', $refs) . ')' : '',
            );
        }

        $total = self::amount($monetary['total'] ?? null);
        if ($total !== null) {
            $diff = abs(round(abs($linesTotal) - abs($total), 2));
            if ($diff > self::TOTAL_TOLERANCE) {
                $review[] = sprintf(
                    'Součet řádků dokladu %s se liší od celkové částky dokladu %s o %s. Doklad jsme '
                        . 'uložili jako koncept: zkontrolujte odpočet zálohy nebo položky a teprve pak ho vystavte.',
                    self::money(abs($linesTotal)),
                    self::money(abs($total)),
                    self::money($diff),
                );
            }
        }

        // Částka k úhradě u DDPP je z podstaty 0 (úplata už přišla), s řádky se tedy
        // neporovnává. Rozdíl proti celkové částce dokladu hlídá kontrola výše.
        $payable = self::amount($monetary['payable'] ?? null);
        if ($type !== 'tax_document' && $payable !== null) {
            $diff = abs(round(abs($linesTotal) - abs($payable), 2));
            if ($diff > self::TOTAL_TOLERANCE) {
                $review[] = sprintf(
                    'Součet řádků dokladu %s se liší od částky k úhradě %s o %s. Doklad jsme uložili '
                        . 'jako koncept: zkontrolujte odpočet zálohy nebo zaokrouhlení a navažte daňový '
                        . 'doklad k záloze ručně.',
                    self::money(abs($linesTotal)),
                    self::money(abs($payable)),
                    self::money($diff),
                );
            } elseif ($diff > self::CENT) {
                $notes[] = sprintf('Součet řádků se od částky k úhradě liší o %s (zaokrouhlení dokladu).', self::money($diff));
            }
        }

        return ['review' => $review, 'notes' => $notes];
    }

    /** Druh vydaného dokladu, který z podstaty dokumentuje už přijatou úplatu. */
    public static function isPaidByNature(string $invoiceType): bool
    {
        return $invoiceType === 'tax_document';
    }

    /**
     * Převzatý daňový doklad k přijaté platbě: přijatá úplata kryje doklad celý, takže
     * `amount_to_pay` = 0 stejně jako u dokladu, který aplikace založí k platbě zálohy.
     * Volá se až po přepočtu dokladu (součet musí být hotový).
     */
    public static function settleTaxDocument(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare(
            "UPDATE invoices SET advance_paid_amount = total_with_vat
              WHERE id = ? AND invoice_type = 'tax_document'"
        )->execute([$invoiceId]);
    }

    /**
     * Odpočet nezdaněné zálohy (proformy) ze souboru: sníží částku k úhradě. Vrací zbývající
     * částku k úhradě po odpočtu, nebo null, když doklad odpočet nemá. Volá se po přepočtu
     * dokladu a jen pro doklad, který {@see self::assess()} nepustil do konceptu.
     *
     * @param array<string,mixed> $inv
     */
    public static function settleAdvanceDeduction(PDO $pdo, int $invoiceId, array $inv): ?float
    {
        $advance = self::advanceDeduction($inv);
        if ($advance === null || $advance['vat'] > self::CENT || (string) ($inv['invoice_type'] ?? 'invoice') !== 'invoice') {
            return null;
        }
        $pdo->prepare(
            "UPDATE invoices SET advance_paid_amount = LEAST(total_with_vat, ?)
              WHERE id = ? AND invoice_type = 'invoice'"
        )->execute([$advance['gross'], $invoiceId]);
        $left = $pdo->prepare('SELECT amount_to_pay FROM invoices WHERE id = ?');
        $left->execute([$invoiceId]);

        return (float) $left->fetchColumn();
    }

    /**
     * @param array<string,mixed> $inv
     * @return array{gross:float, vat:float}|null
     */
    private static function advanceDeduction(array $inv): ?array
    {
        $advance = $inv['advance_deduction'] ?? null;
        if (!is_array($advance) || (self::amount($advance['gross'] ?? null) ?? 0.0) <= self::CENT) {
            return null;
        }
        return ['gross' => (float) $advance['gross'], 'vat' => (float) (self::amount($advance['vat'] ?? null) ?? 0.0)];
    }

    private static function amount(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
