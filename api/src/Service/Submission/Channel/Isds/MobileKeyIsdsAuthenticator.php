<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/** Krátkodobý autentizační tok Mobilního klíče podle specifikace ISDS 1.5.1. */
final class MobileKeyIsdsAuthenticator
{
    private const TOKEN_CONTEXT = 'isds:mobile-key-flow:v1';
    private const FLOW_TTL = 240;
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const USER_AGENT = 'MyUcto-ISDS-MobileKey/1.0';

    /** @param null|callable(string,string,array<string,mixed>):array{status:int,body:string,cookies:array<string,string>} $httpDouble */
    public function __construct(
        private readonly SecretEncryption $crypto,
        private $httpDouble = null,
    ) {}

    /** @return array{flow_token:string,state:int,description:string,expires_at:string} */
    public function start(
        int $supplierId,
        int $userId,
        string $environment,
        string $username,
        string $communicationCode,
    ): array {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);
        $username = trim($username);
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            throw new SubmissionChannelException('isds_mobile_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        if ($communicationCode === '' || strlen($communicationCode) > 256 || preg_match('/[\x00-\x1f\x7f]/', $communicationCode) === 1) {
            throw new SubmissionChannelException('isds_mobile_code_invalid', 'Vyplňte komunikační kód pro Mobilní klíč.', 400);
        }

        $response = $this->loginRequest($environment, $username, $communicationCode, null);
        if ($response['status'] === 401) {
            throw new SubmissionChannelException('isds_mobile_login_rejected', 'ISDS odmítl uživatelské jméno nebo komunikační kód Mobilního klíče.', 401);
        }
        $sCookie = $response['cookies']['S-COOKIE'] ?? '';
        if ($response['status'] !== 302 || $sCookie === '') {
            throw new SubmissionChannelException('isds_mobile_login_failed', 'ISDS nezahájil přihlášení Mobilním klíčem.', 502);
        }

        $expires = time() + self::FLOW_TTL;
        $payload = json_encode([
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'environment' => $environment,
            'username' => $username,
            'communication_code' => $communicationCode,
            's_cookie' => $this->safeCookie($sCookie),
            'expires_at' => $expires,
            'nonce' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'flow_token' => $this->crypto->encryptFor($payload, self::TOKEN_CONTEXT),
            'state' => 1,
            'description' => 'Požadavek byl předán ISDS. Potvrďte přihlášení v aplikaci Mobilní klíč.',
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    /**
     * @return array{state:int,description:string,context:?ChannelContext}
     */
    public function continue(string $flowToken, int $supplierId, int $userId): array
    {
        $state = $this->decodeFlow($flowToken, $supplierId, $userId);
        $response = $this->request(
            'status',
            'GET',
            $this->host($state['environment']) . '/as/mepWsStateUpdate2',
            ['cookie' => 'S-COOKIE=' . $this->safeCookie($state['s_cookie'])],
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new SubmissionChannelException('isds_mobile_status_failed', 'Stav přihlášení Mobilním klíčem se nepodařilo ověřit.', 502);
        }
        try {
            $statusBody = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SubmissionChannelException('isds_mobile_status_malformed', 'ISDS vrátil nečitelný stav Mobilního klíče.', 502);
        }
        $status = (int) ($statusBody['status'] ?? -1);
        $description = trim((string) ($statusBody['description'] ?? ''));
        if ($status === 2) {
            $login = $this->loginRequest(
                $state['environment'],
                $state['username'],
                $state['communication_code'],
                $state['s_cookie'],
            );
            $sessionCookie = $login['cookies']['IPCZ-X-COOKIE'] ?? '';
            if ($login['status'] !== 302 || $sessionCookie === '') {
                throw new SubmissionChannelException('isds_mobile_session_failed', 'ISDS potvrdil Mobilní klíč, ale nevydal přístupovou relaci.', 502);
            }
            $cookie = $this->safeCookie($sessionCookie);
            $context = new ChannelContext(
                $supplierId,
                $state['environment'],
                new ChannelCredentials(
                    boxId: '',
                    authMode: 'mobile_key',
                    sessionCookie: SensitiveValue::fromProducer(static fn (): string => $cookie),
                ),
            );
            return ['state' => 2, 'description' => $description !== '' ? $description : 'Přihlášení potvrzeno.', 'context' => $context];
        }
        if ($status === 3) {
            throw new SubmissionChannelException('isds_mobile_rejected', 'Přihlášení bylo zamítnuto nebo vypršel čas pro potvrzení.', 409);
        }
        if ($status === 19) {
            throw new SubmissionChannelException('isds_mobile_push_failed', 'ISDS nedokázal odeslat upozornění do Mobilního klíče. Spusťte přihlášení znovu.', 502);
        }
        if (!in_array($status, [1, 11, 12, 13], true)) {
            throw new SubmissionChannelException('isds_mobile_flow_unknown', 'ISDS přihlašovací požadavek nezná.', 409);
        }
        return [
            'state' => $status,
            'description' => $description !== '' ? $description : 'Čeká se na potvrzení v Mobilním klíči.',
            'context' => null,
        ];
    }

    public function logout(ChannelContext $context): void
    {
        if ($context->credentials->authMode !== 'mobile_key' || $context->credentials->sessionCookie === null) {
            return;
        }
        $host = $this->host($context->environment);
        $uri = $host . '/apps/DS/dx';
        try {
            $this->request(
                'logout',
                'GET',
                $host . '/as/processLogout?uri=' . rawurlencode($uri),
                ['cookie' => 'IPCZ-X-COOKIE=' . $this->safeCookie($context->credentials->sessionCookie->reveal())],
            );
        } catch (\Throwable) {
            // Relace se po 30 minutách nečinnosti zneplatní sama. Chyba úklidu
            // nesmí přebít výsledek už dokončeného načtení zpráv.
        }
    }

    /** @return array{supplier_id:int,user_id:int,environment:string,username:string,communication_code:string,s_cookie:string,expires_at:int,nonce:string} */
    private function decodeFlow(string $token, int $supplierId, int $userId): array
    {
        try {
            $payload = $this->crypto->decryptFor($token, self::TOKEN_CONTEXT);
            $state = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new SubmissionChannelException('isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 409);
        }
        if (!is_array($state)
            || (int) ($state['supplier_id'] ?? 0) !== $supplierId
            || (int) ($state['user_id'] ?? 0) !== $userId
            || (int) ($state['expires_at'] ?? 0) < time()
        ) {
            throw new SubmissionChannelException('isds_mobile_flow_expired', 'Přihlášení Mobilním klíčem vypršelo. Spusťte ho znovu.', 409);
        }
        $environment = (string) ($state['environment'] ?? '');
        $this->assertEnvironment($environment);
        return [
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'environment' => $environment,
            'username' => (string) ($state['username'] ?? ''),
            'communication_code' => (string) ($state['communication_code'] ?? ''),
            's_cookie' => (string) ($state['s_cookie'] ?? ''),
            'expires_at' => (int) $state['expires_at'],
            'nonce' => (string) ($state['nonce'] ?? ''),
        ];
    }

    /** @return array{status:int,body:string,cookies:array<string,string>} */
    private function loginRequest(string $environment, string $username, string $code, ?string $sCookie): array
    {
        $host = $this->host($environment);
        $uri = $host . '/apps/DS/dx';
        $url = $host . '/as/processLogin?' . http_build_query([
            'type' => 'mep-ws',
            'applicationName' => 'MyÚčto',
            'uri' => $uri,
        ], '', '&', PHP_QUERY_RFC3986);
        $options = ['username' => $username, 'password' => $code];
        if ($sCookie !== null) {
            $options['cookie'] = 'S-COOKIE=' . $this->safeCookie($sCookie);
        }
        return $this->request('login', 'POST', $url, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{status:int,body:string,cookies:array<string,string>}
     */
    private function request(string $operation, string $method, string $url, array $options): array
    {
        if ($this->httpDouble !== null) {
            return ($this->httpDouble)($operation, $url, $options);
        }
        if (!function_exists('curl_init')) {
            throw new SubmissionChannelException('isds_curl_required', 'Pro připojení k datové schránce chybí rozšíření PHP cURL.', 503);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SubmissionChannelException('isds_mobile_connection_failed', 'Spojení s přihlášením Mobilního klíče se nepodařilo otevřít.', 502);
        }
        $cookies = [];
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? '' : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT, 'Expect:'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$cookies): int {
                if (stripos($header, 'Set-Cookie:') === 0) {
                    $pair = trim(explode(';', trim(substr($header, 11)), 2)[0] ?? '');
                    $separator = strpos($pair, '=');
                    if ($separator !== false) {
                        $cookies[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
                    }
                }
                return strlen($header);
            },
        ];
        if (isset($options['username'], $options['password'])) {
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = (string) $options['username'] . ':' . (string) $options['password'];
        }
        if (isset($options['cookie'])) {
            $curlOptions[CURLOPT_COOKIE] = (string) $options['cookie'];
        }
        curl_setopt_array($handle, $curlOptions);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new SubmissionChannelException('isds_mobile_connection_failed', 'Spojení s Mobilním klíčem se přerušilo' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 502);
        }
        return ['status' => $status, 'body' => (string) $body, 'cookies' => $cookies];
    }

    private function host(string $environment): string
    {
        $this->assertEnvironment($environment);
        return 'https://www.' . ($environment === 'test' ? 'datovka-test.gov.cz' : 'datovka.gov.cz');
    }

    private function safeCookie(string $cookie): string
    {
        if (strlen($cookie) < 8 || strlen($cookie) > 4096 || preg_match('/[\x00-\x20;,\x7f]/', $cookie) === 1) {
            throw new SubmissionChannelException('isds_mobile_cookie_invalid', 'Přihlašovací relace Mobilního klíče není platná.', 409);
        }
        return $cookie;
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException('encryption_key_required', 'Pro přihlášení Mobilním klíčem musí být nastavený samostatný šifrovací klíč aplikace.', 503);
        }
    }
}
