<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter as PohodaPartners;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/** Převod daňové evidence Stereo NX do existující firmy; jeden běh = jedna transakce. */
final class StereoNxImporter
{
    private const REVIEW_LABELS = [
        'vat_participation_unassigned' => 'není určeno zpracování DPH',
        'vat_participation_disabled' => 'zpracování DPH je ve zdroji vypnuté',
        'price_mode_unassigned' => 'není určeno, zda jsou ceny včetně DPH',
        'self_assessment_amount_mismatch' => 'samovyměřená DPH neodpovídá základu a sazbě',
        'issued_lines_missing' => 'chybí původní položky vydaného dokladu',
        'missing_items' => 'doklad nemá položky',
        'partner_identity_missing' => 'chybí identita protistrany',
        'partner_country_unresolved' => 'není ověřena země protistrany',
        'partner_country_eu_unspecified' => 'země protistrany je uvedena pouze jako EU',
        'partner_country_changed' => 'země na dokladu se liší od karty protistrany',
        'tax_date_supply_mismatch' => 'datum DPH a den uskutečnění plnění se liší',
        'vat_register_mismatch' => 'údaje dokladu nesouhlasí se zdrojovou evidencí DPH',
        'mixed_vat_deduction' => 'doklad kombinuje položky s různým nárokem na odpočet DPH',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxSourcePlan $sourcePlan,
        private readonly StereoNxImportMap $map,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly MovementClassificationRepository $classifications,
        private readonly StatsRecomputer $stats,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Stejná cesta pro ostrý převod i zkoušku nanečisto. Suchý běh provede veškeré
     * SQL v transakci/savepointu a výsledek vrátí; nic nesmí zůstat v databázi.
     *
     * @return array<string,mixed>
     */
    public function run(StereoNxBackup $backup, int $supplierId, int $userId, bool $dryRun, bool $blankCountryIsCz = false): array
    {
        $statsClientIds = [];
        $report = [
            'ok' => false, 'dry_run' => $dryRun, 'database_writes' => false,
            'preflight' => [], 'errors' => [], 'warnings' => [], 'counts' => [],
            'review_documents' => [],
        ];
        try {
            $plan = $this->sourcePlan->build($backup, $blankCountryIsCz);
            $report['counts'] = $plan['counts'] ?? [];
            $reviewReasons = [];
            foreach (['issued' => 'issued', 'purchases' => 'purchase'] as $section => $kind) {
                foreach ($plan[$section] ?? [] as $document) {
                    $codes = array_values(array_unique(array_map('strval', $document['review_codes'] ?? [])));
                    foreach ($codes as $code) $reviewReasons[$code] = ($reviewReasons[$code] ?? 0) + 1;
                    if (($document['requires_draft'] ?? false) === true) {
                        $report['review_documents'][] = [
                            'kind' => $kind,
                            'source_key' => (string) ($document['source_key'] ?? ''),
                            'document_no' => (string) ($document['document_no'] ?? ''),
                            'review_codes' => $codes,
                            'target_id' => null,
                        ];
                    }
                }
            }
            $report['review_reasons'] = $reviewReasons;
            $sourceDates = [];
            foreach (['issued', 'purchases', 'bank_statements', 'bank_transactions', 'cash_transactions'] as $part) {
                foreach ($plan[$part] ?? [] as $row) {
                    $date = $row['issue_date'] ?? $row['date'] ?? null;
                    if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $sourceDates[] = $date;
                    if (in_array($part, ['issued', 'purchases'], true)) {
                        foreach (['tax_date', 'supply_date'] as $field) {
                            $date = $row[$field] ?? null;
                            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $sourceDates[] = $date;
                        }
                    }
                }
            }
            if ($sourceDates !== []) $report['date_bounds'] = ['from' => min($sourceDates), 'to' => max($sourceDates)];
            $zeroCashCount = count(array_filter($plan['cash_transactions'] ?? [],
                static fn (array $movement): bool => abs((float) ($movement['amount'] ?? 0)) < 0.005));
            if ($zeroCashCount > 0) {
                $report['warnings'][] = ['level' => 'warning', 'code' => 'zero_cash_opening',
                    'message' => 'Nulový počáteční záznam pokladny nevyvolal peněžní pohyb.',
                    'count' => $zeroCashCount];
            }
            $pdo = $this->db->pdo();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT stereo_nx_import');
            else $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
                $lock->execute([$supplierId]);
                if ($lock->fetchColumn() === false) throw new StereoNxException('supplier_missing', 'Cílová firma neexistuje.');
                $preflight = $this->preflight($supplierId, $backup, $plan);
                $report['preflight'] = $preflight;
                foreach ($preflight as $finding) {
                    if (($finding['level'] ?? '') === 'error') $report['errors'][] = $finding;
                    else $report['warnings'][] = $finding;
                }
                if ($report['errors'] !== []) {
                    $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import') : $pdo->rollBack();
                    return $report;
                }
                $context = [
                    'supplier_id' => $supplierId,
                    'user_id' => $userId,
                    'ico' => (string) $plan['identity']['ico'],
                    'company_index' => $backup->companyIndex(),
                    'currency_id' => $this->currencyId($supplierId),
                    'country_id' => $this->countryId('CZ'),
                    'ids' => ['client' => [], 'issued' => [], 'purchase' => [], 'bank_account' => [],
                        'bank_statement' => [], 'bank' => [], 'cash' => []],
                    'new_documents' => ['issued' => [], 'purchase' => []], 'new_statements' => [],
                    'touched_documents' => ['issued' => [], 'purchase' => []], 'touched_statements' => [],
                    'zero_cash' => [],
                    'actual_review_documents' => [],
                    'written' => ['clients' => 0, 'issued' => 0, 'purchases' => 0, 'bank_statements' => 0,
                        'bank_transactions' => 0, 'cash_transactions' => 0, 'payments' => 0,
                        'classifications' => 0, 'skipped_zero_cash' => 0],
                ];
                if ($context['country_id'] <= 0) throw new StereoNxException('country_missing', 'Chybí země CZ v cílové databázi.');
                foreach ($plan['clients'] ?? [] as $record) $this->importClient($context, $record);
                foreach ($plan['issued'] ?? [] as $record) $this->importDocument($context, 'issued', $record);
                foreach ($plan['purchases'] ?? [] as $record) $this->importDocument($context, 'purchase', $record);
                foreach ($plan['bank_accounts'] ?? [] as $record) $this->importBankAccount($context, $record);
                foreach ($plan['bank_statements'] ?? [] as $record) $this->importBankStatement($context, $record);
                foreach ($plan['bank_transactions'] ?? [] as $record) $this->importBankTransaction($context, $record);
                foreach ($plan['cash_transactions'] ?? [] as $record) $this->importCashTransaction($context, $record);
                foreach ($plan['payments'] ?? [] as $record) $this->importPayment($context, $record);
                foreach ($plan['movement_classifications'] ?? [] as $record) $this->importClassification($context, $record);
                $this->refreshBalances($context);
                $reviewDocuments = [];
                foreach ($report['review_documents'] as $document) {
                    $reviewDocuments[$document['kind'] . "\0" . $document['source_key']] = $document;
                }
                foreach ($context['actual_review_documents'] as $key => $document) {
                    $reviewDocuments[$key] = $document;
                }
                $report['review_documents'] = array_values($reviewDocuments);
                $report['counts']['requires_draft'] = count($report['review_documents']);
                $report['review_reasons'] = [];
                foreach ($report['review_documents'] as $document) {
                    foreach ($document['review_codes'] as $code) {
                        $report['review_reasons'][$code] = ($report['review_reasons'][$code] ?? 0) + 1;
                    }
                }
                $statsClientIds = $this->issuedStatsClientIds($context);
                $report['written'] = $context['written'];
                $report['ok'] = true;
                if ($dryRun) {
                    $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import') : $pdo->rollBack();
                } elseif ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT stereo_nx_import');
                    $report['database_writes'] = true;
                } else {
                    $pdo->commit();
                    $report['database_writes'] = true;
                }
                if ($report['database_writes']) {
                    foreach ($report['review_documents'] as &$reviewDocument) {
                        $reviewDocument['target_id'] = $context['ids'][$reviewDocument['kind']][$reviewDocument['source_key']] ?? null;
                    }
                    unset($reviewDocument);
                }
            } catch (Throwable $e) {
                if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import');
                elseif ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } catch (StereoNxException $e) {
            $report['ok'] = false;
            $report['database_writes'] = false;
            $report['errors'][] = ['level' => 'error', 'code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $report['ok'] = false;
            $report['database_writes'] = false;
            // PDO/ZIP/reader výjimka může obsahovat hodnoty z klientských dat.
            $diagnostic = ['exception_class' => $e::class];
            if ($e instanceof PDOException) {
                $sqlState = (string) $e->getCode();
                if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) $diagnostic['sqlstate'] = $sqlState;
                $driverCode = $e->errorInfo[1] ?? null;
                if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                    $diagnostic['driver_code'] = (int) $driverCode;
                }
            }
            $this->log->error('Stereo NX import failed', $diagnostic);
            $report['errors'][] = ['level' => 'error', 'code' => 'import_failed', 'message' => 'Převod selhal; v databázi nebyly uloženy žádné nové záznamy.'];
        }
        if ($report['ok'] && !$dryRun && !$this->db->pdo()->inTransaction() && $statsClientIds !== []) {
            try {
                $this->stats->recomputeMany($statsClientIds);
            } catch (Throwable) {
                // Převod už byl potvrzen; chybu vedlejší cache nelze vydávat za rollback.
                $report['warnings'][] = ['level' => 'warning', 'code' => 'stats_recompute_failed',
                    'message' => 'Převod proběhl, ale statistiky klientů je třeba přepočítat.'];
            }
        }
        return $report;
    }

    /** @param array<string,mixed> $ctx @return list<int> */
    private function issuedStatsClientIds(array $ctx): array
    {
        $ids = array_values(array_unique([...array_values($ctx['new_documents']['issued']),
            ...array_values($ctx['touched_documents']['issued'])]));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT client_id FROM invoices
            WHERE supplier_id = ? AND id IN ({$placeholders}) AND client_id IS NOT NULL");
        $stmt->execute([$ctx['supplier_id'], ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $plan
     * @return list<array<string,mixed>> */
    private function preflight(int $supplierId, StereoNxBackup $backup, array $plan): array
    {
        $out = [];
        $error = static function (string $code, string $message, array $details = []) use (&$out): void {
            $out[] = ['level' => 'error', 'code' => $code, 'message' => $message, ...$details];
        };
        $stmt = $this->db->pdo()->prepare('SELECT ic, is_vat_payer, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $error('supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }
        $identity = $plan['identity'] ?? [];
        if (PohodaPartners::ico((string) ($supplier['ic'] ?? '')) !== ($identity['ico'] ?? null)) {
            $error('ico_mismatch', 'IČO firmy v záloze a vybrané firmy se neshoduje.');
        }
        if (($identity['vat_payer'] ?? null) !== true || (int) $supplier['is_vat_payer'] !== 1) {
            $error('vat_mode_mismatch', 'Převod je nyní určený pouze pro plátce DPH v obou systémech.');
        }
        if ($supplier['accounting_mode'] !== 'tax_evidence') {
            $error('accounting_mode_mismatch', 'Cílová firma musí být v režimu daňové evidence.');
        }
        foreach ($plan['blockers'] ?? [] as $blocker) {
            $code = is_array($blocker) ? (string) ($blocker['code'] ?? 'source_blocker') : (string) $blocker;
            $error('source_' . preg_replace('/[^a-z0-9_]/', '', $code), 'Záloha obsahuje nepodporované nebo nejednoznačné zdrojové údaje.');
        }
        $dates = [];
        foreach (['issued', 'purchases', 'bank_statements', 'bank_transactions', 'cash_transactions'] as $kind) {
            foreach ($plan[$kind] ?? [] as $record) {
                $date = $record['issue_date'] ?? $record['date'] ?? null;
                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $dates[] = $date;
                else $error('source_date_missing', 'Zdrojový doklad nebo pohyb nemá platné datum.');
                if (in_array($kind, ['issued', 'purchases'], true)) {
                    foreach (['tax_date', 'supply_date'] as $field) {
                        if (($record[$field] ?? null) !== null) $dates[] = $this->date($record[$field]);
                    }
                }
            }
        }
        $years = array_values(array_unique(array_map(static fn (string $d): int => (int) substr($d, 0, 4), $dates)));
        $period = $this->db->pdo()->prepare('SELECT status FROM accounting_periods
            WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? LIMIT 1');
        $lockStmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lockStmt->execute([$supplierId]);
        $lockedUntil = $lockStmt->fetchColumn();
        foreach (array_unique($dates) as $date) {
            $period->execute([$supplierId, $date, $date]);
            $status = $period->fetchColumn();
            if ($status !== false && $status !== 'open') $error('period_closed', 'Cílové účetní období je uzavřené.');
            if ($lockedUntil !== false && $lockedUntil !== null && $date <= $lockedUntil) {
                $error('date_locked', 'Datum zdrojového dokladu nebo pohybu spadá do uzamčeného období.');
            }
        }
        foreach ($years as $year) {
            foreach ([['invoices', 'issued', 'issue_date', 'vydané faktury'],
                ['purchase_invoices', 'purchase', 'issue_date', 'přijaté faktury'],
                ['bank_statements', 'bank_statement', 'statement_date', 'bankovní výpisy'],
                ['cash_documents', 'cash', 'issue_date', 'pokladní doklady']] as [$table, $kind, $dateColumn, $label]) {
                $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} t
                    WHERE t.supplier_id = ? AND t.{$dateColumn} >= ? AND t.{$dateColumn} < ?
                      AND NOT EXISTS (SELECT 1 FROM stereo_nx_import_map m
                        WHERE m.supplier_id = ? AND m.source_ico = ? AND m.source_company_index = ?
                          AND m.kind = ? AND m.target_id = t.id)");
                $start = sprintf('%04d-01-01', $year);
                $end = sprintf('%04d-01-01', $year + 1);
                $stmt->execute([$supplierId, $start, $end, $supplierId, $identity['ico'], $backup->companyIndex(), $kind]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $error('target_period_not_empty', sprintf(
                        'Počet nesouvisejících záznamů v agendě „%s“ za rok %d: %d.', $label, $year, $count,
                    ), ['year' => $year, 'agenda' => $table, 'count' => $count]);
                }
            }
            $bank = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transactions bt
                JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE bs.supplier_id = ? AND bt.posted_at >= ? AND bt.posted_at < ?
                  AND NOT EXISTS (SELECT 1 FROM stereo_nx_import_map m
                    WHERE m.supplier_id = ? AND m.source_ico = ? AND m.source_company_index = ?
                      AND m.kind = "bank" AND m.target_id = bt.id)');
            $bank->execute([$supplierId, $start, $end, $supplierId, $identity['ico'], $backup->companyIndex()]);
            $count = (int) $bank->fetchColumn();
            if ($count > 0) {
                $error('target_period_not_empty', sprintf(
                    'Počet nesouvisejících záznamů v agendě „bankovní pohyby“ za rok %d: %d.', $year, $count,
                ), ['year' => $year, 'agenda' => 'bank_transactions', 'count' => $count]);
            }
        }
        $seen = [];
        return array_values(array_filter($out, static function (array $finding) use (&$seen): bool {
            $key = json_encode($finding, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importClient(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'client', $key, $hash);
        if ($existing !== null) { $ctx['ids']['client'][$key] = $existing; return; }
        $ico = PohodaPartners::ico((string) ($record['ico'] ?? ''));
        if ($ico !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT id, dic FROM clients WHERE supplier_id = ? AND ic = ? AND archived_at IS NULL ORDER BY id LIMIT 1');
            $stmt->execute([$ctx['supplier_id'], $ico]);
            $matched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($matched !== false) {
                $sourceDic = PohodaPartners::vatId((string) ($record['dic'] ?? ''));
                $targetDic = PohodaPartners::vatId((string) ($matched['dic'] ?? ''));
                if ($sourceDic !== '' && $targetDic !== '' && $sourceDic !== $targetDic) {
                    throw new StereoNxException('client_identity_conflict', 'Existující partner má při stejném IČO jiné DIČ.');
                }
                $id = (int) $matched['id'];
                $this->put($ctx, 'client', $key, $hash, $id);
                $ctx['ids']['client'][$key] = $id;
                return;
            }
        }
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') throw new StereoNxException('client_name_missing', 'Adresář obsahuje partnera bez názvu.');
        $country = strtoupper(trim((string) ($record['country_code'] ?? '')));
        if ($country === '' || ($record['country_unresolved'] ?? false) === true) {
            // Zdrojový plán takového partnera připouští jen s doklady v konceptu.
            $country = 'CZ';
        }
        $countryId = $this->countryId($country);
        if ($countryId <= 0) throw new StereoNxException('country_unsupported', 'Země partnera nemá jednoznačné zařazení.');
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO clients
            (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email, phone,
             currency_default_id, is_customer, is_vendor, is_vat_payer, note, auto_send_reminders)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, 0)')->execute([
            $ctx['supplier_id'], mb_substr($name, 0, 190), $ico !== '' ? $ico : null,
            PohodaPartners::vatId((string) ($record['dic'] ?? '')) ?: null,
            mb_substr((string) ($record['street'] ?? ''), 0, 190),
            mb_substr((string) ($record['city'] ?? ''), 0, 120),
            mb_substr((string) ($record['zip'] ?? ''), 0, 10), $countryId,
            mb_substr((string) ($record['email'] ?? ''), 0, 190) ?: null,
            mb_substr((string) ($record['phone'] ?? ''), 0, 40) ?: null,
            $ctx['currency_id'], ($record['row']['PlatDPH'] ?? null) === true ? 1 : 0,
            ($record['country_unresolved'] ?? false) ? 'Převzato ze Stereo NX; zemi nutno ověřit.' : 'Převzato ze Stereo NX',
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->put($ctx, 'client', $key, $hash, $id);
        $ctx['ids']['client'][$key] = $id;
        $ctx['written']['clients']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importDocument(array &$ctx, string $kind, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $currentLabelHash = self::sourceHash($record, false);
        $existing = $this->mapped($ctx, $kind, $key, $hash,
            $currentLabelHash !== $hash ? $currentLabelHash : null);
        if ($existing !== null) { $ctx['ids'][$kind][$key] = $existing; return; }
        $partnerId = $ctx['ids']['client'][(string) ($record['partner_key'] ?? '')] ?? null;
        if ($partnerId === null) throw new StereoNxException('document_partner_missing', 'Doklad odkazuje na neznámého partnera.');
        $number = trim((string) ($record['document_no'] ?? ''));
        $issue = $this->date($record['issue_date'] ?? null);
        $tax = $this->date($record['supply_date'] ?? $record['tax_date'] ?? $issue);
        $sourceVatDate = $this->date($record['tax_date'] ?? $tax);
        $due = $this->date($record['due_date'] ?? $issue);
        if ($number === '' || mb_strlen($number) > 20) throw new StereoNxException('document_number_invalid', 'Doklad nemá platné číslo.');
        $table = $kind === 'issued' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $stmt->execute([$ctx['supplier_id'], $number]);
        if ($stmt->fetchColumn() !== false) throw new StereoNxException('document_number_taken', 'Číslo zdrojového dokladu už ve firmě používá jiný doklad.');
        $items = $record['items'] ?? [];
        if (!is_array($items)) throw new StereoNxException('document_items_invalid', 'Položky dokladu nejsou platné.');
        $base = $this->money($record['total_without_vat'] ?? null);
        $vat = $this->money($record['total_vat'] ?? null);
        $total = $this->money($record['source_total_with_vat'] ?? $record['total_with_vat'] ?? null);
        $rounding = $this->money($record['rounding'] ?? 0);
        if (abs($base + $vat + $rounding - $total) > 0.011) {
            throw new StereoNxException('document_total_mismatch', 'Součet dokladu nesouhlasí s položkami a zaokrouhlením.');
        }
        $review = (bool) ($record['requires_draft'] ?? false) || $items === [];
        $reviewCodes = array_values(array_unique(array_map('strval', $record['review_codes'] ?? [])));
        if ($items === []) $reviewCodes[] = 'missing_items';
        $liveSnapshot = $this->clientSnapshot($ctx['supplier_id'], $partnerId);
        $historical = $record['partner_snapshot'] ?? [];
        if (is_array($historical) && ($historical['country_unresolved'] ?? true) === false
            && ($historical['country_code'] ?? '') !== ''
            && $historical['country_code'] !== $liveSnapshot['country_code']) {
            $review = true;
            $reviewCodes[] = 'partner_country_changed';
        }
        if ($review) {
            $ctx['actual_review_documents'][$kind . "\0" . $key] = [
                'kind' => $kind, 'source_key' => $key, 'document_no' => $number,
                'review_codes' => array_values(array_unique($reviewCodes)), 'target_id' => null,
            ];
        }
        $note = 'Převzato ze Stereo NX.';
        if ($reviewCodes !== []) {
            $labels = array_map(static fn (string $code): string => self::REVIEW_LABELS[$code] ?? 'neověřený údaj ze zdrojové zálohy', $reviewCodes);
            $note .= ' K ruční kontrole: ' . implode('; ', array_unique($labels)) . '.';
        }
        if ($sourceVatDate !== $tax) $note .= ' Zdrojové datum pro DPH: ' . $sourceVatDate . '; DUZP: ' . $tax . '.';
        $sourceNote = trim((string) ($record['note'] ?? ''));
        $snapshot = $liveSnapshot;
        if (is_array($historical)) {
            foreach (['name', 'ico', 'dic', 'street', 'city', 'zip'] as $field) {
                $value = trim((string) ($historical[$field] ?? ''));
                if ($value !== '') $snapshot[$field] = $value;
            }
        }
        $snapshotJson = PohodaPartners::snapshotJson($snapshot);
        $vs = preg_replace('/\D/', '', (string) ($record['variable_symbol'] ?? '')) ?? '';
        $vs = strlen($vs) <= 10 ? ($vs !== '' ? $vs : null) : null;
        $pdo = $this->db->pdo();
        if ($kind === 'issued') {
            $pdo->prepare('INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, payment_variable_symbol, issue_date, tax_date,
                 due_date, currency_id, note_above_items, note_below_items, client_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, status, prices_include_vat,
                 reverse_charge, created_by)
                VALUES (?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $ctx['supplier_id'], $partnerId, $number, $vs, $issue, $tax, $due, $ctx['currency_id'],
                $sourceNote !== '' ? mb_substr($sourceNote, 0, 1000) : null, $note, $snapshotJson,
                $base, $vat, $total, $rounding, $review ? 'draft' : 'sent',
                ($record['prices_include_vat'] ?? false) ? 1 : 0,
                ($record['reverse_charge'] ?? false) ? 1 : 0, $ctx['user_id'],
            ]);
        } else {
            $vendorNumber = trim((string) ($record['vendor_number'] ?? ''));
            if ($vendorNumber === '') $vendorNumber = $number;
            [$deduction, $mixedDeduction] = self::purchaseDeduction($items);
            if ($mixedDeduction) {
                throw new StereoNxException('mixed_deduction_unsupported', 'Zdaněné položky s různým nárokem na odpočet vyžadují samostatné mapování.');
            }
            $pdo->prepare('INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, received_at_source, currency_id, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, status, prices_include_vat,
                 reverse_charge, vat_deduction, note_above_items, note_below_items, created_by)
                VALUES (?, ?, ?, ?, ?, "invoice", ?, ?, ?, ?, "import", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $ctx['supplier_id'], $partnerId, $liveSnapshot['is_vat_payer'] ? 1 : 0, $number,
                mb_substr($vendorNumber, 0, 50), $issue, $tax, $due, max($issue, $tax), $ctx['currency_id'],
                $snapshotJson, $base, $vat, $total, $rounding, $review ? 'draft' : 'received',
                ($record['prices_include_vat'] ?? false) ? 1 : 0,
                ($record['reverse_charge'] ?? false) ? 1 : 0, $deduction,
                $sourceNote !== '' ? mb_substr($sourceNote, 0, 1000) : null, $note, $ctx['user_id'],
            ]);
        }
        $id = (int) $pdo->lastInsertId();
        $this->insertItems($kind, $id, $items, $tax);
        $this->put($ctx, $kind, $key, $hash, $id);
        $ctx['ids'][$kind][$key] = $id;
        $ctx['new_documents'][$kind][$key] = $id;
        $ctx['written'][$kind === 'issued' ? 'issued' : 'purchases']++;
    }

    /** @param list<array<string,mixed>> $items */
    private function insertItems(string $kind, int $documentId, array $items, string $taxDate): void
    {
        $table = $kind === 'issued' ? 'invoice_items' : 'purchase_invoice_items';
        $foreign = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare("INSERT INTO {$table}
            ({$foreign}, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
             total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $i => $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? -1);
            if ($rate < 0 || $rate > 100) throw new StereoNxException('vat_rate_invalid', 'Neplatná sazba DPH položky.');
            $rateId = $this->rateId($rate, $taxDate);
            if ($rateId === null) throw new StereoNxException('vat_rate_missing', 'Sazba DPH položky není v cílovém číselníku.');
            $stmt->execute([
                $documentId, (string) ($item['description'] ?? ''), (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'), (float) ($item['unit_price_without_vat'] ?? $item['unit_price'] ?? 0),
                $rateId, $rate,
                $this->money($item['total_without_vat'] ?? null), $this->money($item['total_vat'] ?? null),
                $this->money($item['total_with_vat'] ?? null), $i,
                $item['vat_classification_code'] ?? null,
            ]);
        }
    }

    /**
     * Nulová sazba bez DPH nepředstavuje nárok na odpočet a nesmí přebít režim
     * zdaněných položek. Smíšený nárok u dvou skutečně zdaněných položek se drží
     * v konceptu: cílový VAT ledger umí deduction jen na hlavičce dokladu.
     *
     * @param list<array<string,mixed>> $items
     * @return array{0:string,1:bool} deduction hlavičky, vyžaduje kontrolu
     */
    public static function purchaseDeduction(array $items): array
    {
        $taxableModes = [];
        foreach ($items as $item) {
            $mode = (string) ($item['vat_deduction'] ?? 'full');
            if (!in_array($mode, ['full', 'none', 'proportional', 'reduced'], true)) {
                throw new StereoNxException('vat_deduction_invalid', 'Položka má neplatný rozsah odpočtu DPH.');
            }
            $hasVat = abs((float) ($item['total_vat'] ?? 0)) >= 0.005
                || abs((float) ($item['source_self_assessed_vat'] ?? 0)) >= 0.005;
            if ($hasVat) $taxableModes[$mode] = true;
        }
        if ($taxableModes === []) return ['none', false];
        if (count($taxableModes) > 1) return ['none', true];
        return [(string) array_key_first($taxableModes), false];
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankAccount(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank_account', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank_account'][$key] = $existing; return; }
        $number = trim((string) ($record['account_number'] ?? ''));
        if ($number === '') throw new StereoNxException('bank_account_missing', 'Záloha neobsahuje číslo vlastního účtu.');
        $id = $this->bankAccounts->registerImported($ctx['supplier_id'], $number,
            $record['bank_code'] ?? null, $record['iban'] ?? null,
            (string) ($record['currency'] ?? 'CZK'), (string) ($record['label'] ?? 'Stereo NX'), null);
        if ($id === null) throw new StereoNxException('bank_account_conflict', 'Vlastní bankovní účet nelze bezpečně přiřadit vybrané firmě.');
        $this->put($ctx, 'bank_account', $key, $hash, $id);
        $ctx['ids']['bank_account'][$key] = $id;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankStatement(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank_statement', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank_statement'][$key] = $existing; return; }
        $accountKey = (string) ($record['account_key'] ?? '');
        $accountId = $ctx['ids']['bank_account'][$accountKey] ?? null;
        if ($accountId === null) throw new StereoNxException('statement_account_missing', 'Výpis odkazuje na neznámý vlastní účet.');
        $stmt = $this->db->pdo()->prepare('SELECT account_number, bank_code, currency FROM supplier_bank_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $ctx['supplier_id']]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account === false) throw new StereoNxException('statement_account_missing', 'Vlastní účet nepatří vybrané firmě.');
        $date = $this->date($record['date'] ?? null);
        $number = mb_substr((string) ($record['document_no'] ?? $key), 0, 20);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_statements
            (supplier_id, source, source_ref, file_name, file_hash, account_number, bank_code,
             currency, statement_number, statement_date, transaction_count, imported_by)
            VALUES (?, "import", ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')->execute([
            $ctx['supplier_id'], mb_substr($key, 0, 190), 'stereo-nx.import',
            hash('sha256', 'stereo-nx|' . $ctx['supplier_id'] . '|' . $ctx['ico'] . '|' . $ctx['company_index'] . '|' . $key),
            $account['account_number'], $account['bank_code'], $account['currency'] ?: 'CZK',
            $number, $date, $ctx['user_id'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->put($ctx, 'bank_statement', $key, $hash, $id);
        $ctx['ids']['bank_statement'][$key] = $id;
        $ctx['new_statements'][$key] = $id;
        $ctx['written']['bank_statements']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankTransaction(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank'][$key] = $existing; return; }
        $statementId = $ctx['ids']['bank_statement'][(string) ($record['statement_key'] ?? '')] ?? null;
        if ($statementId === null) throw new StereoNxException('transaction_statement_missing', 'Bankovní pohyb nemá výpis.');
        $amount = $this->money($record['amount'] ?? null);
        $date = $this->date($record['date'] ?? null);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_transactions
            (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol,
             counterparty_account, counterparty_bank, counterparty_name, description, import_fingerprint)
            VALUES ("statement", ?, ?, ?, ?, "CZK", ?, ?, ?, ?, ?, ?)')->execute([
            mb_substr($key, 0, 190), $statementId, $date, $amount,
            mb_substr((string) ($record['variable_symbol'] ?? ''), 0, 10) ?: null,
            mb_substr((string) ($record['counterparty_account'] ?? ''), 0, 40) ?: null,
            mb_substr((string) ($record['counterparty_bank'] ?? ''), 0, 4) ?: null,
            mb_substr((string) ($record['counterparty_name'] ?? ''), 0, 190) ?: null,
            mb_substr((string) ($record['description'] ?? ''), 0, 255) ?: null,
            hash('sha256', 'stereo-nx|' . $ctx['supplier_id'] . '|' . $ctx['ico'] . '|' . $ctx['company_index'] . '|' . $key),
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->put($ctx, 'bank', $key, $hash, $id);
        $ctx['ids']['bank'][$key] = $id;
        $ctx['touched_statements'][$statementId] = $statementId;
        $ctx['written']['bank_transactions']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importCashTransaction(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $amount = $this->money($record['amount'] ?? null);
        if (abs($amount) < 0.005) {
            if (($record['document_key'] ?? null) !== null) {
                throw new StereoNxException('zero_payment_invalid', 'Nulový pokladní pohyb nemůže být úhradou dokladu.');
            }
            $registerId = $this->cashRegister($ctx['supplier_id']);
            if ($this->mapped($ctx, 'cash_zero', $key, $hash) === null) {
                // Zdrojové nulové otevření pokladny nemá peněžní dopad a cílová
                // cash_documents nedovoluje posted doklad s nulovou částkou.
                $this->put($ctx, 'cash_zero', $key, $hash, $registerId);
                $ctx['written']['skipped_zero_cash']++;
            }
            $ctx['zero_cash'][$key] = true;
            return;
        }
        $existing = $this->mapped($ctx, 'cash', $key, $hash);
        if ($existing !== null) { $ctx['ids']['cash'][$key] = $existing; return; }
        $registerId = $this->cashRegister($ctx['supplier_id']);
        $date = $this->date($record['date'] ?? null);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO cash_documents
            (supplier_id, register_id, doc_number, doc_type, issue_date, description, purpose,
             total_amount, currency_code, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, "other", ?, "CZK", "posted", ?)')->execute([
            $ctx['supplier_id'], $registerId, mb_substr((string) ($record['document_no'] ?? $key), 0, 50),
            $amount >= 0 ? 'in' : 'out', $date,
            mb_substr((string) ($record['description'] ?? 'Stereo NX'), 0, 255), abs($amount), $ctx['user_id'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->put($ctx, 'cash', $key, $hash, $id);
        $ctx['ids']['cash'][$key] = $id;
        $ctx['written']['cash_transactions']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importPayment(array &$ctx, array $record): void
    {
        $key = $this->paymentKey($record);
        $hash = self::sourceHash($record);
        $kind = (string) ($record['document_kind'] ?? '');
        $movementType = (string) ($record['movement_type'] ?? '');
        if (!in_array($kind, ['issued', 'purchase'], true) || !in_array($movementType, ['bank', 'cash'], true)) {
            throw new StereoNxException('payment_link_invalid', 'Vazba úhrady má nepodporovaný druh.');
        }
        $mapKind = 'payment_' . $movementType;
        $docId = $ctx['ids'][$kind][(string) ($record['document_key'] ?? '')] ?? null;
        $movementId = $ctx['ids'][$movementType][(string) ($record['movement_key'] ?? '')] ?? null;
        if ($docId === null || $movementId === null) throw new StereoNxException('payment_target_missing', 'Úhrada odkazuje na chybějící doklad nebo pohyb.');
        $amount = abs($this->money($record['amount'] ?? null));
        if ($amount < 0.005) throw new StereoNxException('payment_amount_invalid', 'Úhrada nemá kladnou částku.');
        $existing = $this->mapped($ctx, $mapKind, $key, $hash);
        if ($existing !== null) {
            $this->validateExistingPayment($ctx['supplier_id'], $kind, $movementType, $docId, $movementId, $amount, $existing);
            return;
        }
        $pdo = $this->db->pdo();
        if ($movementType === 'bank') {
            $pdo->prepare('INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                VALUES (?, ?, ?, ?, ?, "manual", ?)')->execute([
                $ctx['supplier_id'], $movementId, $kind === 'issued' ? $docId : null,
                $kind === 'purchase' ? $docId : null, $amount, $ctx['user_id'],
            ]);
            $linkId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE bank_transactions SET match_status = "manual", matched_at = NOW(), matched_by = ?
                WHERE id = ? AND statement_id IN (SELECT id FROM bank_statements WHERE supplier_id = ?)')
                ->execute([$ctx['user_id'], $movementId, $ctx['supplier_id']]);
        } else {
            $column = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
            $purpose = $kind === 'issued' ? 'invoice_payment' : 'purchase_payment';
            $stmt = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$movementId, $ctx['supplier_id']]);
            $cash = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cash === false || $cash['invoice_id'] !== null || $cash['purchase_invoice_id'] !== null) {
                throw new StereoNxException('cash_payment_ambiguous', 'Pokladní pohyb nelze jednoznačně spárovat s dokladem.');
            }
            $pdo->prepare("UPDATE cash_documents SET {$column} = ?, purpose = ?, vat_mode = 'none' WHERE id = ? AND supplier_id = ?")
                ->execute([$docId, $purpose, $movementId, $ctx['supplier_id']]);
            $linkId = $movementId;
        }
        if ($kind === 'issued') {
            $date = $this->movementDate($movementType, $movementId, $ctx['supplier_id']);
            $pdo->prepare('INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id, created_by)
                VALUES (?, ?, ?, ?, "CZK", ?, ?, ?)')->execute([
                $ctx['supplier_id'], $docId, $date, $amount, $movementType,
                $movementType === 'bank' ? $movementId : null, $ctx['user_id'],
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            if ($movementType === 'cash') {
                $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$paymentId, $movementId, $ctx['supplier_id']]);
            }
        }
        $this->put($ctx, $mapKind, $key, $hash, $linkId);
        $ctx['touched_documents'][$kind][$docId] = $docId;
        if ($movementType === 'bank') {
            $stmt = $pdo->prepare('SELECT statement_id FROM bank_transactions WHERE id = ?');
            $stmt->execute([$movementId]);
            $statementId = (int) $stmt->fetchColumn();
            if ($statementId > 0) $ctx['touched_statements'][$statementId] = $statementId;
        }
        $ctx['written']['payments']++;
    }

    private function validateExistingPayment(int $supplierId, string $kind, string $movementType,
        int $docId, int $movementId, float $amount, int $mappedId): void
    {
        if ($movementType === 'bank') {
            $column = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM payment_matches WHERE id = ? AND supplier_id = ?
                AND bank_transaction_id = ? AND {$column} = ? AND ABS(amount - ?) < 0.01");
            $stmt->execute([$mappedId, $supplierId, $movementId, $docId, $amount]);
            if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená úhrada byla změněna nebo odstraněna.');
            if ($kind === 'issued') {
                $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_payments WHERE supplier_id = ?
                    AND invoice_id = ? AND bank_transaction_id = ? AND ABS(amount - ?) < 0.01 LIMIT 1');
                $stmt->execute([$supplierId, $docId, $movementId, $amount]);
                if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená příjmová úhrada chybí.');
            }
            return;
        }
        $column = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare("SELECT invoice_payment_id FROM cash_documents WHERE id = ? AND supplier_id = ?
            AND id = ? AND {$column} = ? AND ABS(total_amount - ?) < 0.01 AND status = 'posted'");
        $stmt->execute([$mappedId, $supplierId, $movementId, $docId, $amount]);
        $paymentId = $stmt->fetchColumn();
        if ($paymentId === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená hotovostní úhrada byla změněna.');
        if ($kind === 'issued') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_payments WHERE id = ? AND supplier_id = ?
                AND invoice_id = ? AND source = "cash" AND ABS(amount - ?) < 0.01');
            $stmt->execute([$paymentId, $supplierId, $docId, $amount]);
            if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená hotovostní příjmová úhrada chybí.');
        }
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importClassification(array &$ctx, array $record): void
    {
        $kind = (string) ($record['movement_type'] ?? '');
        $sourceKey = (string) ($record['movement_key'] ?? '');
        if ($kind === 'cash' && isset($ctx['zero_cash'][$sourceKey])) return;
        $id = $ctx['ids'][$kind][$sourceKey] ?? null;
        if (!in_array($kind, ['bank', 'cash'], true) || $id === null) {
            throw new StereoNxException('classification_movement_missing', 'Zařazení peněžního deníku nemá pohyb.');
        }
        // U spárovaného dokladu přebírá daňový základ z položek kanonický peněžní deník;
        // override celého brutto by zde zdanil i DPH. Číselník Stereo zůstává jen pro
        // pohyby bez dokladu, jejichž částku nelze rozložit podle faktury.
        if (($record['document_key'] ?? null) !== null) return;
        $mapKind = 'classification_' . $kind;
        $mapKey = $sourceKey;
        $hash = self::sourceHash($record);
        if ($this->mapped($ctx, $mapKind, $mapKey, $hash) !== null) return;
        $bucket = (string) ($record['bucket'] ?? '');
        if (!in_array($bucket, MovementClassificationRepository::TAX_BUCKETS, true)) {
            throw new StereoNxException('classification_bucket_invalid', 'Zdrojový sloupec peněžního deníku nemá podporované zařazení.');
        }
        if (!$this->classifications->belongsToSupplier($ctx['supplier_id'], $kind, $id)) {
            throw new StereoNxException('classification_owner_invalid', 'Pohyb peněžního deníku nepatří vybrané firmě.');
        }
        $this->classifications->upsert($ctx['supplier_id'], $kind, $id, $bucket, 'Stereo NX', $ctx['user_id']);
        $this->put($ctx, $mapKind, $mapKey, $hash, $id);
        $ctx['written']['classifications']++;
    }

    /** @param array<string,mixed> $ctx */
    private function refreshBalances(array $ctx): void
    {
        $pdo = $this->db->pdo();
        foreach (array_unique([...array_values($ctx['new_documents']['issued']), ...array_values($ctx['touched_documents']['issued'])]) as $id) {
            $pdo->prepare('UPDATE invoices SET paid_total =
                (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?),
                paid_at = (SELECT MAX(paid_on) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?)
                WHERE supplier_id = ? AND id = ?')->execute([
                $ctx['supplier_id'], $id, $ctx['supplier_id'], $id, $ctx['supplier_id'], $id,
            ]);
            $pdo->prepare('UPDATE invoices SET status = "paid" WHERE supplier_id = ? AND id = ? AND status <> "draft"
                AND paid_total >= total_with_vat - 0.01')->execute([$ctx['supplier_id'], $id]);
        }
        foreach (array_unique([...array_values($ctx['new_documents']['purchase']), ...array_values($ctx['touched_documents']['purchase'])]) as $id) {
            $pdo->prepare('UPDATE purchase_invoices SET paid_amount_invoice_ccy =
                (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_matches pm
                  WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?)
                + (SELECT COALESCE(SUM(cd.total_amount), 0) FROM cash_documents cd
                    WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND cd.status = "posted")
                WHERE supplier_id = ? AND id = ?')->execute([
                $ctx['supplier_id'], $id, $ctx['supplier_id'], $id, $ctx['supplier_id'], $id,
            ]);
            $pdo->prepare('UPDATE purchase_invoices SET status = "paid",
                paid_at = NULLIF(GREATEST(
                    COALESCE((SELECT MAX(bt.posted_at) FROM payment_matches pm
                        JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                        WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?), "1000-01-01"),
                    COALESCE((SELECT MAX(cd.issue_date) FROM cash_documents cd
                        WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND cd.status = "posted"), "1000-01-01")
                ), "1000-01-01")
                WHERE supplier_id = ? AND id = ? AND status <> "draft"
                  AND paid_amount_invoice_ccy >= total_with_vat - 0.01')->execute([
                $ctx['supplier_id'], $id, $ctx['supplier_id'], $id, $ctx['supplier_id'], $id,
            ]);
        }
        foreach (array_unique([...array_values($ctx['new_statements']), ...array_values($ctx['touched_statements'])]) as $id) {
            $pdo->prepare('UPDATE bank_statements SET transaction_count =
                (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ?),
                credit_total = (SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE statement_id = ? AND amount > 0),
                debit_total = (SELECT COALESCE(SUM(-amount), 0) FROM bank_transactions WHERE statement_id = ? AND amount < 0),
                matched_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ? AND match_status <> "unmatched")
                WHERE id = ? AND supplier_id = ?')->execute([$id, $id, $id, $id, $id, $ctx['supplier_id']]);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function mapped(array $ctx, string $kind, string $key, string $hash, ?string $alternateHash = null): ?int
    {
        $row = $this->map->get($ctx['supplier_id'], $ctx['ico'], $ctx['company_index'], $kind, $key);
        if ($row === null) return null;
        if (!hash_equals($row['source_hash'], $hash)
            && ($alternateHash === null || !hash_equals($row['source_hash'], $alternateHash))) {
            throw new StereoNxException('source_changed', 'Zdrojový záznam se od předchozího převodu změnil; proveďte ruční kontrolu.');
        }
        $table = match ($kind) {
            'client' => 'clients', 'issued' => 'invoices', 'purchase' => 'purchase_invoices',
            'bank_account' => 'supplier_bank_accounts',
            'bank_statement' => 'bank_statements', 'bank' => 'bank_transactions', 'cash' => 'cash_documents',
            'classification_bank' => 'bank_transactions', 'classification_cash' => 'cash_documents',
            'cash_zero' => 'cash_registers',
            'payment_bank' => 'payment_matches', 'payment_cash' => 'cash_documents', default => null,
        };
        if ($table === null) return $row['target_id'];
        if ($kind === 'bank' || $kind === 'classification_bank') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE bt.id = ? AND bs.supplier_id = ?');
        } elseif ($kind === 'payment_bank') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payment_matches WHERE id = ? AND supplier_id = ?');
        } else {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
        }
        $stmt->execute([$row['target_id'], $ctx['supplier_id']]);
        if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_target_missing', 'Dříve převedený objekt v cílové firmě chybí.');
        return $row['target_id'];
    }

    /** @param array<string,mixed> $ctx */
    private function put(array $ctx, string $kind, string $key, string $hash, int $targetId): void
    {
        $this->map->put($ctx['supplier_id'], $ctx['ico'], $ctx['company_index'], $kind, $key, $hash, $targetId);
    }

    /** @param array<string,mixed> $record */
    private function key(array $record): string
    {
        $key = $record['source_key'] ?? null;
        if (!is_string($key) || $key === '') throw new StereoNxException('source_key_missing', 'Zdrojový záznam nemá složený klíč.');
        return $key;
    }

    /** @param array<string,mixed> $record */
    private function paymentKey(array $record): string
    {
        return json_encode([
            (string) ($record['movement_type'] ?? ''), (string) ($record['movement_key'] ?? ''),
            (string) ($record['document_kind'] ?? ''), (string) ($record['document_key'] ?? ''),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Zdrojové NX hlavičky obsahují mutující souhrny úhrad a čas změny. Novější
     * záloha smí přidat platbu ke stejnému dokladu a řádky ke stejnému výpisu;
     * hash porovnává jejich kanonický obsah, ne raw hlavičku programu.
     * @param array<string,mixed> $record
     */
    private static function sourceHash(array $record, bool $legacyEuReview = true): string
    {
        unset($record['row'], $record['header']);
        if ($legacyEuReview && isset($record['review_codes']) && is_array($record['review_codes'])) {
            // Pouhé zpřesnění textu „EU“ nesmí změnit identitu již převedeného
            // dokladu. Všechny ostatní zdrojové hodnoty zůstávají součástí otisku.
            $record['review_codes'] = array_map(
                static fn (mixed $code): mixed => $code === 'partner_country_eu_unspecified'
                    ? 'partner_country_unresolved' : $code,
                $record['review_codes'],
            );
        }
        return StereoNxImportMap::fingerprint($record);
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new StereoNxException('date_invalid', 'Zdrojový záznam má neplatné datum.');
        }
        return $value;
    }

    private function money(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 9999999999) {
            throw new StereoNxException('money_invalid', 'Zdrojový záznam má neplatnou částku.');
        }
        return round((float) $value, 2);
    }

    private function currencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' LIMIT 1");
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        if ($id === false) throw new StereoNxException('currency_missing', 'Cílová firma nemá měnu CZK.');
        return (int) $id;
    }

    private function countryId(string $code): int
    {
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) return 0;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function rateId(float $rate, string $date): ?int
    {
        // Historický NX doklad může předcházet valid_from cílového seed řádku.
        // Stejně jako Premier/Pohoda hledáme nejprve časově platnou sazbu a potom
        // stejnou nominální sazbu; rozhodující historické procento drží položka
        // ve vat_rate_snapshot, nikoli dnešní validita číselníku.
        $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE country = "CZ" AND rate_percent = ?
            AND is_reverse_charge = 0
            ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                     is_default DESC, id LIMIT 1');
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country_code:string,is_vat_payer:bool} */
    private function clientSnapshot(int $supplierId, int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT c.company_name, c.ic, c.dic, c.street, c.city, c.zip,
            c.is_vat_payer, co.iso2 AS country_code FROM clients c JOIN countries co ON co.id = c.country_id
            WHERE c.id = ? AND c.supplier_id = ?');
        $stmt->execute([$clientId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new StereoNxException('client_missing', 'Partner dokladu nepatří vybrané firmě.');
        return ['name' => (string) $row['company_name'], 'ico' => (string) ($row['ic'] ?? ''),
            'dic' => (string) ($row['dic'] ?? ''), 'street' => (string) $row['street'],
            'city' => (string) $row['city'], 'zip' => (string) $row['zip'],
            'country_code' => (string) $row['country_code'],
            'is_vat_payer' => (int) $row['is_vat_payer'] === 1];
    }

    private function cashRegister(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND currency_code = "CZK" ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $this->db->pdo()->prepare('INSERT INTO cash_registers (supplier_id, name, currency_code, is_active, is_default)
            VALUES (?, "Stereo NX", "CZK", 1, 1)')->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function movementDate(string $kind, int $id, int $supplierId): string
    {
        $stmt = $kind === 'bank'
            ? $this->db->pdo()->prepare('SELECT bt.posted_at FROM bank_transactions bt
                JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ? AND bs.supplier_id = ?')
            : $this->db->pdo()->prepare('SELECT issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $date = $stmt->fetchColumn();
        if ($date === false) throw new StereoNxException('movement_missing', 'Úhrada odkazuje na pohyb mimo vybranou firmu.');
        return (string) $date;
    }
}
