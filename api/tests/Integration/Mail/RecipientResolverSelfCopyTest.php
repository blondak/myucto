<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Mail\RecipientResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kopie odchozích e-mailů dodavateli — supplier.self_copy (migrace 0102).
 *
 * Kontrakt:
 *   - self_copy[typ] = 'cc'|'bcc' → e-mail dodavatele v příslušném bucketu,
 *     'off' → nikdy; chybějící klíč/NULL → fallback na cfg flagy (legacy kind:
 *     documents/reminders=CC, approvals=BCC; approvals rozlišuje žádost vs.
 *     upomínku přes isApprovalReminder).
 *   - dedup: e-mail dodavatele už v TO si roli ponechá (to > cc > bcc).
 *   - resolve(..., supplierCopy: false) kopii nepřidá (payment thanks).
 *
 * Test dočasně přepisuje email+self_copy prvního supplier-a; tearDown vrací
 * původní hodnoty. Soft-skip bez cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class RecipientResolverSelfCopyTest extends TestCase
{
    private const SUP_EMAIL = 'dodavatel-selfcopy@example.cz';

    private Connection $db;
    private Config $config;
    private RecipientResolver $resolver;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private ?string $origEmail = null;
    private ?string $origSelfCopy = null;

    /** @var int[] */
    private array $clientIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->config = $container->get(Config::class);
            $this->resolver = $container->get(RecipientResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $row = $pdo->query('SELECT id, email, self_copy FROM supplier ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->supplierId = (int) ($row['id'] ?? 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->origEmail = (string) $row['email'];
        $this->origSelfCopy = $row['self_copy'] !== null ? (string) $row['self_copy'] : null;
        $pdo->prepare('UPDATE supplier SET email = ? WHERE id = ?')->execute([self::SUP_EMAIL, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM client_email_contacts WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        if ($this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET email = ?, self_copy = ? WHERE id = ?')
                ->execute([$this->origEmail, $this->origSelfCopy, $this->supplierId]);
        }
        $this->db->close();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function setSelfCopy(?array $sc): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET self_copy = ? WHERE id = ?')
            ->execute([$sc !== null ? json_encode($sc, JSON_UNESCAPED_UNICODE) : null, $this->supplierId]);
    }

    private function client(string $mainEmail = 'hlavni@example.cz'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'SC Test klient', 'Ulice 1', 'Praha', '11000', $this->czId, $mainEmail, $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> pseudo-invoice row pro resolver (VČETNĚ supplier_id) */
    private function invoice(int $clientId, string $mainEmail = 'hlavni@example.cz'): array
    {
        return [
            'client_id' => $clientId,
            'client_main_email' => $mainEmail,
            'project_id' => null,
            'supplier_id' => $this->supplierId,
        ];
    }

    // ── explicitní self_copy ─────────────────────────────────────────────

    public function testSelfCopyCcOnDocuments(): void
    {
        $this->setSelfCopy(['documents' => 'cc']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c));
        self::assertSame(['hlavni@example.cz'], $r['to']);
        self::assertSame([self::SUP_EMAIL], $r['cc']);
        self::assertSame([], $r['bcc']);
    }

    public function testSelfCopyBccOnReminders(): void
    {
        $this->setSelfCopy(['reminders' => 'bcc']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c));
        self::assertSame([], $r['cc']);
        self::assertSame([self::SUP_EMAIL], $r['bcc']);
    }

    public function testSelfCopyOffBeatsCfg(): void
    {
        // 'off' vypne kopii bez ohledu na cfg flagy (default approvals=true).
        $this->setSelfCopy(['documents' => 'off', 'reminders' => 'off', 'approvals' => 'off']);
        $c = $this->client();

        foreach ([RecipientResolver::TYPE_DOCUMENTS, RecipientResolver::TYPE_REMINDERS, RecipientResolver::TYPE_APPROVALS] as $type) {
            $r = $this->resolver->resolve($type, $this->invoice($c));
            self::assertNotContains(self::SUP_EMAIL, array_merge($r['to'], $r['cc'], $r['bcc']), "typ $type");
        }
    }

    public function testSelfCopyApprovalsAppliesToRequestAndReminder(): void
    {
        // Jeden klíč `approvals` platí pro žádost i schvalovací upomínku.
        $this->setSelfCopy(['approvals' => 'cc']);
        $c = $this->client();

        $request = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c));
        $reminder = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c), isApprovalReminder: true);
        self::assertSame([self::SUP_EMAIL], $request['cc']);
        self::assertSame([self::SUP_EMAIL], $reminder['cc']);
    }

    public function testMissingKeyFallsBackToCfg(): void
    {
        // self_copy definuje jen reminders → documents jede dle cfg flagu.
        $this->setSelfCopy(['reminders' => 'off']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c));
        $expected = (bool) $this->config->get('smtp.cc_supplier_on_send', false);
        self::assertSame($expected, in_array(self::SUP_EMAIL, $r['cc'], true),
            'Chybějící klíč documents → kopie přesně dle cfg smtp.cc_supplier_on_send');
        self::assertNotContains(self::SUP_EMAIL, $r['bcc'], 'Cfg fallback pro documents je CC, nikdy BCC');
    }

    public function testNullSelfCopyFallsBackToCfgForApprovals(): void
    {
        $this->setSelfCopy(null);
        $c = $this->client();

        $request = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c));
        $reminder = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c), isApprovalReminder: true);

        $expReq = (bool) $this->config->get('approval.cc_supplier_on_approval', true);
        $expRem = (bool) $this->config->get('approval.cc_supplier_on_approval_reminder', true);
        self::assertSame($expReq, in_array(self::SUP_EMAIL, $request['bcc'], true), 'žádost: cfg flag → BCC');
        self::assertSame($expRem, in_array(self::SUP_EMAIL, $reminder['bcc'], true), 'upomínka: vlastní cfg flag → BCC');
    }

    // ── dedup a provenance ───────────────────────────────────────────────

    public function testSupplierEmailAlreadyInToKeepsToRole(): void
    {
        // Klient má (kuriózně) e-mail dodavatele jako hlavní → priorita to > cc.
        $this->setSelfCopy(['documents' => 'cc']);
        $c = $this->client(self::SUP_EMAIL);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, self::SUP_EMAIL));
        self::assertSame([self::SUP_EMAIL], $r['to']);
        self::assertSame([], $r['cc'], 'Dedup: e-mail v TO se nepřidává podruhé do CC');
    }

    public function testResolvedProvenanceSupplier(): void
    {
        $this->setSelfCopy(['documents' => 'bcc']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c));
        $supplier = array_values(array_filter($r['resolved'], static fn (array $e) => $e['source'] === 'supplier'));
        self::assertCount(1, $supplier);
        self::assertSame('bcc', $supplier[0]['recipient']);
        self::assertSame(self::SUP_EMAIL, $supplier[0]['email']);
    }

    // ── opt-out (payment thanks) a přímé volání ──────────────────────────

    public function testSupplierCopyFalseSkipsCopy(): void
    {
        $this->setSelfCopy(['documents' => 'cc']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c), supplierCopy: false);
        self::assertNotContains(self::SUP_EMAIL, array_merge($r['to'], $r['cc'], $r['bcc']),
            'supplierCopy: false (payment thanks) kopii nepřidává');
    }

    public function testSupplierCopyDirectCall(): void
    {
        $this->setSelfCopy(['documents' => 'bcc']);

        $copy = $this->resolver->supplierCopy($this->supplierId, RecipientResolver::TYPE_DOCUMENTS);
        self::assertSame(['email' => self::SUP_EMAIL, 'recipient' => 'bcc'], $copy);

        self::assertNull($this->resolver->supplierCopy(0, RecipientResolver::TYPE_DOCUMENTS), 'supplier_id 0 → null');
    }

    public function testInvalidJsonFallsBackToCfg(): void
    {
        // Nevalidní hodnota v JSON (mimo off|cc|bcc) se ignoruje → cfg fallback.
        $this->setSelfCopy(['documents' => 'vsem']);
        $c = $this->client();

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c));
        $expected = (bool) $this->config->get('smtp.cc_supplier_on_send', false);
        self::assertSame($expected, in_array(self::SUP_EMAIL, $r['cc'], true));
    }
}
