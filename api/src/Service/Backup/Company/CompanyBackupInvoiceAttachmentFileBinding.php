<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Neměnná vazba archivního vlastníka souboru na cílovou přílohu a fakturu. */
final readonly class CompanyBackupInvoiceAttachmentFileBinding
{
    public function __construct(
        public int $sourceAttachmentId,
        public int $targetAttachmentId,
        public int $sourceInvoiceId,
        public int $targetInvoiceId,
    ) {
        if ($sourceAttachmentId < 1 || $targetAttachmentId < 1
            || $sourceInvoiceId < 1 || $targetInvoiceId < 1
        ) {
            throw new \InvalidArgumentException('Vazba přílohy faktury není platná.');
        }
    }

    /** @return array{source_attachment_id:int,target_attachment_id:int,source_invoice_id:int,target_invoice_id:int} */
    public function bindingValue(): array
    {
        return [
            'source_attachment_id' => $this->sourceAttachmentId,
            'target_attachment_id' => $this->targetAttachmentId,
            'source_invoice_id' => $this->sourceInvoiceId,
            'target_invoice_id' => $this->targetInvoiceId,
        ];
    }

    public static function fromArray(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Vazba přílohy faktury není platná.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'source_attachment_id', 'source_invoice_id',
            'target_attachment_id', 'target_invoice_id',
        ] || !is_int($value['source_attachment_id'])
            || !is_int($value['target_attachment_id'])
            || !is_int($value['source_invoice_id'])
            || !is_int($value['target_invoice_id'])
        ) {
            throw new \InvalidArgumentException('Vazba přílohy faktury není platná.');
        }
        return new self(
            $value['source_attachment_id'], $value['target_attachment_id'],
            $value['source_invoice_id'], $value['target_invoice_id'],
        );
    }

    public static function canonicalPositiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value)
            || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
        ) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return is_int($id) && (string) $id === $value ? $id : null;
    }
}
