<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Evidence daňových ztrát §34 ZDP (Fáze E, audit 2026-07) — FO i PO.
 *
 * Ztráta vzniká automaticky při finalizaci přiznání se záporným základem (tax_losses)
 * a v dalších letech se uplatňuje (tax_loss_applications, FIFO od nejstarší). Zbývající
 * zůstatek každé ztráty = amount − Σ applications. Uplatnit lze v 5 zdaňovacích obdobích
 * bezprostředně následujících po období vzniku (origin_year+1 … origin_year+5).
 *
 * Uplatnění je vázané na rok uplatnění (applied_year), takže re-finalizace téhož roku je
 * idempotentní (nejprve se smažou dosavadní uplatnění daného roku, pak zapíšou znovu).
 *
 * ── Zpětné uplatnění (§ 34 odst. 1 ve znění novely 299/2020) ────────────────────────
 * Od roku 2020 lze ztrátu odečíst i ve 2 obdobích bezprostředně PŘEDCHÁZEJÍCÍCH období
 * jejího vzniku, nejvýše však v souhrnné výši 30 000 000 Kč. Systém to neuměl vůbec:
 * okno bylo natvrdo `origin_year <= year - 1`, takže poplatník, kterému vznikla ztráta,
 * neměl jak snížit daň už zaplacenou za předchozí roky — a šlo o peníze navíc, které
 * systém neuměl ani navrhnout.
 *
 * Zpětné uplatnění se v evidenci liší jen tím, že `applied_year < origin_year`; mechanika
 * (FIFO, idempotence per rok, zákaz snížení ztráty pod uplatněné) zůstává stejná. Navenek
 * se provede DODATEČNÝM přiznáním za dřívější rok, což systém už umí.
 *
 * Pořadí FIFO (origin_year ASC) přitom dává správnou přednost: dopředu přenášené starší
 * ztráty se spotřebují dřív než ztráta budoucí, protože ta má vyšší `origin_year`. Zpětné
 * uplatnění je navíc PRÁVO, ne povinnost — systém ho nabídne, ale sám nikdy nezaúčtuje
 * dodatečné přiznání za uzavřený rok.
 */
final class TaxLossService
{
    public function __construct(private readonly Connection $db, private readonly TaxConstantsRepository $constants) {}

    /**
     * Karta „Daňové ztráty" pro FE: seznam ztrát se zůstatkem a expirací + FIFO návrh
     * uplatnění pro daný rok.
     *
     * @return array{
     *   losses: list<array{origin_year:int,amount:float,applied:float,remaining:float,expires_year:int,applicable:bool}>,
     *   available_total: float, suggested: float, year: int, carry_years: int
     * }
     */
    public function card(int $supplierId, int $year, string $type): array
    {
        $rows = $this->rows($supplierId, $type);
        $c = $this->constants->forYear($year);
        $carryYears = (int) ($c['tax_loss_carry_years'] ?? 5);
        $carrybackYears = (int) ($c['tax_loss_carryback_years'] ?? 0);
        $carrybackLimit = (float) ($c['tax_loss_carryback_limit'] ?? 0);

        $losses = [];
        $available = 0.0;
        $carrybackAvailable = 0.0;
        foreach ($rows as $r) {
            $remaining = round((float) $r['amount'] - (float) $r['applied'], 2);
            $originYear = (int) $r['origin_year'];
            $forward = $remaining > 0 && $originYear >= $year - $carryYears && $originYear <= $year - 1;

            // Zpětné uplatnění: ztráta vznikla POZDĚJI než rok, do kterého se odečítá,
            // nejvýš však o `carrybackYears` období — a jen do zbývajícího stropu 30 mil.
            $carrybackRoom = $carrybackYears > 0
                ? round(max(0.0, $carrybackLimit - $this->carriedBackTotal($supplierId, $type, $originYear)), 2)
                : 0.0;
            $backward = $remaining > 0 && $carrybackYears > 0
                && $originYear > $year && $originYear <= $year + $carrybackYears
                && $carrybackRoom > 0;

            if ($forward) {
                $available = round($available + $remaining, 2);
            }
            if ($backward) {
                $carrybackAvailable = round($carrybackAvailable + min($remaining, $carrybackRoom), 2);
            }

            $losses[] = [
                'origin_year' => $originYear,
                'amount' => round((float) $r['amount'], 2),
                'applied' => round((float) $r['applied'], 2),
                'remaining' => $remaining,
                'expires_year' => $originYear + $carryYears,
                'applicable' => $forward,
                'carryback_applicable' => $backward,
                'carryback_room' => $carrybackRoom,
            ];
        }

        return [
            'losses' => $losses,
            'available_total' => $available,
            // Návrh drží jen DOPŘEDNÉ uplatnění. Zpětné je právo vázané na podání
            // dodatečného přiznání za uzavřený rok — přednabídnout ho jako samozřejmost
            // by uživatele tlačilo k úkonu, který musí zvážit sám.
            'suggested' => $available,
            'carryback_available_total' => $carrybackAvailable,
            'year' => $year,
            'carry_years' => $carryYears,
            'carryback_years' => $carrybackYears,
            'carryback_limit' => $carrybackLimit,
        ];
    }

    /** Dosud zpětně uplatněná část ztráty daného roku (applied_year < origin_year). */
    private function carriedBackTotal(int $supplierId, string $type, int $originYear): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(a.amount), 0)
               FROM tax_losses l
               JOIN tax_loss_applications a ON a.loss_id = l.id
              WHERE l.supplier_id = ? AND l.taxpayer_type = ? AND l.origin_year = ?
                AND a.applied_year < l.origin_year'
        );
        $stmt->execute([$supplierId, $type, $originYear]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Rekonciliace evidence ztrát při finalizaci přiznání (idempotentně dle roku):
     *  1) zruší dosavadní uplatnění tohoto roku (re-finalizace),
     *  2) zaeviduje/zruší ztrátu vzniklou v tomto roce (yearLoss),
     *  3) FIFO uplatní appliedLoss proti nejstarším uplatnitelným ztrátám.
     */
    public function reconcileFinalize(int $supplierId, string $type, int $year, float $yearLoss, float $appliedLoss, ?int $returnId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // (1) zrušit dosavadní uplatnění tohoto roku
            $pdo->prepare('DELETE FROM tax_loss_applications WHERE supplier_id = ? AND taxpayer_type = ? AND applied_year = ?')
                ->execute([$supplierId, $type, $year]);

            $this->assertLossAmountCanBeSet($supplierId, $type, $year, $yearLoss);

            // (2) ztráta vzniklá v tomto roce
            if ($yearLoss > 0) {
                $pdo->prepare(
                    'INSERT INTO tax_losses (supplier_id, taxpayer_type, origin_year, amount, source_return_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE amount = VALUES(amount), source_return_id = VALUES(source_return_id)'
                )->execute([$supplierId, $type, $year, round($yearLoss, 2), $returnId]);
            } else {
                // Rok už ztrátu netvoří — smaž auto-založený záznam, pokud ho nikdo neuplatnil.
                $pdo->prepare(
                    'DELETE FROM tax_losses
                      WHERE supplier_id = ? AND taxpayer_type = ? AND origin_year = ?
                        AND NOT EXISTS (SELECT 1 FROM tax_loss_applications a WHERE a.loss_id = tax_losses.id)'
                )->execute([$supplierId, $type, $year]);
            }

            // (3) FIFO uplatnění ztráty minulých let
            if ($appliedLoss > 0) {
                $this->consumeFifo($supplierId, $type, $year, round($appliedLoss, 2), $returnId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assertLossAmountCanBeSet(int $supplierId, string $type, int $year, float $yearLoss): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(a.amount), 0)
               FROM tax_losses l
               JOIN tax_loss_applications a ON a.loss_id = l.id
              WHERE l.supplier_id = ? AND l.taxpayer_type = ? AND l.origin_year = ?'
        );
        $stmt->execute([$supplierId, $type, $year]);
        $alreadyUsed = round((float) $stmt->fetchColumn(), 2);
        if ($alreadyUsed > round($yearLoss, 2)) {
            throw new \DomainException(
                'Daňovou ztrátu roku ' . $year . ' nelze snížit na ' . number_format($yearLoss, 2, ',', ' ')
                . ' Kč; v pozdějších přiznáních je již uplatněno ' . number_format($alreadyUsed, 2, ',', ' ')
                . ' Kč. Nejprve znovu otevřete a opravte tato přiznání.'
            );
        }
    }

    /**
     * Uvolní vazby evidence při vrácení přiznání do draftu (reopen): zruší uplatnění
     * provedená v daném roce. Ztrátu vzniklou v roce ponecháváme (živý ledger; při
     * re-finalizaci se přepíše nebo smaže dle nového výsledku).
     */
    public function releaseReturn(int $supplierId, string $type, int $year): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM tax_loss_applications WHERE supplier_id = ? AND taxpayer_type = ? AND applied_year = ?')
            ->execute([$supplierId, $type, $year]);
    }

    /**
     * FIFO spotřeba uplatňované ztráty. Okno zahrnuje jak DOPŘEDU přenášené ztráty
     * (origin_year < year), tak ZPĚTNÉ uplatnění ztráty vzniklé až po tomto roce
     * (§ 34 odst. 1, novela 299/2020) — u té se navíc hlídá souhrnný strop 30 mil. Kč.
     *
     * Řazení podle origin_year ASC dává správnou přednost: starší dopředu přenášené
     * ztráty se spotřebují dřív než ztráta budoucí, protože ta má vyšší origin_year.
     */
    private function consumeFifo(int $supplierId, string $type, int $year, float $toApply, ?int $returnId): void
    {
        $c = $this->constants->forYear($year);
        $carryYears = (int) ($c['tax_loss_carry_years'] ?? 5);
        $carrybackYears = (int) ($c['tax_loss_carryback_years'] ?? 0);
        $carrybackLimit = (float) ($c['tax_loss_carryback_limit'] ?? 0);
        $pdo = $this->db->pdo();
        // FOR UPDATE (nález N4): zamkne řádky tax_losses po dobu transakce, aby souběžná
        // finalizace jiného přiznání téhož poplatníka nepočítala "remaining" ze zastaralého
        // stavu a neuplatnila tutéž ztrátu dvakrát (race condition).
        $stmt = $pdo->prepare(
            'SELECT tl.id, tl.origin_year, tl.amount,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a WHERE a.loss_id = tl.id), 0) AS applied,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a
                               WHERE a.loss_id = tl.id AND a.applied_year < tl.origin_year), 0) AS carried_back
               FROM tax_losses tl
              WHERE tl.supplier_id = ? AND tl.taxpayer_type = ?
                AND tl.origin_year >= ? AND tl.origin_year <= ?
                AND tl.origin_year <> ?
              ORDER BY tl.origin_year ASC, tl.id ASC
                FOR UPDATE'
        );
        $stmt->execute([$supplierId, $type, $year - $carryYears, $year + $carrybackYears, $year]);

        $insert = $pdo->prepare(
            'INSERT INTO tax_loss_applications (supplier_id, taxpayer_type, loss_id, applied_year, applied_return_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($toApply <= 0) {
                break;
            }
            $remaining = round((float) $row['amount'] - (float) $row['applied'], 2);
            if ($remaining <= 0) {
                continue;
            }

            // Zpětné uplatnění (ztráta vznikla až po tomto roce) podléhá souhrnnému
            // stropu 30 mil. Kč na jednotlivou ztrátu. Bez tohoto omezení by se odečetla
            // celá ztráta a přiznání by prošlo s částkou, kterou zákon nepřipouští.
            if ((int) $row['origin_year'] > $year) {
                $room = round($carrybackLimit - (float) $row['carried_back'], 2);
                if ($room <= 0) {
                    continue;
                }
                $remaining = min($remaining, $room);
            }

            $chunk = min($remaining, $toApply);
            $insert->execute([$supplierId, $type, (int) $row['id'], $year, $returnId, round($chunk, 2)]);
            $toApply = round($toApply - $chunk, 2);
        }
    }

    /** @return list<array{origin_year:int,amount:float,applied:float}> */
    private function rows(int $supplierId, string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tl.origin_year, tl.amount,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a WHERE a.loss_id = tl.id), 0) AS applied
               FROM tax_losses tl
              WHERE tl.supplier_id = ? AND tl.taxpayer_type = ?
              ORDER BY tl.origin_year ASC'
        );
        $stmt->execute([$supplierId, $type]);
        /** @var list<array{origin_year:int,amount:float,applied:float}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
