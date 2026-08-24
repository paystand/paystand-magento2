<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Sales\Model\Order;
use PayStand\PayStandMagento\Helper\QuoteAccess;
use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;
use PayStand\PayStandMagento\Plugin\Checkout\SuccessValidatorRepair;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for Plugin\Checkout\SuccessValidatorRepair.
 *
 * The plugin only ever turns a refusal into an approval, and only when an order
 * for the session's own quote was placed inside the repair window. The age guard
 * is the security boundary: without it a stale session could surface an old order
 * as a fresh confirmation, and it cannot be exercised against a live checkout.
 */
class SuccessValidatorRepairTest extends TestCase
{
    /** @var CheckoutSession|MockObject */
    private $checkoutSessionMock;

    /** @var QuoteAccess|MockObject */
    private $quoteAccessMock;

    /** @var CheckoutQuoteMemo */
    private $memo;

    /** @var SuccessValidator|MockObject */
    private $subjectMock;

    /** @var array Values written back to the checkout session. */
    private $restored;

    protected function setUp(): void
    {
        $this->restored = [];

        $this->checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->addMethods([
                'setLastQuoteId',
                'setLastSuccessQuoteId',
                'setLastOrderId',
                'setLastRealOrderId',
                'setLastOrderStatus',
                'getLastSuccessQuoteId',
                'getLastQuoteId',
                'getLastOrderId',
                'getLastRealOrderId',
            ])
            ->getMock();

        foreach (['LastQuoteId', 'LastSuccessQuoteId', 'LastOrderId', 'LastRealOrderId', 'LastOrderStatus'] as $name) {
            $this->checkoutSessionMock->method('set' . $name)
                ->willReturnCallback(function ($value) use ($name) {
                    $this->restored[$name] = $value;
                    return $this->checkoutSessionMock;
                });
        }

        $this->quoteAccessMock = $this->getMockBuilder(QuoteAccess::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->memo = new CheckoutQuoteMemo();

        $this->subjectMock = $this->getMockBuilder(SuccessValidator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Magento's own approval must pass through untouched — no lookup, no writes.
     */
    public function testLeavesAnApprovedValidationAlone(): void
    {
        $this->quoteAccessMock->expects($this->never())->method('findOrderByQuoteId');

        $this->assertTrue($this->build()->afterIsValid($this->subjectMock, true));
        $this->assertSame([], $this->restored);
    }

    public function testRestoresTheSessionWhenARecentOrderExists(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->with(116)
            ->willReturn($this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(5)));

        $this->assertTrue($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame(
            [
                'LastQuoteId'        => 116,
                'LastSuccessQuoteId' => 116,
                'LastOrderId'        => 99,
                'LastRealOrderId'    => '000000099',
                'LastOrderStatus'    => 'pending',
            ],
            $this->restored
        );
    }

    /**
     * By the time the validator runs, getQuote() has usually already nulled the
     * session's own id — the memo is the only thing left that names the quote.
     */
    public function testUsesTheMemoWhenTheSessionQuoteIdIsGone(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(null);
        $this->memo->remember(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->with(116)
            ->willReturn($this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(5)));

        $this->assertTrue($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame(116, $this->restored['LastQuoteId']);
    }

    /**
     * For a logged-in shopper getQuote() does not leave the id empty: it assigns
     * the replacement cart it just created. Repairing from that id would look up
     * an order that does not exist, so the memo has to win.
     */
    public function testPrefersTheMemoOverAReplacementCartIdInTheSession(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(117);
        $this->memo->remember(116);

        $this->quoteAccessMock->expects($this->once())
            ->method('findOrderByQuoteId')
            ->with(116)
            ->willReturn($this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(5)));

        $this->assertTrue($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame(116, $this->restored['LastQuoteId']);
        $this->assertSame(116, $this->restored['LastSuccessQuoteId']);
    }

    public function testRefusesWhenNoOrderExistsForTheQuote(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);
    }

    public function testRefusesWhenNoQuoteIdIsKnownAtAll(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(null);
        $this->quoteAccessMock->expects($this->never())->method('findOrderByQuoteId');

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
    }

    // ── Repair window ────────────────────────────────────────────────────────

    /**
     * The window is the security boundary, so it is pinned to a literal rather
     * than read from the constant — a silent widening has to fail here.
     */
    public function testTheRepairWindowIsFifteenMinutes(): void
    {
        $this->assertSame(900, SuccessValidatorRepair::MAX_ORDER_AGE_SECONDS);
    }

    /**
     * An order past the window must not revive a confirmation page, or a stale
     * session could show an old order as if it had just been placed.
     */
    public function testRefusesAnOrderPlacedHoursAgo(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(
            $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(7200))
        );

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);
    }

    /**
     * One second past the window. Ages only grow while the test runs, so this
     * stays outside it.
     */
    public function testRefusesAnOrderOneSecondPastTheRepairWindow(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(
            $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(901))
        );

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);
    }

    /**
     * One second inside the window. A tick during the test lands exactly on the
     * boundary, which the guard still accepts.
     */
    public function testAcceptsAnOrderOneSecondInsideTheRepairWindow(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(
            $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(899))
        );

        $this->assertTrue($this->build()->afterIsValid($this->subjectMock, false));
    }

    public function testRefusesAnOrderWithNoCreatedAt(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')
            ->willReturn($this->buildOrderMock(99, '000000099', 'pending', null));

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
    }

    /**
     * Order timestamps are stored in UTC. The process timezone is set ahead of
     * UTC on purpose: parsing a UTC stamp naively there ages the order past the
     * window, so a dropped timezone fails here instead of passing by luck. A
     * zone behind UTC would read as a future timestamp and still look recent.
     */
    public function testCreatedAtIsReadAsUtcRegardlessOfProcessTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
            $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(
                $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(5))
            );

            $this->assertTrue($this->build()->afterIsValid($this->subjectMock, false));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    // ── Failure containment ──────────────────────────────────────────────────

    /**
     * A repair that blows up must leave Magento's own decision standing rather
     * than fataling on the confirmation page.
     */
    public function testAThrowingSessionLeavesTheValidatorDecisionUntouched(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')
            ->willThrowException(new \RuntimeException('session gone'));

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);
    }

    public function testALookupFailureLeavesTheValidatorDecisionUntouched(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(116);
        $this->quoteAccessMock->method('findOrderByQuoteId')
            ->willThrowException(new \RuntimeException('db gone'));

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);
    }

    /**
     * @return SuccessValidatorRepair
     */
    private function build(): SuccessValidatorRepair
    {
        return new SuccessValidatorRepair(
            $this->checkoutSessionMock,
            $this->quoteAccessMock,
            $this->memo
        );
    }

    /**
     * @param int $seconds
     * @return string UTC timestamp in Magento's created_at format
     */
    private function agedBySeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /**
     * @param int $id
     * @param string $incrementId
     * @param string $status
     * @param string|null $createdAt
     * @return Order|MockObject
     */
    private function buildOrderMock(int $id, string $incrementId, string $status, $createdAt)
    {
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId', 'getStatus', 'getCreatedAt'])
            ->getMock();
        $orderMock->method('getId')->willReturn($id);
        $orderMock->method('getIncrementId')->willReturn($incrementId);
        $orderMock->method('getStatus')->willReturn($status);
        $orderMock->method('getCreatedAt')->willReturn($createdAt);
        return $orderMock;
    }
}
