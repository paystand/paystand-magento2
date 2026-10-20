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
 * Tests for Plugin\Checkout\SuccessValidatorRepair. It turns a refusal into an approval
 * only for an order on the session's own quote placed inside the repair window.
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
     * getQuote() has usually nulled the session's quote id, so only the memo names the quote.
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
     * For a logged-in shopper, the session holds the new cart's id, which has no order.
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

    /**
     * The window is the security boundary, so the test uses a literal, not the constant.
     */
    public function testTheRepairWindowIsFifteenMinutes(): void
    {
        $this->assertSame(900, SuccessValidatorRepair::MAX_ORDER_AGE_SECONDS);
    }

    /**
     * An order past the window must not show on a confirmation page as if it were new.
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
     * created_at is UTC. The process timezone is ahead of UTC, so parsing the stamp as local
     * time ages the order past the window and the test fails.
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

    /**
     * The confirmation page keeps last_order_id and clears last_success_quote_id.
     * A second request after it must report as a revisit with the age of that
     * order, apart from a paid order that never got a confirmation.
     */
    public function testABounceAfterTheConfirmationIsARevisitWithTheOrderAge(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(117);
        $this->checkoutSessionMock->method('getLastQuoteId')->willReturn('116');
        $this->checkoutSessionMock->method('getLastOrderId')->willReturn('99');
        $this->checkoutSessionMock->method('getLastRealOrderId')->willReturn('000000099');
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturnMap([
            [117, null],
            [116, $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(40))],
        ]);

        $this->assertFalse($this->build()->afterIsValid($this->subjectMock, false));
        $this->assertSame([], $this->restored);

        $message = $this->bounceMessage(117, null);
        $this->assertStringStartsWith('success page redirected to cart; kind=revisit; no order for session quote;', $message);
        $this->assertStringContainsString('missing[last_success_quote_id] last_real_order_id=000000099', $message);
        $this->assertMatchesRegularExpression('/ last_order_age=4[01]s$/', $message);
    }

    public function testARevisitOfTheSessionQuoteReusesItsOrder(): void
    {
        $this->checkoutSessionMock->method('getLastQuoteId')->willReturn('116');
        $this->checkoutSessionMock->method('getLastOrderId')->willReturn('99');
        $this->quoteAccessMock->expects($this->never())->method('findOrderByQuoteId');

        $message = $this->bounceMessage(
            116,
            $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(7200))
        );

        $this->assertStringContainsString('kind=revisit; order 000000099 too old to restore;', $message);
        $this->assertMatchesRegularExpression('/ last_order_age=720[01]s$/', $message);
    }

    public function testARevisitWithoutItsOrderReportsAnUnknownAge(): void
    {
        $this->checkoutSessionMock->method('getLastQuoteId')->willReturn('116');
        $this->checkoutSessionMock->method('getLastOrderId')->willReturn('99');
        $this->quoteAccessMock->method('findOrderByQuoteId')->willReturn(null);

        $this->assertStringEndsWith(' last_order_age=(unknown)', $this->bounceMessage(0, null));
    }

    /**
     * The age is extra detail. A failed lookup must not lose the bounce report.
     */
    public function testARevisitAgeLookupFailureStillReportsTheBounce(): void
    {
        $this->checkoutSessionMock->method('getLastQuoteId')->willReturn('116');
        $this->checkoutSessionMock->method('getLastOrderId')->willReturn('99');
        $this->quoteAccessMock->method('findOrderByQuoteId')
            ->willThrowException(new \RuntimeException('db gone'));

        $message = $this->bounceMessage(0, null);
        $this->assertStringContainsString('kind=revisit;', $message);
        $this->assertStringEndsWith(' last_order_age=(unknown)', $message);
    }

    public function testAnOldOrderForTheSessionQuoteIsStale(): void
    {
        $message = $this->bounceMessage(
            116,
            $this->buildOrderMock(99, '000000099', 'pending', $this->agedBySeconds(7200))
        );

        $this->assertSame(
            'success page redirected to cart; kind=stale_order; order 000000099 too old to restore;'
            . ' missing[last_success_quote_id,last_quote_id,last_order_id] last_real_order_id=(none)',
            $message
        );
    }

    public function testAQuoteWithoutAnOrderIsNoOrder(): void
    {
        $this->assertSame(
            'success page redirected to cart; kind=no_order; no order for session quote;'
            . ' missing[last_success_quote_id,last_quote_id,last_order_id] last_real_order_id=(none)',
            $this->bounceMessage(116, null)
        );
    }

    public function testASessionWithNoQuoteAndNoLastOrderIsNoSession(): void
    {
        $this->assertSame(
            'success page redirected to cart; kind=no_session; no order for session quote;'
            . ' missing[last_success_quote_id,last_quote_id,last_order_id] last_real_order_id=(none)',
            $this->bounceMessage(0, null)
        );
    }

    /**
     * A repair that throws leaves Magento's own decision in place.
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

    private function build(): SuccessValidatorRepair
    {
        return new SuccessValidatorRepair(
            $this->checkoutSessionMock,
            $this->quoteAccessMock,
            $this->memo
        );
    }

    /** @return string the success_page_bounced message for this session state */
    private function bounceMessage(int $quoteId, $order): string
    {
        $method = new \ReflectionMethod(SuccessValidatorRepair::class, 'bounceMessage');
        $method->setAccessible(true);
        return $method->invoke($this->build(), $quoteId, $order);
    }

    /** @return string UTC timestamp in Magento's created_at format */
    private function agedBySeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /**
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
