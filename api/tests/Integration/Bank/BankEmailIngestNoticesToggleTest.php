<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vypínač načítání bankovních avíz u IMAP účtu (`ingest_notices`, migrace 1857).
 *
 * Nebezpečné selhání tady není „přepínač nefunguje", ale „přepínač se sám vypne":
 * účet, který avíza načítal, by po nasazení tiše přestal zakládat pohyby a nikdo by
 * si toho nevšiml, dokud by nechyběly platby. Proto se hlídá hlavně VÝCHOZÍ STAV
 * a to, že částečné uložení formuláře (bez toho klíče) hodnotu nepřepíše.
 */
#[Group('integration')]
final class BankEmailIngestNoticesToggleTest extends TestCase
{
    private Connection $db;
    private BankEmailNoticeRepository $repository;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->repository = $container->get(BankEmailNoticeRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->createdAccounts as $id) {
            $this->repository->deleteImapAccount($this->supplierId, $id);
        }
    }

    /** Formulář nového účtu nabídne avíza ZAPNUTÁ — je to hlavní účel téhle schránky. */
    public function testNewAccountFormDefaultsToNoticesEnabled(): void
    {
        $empty = $this->repository->imapSettings($this->supplierId);

        self::assertTrue((bool) $empty['ingest_notices'], 'Nový účet má mít avíza zapnutá.');
    }

    /** Uložení bez toho klíče zůstane zapnuté — jinak by účty po nasazení oněměly. */
    public function testAccountSavedWithoutTheKeyKeepsNoticesEnabled(): void
    {
        $saved = $this->save(['name' => 'Avíza výchozí']);

        self::assertTrue((bool) $saved['ingest_notices']);
    }

    public function testNoticesCanBeTurnedOffAndBackOn(): void
    {
        $saved = $this->save(['name' => 'Jen výpisy', 'ingest_notices' => false, 'ingest_pdf_statements' => true]);
        self::assertFalse((bool) $saved['ingest_notices'], 'Vypnutí se musí uložit.');
        self::assertTrue((bool) $saved['ingest_pdf_statements'], 'Výpisy z příloh zůstanou zapnuté.');

        $back = $this->save(['name' => 'Jen výpisy', 'ingest_notices' => true], (int) $saved['id']);
        self::assertTrue((bool) $back['ingest_notices']);
    }

    /**
     * JÁDRO: částečné uložení (klíč ve formuláři vůbec není) NESMÍ vypnutou hodnotu
     * zapnout ani zapnutou vypnout. Kdyby se chybějící klíč vyhodnotil jako `false`
     * (běžná chyba u checkboxů, které se neposílají, když nejsou zaškrtnuté), přišla
     * by firma o avíza při úplně nesouvisející změně, třeba přejmenování účtu.
     */
    public function testPartialSaveDoesNotFlipTheStoredValue(): void
    {
        $off = $this->save(['name' => 'Jen výpisy', 'ingest_notices' => false]);
        $id  = (int) $off['id'];

        $renamed = $this->save(['name' => 'Jen výpisy (přejmenováno)'], $id);

        self::assertFalse((bool) $renamed['ingest_notices'], 'Vypnuté avízo musí zůstat vypnuté.');

        $on = $this->save(['name' => 'Avíza zpět', 'ingest_notices' => true], $id);
        $renamedAgain = $this->save(['name' => 'Avíza zpět (přejmenováno)'], $id);

        self::assertTrue((bool) $on['ingest_notices']);
        self::assertTrue((bool) $renamedAgain['ingest_notices'], 'Zapnuté avízo musí zůstat zapnuté.');
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function save(array $body, ?int $id = null): array
    {
        $saved = $this->repository->saveImapAccount($this->supplierId, $body + [
            'host'       => 'imap.example.invalid',
            'port'       => 993,
            'encryption' => 'ssl',
            'username'   => 'test@example.invalid',
            'password'   => 'x',
            'folder'     => 'INBOX',
        ], $id);
        $newId = (int) $saved['id'];
        if ($newId > 0 && !in_array($newId, $this->createdAccounts, true)) {
            $this->createdAccounts[] = $newId;
        }

        return $saved;
    }
}
