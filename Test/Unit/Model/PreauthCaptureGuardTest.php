<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Model;

use PayStand\PayStandMagento\Model\PreauthCaptureGuard;
use PHPUnit\Framework\TestCase;

class PreauthCaptureGuardTest extends TestCase
{
    private const FUTURE = '2099-01-01 00:00:00';
    private const PAST = '2000-01-01 00:00:00';

    private PreauthCaptureGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PreauthCaptureGuard();
    }

    public function testFullRemainingAuthorizedAmountPasses(): void
    {
        $this->assertNull($this->guard->refusal($this->hold('authorized', '10.00', self::FUTURE), '10.00'));
    }

    public function testFullRemainingOfPartiallyCapturedHoldPasses(): void
    {
        $this->assertNull($this->guard->refusal($this->hold('partially_captured', '6.00', self::FUTURE), '6.00'));
    }

    public function testZeroAmountIsRefused(): void
    {
        $this->assertSame(
            'Capture amount must be greater than zero.',
            $this->guard->refusal($this->hold('authorized', '10.00', self::FUTURE), '0')
        );
    }

    public function testAmountAboveRemainingIsRefused(): void
    {
        $this->assertSame(
            'Capture amount is above the remaining authorization.',
            $this->guard->refusal($this->hold('authorized', '10.00', self::FUTURE), '10.02')
        );
    }

    public function testPartialAmountIsRefused(): void
    {
        $this->assertSame(
            'Partial capture is not supported. Capture the full remaining authorization.',
            $this->guard->refusal($this->hold('authorized', '10.00', self::FUTURE), '4.00')
        );
    }

    public function testVoidedHoldIsRefused(): void
    {
        $this->assertSame(
            'Authorization cannot be captured.',
            $this->guard->refusal($this->hold('voided', '10.00', ''), '10.00')
        );
    }

    public function testCapturedHoldIsRefused(): void
    {
        $this->assertSame(
            'Authorization cannot be captured.',
            $this->guard->refusal($this->hold('captured', '10.00', self::FUTURE), '10.00')
        );
    }

    public function testExpiredHoldIsRefused(): void
    {
        $this->assertSame(
            'Authorization has expired.',
            $this->guard->refusal($this->hold('authorized', '10.00', self::PAST), '10.00')
        );
    }

    public function testHoldWithoutExpiryPasses(): void
    {
        $this->assertNull($this->guard->refusal($this->hold('authorized', '10.00', null), '10.00'));
    }

    public function testCanCaptureOpenHold(): void
    {
        $this->assertTrue($this->guard->canCapture($this->hold('authorized', '10.00', self::FUTURE)));
    }

    public function testCannotCaptureExpiredHold(): void
    {
        $this->assertFalse($this->guard->canCapture($this->hold('authorized', '10.00', self::PAST)));
    }

    public function testCannotCaptureFullyCapturedHold(): void
    {
        $this->assertFalse($this->guard->canCapture($this->hold('partially_captured', '0', self::FUTURE)));
    }

    private function hold(string $status, string $remaining, ?string $expiresAt): array
    {
        return ['status' => $status, 'remaining' => $remaining, 'expires_at' => $expiresAt];
    }
}
