<?php

declare(strict_types=1);

/**
 * Vyzvednutí nových zpráv z datové schránky.
 *
 * ⚠️ POZOR, TENHLE CRON MĚNÍ PRÁVNÍ STAV ⚠️
 * Vyzvednutí seznamu je přihlášení do schránky, a tím DORUČENÍ všech dodaných
 * zpráv podle § 17 odst. 3 zák. 300/2008 Sb. Od té chvíle běží lhůty.
 *
 * Proto se nespouští plošně: pracuje výhradně nad firmami, které si vybírání
 * schránky VÝSLOVNĚ zapnuly (`submission_channel_credentials.inbox_polling_enabled`).
 * Čerstvá instalace tedy nikomu nic nedoručí, i kdyby cron běžel od první minuty.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionInboxService;

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int) substr($arg, 8)));
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

// Preflight PŘED stavbou kontejneru — stejný důvod jako u cron-epo-status:
// u naprosté většiny instalací nemá tenhle cron co dělat (schránka není
// nastavená nebo je vybírání vypnuté) a stavět kvůli tomu celý DI kontejner
// by bylo drahé.
$lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
if (!CronPreflight::hasDataBoxInboxWork($lightPdo)) {
    $result = ['polled' => 0, 'stored' => 0, 'failed' => 0, 'skipped' => 'no_inbox_polling_enabled'];
    CronRun::start($lightPdo, 'cron-databox-inbox')->finish('ok', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$container = Bootstrap::buildContainer();

/** @var Connection $connection */
$connection = $container->get(Connection::class);
/** @var SubmissionInboxService $inbox */
$inbox = $container->get(SubmissionInboxService::class);
/** @var SubmissionCredentialService $credentials */
$credentials = $container->get(SubmissionCredentialService::class);

$run = CronRun::start($connection->pdo(), 'cron-databox-inbox');
try {
    $polled = 0;
    $stored = 0;
    $failed = 0;
    $unclassified = 0;

    foreach ($inbox->suppliersWithPollingEnabled('isds') as $credential) {
        $supplierId = (int) $credential['supplier_id'];
        $environment = (string) $credential['environment'];
        try {
            $context = $credentials->unlock($supplierId, $environment);
            $result = $inbox->poll($context, 'isds', null, $limit);
        } catch (SubmissionChannelException $e) {
            // Jedna nefunkční schránka nesmí zastavit ostatní firmy.
            $failed++;
            fwrite(STDERR, sprintf("supplier %d: %s\n", $supplierId, $e->errorCode));
            continue;
        }
        $polled++;
        $stored += $result['stored'];
        $failed += $result['failed'];
        $unclassified += $result['unclassified'];
    }

    $result = [
        'polled' => $polled,
        'stored' => $stored,
        'failed' => $failed,
        'unclassified' => $unclassified,
    ];
    // Selhání se NIKDY nehlásí jako úspěšný běh s nulou zpráv — právě tahle
    // záměna by tiše zastavila vyzvedávání výzev podle § 74 DŘ.
    $run->finish($failed > 0 ? 'error' : 'ok', $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed > 0 ? 2 : 0);
} catch (\Throwable $e) {
    $run->finish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
