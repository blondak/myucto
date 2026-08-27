<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

final readonly class TenantDataCoverageIssue
{
    public function __construct(
        public string $code,
        public string $object,
        public string $message,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Coverage chyba nemá bezpečný kód.');
        }
        if (preg_match(
            '/^(?:table|file-area|logical|profile):[a-z][a-z0-9_.-]{0,127}$/D',
            $object,
        ) !== 1) {
            throw new \InvalidArgumentException('Coverage chyba nemá bezpečný klíč objektu.');
        }
        if ($message === '' || preg_match('/[\x00-\x1F\x7F]/', $message) === 1) {
            throw new \InvalidArgumentException('Coverage chyba nemá bezpečnou zprávu.');
        }
    }

    /** @return array{code:string,object:string,message:string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'object' => $this->object,
            'message' => $this->message,
        ];
    }
}
