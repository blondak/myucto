<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\EldpStatementRepository;
use MyInvoice\Repository\Payroll\PayrollPensionRequestRepository;
use MyInvoice\Repository\Payroll\PayrollPersonPensionEvidenceRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use PDO;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Potvrzení o době důchodového pojištění v kalendářním roce (§ 42 zákona
 * č. 582/1991 Sb.) na žádost zaměstnance, bývalého zaměstnance nebo územní
 * správy sociálního zabezpečení.
 *
 * Doby skládá {@see EldpAnnualStatementBuilder::insurancePeriods()} ze
 * zmrazených schválených revizí (v roce přechodu i z převzatých měsíců) —
 * tentýž podklad i táž pravidla účasti jako evidenční list, žádný druhý
 * výpočet. Důchodové údaje osoby se berou ze zákonné evidence, stejně jako
 * u listu ({@see EldpStatementService}).
 */
final class PensionInsuranceCertificateService
{
    public const VERSION = 'mz-pension-insurance-certificate-2026-v1';

    private ?Environment $twig = null;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPensionRequestRepository $requests,
        private readonly EldpStatementRepository $statements,
        private readonly EldpAnnualStatementBuilder $builder,
        private readonly PayrollTakeoverReader $takeover,
        private readonly PayrollPersonPensionEvidenceRepository $pensions,
    ) {}

    /**
     * Údaje potvrzení bez tisku.
     *
     * @return array<string,mixed>
     */
    public function data(int $supplierId, int $employeeId, int $requestId): array
    {
        $request = $this->requests->findForEmployee($supplierId, $employeeId, $requestId);
        if ($request['request_kind'] !== 'insurance_period_confirmation') {
            throw new \InvalidArgumentException('Žádost se netýká potvrzení o době důchodového pojištění.');
        }
        $employmentId = (int) $request['employment_id'];
        $year = (int) $request['period_year'];
        $pension = $this->pensions->statusForEmployment(
            $supplierId,
            $employmentId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
        ) ?? array_fill_keys(PayrollPensionStatus::STATUS_KEYS, null);
        $periods = $this->builder->insurancePeriods(
            $supplierId,
            $employmentId,
            $year,
            $this->statements->revisionsForYear($supplierId, $year),
            $pension + ['foreign_insurance' => false],
            $this->takeover->forEmployment($supplierId, $employmentId, $year),
        );
        if ($periods['employee_id'] !== $employeeId) {
            throw new \InvalidArgumentException('Pracovní vztah žádosti nepatří zaměstnanci.');
        }

        return [
            'request' => [
                'id' => $requestId,
                'requester' => $request['requester'],
                'received_on' => $request['received_on'],
                'requester_reference' => $request['requester_reference'],
            ],
            'year' => $year,
            'employment_id' => $employmentId,
            ...$periods,
            'source_sha256' => hash('sha256', CanonicalJson::encode([
                'schema_reference' => 'payroll-pension-insurance-certificate.v1',
                'supplier_id' => $supplierId,
                'employment_id' => $employmentId,
                'year' => $year,
                'periods' => $periods['periods'],
                'source_revisions' => $periods['source_revisions'],
                'source_takeover_periods' => $periods['source_takeover_periods'],
            ])),
        ];
    }

    /** @return array{pdf:string,filename:string} */
    public function render(int $supplierId, int $employeeId, int $requestId): array
    {
        $data = $this->data($supplierId, $employeeId, $requestId);
        $template = [
            ...$data,
            'employer' => $this->employer($supplierId),
            'employee' => $this->employee($supplierId, $employeeId),
            'issued_on' => PayrollSubmissionCalendar::today(),
            'renderer_version' => self::VERSION,
        ];
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Potvrzení o době důchodového pojištění');
        $mpdf->SetSubject('Potvrzení podle § 42 zákona č. 582/1991 Sb.');
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollSourceSnapshotSHA256', (string) $data['source_sha256']);
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->WriteHTML($this->twig()->render('pension-insurance-certificate.twig', $template));
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException('mPDF nevytvořilo platné potvrzení o době důchodového pojištění.');
        }

        return [
            'pdf' => $pdf,
            'filename' => sprintf('potvrzeni-doba-duchodoveho-pojisteni-%d-%d.pdf', $data['year'], $requestId),
        ];
    }

    /** @return array{name:string,identification_number:string,address:string} */
    private function employer(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(display_name), ""), TRIM(company_name)) AS name,
                    TRIM(COALESCE(ic, "")) AS ic,
                    TRIM(CONCAT_WS(", ", NULLIF(TRIM(street), ""), NULLIF(TRIM(CONCAT_WS(" ", zip, city)), ""))) AS address
               FROM supplier WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Zaměstnavatel nenalezen.');
        }

        return [
            'name' => (string) $row['name'],
            'identification_number' => (string) $row['ic'],
            'address' => (string) $row['address'],
        ];
    }

    /** @return array{name:string,birth_date:?string} */
    private function employee(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT full_name, birth_date FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Zaměstnanec nenalezen.');
        }

        return [
            'name' => (string) $row['full_name'],
            'birth_date' => $row['birth_date'] === null ? null : (string) $row['birth_date'],
        ];
    }

    private function mpdf(): Mpdf
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 13,
            'margin_right' => 13,
            'margin_top' => 11,
            'margin_bottom' => 15,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                ['autoescape' => 'html', 'strict_variables' => true] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'cz_date',
                static fn (string $date): string => (new \DateTimeImmutable($date))->format('d.m.Y'),
            ));
        }

        return $this->twig;
    }
}
