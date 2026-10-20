<?php

namespace PayStand\PayStandMagento\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;
use PayStand\PayStandMagento\Plugin\Checkout\QuoteIdMemo;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for Plugin\Checkout\QuoteIdMemo. It runs on every frontend getQuote() call and
 * records the quote id before getQuote() can clear it.
 */
class QuoteIdMemoTest extends TestCase
{
    /** @var CheckoutQuoteMemo */
    private $memo;

    /** @var QuoteIdMemo */
    private $plugin;

    protected function setUp(): void
    {
        $this->memo = new CheckoutQuoteMemo();
        $this->plugin = new QuoteIdMemo($this->memo);
    }

    public function testCapturesTheSessionQuoteId(): void
    {
        $this->plugin->beforeGetQuote($this->buildSessionMock(4189563));

        $this->assertSame(4189563, $this->memo->get());
    }

    /**
     * The id seen before the quote goes inactive is kept; the new cart's id does not replace it.
     */
    public function testKeepsTheIdSeenBeforeTheSessionWasCleared(): void
    {
        $this->plugin->beforeGetQuote($this->buildSessionMock(4189563));
        $this->plugin->beforeGetQuote($this->buildSessionMock(null));
        $this->plugin->beforeGetQuote($this->buildSessionMock(4189999));

        $this->assertSame(4189563, $this->memo->get());
    }

    /**
     * Bookkeeping must never be able to break the checkout session it observes.
     */
    public function testASessionThatThrowsIsSwallowed(): void
    {
        $sessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->getMock();
        $sessionMock->method('getQuoteId')->willThrowException(new \RuntimeException('no session'));

        $this->plugin->beforeGetQuote($sessionMock);

        $this->assertSame(0, $this->memo->get());
    }

    /**
     * @param mixed $quoteId
     * @return CheckoutSession|MockObject
     */
    private function buildSessionMock($quoteId)
    {
        $sessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->getMock();
        $sessionMock->method('getQuoteId')->willReturn($quoteId);
        return $sessionMock;
    }
}
