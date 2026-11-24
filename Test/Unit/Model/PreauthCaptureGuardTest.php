<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Model;

use PayStand\PayStandMagento\Model\PreauthCaptureGuard;
use PHPUnit\Framework\TestCase;

class PreauthCaptureGuardTest extends TestCase
{
    public function testFullRemainingAuthorizedAmountPasses(): void
    {
        $guard = new PreauthCaptureGuard();

        $this->assertNull($guard->refusal([
            'status' => 'authorized',
            'remaining' => '10.00',
            'expires_at' => '2099-01-01 00:00:00'
        ], '10.00'));
    }

    public function testPartialAmountIsRefused(): void
    {
        $guard = new PreauthCaptureGuard();

        $this->assertSame(
            'Capture must be the full remaining authorization.',
            $guard->refusal([
                'status' => 'authorized',
                'remaining' => '10.00',
                'expires_at' => '2099-01-01 00:00:00'
            ], '4.00')
        );
    }

    public function testVoidedAndExpiredHoldsAreRefused(): void
    {
        $guard = new PreauthCaptureGuard();

        $this->assertSame(
            'Authorization cannot be captured.',
            $guard->refusal(['status' => 'voided', 'remaining' => '10.00', 'expires_at' => ''], '10.00')
        );
        $this->assertSame(
            'Authorization has expired.',
            $guard->refusal([
                'status' => 'authorized',
                'remaining' => '10.00',
                'expires_at' => '2000-01-01 00:00:00'
            ], '10.00')
        );
    }
}
