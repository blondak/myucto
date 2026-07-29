<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing;

use MyInvoice\Action\Settings\SigningProfilesAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SigningProfilesActionTest extends TestCase
{
    public function testMissingSmimeIdentityPolicyKeepsWarningOnlyUiDefault(): void
    {
        $action = (new ReflectionClass(SigningProfilesAction::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SigningProfilesAction::class, 'normalizeSignatureConfig');

        self::assertSame(
            ['smime_identity_policy' => 'warning_only'],
            $method->invoke($action, [], 'email_smime'),
        );
        self::assertSame(
            ['smime_identity_policy' => 'warning_only'],
            $method->invoke($action, ['smime_identity_policy' => 'invalid'], 'email_smime'),
        );
        self::assertSame(
            ['smime_identity_policy' => 'strict_match'],
            $method->invoke($action, ['smime_identity_policy' => 'strict_match'], 'email_smime'),
        );
    }
}
