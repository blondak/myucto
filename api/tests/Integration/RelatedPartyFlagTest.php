<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 36a ZDPH / § 23 odst. 7 ZDP — příznak spojené osoby na kartě klienta.
 *
 * Bez něj je celá kontrola cen obvyklých mrtvá: spojení osob je právní vztah, který
 * z faktur ani z DIČ odvodit nelze, takže ho musí označit uživatel. Testy hlídají cestu
 * od API do DB, protože `ClientRepository::update()` má pevný seznam sloupců a nový
 * příznak se do něj musel doplnit ručně — tichý výpadek by znamenal, že uživatel klienta
 * označí, ono se to neuloží a kontrola dál mlčí.
 */
#[Group('integration')]
final class RelatedPartyFlagTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;
    private int $supplierId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('clients', 'related_party')) {
            $this->markTestSkipped('Migrace 1163 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Měna musí patřit IZOLOVANÉMU dodavateli — ClientRepository příslušnost ověřuje
        // a měnu cizí firmy odmítne. Trait měny nezakládá, takže se vytvoří tady.
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals)
             VALUES (?, "CZK", "CZK", "Kč", "koruna česká", "Czech koruna", 2)'
        )->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();
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

    /** Označení spojené osoby se uloží i s typem vztahu a doložením. */
    public function testFlagIsPersisted(): void
    {
        $id = $this->client();

        $this->clients->update($id, $this->payload([
            'related_party' => true,
            'related_party_type' => 'capital',
            'related_party_note' => 'podíl 60 %',
        ]));

        $row = $this->row($id);
        self::assertSame(1, (int) $row['related_party']);
        self::assertSame('capital', $row['related_party_type']);
        self::assertSame('podíl 60 %', $row['related_party_note']);
    }

    /**
     * Odznačení musí smazat i typ vztahu a doložení — jinak by u klienta zůstala viset
     * informace o vztahu, který už neplatí, a při kontrole by mátla.
     */
    public function testUnflaggingClearsTypeAndNote(): void
    {
        $id = $this->client();
        $this->clients->update($id, $this->payload([
            'related_party' => true,
            'related_party_type' => 'capital',
            'related_party_note' => 'podíl 60 %',
        ]));

        $this->clients->update($id, $this->payload(['related_party' => false]));

        $row = $this->row($id);
        self::assertSame(0, (int) $row['related_party']);
        self::assertNull($row['related_party_type']);
        self::assertNull($row['related_party_note']);
    }

    /**
     * ČÁSTEČNÝ update bez těchhle klíčů příznak SHODIT NESMÍ. `update()` má pevný seznam
     * sloupců, takže bez COALESCE by každé uložení klienta z jiné obrazovky spojenou
     * osobu tiše odznačilo.
     */
    public function testPartialUpdateKeepsTheFlag(): void
    {
        $id = $this->client();
        $this->clients->update($id, $this->payload([
            'related_party' => true,
            'related_party_type' => 'otherwise',
        ]));

        $this->clients->update($id, $this->payload(['company_name' => 'Přejmenovaný klient']));

        $row = $this->row($id);
        self::assertSame(1, (int) $row['related_party'], 'Příznak musí přežít update bez těchhle polí.');
        self::assertSame('otherwise', $row['related_party_type']);
    }

    /** Výchozí stav je „není spojená osoba" — dosavadní klienti se nemění. */
    public function testDefaultIsNotRelated(): void
    {
        self::assertSame(0, (int) $this->row($this->client())['related_party']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer)
             VALUES (?, "Klient", "Test 1", "Praha", "11000", ?, "k@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * `update()` očekává plnou sadu povinných polí — testy mění jen to podstatné.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'company_name' => 'Klient',
            'street' => 'Test 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country_iso2' => 'CZ',
            'currency_default_id' => $this->currencyId,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT related_party, related_party_type, related_party_note FROM clients WHERE id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
