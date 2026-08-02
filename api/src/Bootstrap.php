<?php

declare(strict_types=1);

namespace MyInvoice;

use DI\ContainerBuilder;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Clock\UtcClock;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiRequestLogMiddleware;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\IpAllowlistMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\RequireMfaMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\WebAuthnBodyLimitMiddleware;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\WebAuthnConfigProvider;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Bootstrap
{
    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Postaví JEN DI kontejner — bez rout a bez middleware.
     *
     * Tohle je vstupní bod pro CLI (api/bin/cron-*.php, workery, backfilly).
     * `buildApp()` registruje 586 rout a instancuje 14 middleware, což je pro
     * skript spouštěný z cronu čistá režie: rozdíl je ~220 vs ~100 načtených
     * souborů na jeden běh. Při frekvenci „každou minutu" × počet tenantů to
     * přestává být kosmetika.
     *
     * Definice služeb jsou tady, ne v buildApp(), aby obě cesty (web i CLI)
     * dostaly bit po bitu stejný kontejner — jinak by se konfigurace časem
     * rozešla a chyba by se projevila jen v jedné z nich.
     */
    public static function buildContainer(): ContainerInterface
    {
        $rootDir = self::rootDir();
        $config  = Config::load($rootDir);

        // Bezpečnostní guard: v produkci pepper musí být nastavený (jinak hesla nemají druhotnou ochranu)
        $env    = (string) $config->get('app.env', 'production');
        $pepper = (string) $config->get('app.pepper', '');
        if ($env === 'production' && $pepper === '') {
            throw new \RuntimeException('cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

        // PHP error log → log/php-errors.log (jinak by warnings/notices padaly do
        // system php_errors.log, který je mimo repo). Display_errors v dev=on, prod=off.
        // Pokud je nastaven MYINVOICE_DATA_DIR, ukládáme i tento log do data_dir
        // (drží všechen state pod jediným perzistentním volume).
        $logDir = ($config->dataDir() ?? $rootDir) . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-errors.log');
        // NIKDY display_errors=on pro API endpoints — JSON response by byla kontaminována
        // deprecation/notice warningy (typicky vendor 3rd-party kód). Logujeme do souboru.
        // Dev env: warnings se objeví v log/php-errors.log + log/app-YYYY-MM-DD.log.
        ini_set('display_errors', '0');
        // Reporting: E_ALL minus E_DEPRECATED (PHP 8.5 deprecates older patterns ve vendoru,
        // které nemůžeme fixnout — nechceme je v error log spamovat).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,
            // Aplikační hodiny zůstávají v `app.timezone` (Europe/Prague) — účetní kód
            // (odpisy, období) porovnává kalendářní datum, a UTC by ho mezi půlnocí
            // a 2:00 posunul o den zpět. Autentizace na tom nestojí: bezpečnostní časy
            // bere SecurityClock z databáze a zbytek se normalizuje na UTC explicitně.
            ClockInterface::class => fn () => new \Symfony\Component\Clock\NativeClock(),
            SecurityClock::class => fn () => new DatabaseSecurityClock(),

            LoggerInterface::class => function (ContainerInterface $c) use ($config): LoggerInterface {
                $logger = new Logger('myinvoice');
                $path   = (string) $config->get('logging.path');
                $level  = self::resolveLogLevel((string) $config->get('logging.level', 'info'));
                $maxFiles = (int) $config->get('logging.max_files', 90);
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $logger->pushHandler(new RotatingFileHandler($path, $maxFiles, $level));
                return $logger;
            },

            ResponseFactory::class => fn () => new ResponseFactory(),
            Connection::class      => fn (ContainerInterface $c) => new Connection($c->get(Config::class), $c->get(LoggerInterface::class)),
            \MyInvoice\Service\Epo\EpoDirectResponseParser::class => function () use ($config, $rootDir): \MyInvoice\Service\Epo\EpoDirectResponseParser {
                $caBundle = trim((string) $config->get('epo.ca_bundle_path', ''));
                if (
                    $caBundle !== ''
                    && !preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $caBundle)
                ) {
                    $caBundle = $rootDir . DIRECTORY_SEPARATOR . $caBundle;
                }
                $fingerprints = $config->get('epo.receipt_signer_fingerprints_sha256', []);
                if (is_string($fingerprints)) {
                    $fingerprints = array_filter(array_map('trim', explode(',', $fingerprints)));
                }
                $testFingerprints = $config->get('epo.test_receipt_signer_fingerprints_sha256', []);
                if (is_string($testFingerprints)) {
                    $testFingerprints = array_filter(array_map('trim', explode(',', $testFingerprints)));
                }
                return new \MyInvoice\Service\Epo\EpoDirectResponseParser(
                    $caBundle !== '' ? $caBundle : null,
                    is_array($fingerprints) ? array_values($fingerprints) : [],
                    is_array($testFingerprints) ? array_values($testFingerprints) : [],
                );
            },
            // DPPO podklady: ClosingService (4. arg) je konstrukčně volitelný (unit testy nad SQLite
            // ho nepředávají), ale PHP-DI autowire optional class-param nevyplní → explicitní bind,
            // aby náhled DPPO měl projekci závěrkových operací (Feature 1) i v produkci.
            \MyInvoice\Service\Tax\Return\DppoReturnDataProvider::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Tax\Return\DppoReturnDataProvider(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\AccountingPeriodRepository::class),
                $c->get(\MyInvoice\Service\Tax\Return\NonDeductibleCostsService::class),
                $c->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class),
                // § 23/3/a/12 — rovněž volitelná, takže bez tohohle bindu by návrh
                // připočtení neuhrazených dluhů v produkci tiše nevznikl.
                $c->get(\MyInvoice\Service\Tax\Return\UnpaidLiabilityService::class),
                // § 23/7 — ze stejného důvodu: volitelný parametr PHP-DI neautowiruje,
                // takže bez tohohle bindu by podklad ke spojeným osobám zůstal v produkci
                // navždy prázdný a nikdo by se to nedozvěděl.
                $c->get(\MyInvoice\Service\Tax\RelatedPartyService::class),
            ),
            // § 33a — zřetězení auditní stopy hashem. Druhý argument loggeru je volitelný
            // kvůli testovacím dvojníkům; bez explicitního bindu by se v produkci nic
            // nepečetilo a řetěz by neexistoval, aniž by to cokoli ohlásilo.
            \MyInvoice\Service\ActivityLogger::class => fn (ContainerInterface $c) => new \MyInvoice\Service\ActivityLogger(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\ActivityLogHashChain::class),
            ),
            // Epic #48 — dashboard „Akce pro tebe" potřebuje živý náhled doplatku DPPO
            // (TaxReturnService::balanceDuePreview). Konstrukčně volitelný (2. arg) kvůli
            // ReceivablesPayablesServiceTest, který CrmAggregationService staví ručně jen
            // s Connection — PHP-DI autowire optional class-param nevyplní, proto explicitní
            // bind, aby produkce/CrmDashboardAction reálnou instanci vždy dostaly.
            \MyInvoice\Service\Crm\CrmAggregationService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Crm\CrmAggregationService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class),
                $c->get(\MyInvoice\Service\Accounting\JournalIntegrityService::class),
                $c->get(\MyInvoice\Service\License\LicenseService::class),
                $c->get(\MyInvoice\Service\Report\VatRegistrationService::class),
            ),
            // Epic F0 — seam pro budoucí shard-routing per supplier; nový účetní kód (F1+)
            // si PDO bere přes forSupplier(), dnes vrací sdílené spojení.
            \MyInvoice\Infrastructure\Database\ConnectionResolver::class => fn (ContainerInterface $c) => new \MyInvoice\Infrastructure\Database\ConnectionResolver($c->get(Connection::class)),
            // Nativní updater si cesty (root / data dir) rozřeší sám; explicitní bind,
            // ať PHP-DI nemusí hádat volitelné string parametry konstruktoru.
            \MyInvoice\Service\Update\NativeUpdateService::class => fn () => new \MyInvoice\Service\Update\NativeUpdateService(),
            RedisProbe::class      => fn (ContainerInterface $c) => new RedisProbe($c->get(Config::class)),
            RedisFactory::class    => fn (ContainerInterface $c) => new RedisFactory($c->get(Config::class)),
            PasskeyService::class  => fn (ContainerInterface $c) => new PasskeyService(
                $c->get(WebAuthnConfigProvider::class),
            ),
            \MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\SigningPassphraseProvider(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
            ),
            \MyInvoice\Service\Signing\Pdf\PdfSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Pdf\PdfSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
                $c->get(\MyInvoice\Service\Signing\PersonalCertificateVaultService::class),
            ),
            \MyInvoice\Service\Signing\Email\EmailSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Email\EmailSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(\MyInvoice\Service\Signing\PersonalCertificateVaultService::class),
            ),
            \MyInvoice\Service\Mail\Mailer::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Mail\Mailer(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\EmailTemplateRepository::class),
                $c->get(\MyInvoice\Service\Signing\Email\EmailSigningService::class),
                $c->get(\MyInvoice\Repository\EmailProfileRepository::class),
                $c->get(\MyInvoice\Service\Mail\SentMailImapAppender::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient(
                $c->get(\MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository(
                $c->get(Connection::class),
                self::bankEmailNoticeParsers($c, $config),
            ),
            \MyInvoice\Service\Bank\StatementMatcher::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\StatementMatcher(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class),
                // #127 — automatické párování (GPC import, e-mailové avízo, cron) musí
                // poslat děkovný e-mail za úhradu stejně jako ruční mark-paid/manualMatch.
                $c->get(\MyInvoice\Service\Mail\PaymentThanksMailer::class),
                // #89 — evidence plateb (exact i částečné úhrady přes invoice_payments)
                // + auto DRAFT daňového dokladu k přijaté platbě u částečně uhrazené proformy.
                $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class),
                $c->get(\MyInvoice\Service\Invoice\PaymentTaxDocumentCreator::class),
                // Aktivita dokladu — „payment_matched" záznam u auto-spárování platby
                // (vidět v aktivitě vystavené i přijaté faktury).
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Repository\ClientBankAccountRepository::class),
                $c->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class),
            ),

            \MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface::class => fn (ContainerInterface $c) =>
                $c->get(\MyInvoice\Service\Accounting\AutoPostingPolicyService::class),
            // TransferPairService je jinak autowired, ale ?BankAnalyticResolver je optional
            // class-param (PHP-DI autowire ho nevyplní) — explicitní bind, ať #35 analytika
            // vlastního účtu funguje i na noze převodu mezi vlastními účty (221/261).
            \MyInvoice\Service\Accounting\Bank\TransferPairService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Accounting\Bank\TransferPairService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Accounting\PostingService::class),
                $c->get(\MyInvoice\Repository\PostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\JournalEntryRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingSuggestionRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\OwnTransferDetector::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticResolver::class),
            ),
            \MyInvoice\Service\Accounting\Bank\BankPostingService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Accounting\Bank\BankPostingService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Accounting\PostingService::class),
                $c->get(\MyInvoice\Repository\PostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\AccountingPeriodRepository::class),
                $c->get(\MyInvoice\Repository\JournalEntryRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingSuggestionRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankRuleMatcher::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
                $c->get(\MyInvoice\Service\Currency\FixedExchangeRateService::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\Detect\BankDetectorChain::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\TransferPairService::class),
                $c->get(\MyInvoice\Service\Accounting\AutoPostingPolicyService::class),
                $c->get(\MyInvoice\Repository\TaxAdvanceScheduleRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Learning\CorrectionRecorder::class),
                $c->get(\MyInvoice\Service\Accounting\Learning\RulePromotionService::class),
                $c->get(\MyInvoice\Service\Ai\AnomalyDetector::class),
                $c->get(\MyInvoice\Service\Ai\AiSuggestionService::class),
                $c->get(\MyInvoice\Service\Ai\AiKillSwitchService::class),
                $c->get(\MyInvoice\Service\Ai\EmbeddingWriter::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\LegacyBankPaymentReconciler::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticResolver::class),
            ),
            \MyInvoice\Service\Bank\StatementImporter::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\StatementImporter(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Bank\GpcParser::class),
                $c->get(\MyInvoice\Service\Bank\StatementMatcher::class),
                $c->get(\MyInvoice\Service\Bank\EmailNoticeReconciler::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class),
                $c->get(\MyInvoice\Repository\SupplierBankAccountRepository::class),
            ),

            // Licenční klient (E4) má volitelný `?GuzzleHttp\Client $http = null` (test
            // seam). Autowire by ho vyplnil bare Guzzle (bez base_uri/verify z cfg) →
            // definujeme explicitně s $http = null, ať si klient postaví vlastní klienta.
            \MyInvoice\Service\License\LicenseClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\License\LicenseClient(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
            ),
            // EPO klient má volitelný Guzzle pouze jako test seam. Produkce musí
            // použít jeho vlastní timeout/TLS/no-redirect konfiguraci.
            \MyInvoice\Service\Epo\EpoClient::class => fn () => new \MyInvoice\Service\Epo\EpoClient(
                null,
            ),
            \MyInvoice\Service\Epo\EpoDirectClient::class => fn () => new \MyInvoice\Service\Epo\EpoDirectClient(
                null,
                $config->get('epo_test', false) ? 'test' : 'production',
            ),

            // IpMatcher má v konstruktoru volitelný `?Config $config = null`. Autowiring
            // takový parametr neresolvuje (dosadí default null), takže clientIpFromRequest()
            // by ignorovalo cfg.ip_allowlist.trusted_proxies a vždy vracelo REMOTE_ADDR.
            // Za reverse proxy → audit log a brute-force lockout vidí IP proxy místo
            // reálného klienta. Explicitní injekce Configu to opravuje.
            IpMatcher::class       => fn (ContainerInterface $c) => new IpMatcher($c->get(Config::class)),

            // Repo sazba ČNB pro úrok z prodlení (penalizace) — interface → repository.
            \MyInvoice\Service\Penalty\RepoRateProvider::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Repository\CnbRepoRateRepository::class),

            // Kniha jízd — registry parserů detailních výpisů tankování. Pořadí = priorita:
            // konkrétní vendor parsery → AI fallback → univerzální summary (vždy uspěje).
            // PŘIDÁNÍ NOVÉ TANKOVACÍ SPOLEČNOSTI: vytvoř třídu implements FuelStatementParser
            // a vlož ji do tohoto pole PŘED AiFuelStatementParser.
            \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry([
                $c->get(\MyInvoice\Service\Logbook\Fuel\AxigonStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\AiFuelStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\SummaryFuelParser::class),
            ]),

            // F7 — AI extrakční brána (LlmGateway). PHP-DI s useAttributes(false)
            // neumí autowire interface → explicitní bind na router. Konkrétní klienti
            // (AnthropicClient), LlmProviderRegistry i ResidencyPolicy zůstávají autowired.
            \MyInvoice\Service\Import\LlmGatewayInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Import\LlmGatewayRouter::class),
            \MyInvoice\Service\Ai\EmbeddingGatewayInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Ai\EmbeddingGatewayRouter::class),
            \MyInvoice\Service\Ai\LlmClassifierInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Ai\LlmClassifierRouter::class),
            \MyInvoice\Service\Ai\AiProviderHttpClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Ai\AiProviderHttpClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Import\LlmProviderRegistry::class),
                $c->get(\MyInvoice\Service\Import\ResidencyPolicy::class),
                $c->get(\MyInvoice\Service\Ai\AiDpaGate::class),
                $c->get(LoggerInterface::class),
            ),

            // Non-Anthropic klienti mají volitelný `?GuzzleHttp\Client $http = null`
            // (test seam). Autowire by ho vyplnil bare Guzzle (bez http_errors=false →
            // non-2xx by házelo výjimky) → definujeme explicitně s $http = null,
            // aby si klient postavil vlastní nakonfigurovaný Guzzle interně.
            \MyInvoice\Service\Import\AzureOpenAiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\AzureOpenAiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),
            \MyInvoice\Service\Import\OpenAiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\OpenAiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),
            \MyInvoice\Service\Import\GeminiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\GeminiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),

            // "Upload PDF" bankovních výpisů — registry bank-specifických PDF parserů
            // (banky bez GPC/ABO exportu). PŘIDÁNÍ NOVÉ BANKY: nová třída implements
            // BankStatementPdfParserInterface a vlož ji do tohoto pole.
            \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry([
                $c->get(\MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\KbStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser::class),
            ]),
        ]);

        return $builder->build();
    }

    /** @return App<ContainerInterface|null> */
    public static function buildApp(): App
    {
        $container = self::buildContainer();
        $config    = $container->get(Config::class);

        AppFactory::setContainer($container);

        $app = AppFactory::create();

        Routes::register($app);

        // Slim 4 LIFO: poslední `add()` = NEJVĚTŠÍ vrstva = běží JAKO PRVNÍ.
        // Cílový order běhu (outside → inside):
        //   IpAllowlist → FirstRunLock → Auth → ApiRequestLog → SessionLock → RequireMfa → License → DemoReadOnly → SupplierScope → Permission → ApiScope → RateLimit → CSRF → WebAuthnBodyLimit → Routing → BodyParsing → Action
        // → add() v opačném pořadí (innermost první):
        //
        // ⚠️ Middleware se předávají jako CLASS-STRING, ne jako instance. Slim je pak
        // přidá přes addDeferred() a z kontejneru je vytáhne až ve chvíli, kdy k nim
        // request skutečně sestoupí. Předávat `$container->get(...)` znamenalo postavit
        // všech 14 (i s jejich stromy závislostí) na KAŽDÝ request — naměřeno +7,2 ms
        // a +79 načtených tříd, i když request skončil na 401 hned v první vrstvě.
        // Pořadí zůstává beze změny; líné je jen vytvoření instance.
        $app->addBodyParsingMiddleware();                            // innermost
        $app->addRoutingMiddleware();
        $app->add(WebAuthnBodyLimitMiddleware::class);               // limit raw WebAuthn credential před JSON parsingem
        $app->add(CsrfMiddleware::class);                            // potřebuje session z Auth (bearer skip)
        $app->add(RateLimitMiddleware::class);                       // chrání forgot/setup/login/ARES + per-user/per-token limity
        $app->add(ApiScopeMiddleware::class);                        // bearer-only: enforce read / read_write scope
        $app->add(PermissionMiddleware::class);                      // jemnozrnná route permission kontrola
        $app->add(SupplierScopeMiddleware::class);                   // multi-supplier scope (X-Supplier-Id / token's supplier_id)
        $app->add(DemoReadOnlyMiddleware::class);                    // demo: globální zákaz business mutací
        $app->add(LicenseMiddleware::class);                         // E4: denní obnova tokenu + blokace komerčních modulů po expiraci
        $app->add(RequireMfaMiddleware::class);                      // assurance + povinný MFA setup (bearer skip)
        $app->add(SessionLockMiddleware::class);                     // autoritativní idle/manual lock browser session
        $app->add(ApiRequestLogMiddleware::class);                   // bearer-only: per-request log do api_request_log (nad scope/právy, ať jsou vidět i zamítnutá volání)
        $app->add(AuthMiddleware::class);                            // načte session nebo bearer token
        $app->add(FirstRunLockMiddleware::class);                    // 423 pokud users prázdná
        $app->add(IpAllowlistMiddleware::class);                     // outermost user mw
        $app->add(new ApiVersionRewriteMiddleware());                // /api/v1/* → /api/* před vším ostatním

        $displayErrors = (bool) $config->get('app.debug', false);
        $app->addErrorMiddleware($displayErrors, true, true, $container->get(LoggerInterface::class));

        return $app;
    }

    /**
     * Resolve class names ze slotů cfg.bank_email.notice_parsers na instance.
     * Validaci (interface, prázdný/duplicitní key) dělá konstruktor
     * BankEmailNoticeParserRepository — tady se jen vypínají sloty (null/false/'').
     *
     * @return list<object>
     */
    private static function bankEmailNoticeParsers(ContainerInterface $container, Config $config): array
    {
        $classes = $config->get('bank_email.notice_parsers', []);
        if (!is_array($classes) || $classes === []) {
            throw new \RuntimeException('cfg.bank_email.notice_parsers musí být neprázdná mapa parser slot => class.');
        }

        $parsers = [];
        foreach ($classes as $class) {
            if ($class === null || $class === false || trim((string) $class) === '') {
                continue; // slot vypnutý přes cfg.php
            }
            $parsers[] = $container->get(trim((string) $class));
        }

        return $parsers;
    }

    private static function resolveLogLevel(string $level): \Monolog\Level
    {
        return match (strtolower($level)) {
            'debug'   => \Monolog\Level::Debug,
            'info'    => \Monolog\Level::Info,
            'notice'  => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error'   => \Monolog\Level::Error,
            default   => \Monolog\Level::Info,
        };
    }
}
