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
 * Pohoda XML odpočet zálohy nese jako řádek dokladu, takže tam kontrola nemá co hlídat
 * a parser tyto údaje neposílá (klíč `monetary` chybí → kontrola se přeskočí).
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

    private static function amount(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
