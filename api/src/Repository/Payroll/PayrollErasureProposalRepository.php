<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionService;
use PDO;

/**
 * Návrh výmazu — a doklad, že proběhl.
 *
 * ── Proč návrh a ne automat ───────────────────────────────────────────────────
 * Uplynulá retenční lhůta je konec povinnosti uchovávat, ne příkaz ke smazání.
 * Osobní údaje mzdové agendy jsou navíc nevratné: nikdo je zpátky nezadá. Proto
 * se sestaví návrh, který dopředu JMENUJE, čeho se to týká a co zůstane, člověk
 * ho schválí a teprve pak se provede. Cesta, která by mazala bez schválení,
 * v téhle třídě neexistuje — {@see execute()} odmítne cokoli jiného než
 * `approved`.
 *
 * ── Návrh je zároveň doklad ───────────────────────────────────────────────────
 * Po výmazu musí zůstat záznam, ŽE proběhl: co, kdy, kdo schválil a podle které
 * lhůty. Kdyby se položky po provedení uklidily, výsledek by se nedal odlišit od
 * ztráty dat. Položky proto zůstávají a nesou i citaci ustanovení, podle kterého
 * se rozhodovalo — včetně toho, jak byla doložená.
 *
 * Položka ZÁMĚRNĚ nemá cizí klíč na `payroll_employees` (viz migrace 1397):
 * kaskáda by doklad smazala spolu s osobou. Ze stejného důvodu nenese jméno —
 * doklad o výmazu, který si osobní údaj nechá, výmaz popírá.
 *
 * ── Schválení nestačí ────────────────────────────────────────────────────────
 * Mezi schválením a provedením může přibýt zadržení nebo pohyb. {@see execute()}
 * proto posuzuje KAŽDOU položku znovu a to, co se mezitím změnilo, přeskočí
 * s důvodem místo aby to provedlo podle zastaralého rozhodnutí.
 */
final class PayrollErasureProposalRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRetentionService $retention,
        private readonly PayrollEmployeeDeletionRepository $deletion,
        private readonly PayrollPersonAnonymizationRepository $anonymization,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Sestaví návrh z osob, kterým uplynula lhůta a nic je nedrží.
     *
     * Vrací `null`, když není co navrhnout — prázdný návrh by se dal schválit
     * a v přehledu by vypadal jako provedený výmaz.
     */
    public function create(
        int $supplierId,
        ?int $userId,
        ?string $asOf,
        ?string $note,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ?int {
        $asOf ??= date('Y-m-d');
        $proposable = array_values(array_filter(
            $this->retention->assess($supplierId, $asOf),
            static fn (PayrollRetentionAssessment $a): bool => $a->isProposable(),
        ));
        if ($proposable === []) {
            return null;
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $head = $pdo->prepare(
                'INSERT INTO payroll_erasure_proposals (supplier_id, as_of, note, created_by)
                 VALUES (?, ?, ?, ?)'
            );
            $head->execute([
                $supplierId,
                $asOf,
                $note === null ? null : mb_substr(trim($note), 0, 500),
                $userId,
            ]);
            $proposalId = (int) $pdo->lastInsertId();

            $item = $pdo->prepare(
                'INSERT INTO payroll_erasure_proposal_items
                    (supplier_id, proposal_id, employee_id, action, governing_category,
                     governing_source, governing_source_status, retained_until,
                     last_record_year, cascade_counts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($proposable as $assessment) {
                $item->execute([
                    $supplierId,
                    $proposalId,
                    $assessment->employeeId,
                    $assessment->action,
                    (string) $assessment->governingCategory,
                    mb_substr((string) $assessment->governingSource, 0, 191),
                    (string) $assessment->governingSourceStatus,
                    (string) $assessment->retainedUntil,
                    $assessment->lastRecordYear,
                    json_encode(
                        ['identity' => $assessment->identity, 'residue' => $assessment->residue],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                ]);
            }

            $this->activityLogger->log(
                'payroll.erasure.proposed',
                $userId,
                'payroll_erasure_proposal',
                $proposalId,
                ['as_of' => $asOf, 'items' => count($proposable)],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($owns) {
                $pdo->commit();
            }

            return $proposalId;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $proposalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_erasure_proposals WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Položky návrhu i se jménem osoby — DOPOJENÝM, ne uloženým.
     *
     * Nevratný výmaz nejde schválit podle čísla osoby, takže jméno k položce
     * patřit musí. Uložit ho do tabulky se ale nesmí (viz komentář u třídy):
     * doklad o výmazu, který si osobní údaj nechá, výmaz popírá. Join to řeší
     * sám: dokud osoba existuje, jméno se ukáže; po úplném výmazu zbude `null`
     * a po anonymizaci anonymizovaná náhrada. Tabulka tím zůstává čistá i po
     * provedení, i když si někdo návrh otevře zpětně.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $supplierId, int $proposalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item.*, ' . PayrollPeopleRepository::fullNameExpression() . ' AS full_name
               FROM payroll_erasure_proposal_items item
               LEFT JOIN payroll_employees employee
                      ON employee.supplier_id = item.supplier_id
                     AND employee.id = item.employee_id
              WHERE item.supplier_id = ? AND item.proposal_id = ?
              ORDER BY item.employee_id'
        );
        $stmt->execute([$supplierId, $proposalId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function all(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, COUNT(i.id) AS item_count
               FROM payroll_erasure_proposals p
               LEFT JOIN payroll_erasure_proposal_items i
                      ON i.supplier_id = p.supplier_id AND i.proposal_id = p.id
              WHERE p.supplier_id = ?
              GROUP BY p.id
              ORDER BY p.created_at DESC, p.id DESC'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approve(
        int $supplierId,
        int $proposalId,
        ?int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->transition(
            $supplierId,
            $proposalId,
            self::STATUS_PENDING,
            'UPDATE payroll_erasure_proposals
                SET status = \'approved\', approved_by = ?, approved_at = NOW(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND status = \'pending\'',
            [$userId, $supplierId, $proposalId],
        );

        $this->activityLogger->log(
            'payroll.erasure.approved',
            $userId,
            'payroll_erasure_proposal',
            $proposalId,
            ['items' => count($this->items($supplierId, $proposalId))],
            $ip,
            $userAgent,
            $supplierId,
        );
    }

    public function reject(
        int $supplierId,
        int $proposalId,
        ?int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->transition(
            $supplierId,
            $proposalId,
            self::STATUS_PENDING,
            'UPDATE payroll_erasure_proposals
                SET status = \'rejected\', rejected_by = ?, rejected_at = NOW(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND status = \'pending\'',
            [$userId, $supplierId, $proposalId],
        );

        $this->activityLogger->log(
            'payroll.erasure.rejected',
            $userId,
            'payroll_erasure_proposal',
            $proposalId,
            [],
            $ip,
            $userAgent,
            $supplierId,
        );
    }

    /** @param list<mixed> $params */
    private function transition(
        int $supplierId,
        int $proposalId,
        string $expected,
        string $sql,
        array $params,
    ): void {
        $proposal = $this->find($supplierId, $proposalId);
        if ($proposal === null) {
            throw new PayrollErasureException(
                'not_found',
                'Návrh výmazu nebyl nalezen.',
            );
        }
        if ((string) $proposal['status'] !== $expected) {
            throw new PayrollErasureException(
                'payroll_erasure_state',
                'Návrh výmazu už není ve stavu, ve kterém tuhle akci přijímá.',
            );
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollErasureException(
                'payroll_erasure_state',
                'Návrh výmazu se mezitím změnil. Načtěte ho prosím znovu.',
            );
        }
    }

    /**
     * Provedení schváleného návrhu.
     *
     * KAŽDÁ položka se posuzuje ZNOVU. Mezi schválením a provedením mohlo přibýt
     * zadržení (exekuce, kontrola) nebo pohyb, který z úplného výmazu dělá
     * anonymizaci. Provést to podle zastaralého rozhodnutí by znamenalo smazat
     * data chráněná holdem, který v době schválení ještě neexistoval.
     *
     * @return array<string,int> souhrn po výsledcích
     */
    public function execute(
        int $supplierId,
        int $proposalId,
        ?int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }

        // Návrh se čte POD ZÁMKEM a až uvnitř transakce. Dva souběžné požadavky na
        // provedení by jinak oba viděly `approved` a pustily mazání dvakrát; zámek
        // je seřadí a druhý pak narazí na stav `executed`.
        $lock = $pdo->prepare(
            'SELECT * FROM payroll_erasure_proposals
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $lock->execute([$supplierId, $proposalId]);
        $proposal = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($proposal)) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw new PayrollErasureException('not_found', 'Návrh výmazu nebyl nalezen.');
        }
        if ((string) $proposal['status'] !== self::STATUS_APPROVED) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw new PayrollErasureException(
                'payroll_erasure_not_approved',
                'Výmaz jde provést až po schválení. Neschválený návrh se neprovádí.',
            );
        }

        $asOf = (string) $proposal['as_of'];

        $summary = ['done' => 0, 'skipped_hold' => 0, 'skipped_changed' => 0];

        // Přeposouzení se dělá JEDNOU, uvnitř transakce, těsně před prvním zásahem.
        // Osoby jsou na sobě nezávislé, takže výmaz jedné posudek ostatních nemění —
        // a posudek na položku by z provedení udělal N× průchod celým tenantem.
        $fresh = [];
        foreach ($this->retention->assess($supplierId, $asOf) as $assessment) {
            $fresh[$assessment->employeeId] = $assessment;
        }

        try {
            foreach ($this->items($supplierId, $proposalId) as $item) {
                if ((string) $item['outcome'] !== 'pending') {
                    continue;
                }
                $employeeId = (int) $item['employee_id'];
                $outcome = $this->executeItem(
                    $supplierId,
                    $employeeId,
                    (string) $item['action'],
                    $fresh[$employeeId] ?? null,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $summary[$outcome['outcome']] = ($summary[$outcome['outcome']] ?? 0) + 1;

                $update = $pdo->prepare(
                    'UPDATE payroll_erasure_proposal_items
                        SET outcome = ?, skip_reason = ?, executed_at = NOW(),
                            cascade_counts = ?
                      WHERE supplier_id = ? AND proposal_id = ? AND employee_id = ?'
                );
                $update->execute([
                    $outcome['outcome'],
                    $outcome['skip_reason'],
                    json_encode($outcome['counts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $supplierId,
                    $proposalId,
                    $employeeId,
                ]);
            }

            $head = $pdo->prepare(
                'UPDATE payroll_erasure_proposals
                    SET status = \'executed\', executed_at = NOW(), row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND status = \'approved\''
            );
            $head->execute([$supplierId, $proposalId]);
            if ($head->rowCount() !== 1) {
                throw new PayrollErasureException(
                    'payroll_erasure_state',
                    'Návrh výmazu se mezitím změnil. Načtěte ho prosím znovu.',
                );
            }

            $this->activityLogger->log(
                'payroll.erasure.executed',
                $userId,
                'payroll_erasure_proposal',
                $proposalId,
                [
                    'as_of' => $asOf,
                    'approved_by' => $proposal['approved_by'] === null
                        ? null
                        : (int) $proposal['approved_by'],
                    'summary' => $summary,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($owns) {
                $pdo->commit();
            }

            return $summary;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{outcome:string,skip_reason:?string,counts:array<string,mixed>}
     */
    private function executeItem(
        int $supplierId,
        int $employeeId,
        string $action,
        ?PayrollRetentionAssessment $fresh,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        if ($fresh === null) {
            return [
                'outcome' => 'skipped_changed',
                'skip_reason' => 'Osoba už v evidenci není.',
                'counts' => [],
            ];
        }
        if ($fresh->blockedBy === PayrollRetentionAssessment::BLOCK_HOLD) {
            return [
                'outcome' => 'skipped_hold',
                'skip_reason' => 'Po schválení přibylo zadržení výmazu (legal hold).',
                'counts' => [],
            ];
        }
        if (!$fresh->isProposable()) {
            return [
                'outcome' => 'skipped_changed',
                'skip_reason' => 'Podmínky se od schválení změnily: ' . (string) $fresh->blockedBy,
                'counts' => [],
            ];
        }
        if ($fresh->action !== $action) {
            return [
                'outcome' => 'skipped_changed',
                'skip_reason' => 'Rozsah se od schválení změnil — schváleno „' . $action
                    . '", nyní by šlo o „' . $fresh->action . '".',
                'counts' => [],
            ];
        }

        $counts = $action === PayrollRetentionAssessment::ACTION_ERASE
            ? $this->deletion->delete($supplierId, $employeeId, $userId, $ip, $userAgent)
            : $this->anonymization->anonymize($supplierId, $employeeId, $userId, $ip, $userAgent);

        return ['outcome' => 'done', 'skip_reason' => null, 'counts' => $counts];
    }
}
