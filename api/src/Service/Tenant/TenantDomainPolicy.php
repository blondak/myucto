<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

/** Sdílené rozhodnutí pro Slim requesty i serverový SPA fallback. */
final class TenantDomainPolicy
{
    /** @return array{status:int,code:string,message:string}|null */
    public function denial(TenantDomainContext $context, string $method, string $path): ?array
    {
        $method = strtoupper($method);
        if ($context->mode === TenantDomainContext::UNKNOWN) {
            return [
                'status' => 421,
                'code' => 'unknown_host',
                'message' => 'Tento hostname není pro aplikaci nakonfigurovaný.',
            ];
        }

        if ($context->mode === TenantDomainContext::VERIFICATION
            && (!in_array($method, ['GET', 'HEAD'], true)
                || preg_match('#^/api/public/domain-verification/[a-f0-9]{64}$#D', $path) !== 1)
        ) {
            return [
                'status' => 421,
                'code' => 'domain_not_active',
                'message' => 'Vlastní doména ještě není aktivní.',
            ];
        }

        if ($context->mode !== TenantDomainContext::CUSTOM) {
            return null;
        }

        $isPublic = str_starts_with($path, '/api/public/')
            || preg_match('#^/(?:invoice|approval|work-report)/#', $path) === 1;
        if ($isPublic && !$context->allowsPublicLinks()) {
            return ['status' => 404, 'code' => 'not_found', 'message' => 'Odkaz nebyl nalezen.'];
        }
        if (!$isPublic && !$context->allowsPortal() && !$this->isPublicAssetOrContext($path)) {
            return [
                'status' => 404,
                'code' => 'portal_not_enabled',
                'message' => 'Na této doméně není klientský portál aktivní.',
            ];
        }

        return null;
    }

    private function isPublicAssetOrContext(string $path): bool
    {
        return $path === '/api/auth/domain-context'
            || str_starts_with($path, '/assets/')
            || in_array($path, [
                '/favicon.ico',
                '/manifest.webmanifest',
                '/service-worker.js',
                '/sw.js',
            ], true);
    }
}
