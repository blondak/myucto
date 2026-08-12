<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep;
use MyInvoice\Service\Mail\Mailer;
use PDO;

final class AutomationDigestService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AutomationFeedService $feed,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly StaleSuggestionSweep $sweep,
    ) {}

    /** @return array{sent:int,skipped:int,resweep:array<string,mixed>,recipients:list<array<string,mixed>>} */
    public function run(\DateTimeImmutable $now, bool $dryRun = false, ?int $hourOverride = null): array
    {
        $hour = $hourOverride ?? (int) $now->format('G');
        if ($hour < 0 || $hour > 23) throw new \InvalidArgumentException('Hour must be 0..23.');

        // Přepočet zastaralých důvodů PŘED sečtením fronty. Souhrn je jediné místo, kde
        // se fronta pravidelně prochází celá, a zastaralé „předpis chybí" škodí právě
        // tady nejvíc: uživatel dostane mail o deseti čekajících položkách, přestože
        // osm z nich by se po přepočtu zaúčtovalo samo. Sweep nesmí shodit souhrn —
        // když selže, pošle se mail bez něj a chyba je vidět v reportu cronu.
        // Běží nad VŠEMI firmami s podvojným účetnictvím, ne jen nad adresáty téhle
        // hodiny: firma s vypnutým souhrnem má frontu úplně stejně.
        try {
            $resweep = $this->sweep->run($dryRun);
        } catch (\Throwable $e) {
            $resweep = ['error' => $e->getMessage()];
        }
        $perSupplier = is_array($resweep['per_supplier'] ?? null) ? $resweep['per_supplier'] : [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.id user_id,u.email,u.locale,s.id supplier_id,
                    COALESCE(NULLIF(s.display_name,''),s.company_name) supplier_name
               FROM accounting_supplier_settings aset
               JOIN supplier s ON s.id=aset.supplier_id AND s.accounting_mode='double_entry'
               JOIN user_suppliers us ON us.supplier_id=s.id
               JOIN users u ON u.id=us.user_id AND u.is_active=1 AND u.email IS NOT NULL AND u.email<>''
               JOIN roles base_role ON base_role.id=u.role_id
               JOIN roles effective_role ON effective_role.id=COALESCE(us.role_id,u.role_id)
               JOIN role_permissions rp ON rp.role_id=effective_role.id AND rp.permission_key='accounting' AND rp.access_level>=1
              WHERE aset.automation_digest_enabled=1 AND aset.automation_digest_hour=?
                AND effective_role.is_active=1 AND effective_role.role_type='staff'
                AND (us.role_id IS NULL OR effective_role.role_type=base_role.role_type)
              ORDER BY u.id,s.id"
        );
        $stmt->execute([$hour]);
        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) $row['user_id'];
            $users[$uid]['email'] = (string) $row['email'];
            $users[$uid]['locale'] = in_array($row['locale'], ['cs', 'en'], true) ? (string) $row['locale'] : 'cs';
            $users[$uid]['suppliers'][] = ['id' => (int) $row['supplier_id'], 'name' => (string) $row['supplier_name']];
        }
        $sent = 0; $skipped = 0; $recipients = [];
        $baseUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        foreach ($users as $uid => $user) {
            $companies = []; $totals = ['auto' => 0, 'pending' => 0, 'needs' => 0];
            foreach ($user['suppliers'] as $supplier) {
                $counts = $this->feed->counts($uid, false, $now->format('Y-m-d'), $now->format('Y-m-d'), [$supplier['id']]);
                $swept = $perSupplier[$supplier['id']] ?? ['posted' => 0, 'refreshed' => 0, 'queued' => 0];
                $row = [
                    'id' => $supplier['id'], 'name' => $supplier['name'],
                    'auto' => $counts['auto_today'], 'pending' => $counts['pending'], 'needs' => $counts['needs_input'],
                    'pending_link' => $baseUrl . '/automation?tab=pending&suppliers=' . $supplier['id'],
                    'needs_link' => $baseUrl . '/automation?tab=needs_input&suppliers=' . $supplier['id'],
                    // Jen vlastní čísla firmy — souhrn chodí uživateli, který může vidět
                    // jen část firem, takže globální součty sweepu do mailu nepatří.
                    'resweep_posted' => (int) ($swept['posted'] ?? 0),
                    'resweep_refreshed' => (int) ($swept['refreshed'] ?? 0) + (int) ($swept['queued'] ?? 0),
                ];
                $companies[] = $row;
                foreach ($totals as $key => $_) $totals[$key] += $row[$key];
            }
            if (array_sum($totals) === 0) { $skipped++; continue; }
            $locale = $user['locale'];
            $subject = $locale === 'en'
                ? sprintf('⚡ Automation: %d posted · %d to approve · %d need you', $totals['auto'], $totals['pending'], $totals['needs'])
                : sprintf('⚡ Automat: %d zaúčtováno · %d k potvrzení · %d potřebuje vás', $totals['auto'], $totals['pending'], $totals['needs']);
            $detail = ['email' => $user['email'], 'locale' => $locale, 'totals' => $totals, 'companies' => $companies];
            $recipients[] = $detail;
            if (!$dryRun) {
                $this->mailer->sendTemplate('automation_digest', $locale, [$user['email']], [
                    'totals' => $totals, 'companies' => $companies, 'automation_link' => $baseUrl . '/automation',
                ], $subject, userId: $uid);
                $sent++;
            }
        }
        return ['sent' => $sent, 'skipped' => $skipped, 'resweep' => $resweep, 'recipients' => $recipients];
    }
}
