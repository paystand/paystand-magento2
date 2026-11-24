<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\CheckoutProof;
use PHPUnit\Framework\TestCase;

class CheckoutProofTest extends TestCase
{
    public function testSignMatchesTheRoadrunnerMessage(): void
    {
        $proof = (new CheckoutProof())->sign('q-1', '10.00', 'usd', 'magento-client-secret');
        $expected = hash_hmac('sha256', 'q-1|1000|USD', 'magento-client-secret');

        $this->assertSame($expected, $proof);
    }
}
