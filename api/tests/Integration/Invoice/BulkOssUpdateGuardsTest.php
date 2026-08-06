<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkOssUpdateAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Hromadné nastavení OSS nad výběrem dokladů (OSS-7) — hlavně to, co ODMÍTNE.
 *
 * Akce přepisuje `invoice_items.oss_applicable`, tedy rozhodnutí, jestli řádek jde do
 * ČESKÉHO přiznání k DPH, nebo do OSS podání. Nad 1 670 doklady je to nejrychlejší
 * způsob, jak si rozejít účetnictví s tím, co je odevzdané na finanční správě — proto
 * tenhle soubor netestuje jen šťastnou cestu, ale u každé brány i to, že se doklad
 * NEZMĚNIL. Test, který by kontroloval jen návratový kód „přeskočeno", by svítil zeleně
 * i nad akcí, která doklad přepsala a jen o tom lhala.
 *
 * Data jsou syntetická, dodavatel se v tearDown vrací do původního stavu.
 */
#[Group('integration')]
final class BulkOssUpdateGuardsTest extends TestCase
{
    /** Daleká budoucnost, aby se fixtura nepotkala se skutečnými doklady ani podáními. */
    private const TAX_DATE = '2096-05-15';

    /** Popis, na který reaguje dočasný trigger simulující chybu uprostřed dávky. */
    private const POISON_DESCRIPTION = 'TEST OSS položka — simulovaná chyba (PHPUnit)';

    private const FAIL_TRIGGER = 'phpunit_oss_bulk_fail';

    private Connection $db;
    private BulkOssUpdateAction $action;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;

    /** @var array<string,mixed> původní OSS nastavení dodavatele */
    private array $supplierBackup = [];

    /** @var list<int> */
    private array $submissionIds = [];

    /** @var list<int> syntetické řádky číselníku sazeb členských států */
    private array $codebookRowIds = [];

    private bool $failTriggerInstalled = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(BulkOssUpdateAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Chybí migrace 1293.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / vat_rates).');
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        $this->backupAndEnableOss();
        $this->clientId = $this->createClient();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        if ($this->failTriggerInstalled) {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . self::FAIL_TRIGGER);
        }
        // Activity log se ZÁMĚRNĚ neuklízí: řádky drží hash chain
        // ({@see \MyInvoice\Tests\Integration\ActivityLogHashChainTest}) a mazání by ho
        // přetrhlo. Testovací záznamy nesou syntetické ID dokladu a nikomu nevadí.
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        foreach ($this->codebookRowIds as $id) {
            $pdo->prepare('DELETE FROM oss_member_state_rates WHERE id = ?')->execute([$id]);
        }
        foreach ($this->submissionIds as $id) {
            $pdo->prepare('DELETE FROM tax_submissions WHERE id = ?')->execute([$id]);
        }
        if ($this->supplierBackup !== []) {
            $pdo->prepare(
                'UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ?,
                        oss_identification_country = ?, country_id = ? WHERE id = ?'
            )->execute([
                $this->supplierBackup['oss_enabled'],
                $this->supplierBackup['oss_valid_from'],
                $this->supplierBackup['oss_valid_to'],
                $this->supplierBackup['oss_identification_country'],
                $this->supplierBackup['country_id'],
                $this->supplierId,
            ]);
        }

        $this->db->close();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Šťastná cesta
    // ─────────────────────────────────────────────────────────────────────────

    public function testApplySetsOssParametersAndClearsTheReviewFlag(): void
    {
        $id = $this->invoice(needsReview: true);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable'       => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type'        => 'standard',
            'oss_supply_type'      => 'goods',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('update', $result['body']['documents'][0]['action']);

        $item = $this->item($id);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertSame('standard', (string) $item['oss_rate_type']);
        self::assertSame('goods', (string) $item['oss_supply_type']);
        self::assertSame(0, (int) $item['oss_needs_manual_review'],
            'Potvrzení místa plnění je rozhodnutí člověka a tahle akce je místo, kde padne.');
    }

    /** Náhled je povinný právě proto, že sám nic nemění. */
    public function testPreviewChangesNothing(): void
    {
        $id = $this->invoice(needsReview: true);

        $result = $this->call(apply: false, ids: [$id], set: [
            'oss_applicable'       => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type'        => 'standard',
        ]);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['applied']);
        self::assertSame('update', $result['body']['documents'][0]['action']);
        self::assertSame(1, $result['body']['summary']['documents_to_change']);

        $item = $this->item($id);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertSame(1, (int) $item['oss_needs_manual_review']);
    }

    public function testApplyWithoutConfirmationIsRefused(): void
    {
        $id = $this->invoice(needsReview: true);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_consumer_country' => 'PL'], confirm: false);

        self::assertSame(428, $result['status']);
        self::assertSame('preview_required', $result['body']['error']['code']);
        self::assertSame(1, (int) $this->item($id)['oss_needs_manual_review']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brány — u každé se ověřuje i to, že doklad zůstal netknutý
    // ─────────────────────────────────────────────────────────────────────────

    public function testBookedInvoiceIsSkippedAndUntouched(): void
    {
        $id = $this->invoice(needsReview: true);
        $this->db->pdo()->prepare('UPDATE invoices SET booked_at = ? WHERE id = ?')
            ->execute([self::TAX_DATE . ' 10:00:00', $id]);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        $doc = $result['body']['documents'][0];
        self::assertSame('skip', $doc['action']);
        self::assertSame('locked', $doc['skip_reason']);
        self::assertStringContainsString('zaúčtováno', $doc['skip_detail']);
        $this->assertUntouched($id);
    }

    public function testCancelledInvoiceIsSkippedAndUntouched(): void
    {
        $id = $this->invoice(needsReview: true, status: 'cancelled');

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        self::assertSame('cancelled', $result['body']['documents'][0]['skip_reason']);
        $this->assertUntouched($id);
    }

    /**
     * Podané období je tvrdá hranice: co je odevzdané na finanční správě, se neopravuje
     * přepsáním dokladu, ale opravným/dodatečným tvrzením.
     */
    public function testAlreadyFiledPeriodIsSkippedAndUntouched(): void
    {
        $id = $this->invoice(needsReview: true);
        $this->fileVatReturn(2096, 5);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        $doc = $result['body']['documents'][0];
        self::assertSame('period_filed', $doc['skip_reason']);
        self::assertStringContainsString('5/2096', $doc['skip_detail']);
        $this->assertUntouched($id);
    }

    /** Pouhé VYGENEROVÁNÍ XML podáním není — jinak by šablona blokovala kdejaký náhled. */
    public function testMerelyGeneratedReturnDoesNotBlock(): void
    {
        $id = $this->invoice(needsReview: true);
        $this->fileVatReturn(2096, 5, status: 'generated');

        $result = $this->call(apply: false, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        self::assertSame('update', $result['body']['documents'][0]['action']);
    }

    /** Cizí doklad se nesmí ani ukázat, natož změnit. */
    public function testUnknownInvoiceIsReportedAsNotFound(): void
    {
        $result = $this->call(apply: true, ids: [999000111], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        self::assertSame('not_found', $result['body']['documents'][0]['skip_reason']);
    }

    /**
     * OSS řádek bez země spotřeby exportér do podání nepustí. Zapsat půlku dokladu a
     * tvářit se, že je hotový, je horší než ho přeskočit a nahlásit.
     */
    public function testTurningOssOnWithoutConsumerCountryIsSkipped(): void
    {
        $id = $this->invoice(needsReview: true);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_applicable' => true]);

        self::assertSame('missing_consumer_country', $result['body']['documents'][0]['skip_reason']);
        $this->assertUntouched($id);
    }

    /** Vypnutý OSS u firmy = není kam plnění vykázat; odmítá se celá dávka, ne jednotlivě. */
    public function testTurningOssOnWithoutSupplierRegistrationIsRefused(): void
    {
        $id = $this->invoice(needsReview: true);
        $this->db->pdo()->prepare('UPDATE supplier SET oss_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('oss_disabled', $result['body']['error']['code']);
        $this->assertUntouched($id);
    }

    /** Země spotřeby shodná se státem identifikace není OSS, ale tuzemské plnění. */
    public function testConsumerCountryEqualToIdentificationCountryIsRefused(): void
    {
        $result = $this->call(apply: false, ids: [$this->invoice(needsReview: true)], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'CZ',
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('validation_failed', $result['body']['error']['code']);
    }

    /** Výběr „k ručnímu posouzení" nesmí sáhnout na řádek, který k posouzení není. */
    public function testScopeNeedsReviewLeavesOtherItemsAlone(): void
    {
        $id = $this->invoice(needsReview: false);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL',
        ]);

        self::assertSame('no_matching_items', $result['body']['documents'][0]['skip_reason']);
        $item = $this->item($id);
        self::assertSame(0, (int) $item['oss_applicable'], 'Řádek mimo výběr se nesmí změnit.');
        self::assertNull($item['oss_consumer_country']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vypnutí OSS — zrcadlo ke kontrole „zapnout nelze bez země spotřeby"
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Jádro věci: zhasnutím `oss_applicable` na řádku s POLSKOU sazbou by se cizí daň
     * přesunula na ř. 1 českého přiznání. Číselník členských států tvrdí, že 23 % v zemi
     * dodavatele (CZ) k datu plnění neplatí → doklad se nezmění.
     */
    public function testTurningOssOffIsRefusedWhenTheCodebookSaysTheRateIsNotDomestic(): void
    {
        // Sazba, kterou v zemi dodavatele nevede žádný seed ani jeho historie — test tak
        // netvrdí nic o tom, jak vypadá číselník na konkrétní instalaci.
        $this->seedDomesticRate(21.0);
        $id = $this->invoice(needsReview: false, ossOn: true, rate: 17.5);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_applicable' => false], scope: 'oss');

        $doc = $result['body']['documents'][0];
        self::assertSame('skip', $doc['action'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('unverified_domestic_rate', $doc['skip_reason']);
        self::assertStringContainsString('17,5', $doc['skip_detail']);
        self::assertStringContainsString('neplatí', $doc['skip_detail']);
        self::assertStringContainsString('doplňte zemi spotřeby', $doc['skip_detail'],
            'Hláška musí říct, co s tím — jinak uživatel jen ví, že to nejde.');

        $item = $this->item($id);
        self::assertSame(1, (int) $item['oss_applicable'], 'Cizí sazba se nesmí stát tuzemskou.');
        self::assertSame('PL', (string) $item['oss_consumer_country']);
    }

    /**
     * „Nevím" není „ne, sazba tuzemská není" — ale ani „ano". Číselník zemi dodavatele
     * k datu plnění nevede, takže se nedá položit ani otázka a vypnutí se neprovede.
     * Zablokovat je bezpečný směr: nic se tím nikam nepřesune.
     *
     * Nevědomost se vyrábí dodavatelem sídlícím MIMO EU: číselník vede jen členské státy,
     * takže je to jediné deterministické „nedá se zeptat" nezávislé na tom, co má daná
     * instalace naseedované.
     */
    public function testTurningOssOffIsRefusedWhenTheCodebookCannotAnswer(): void
    {
        $this->moveSupplierOutsideTheCodebook();
        $id = $this->invoice(needsReview: false, ossOn: true, rate: 21.0);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_applicable' => false], scope: 'oss');

        $doc = $result['body']['documents'][0];
        self::assertSame('unverified_domestic_rate', $doc['skip_reason'],
            json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('nevede', $doc['skip_detail']);
        self::assertSame(1, (int) $this->item($id)['oss_applicable']);
    }

    /** Pozitivní potvrzení číselníku je JEDINÁ cesta ven z OSS — a musí fungovat. */
    public function testTurningOssOffIsAllowedWhenTheCodebookConfirmsTheDomesticRate(): void
    {
        $this->seedDomesticRate(21.0);
        $id = $this->invoice(needsReview: false, ossOn: true, rate: 21.0);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_applicable' => false], scope: 'oss');

        self::assertSame('update', $result['body']['documents'][0]['action'],
            json_encode($result['body']['documents'][0], JSON_UNESCAPED_UNICODE));

        $item = $this->item($id);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertNull($item['oss_consumer_country'], 'Doprovodná pole nesmí zůstat viset.');
    }

    /**
     * Nulová sazba je z invariantu vyňatá stejně jako u deriveru: osvobození, přenesená
     * daňová povinnost i vývoz se vykazují BEZ DANĚ, takže není co unikat — a číselník
     * nulové sazby vůbec nevede, takže by vynucení odmítlo každé osvobozené plnění.
     */
    public function testZeroRatedItemMayLeaveOssWithoutCodebookConfirmation(): void
    {
        $id = $this->invoice(needsReview: false, ossOn: true, rate: 0.0);

        $result = $this->call(apply: true, ids: [$id], set: ['oss_applicable' => false], scope: 'oss');

        self::assertSame('update', $result['body']['documents'][0]['action']);
        self::assertSame(0, (int) $this->item($id)['oss_applicable']);
    }

    /**
     * Řádek, který mimo OSS byl UŽ PŘEDTÍM, akce nikam nepřesouvá — zhasnout na něm „nevím"
     * nových kanálů je hlavní důvod, proč výběr `needs_review` existuje, takže se nezakazuje.
     * Nepotvrzená sazba se ale musí objevit v náhledu, ne mlčky projít.
     */
    public function testAlreadyDomesticItemIsOnlyWarnedAboutAnUnconfirmedRate(): void
    {
        $id = $this->invoice(needsReview: true, rate: 17.5);

        $result = $this->call(apply: true, ids: [$id], set: ['clear_needs_review' => true]);

        $doc = $result['body']['documents'][0];
        self::assertSame('update', $doc['action'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('17,5', implode(' ', $doc['warnings']));
        self::assertSame(0, (int) $this->item($id)['oss_needs_manual_review']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Příznak k ručnímu posouzení, cache PDF, selhání dávky
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `clear_needs_review` nesmí zhasnout příznak, který drží rozpor CELÉHO dokladu —
     * ten hromadná akce nepřepočítává z payloadu, ale ze stavu po změně. Uživatel by jinak
     * odklikl varování, které pořád platí (a rozpor „doklad leží ve dvou přiznáních" by
     * po zavření hlášky nezůstal nikde vidět).
     */
    public function testContradictionKeepsTheReviewFlagLitOnBothSides(): void
    {
        $id = $this->invoice(needsReview: true);
        $domesticItemId = $this->addItem($id, needsReview: false, ossOn: false, rate: 21.0);

        $result = $this->call(apply: true, ids: [$id], set: [
            'oss_applicable'       => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type'        => 'standard',
            'oss_supply_type'      => 'goods',
        ]);

        self::assertSame('update', $result['body']['documents'][0]['action']);
        self::assertNotSame([], $result['body']['documents'][0]['warnings'],
            'Rozpor dokladu se musí objevit už v náhledu, ne až v datech.');

        $rows = $this->items($id);
        self::assertSame(1, (int) $rows[0]['oss_applicable']);
        self::assertSame(1, (int) $rows[0]['oss_needs_manual_review'],
            'Příznak z kontroly soudržnosti zhasl, i když rozpor pořád platí.');
        self::assertSame(1, (int) $this->itemById($domesticItemId)['oss_needs_manual_review'],
            'Označit se má i tuzemská strana rozporu — právě tu má člověk prověřit.');
    }

    /** Bez invalidace by se vytištěné PDF rozešlo s doložkou i s podáním. */
    public function testCachedPdfIsInvalidated(): void
    {
        $id = $this->invoice(needsReview: true);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET pdf_path = 'phpunit-oss-bulk-neexistuje.pdf',
                    pdf_generated_at = '2096-05-16 08:00:00' WHERE id = ?"
        )->execute([$id]);

        $this->call(apply: true, ids: [$id], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL', 'oss_rate_type' => 'standard',
        ]);

        $stmt = $this->db->pdo()->prepare('SELECT pdf_path, pdf_generated_at FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['pdf_path'], 'Cache vytištěného dokladu zůstala platná.');
        self::assertNull($row['pdf_generated_at']);
    }

    /**
     * Holá 500 je u 200 dokladů k nepoužití: uživatel nezjistí, které doklady už změněné
     * jsou. Odpověď proto nese hotové, nezpracované i ten jeden, na kterém to skončilo —
     * a v historii toho dokladu zůstane záznam i tehdy, když odpověď zahodí.
     */
    public function testFailureMidBatchReportsWhatWasWritten(): void
    {
        $done    = $this->invoice(needsReview: true);
        $failing = $this->invoice(needsReview: true, description: self::POISON_DESCRIPTION);
        $pending = $this->invoice(needsReview: true);
        $this->installFailingTrigger();

        $result = $this->call(apply: true, ids: [$done, $failing, $pending], set: [
            'oss_applicable' => true, 'oss_consumer_country' => 'PL', 'oss_rate_type' => 'standard',
        ]);

        self::assertSame(500, $result['status']);
        $error = $result['body']['error'];
        self::assertSame('bulk_update_failed', $error['code']);
        self::assertSame([$done], $error['completed_invoice_ids']);
        self::assertSame($failing, $error['failed_invoice']['invoice_id']);
        self::assertSame([$pending], $error['not_attempted_invoice_ids'],
            'Po první chybě se další doklady nesmí zkoušet — a musí být vidět, že se nezkusily.');

        self::assertSame(1, (int) $this->item($done)['oss_applicable'], 'Hotový doklad se měl zapsat.');
        $this->assertUntouched($failing);
        $this->assertUntouched($pending);

        self::assertTrue($this->hasActivityLog('invoice.oss_bulk_failed', $failing),
            'Bez záznamu u dokladu není kde dohledat, kde se dávka zastavila.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pomocné
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $set
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(bool $apply, array $ids, array $set, bool $confirm = true, string $scope = 'needs_review'): array
    {
        $body = ['invoice_ids' => $ids, 'scope' => $scope, 'set' => $set];
        if ($apply) {
            $body['confirm'] = $confirm;
        }
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/bulk-oss')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        $response = $apply
            ? $this->action->apply($request, new Psr7Response())
            : $this->action->preview($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function invoice(
        bool $needsReview,
        string $status = 'issued',
        bool $ossOn = false,
        float $rate = 23.0,
        string $description = 'TEST OSS položka (PHPUnit)',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date,
                                   due_date, currency_id, status, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, ?, ?, 1230, ?)'
        )->execute([
            $this->supplierId, $this->clientId, null, self::TAX_DATE, self::TAX_DATE,
            self::TAX_DATE, $this->currencyId, $status, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $this->addItem($invoiceId, $needsReview, $ossOn, $rate, $description);

        return $invoiceId;
    }

    /** Další řádek téhož dokladu — kvůli rozporu je potřeba doklad se dvěma stranami. */
    private function addItem(
        int $invoiceId,
        bool $needsReview,
        bool $ossOn,
        float $rate,
        string $description = 'TEST OSS položka (PHPUnit)',
    ): int {
        $pdo = $this->db->pdo();
        $order = (int) $pdo->query(
            'SELECT COALESCE(MAX(order_index) + 1, 0) FROM invoice_items WHERE invoice_id = ' . $invoiceId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
                                        vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat,
                                        total_with_vat, order_index, oss_applicable, oss_consumer_country,
                                        oss_rate_type, oss_supply_type, oss_needs_manual_review)
             VALUES (?, ?, 1, "ks", 1000, ?, ?, 1000, 0, 1000, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $invoiceId, $description, $this->vatRateId, $rate, $order,
            $ossOn ? 1 : 0,
            $ossOn ? 'PL' : null,
            $ossOn ? 'standard' : null,
            $ossOn ? 'goods' : null,
            $needsReview ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function item(int $invoiceId): array
    {
        return $this->items($invoiceId)[0];
    }

    /** @return list<array<string,mixed>> */
    private function items(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type,
                    oss_needs_manual_review
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotSame([], $rows, 'Doklad nemá položku — pak netvrdí nic ani zbytek testu.');

        return $rows;
    }

    /** @return array<string,mixed> */
    private function itemById(int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_applicable, oss_needs_manual_review FROM invoice_items WHERE id = ?'
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);

        return $row;
    }

    /**
     * Syntetická sazba v zemi dodavatele, platná k datu fixtury. Seedovaný číselník sahá
     * jen do současnosti, takže v roce 2096 by na cokoli odpověděl „nevím" a nešlo by
     * odlišit „sazba neplatí" od „nedá se zeptat".
     */
    private function seedDomesticRate(float $percent): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to,
                                                 is_custom, note)
             VALUES ('CZ', 'standard', ?, '2096-01-01', NULL, 1, 'PHPUnit fixture')"
        )->execute([$percent]);
        $this->codebookRowIds[] = (int) $pdo->lastInsertId();
    }

    /** Dodavatel do třetí země — číselník členských států o ní nemůže vědět nic. */
    private function moveSupplierOutsideTheCodebook(): void
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = 'CH' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CH není v číselníku zemí.');
        }
        $pdo->prepare('UPDATE supplier SET country_id = ? WHERE id = ?')->execute([$countryId, $this->supplierId]);
    }

    /**
     * Dočasný trigger, který shodí UPDATE právě jedné syntetické položky. Jiná cesta, jak
     * deterministicky vyrobit chybu UPROSTŘED dávky, není — a bez ní se nedá otestovat, co
     * odpověď o rozpracované dávce tvrdí.
     */
    private function installFailingTrigger(): void
    {
        try {
            $this->db->pdo()->exec(
                'CREATE TRIGGER ' . self::FAIL_TRIGGER . ' BEFORE UPDATE ON invoice_items
                 FOR EACH ROW BEGIN
                   IF NEW.description = ' . $this->db->pdo()->quote(self::POISON_DESCRIPTION) . ' THEN
                     SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "PHPUnit: simulovana chyba davky";
                   END IF;
                 END'
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Nelze založit trigger (chybí oprávnění?): ' . $e->getMessage());
        }
        $this->failTriggerInstalled = true;
    }

    private function hasActivityLog(string $action, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE action = ? AND entity_type = "invoice" AND entity_id = ?'
        );
        $stmt->execute([$action, $invoiceId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function assertUntouched(int $invoiceId): void
    {
        $item = $this->item($invoiceId);
        self::assertSame(0, (int) $item['oss_applicable'], 'Přeskočený doklad se nesmí změnit.');
        self::assertNull($item['oss_consumer_country']);
        self::assertSame(1, (int) $item['oss_needs_manual_review'],
            'Přeskočenému dokladu nesmí zhasnout ani příznak k posouzení.');
    }

    private function fileVatReturn(int $year, int $month, string $status = 'submitted'): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, 'dphdp3', ?, ?, NULL, 'B', '<test/>', 7, ?, 'passed', ?, ?, '{}', ?)"
        )->execute([
            $this->supplierId, $year, $month, hash('sha256', 'test'), $status,
            $status === 'submitted' ? '2096-06-20 09:00:00' : null,
            $this->userId,
        ]);
        $this->submissionIds[] = (int) $pdo->lastInsertId();
    }

    private function backupAndEnableOss(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country, country_id
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $this->supplierBackup = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdo->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_valid_from = '2000-01-01', oss_valid_to = NULL,
                    oss_identification_country = 'CZ' WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    private function createClient(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'PL' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát PL není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testowy Odbiorca (PHPUnit)", "Ulica 1", "Warszawa", "00-001", ?,
                     "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
