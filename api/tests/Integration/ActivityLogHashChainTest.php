<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zřetězení auditní stopy hashem — § 33a ZoÚ.
 *
 * Matice účetnictví to vedla mezi vysokými riziky: „`activity_log` je běžná tabulka bez
 * hash-chainu. `JournalIntegrityService` detekuje nekonzistence deníku, ale přepsání
 * samotného logu nikoli."
 *
 * Testy dokazují to podstatné — že zásah je POZNAT. Zvlášť
 * {@see testDeletingRecordBreaksTheChain()}: smazání záznamu je typický způsob, jak
 * auditní stopu „vyčistit", a řetěz musí prasknout i tehdy, když po sobě zásah nenechá
 * žádnou jinou stopu.
 *
 * Co testy NEDOKAZUJÍ a ani dokázat nemohou: odolnost proti útočníkovi, který má právo
 * zápisu a přepočítá celý řetěz znovu. Proti tomu chrání až kotva mimo databázi.
 */
#[Group('integration')]
final class ActivityLogHashChainTest extends TestCase
{
    private Connection $db;
    private ActivityLogger $logger;
    private ActivityLogHashChain $chain;
    private bool $inTx = false;
    /** Id, od kterého se ověřuje — cizí záznamy v sadě jsou pro tenhle test šum. */
    private int $from = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->logger = $c->get(ActivityLogger::class);
            $this->chain = $c->get(ActivityLogHashChain::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->chain->isAvailable()) {
            $this->markTestSkipped('Migrace 1157 neproběhla.');
        }

        $this->db->pdo()->beginTransaction();
        $this->inTx = true;

        // Do `activity_log` zapisují i jiné testy sady, a to v transakcích, které se
        // rollbacknou — článek, na který mezitím navázal jiný, pak zmizí. Globální
        // ověření by proto hlásilo porušení tam, kde v reálném provozu žádné není.
        $this->from = 1 + (int) $this->db->pdo()->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Nový záznam dostane hash — bez toho by řetěz nevznikl. */
    public function testNewRecordIsSealed(): void
    {
        $id = $this->write('test.sealed');

        $row = $this->row($id);
        self::assertNotNull($row['hash']);
        self::assertSame(64, strlen((string) $row['hash']), 'SHA-256 v hex má 64 znaků.');
    }

    /** Druhý záznam navazuje na hash prvního. */
    public function testSecondRecordLinksToFirst(): void
    {
        $a = $this->write('test.a');
        $b = $this->write('test.b');

        self::assertSame($this->row($a)['hash'], $this->row($b)['prev_hash']);
    }

    /** Nedotčený řetěz projde ověřením. */
    public function testUntouchedChainVerifies(): void
    {
        $this->write('test.a');
        $this->write('test.b');
        $this->write('test.c');

        self::assertTrue($this->chain->verify($this->from)['ok']);
    }

    /** Změna OBSAHU záznamu se pozná — hash přestane sedět. */
    public function testTamperingWithPayloadIsDetected(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b', ['amount' => 1000]);
        $this->write('test.c');

        $this->db->pdo()->prepare('UPDATE activity_log SET payload = ? WHERE id = ?')
            ->execute(['{"amount":1}', $id]);

        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertContains($id, array_column($r['broken'], 'id'));
        self::assertStringContainsString('byl změněn', $r['broken'][0]['reason']);
    }

    /**
     * SMAZÁNÍ záznamu se pozná. Je to typický způsob, jak auditní stopu „vyčistit" —
     * a po smazaném řádku nezůstane žádná jiná stopa než přerušený řetěz.
     */
    public function testDeletingRecordBreaksTheChain(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b');
        $this->write('test.c');

        $this->db->pdo()->prepare('DELETE FROM activity_log WHERE id = ?')->execute([$id]);

        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('řetěz přerušen', $r['broken'][0]['reason']);
    }

    /** Změna metadat (kdo/odkud) se pozná stejně jako změna obsahu. */
    public function testChangingActorMetadataIsDetected(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b');

        // `user_id` má FK na `users`, takže se podvrhává `ip` — do hashe vstupuje stejně
        // a jde o typický údaj, který by chtěl někdo zahladit.
        $this->db->pdo()->prepare('UPDATE activity_log SET ip = INET6_ATON(?) WHERE id = ?')
            ->execute(['10.0.0.1', $id]);

        self::assertFalse($this->chain->verify($this->from)['ok']);
    }

    /**
     * Historie před zavedením řetězu se počítá zvlášť a NEVYDÁVÁ se za chráněnou.
     * Zpětný dopočet by nedokázal nic — hash spočtený dnes nad daty, která mohla být
     * kdykoli změněna, dodá jen zdání důvěryhodnosti.
     */
    public function testUnprotectedHistoryIsReportedSeparately(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO activity_log (supplier_id, user_id, action, hash) VALUES (NULL, NULL, 'test.legacy', NULL)"
        )->execute();
        $this->write('test.new');

        $r = $this->chain->verify($this->from);
        self::assertGreaterThan(0, $r['unprotected']);
        self::assertTrue($r['ok'], 'Chybějící hash u historie není porušení řetězu.');
    }

    /**
     * Hlava řetězu (migrace 1171) musí po zapečetění ukazovat na POSLEDNÍ článek.
     *
     * Hlava existuje proto, aby se zamykal jeden známý řádek místo skenování rozsahu —
     * ten bral gap locky a souběžné transakce se zaklesly (naměřeno: SQLSTATE 40001,
     * chyba 1213). Když se ale hlava rozejde s řetězem, další článek naváže na špatný
     * `prev_hash` a ověření nahlásí porušení, které nenastalo.
     */
    public function testChainHeadFollowsTheLastSealedRecord(): void
    {
        if (!$this->db->hasColumn('activity_log_chain_head', 'last_hash')) {
            self::markTestSkipped('Migrace 1171 neproběhla.');
        }

        $this->write('test.a');
        $last = $this->write('test.b');

        $head = $this->db->pdo()->query('SELECT last_id, last_hash FROM activity_log_chain_head WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertSame($last, (int) $head['last_id'], 'Hlava ukazuje na poslední článek.');
        self::assertSame($this->row($last)['hash'], $head['last_hash'], 'A nese jeho hash.');
        self::assertTrue($this->chain->verify($this->from)['ok']);
    }

    /**
     * Zapíše záznam a vrátí jeho id.
     *
     * `lastInsertId()` se použít NEDÁ: zapečetění uvnitř `log()` provede UPDATE, po kterém
     * už hodnota neodpovídá vloženému řádku. Id se proto dohledá podle akce.
     */
    private function write(string $action, ?array $payload = null): int
    {
        $this->logger->log($action, null, null, null, $payload);

        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM activity_log WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$action]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ověření nesmí záviset na zóně spojení.
     *
     * `created_at` je TIMESTAMP, takže ho MariaDB renderuje podle zóny session. Dokud
     * do hashe vstupovala ta renderovaná hodnota, stačil přechod na zimní čas (nebo
     * cron běžící v UTC) a `verify()` označil VŠECHNY letní záznamy za pozměněné —
     * auditní stopa tvrdila, že s ní někdo manipuloval. Test to simuluje tvrdě:
     * po zapečetění přepne zónu session a ověřuje znovu.
     */
    public function testVerificationSurvivesSessionTimeZoneChange(): void
    {
        $this->write('test.tz.a');
        $this->write('test.tz.b');

        $pdo = $this->db->pdo();
        $original = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();

        foreach (["+00:00", "+05:45", "-08:00"] as $zone) {
            $pdo->exec("SET time_zone = '{$zone}'");
            self::assertTrue(
                $this->chain->verify($this->from)['ok'],
                "Řetěz musí projít i v session zóně {$zone}.",
            );
        }

        $pdo->exec("SET time_zone = '{$original}'");
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT prev_hash, hash FROM activity_log WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
