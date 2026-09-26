<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzExternalSubmissionAction;
use MyInvoice\Action\Payroll\PayrollJmhzSubmissionFreezeAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Celý tok účetní u firmy, která přešla z jiného mzdového programu: za měsíc, za který
 * řádné hlášení podal předchozí program (v historii převzatých podání), se vlastní řádné
 * hlášení nezmrazí a obrazovka dostane kód, podle kterého nabídne odkaz na historii.
 * Neodeslané převzaté hlášení nic neblokuje. Když záznam neodpovídá, odebere se na
 * obrazovce (akce, ne databáze) a hlášení pak vznikne.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzExternalSubmissionFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Chybí tabulka payroll_external_jmhz_submissions (migrace 1901).');
        }
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testMonthSubmittedByPreviousProgramBlocksRegularSubmissionUntilRecordIsRemoved(): void
    {
        $preparationId = $this->preparedMonth();
        $store = $this->container->get(JmhzExternalSubmissionStore::class);
        self::assertInstanceOf(JmhzExternalSubmissionStore::class, $store);

        // Neodeslané hlášení předchozího programu (PAMICA ho jen připravila) nic neblokuje.
        $store->store($this->supplierId, 'test', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::external('MH:1', 'not_sent', null), [], $this->actors[0]);
        // Odeslané řádné hlášení za týž měsíc blokuje.
        $store->store($this->supplierId, 'test', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::external('MH:2', 'sent', '2026-08-10T09:15:00'), [], $this->actors[0]);

        $blocked = $this->freeze($preparationId);
        self::assertSame(409, $blocked['status'], CanonicalJson::encode($blocked['body']));
        self::assertSame('jmhz_period_submitted_externally', $blocked['body']['error']['code'] ?? null, CanonicalJson::encode($blocked['body']));
        $message = (string) ($blocked['body']['error']['message'] ?? '');
        self::assertStringContainsString('Za období 07/2026 už řádné měsíční hlášení podal předchozí mzdový program (PAMICA, odesláno 10. 8. 2026)', $message);
        self::assertStringContainsString('Mzdy → Podání → JMHZ, oddíl Podání předchozím programem', $message);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?', [$this->supplierId]),
            'Blokované řádné hlášení nezaloží podání.');

        // Obrazovka: přehled převzatých podání a odebrání záznamu, který neodpovídá.
        $list = $this->listExternal();
        self::assertSame(['sent', 'not_sent'], array_column($list, 'status'));
        $sent = array_values(array_filter($list, static fn (array $row): bool => $row['status'] === 'sent'))[0];
        self::assertSame(['period' => self::PERIOD, 'submission_type' => 'R', 'source' => 'pamica'],
            ['period' => $sent['period'], 'submission_type' => $sent['submission_type'], 'source' => $sent['source']]);
        $action = $this->container->get(PayrollJmhzExternalSubmissionAction::class);
        self::assertInstanceOf(PayrollJmhzExternalSubmissionAction::class, $action);
        $deleted = $action->delete(
            $this->request('DELETE', "/api/payroll/submissions/jmhz-external/{$sent['id']}")->withQueryParams(['environment' => 'test']),
            new Response(),
            ['id' => (string) $sent['id']],
        );
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertCount(1, $this->listExternal());

        $frozen = $this->freeze($preparationId);
        self::assertSame(201, $frozen['status'], 'Po odebrání záznamu hlášení vznikne. ' . CanonicalJson::encode($frozen['body']));
        self::assertGreaterThan(0, (int) ($frozen['body']['submission_id'] ?? 0));
    }

    /** Běh za měsíc a připravené hlášení, které projde XSD i katalogem kontrol. */
    private function preparedMonth(): int
    {
        $person = $this->createEmployment($this->officeId, 'Eva Převzatá', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Eva',
            'last_name' => 'Převzatá',
            'birth_date' => '1988-04-12',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1988-04-12', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);
        $approved = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $approved->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base-' . $person['employment_id'], self::PERIOD_START);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'external-flow');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved'], 'Zaseknutí: schválení běhu. ' . CanonicalJson::encode($run['warnings']));
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'external-flow');
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'] ?? null, 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));

        return (int) $preparation['body']['id'];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function freeze(int $preparationId): array
    {
        $action = $this->container->get(PayrollJmhzSubmissionFreezeAction::class);
        self::assertInstanceOf(PayrollJmhzSubmissionFreezeAction::class, $action);
        $response = $action(
            $this->request('POST', "/api/payroll/submissions/jmhz-freeze/{$preparationId}")
                ->withParsedBody(['environment' => 'test', 'office' => $this->officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /** @return list<array<string,mixed>> */
    private function listExternal(): array
    {
        $action = $this->container->get(PayrollJmhzExternalSubmissionAction::class);
        self::assertInstanceOf(PayrollJmhzExternalSubmissionAction::class, $action);
        $response = $action->list(
            $this->request('GET', '/api/payroll/submissions/jmhz-external')->withQueryParams(['environment' => 'test']),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['items'];
    }

    /** @return array<string,mixed> */
    private static function external(string $key, string $status, ?string $submittedAt): array
    {
        return [
            'source_key' => $key,
            'document_kind' => 'monthly',
            'period' => self::PERIOD,
            'submission_type' => 'R',
            'submission_guid' => null,
            'corrected_source_key' => null,
            'status' => $status,
            'filled_at' => '2026-08-10T09:00:00',
            'submitted_at' => $submittedAt,
            'accepted_at' => $submittedAt,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA'],
        ];
    }
}
