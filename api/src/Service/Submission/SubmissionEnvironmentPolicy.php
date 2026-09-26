<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Jediné místo, které rozhoduje, zda smí podání jít do TESTOVACÍHO prostředí úřadu.
 *
 * Testovací prostředí (ČSSZ VREP test, datovka-test, zkušební ISDS brána) je nástroj
 * vývoje. Mimo `app.env = development` se podává vždy do produkce: výběr prostředí
 * frontend nezobrazí ({@see testAllowed()} jde do `/api/auth/me`) a požadavek na test
 * odmítne {@see \MyInvoice\Middleware\SubmissionEnvironmentMiddleware} dřív, než
 * dojde k akci. Tiché přesměrování na produkci by bylo horší než odmítnutí, protože
 * volající by si myslel, že zkouší nanečisto.
 *
 * Výjimkou je zkušební podání na EPO (`test=1`): to úřad jen zkontroluje a nic
 * nepřijme, proto zůstává všem jako „Zkontrolovat na EPO" a pod tuhle politiku nespadá.
 */
final class SubmissionEnvironmentPolicy
{
    public const PRODUCTION = 'production';
    public const TEST = 'test';
    public const ERROR_CODE = 'submission_test_environment_disabled';

    public function __construct(private readonly Config $config) {}

    public function testAllowed(): bool
    {
        return $this->config->get('app.env') === 'development';
    }

    public function allows(string $environment): bool
    {
        return $environment !== self::TEST || $this->testAllowed();
    }

    public function defaultEnvironment(): string
    {
        return self::PRODUCTION;
    }

    public function rejectionMessage(): string
    {
        return 'Testovací prostředí úřadů je dostupné jen ve vývojové instalaci. Podání jde vždy do produkce.';
    }
}
