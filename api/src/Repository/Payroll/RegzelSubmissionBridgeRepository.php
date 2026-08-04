<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class RegzelSubmissionBridgeRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,obligation_kind:string,
     *   preferred_channel:string,status:string,
     *   source_event_type:string,source_event_reference:string,
     *   source_event_hash:string,deadline_kind:string,
     *   earliest_submission_on:string,due_on:string,calendar_basis:string,
     *   ruleset_id:string,ruleset_hash:string,trigger_event_hash:string
     * }|null
     */
    public function lockVerifiedObligation(
        int $supplierId,
        int $obligationId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.environment,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference,
                    obligation.obligation_kind,
                    obligation.preferred_channel, obligation.status,
                    obligation.source_event_type,
                    obligation.source_event_reference,
                    obligation.source_event_hash,
                    deadline.deadline_kind,
                    deadline.earliest_submission_on, deadline.due_on,
                    deadline.calendar_basis, deadline.ruleset_id,
                    deadline.ruleset_hash, deadline.trigger_event_hash
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new \UnexpectedValueException(
                'Povinnost REGZEL nemá očekávaný databázový tvar.',
            );
        }
        $normalized = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Povinnost REGZEL nemá pojmenované sloupce.',
                );
            }
            $normalized[$key] = $value;
        }

        return [
            'id' => $this->integer($normalized, 'id'),
            'environment' => $this->string($normalized, 'environment'),
            'agenda_code' => $this->string($normalized, 'agenda_code'),
            'subject_type' => $this->string($normalized, 'subject_type'),
            'subject_reference' => $this->string(
                $normalized,
                'subject_reference',
            ),
            'obligation_kind' => $this->string(
                $normalized,
                'obligation_kind',
            ),
            'preferred_channel' => $this->string(
                $normalized,
                'preferred_channel',
            ),
            'status' => $this->string($normalized, 'status'),
            'source_event_type' => $this->string(
                $normalized,
                'source_event_type',
            ),
            'source_event_reference' => $this->string(
                $normalized,
                'source_event_reference',
            ),
            'source_event_hash' => $this->hash(
                $normalized,
                'source_event_hash',
            ),
            'deadline_kind' => $this->string(
                $normalized,
                'deadline_kind',
            ),
            'earliest_submission_on' => $this->date(
                $normalized,
                'earliest_submission_on',
            ),
            'due_on' => $this->date($normalized, 'due_on'),
            'calendar_basis' => $this->string(
                $normalized,
                'calendar_basis',
            ),
            'ruleset_id' => $this->string($normalized, 'ruleset_id'),
            'ruleset_hash' => $this->hash($normalized, 'ruleset_hash'),
            'trigger_event_hash' => $this->hash(
                $normalized,
                'trigger_event_hash',
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value)
                || (is_string($value)
                    && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(
            "Pole povinnosti {$field} není kladné celé číslo.",
        );
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Pole povinnosti {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Pole povinnosti {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function date(array $row, string $field): string
    {
        $value = $this->string($row, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException(
                "Pole povinnosti {$field} není platné datum.",
            );
        }

        return $value;
    }
}
