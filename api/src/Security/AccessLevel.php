<?php

declare(strict_types=1);

namespace MyInvoice\Security;

enum AccessLevel: int
{
    case NONE = 0;
    case READ = 1;
    case WRITE = 2;

    public function allows(self $minimum): bool
    {
        return $this->value >= $minimum->value;
    }

    public static function fromMixed(int|string|self $value): self
    {
        if ($value instanceof self) return $value;
        if (is_string($value) && !ctype_digit($value)) {
            return match (strtolower($value)) {
                'none' => self::NONE,
                'read' => self::READ,
                'write' => self::WRITE,
                default => throw new \InvalidArgumentException('Unknown access level: ' . $value),
            };
        }
        return self::from((int) $value);
    }
}
