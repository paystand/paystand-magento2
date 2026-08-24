<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\QuoteAccess;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Unit tests for Helper\QuoteAccess — the shared quote resolution + session
 * authorization used by the checkout AJAX endpoints.
 *
 * The authorization matrix under test:
 * - masked id             → allow (unguessable capability token)
 * - numeric + owning customer session      → allow
 * - numeric + same checkout-session quote  → allow
 * - numeric + neither                      → DENY (fail closed)
 */
class QuoteAccessTest extends TestCase
{
    /** @var QuoteAccess */
    private $helper;

    /** @var QuoteIdMaskFactory|MockObject */
    private $quoteIdMaskFactoryMock;

    /** @var CartRepositoryInterface|MockObject */
    private $cartRepositoryMock;

    /** @var CustomerSession|MockObject */
    private $customerSessionMock;

    /** @var CheckoutSession|MockObject */
    private $checkoutSessionMock;

    /** @var OrderCollectionFactory|MockObject */
    private $orderCollectionFactoryMock;

    protected function setUp(): void
    {
        $this->quoteIdMaskFactoryMock = $this->getMockBuilder(QuoteIdMaskFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->customerSessionMock = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();

        $this->checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->getMock();

        $this->orderCollectionFactoryMock = $this->getMockBuilder(OrderCollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock = $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass();

        $this->helper = new QuoteAccess(
            $this->quoteIdMaskFactoryMock,
            $this->cartRepositoryMock,
            $this->customerSessionMock,
            $this->checkoutSessionMock,
            $this->orderCollectionFactoryMock,
            $loggerMock
        );
    }

    // ── resolveRealQuoteId ────────────────────────────────────────────────────

    public function testNumericIdIsReturnedAsIs(): void
    {
        $this->assertSame(4189563, $this->helper->resolveRealQuoteId('4189563'));
        $this->assertSame(47, $this->helper->resolveRealQuoteId(47));
    }

    public function testMaskedIdIsResolvedThroughQuoteIdMask(): void
    {
        $this->setupMask(4189563);

        $this->assertSame(4189563, $this->helper->resolveRealQuoteId('maskedguesttoken123'));
    }

    public function testUnresolvableMaskedIdThrows(): void
    {
        $this->setupMask(null);

        $this->expectException(NoSuchEntityException::class);
        $this->helper->resolveRealQuoteId('bogusmaskedtoken');
    }

    // ── canAccess authorization matrix ───────────────────────────────────────

    public function testMaskedIdGrantsAccess(): void
    {
        $quote = $this->buildQuoteMock(4189563, 999);

        $this->assertTrue($this->helper->canAccess($quote, true));
    }

    public function testNumericIdWithOwningCustomerSessionGrantsAccess(): void
    {
        $quote = $this->buildQuoteMock(4189563, 555);
        $this->customerSessionMock->method('isLoggedIn')->willReturn(true);
        $this->customerSessionMock->method('getCustomerId')->willReturn(555);

        $this->assertTrue($this->helper->canAccess($quote, false));
    }

    public function testNumericIdWithMatchingCheckoutSessionQuoteGrantsAccess(): void
    {
        // Guest-created quote (no customer) but it IS the session's own cart.
        $quote = $this->buildQuoteMock(4189563, null);
        $this->customerSessionMock->method('isLoggedIn')->willReturn(false);
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(4189563);

        $this->assertTrue($this->helper->canAccess($quote, false));
    }

    public function testNumericIdWithForeignSessionIsDenied(): void
    {
        // Attacker probing a sequential id: logged out, different session quote.
        $quote = $this->buildQuoteMock(4189563, 555);
        $this->customerSessionMock->method('isLoggedIn')->willReturn(false);
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(999999);

        $this->assertFalse($this->helper->canAccess($quote, false));
    }

    public function testNumericIdWithDifferentLoggedInCustomerIsDenied(): void
    {
        $quote = $this->buildQuoteMock(4189563, 555);
        $this->customerSessionMock->method('isLoggedIn')->willReturn(true);
        $this->customerSessionMock->method('getCustomerId')->willReturn(666);
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(999999);

        $this->assertFalse($this->helper->canAccess($quote, false));
    }

    // ── getAuthorizedQuote end-to-end ────────────────────────────────────────

    public function testGetAuthorizedQuoteReturnsNullForForeignNumericId(): void
    {
        $quote = $this->buildQuoteMock(4189563, 555);
        $this->cartRepositoryMock->method('get')->with(4189563)->willReturn($quote);
        $this->customerSessionMock->method('isLoggedIn')->willReturn(false);
        $this->checkoutSessionMock->method('getQuoteId')->willReturn(1);

        $this->assertNull($this->helper->getAuthorizedQuote('4189563'));
    }

    public function testGetAuthorizedQuoteReturnsQuoteForMaskedId(): void
    {
        $this->setupMask(4189563);
        $quote = $this->buildQuoteMock(4189563, null);
        $this->cartRepositoryMock->method('get')->with(4189563)->willReturn($quote);

        $this->assertSame($quote, $this->helper->getAuthorizedQuote('maskedguesttoken123'));
    }

    public function testGetAuthorizedQuoteReturnsNullWhenQuoteMissing(): void
    {
        $this->cartRepositoryMock->method('get')
            ->willThrowException(new NoSuchEntityException(__('missing')));
        $this->assertNull($this->helper->getAuthorizedQuote('123456'));
    }

    public function testGetAuthorizedQuoteReturnsNullForEmptyId(): void
    {
        $this->assertNull($this->helper->getAuthorizedQuote(null));
        $this->assertNull($this->helper->getAuthorizedQuote(''));
    }

    // ── findOrderByQuoteId ────────────────────────────────────────────────────

    public function testFindOrderByQuoteIdReturnsNullWhenTheQuoteHasNoOrder(): void
    {
        $this->setupOrderCollection(0, null);

        $this->assertNull($this->helper->findOrderByQuoteId(4189563));
    }

    public function testFindOrderByQuoteIdReturnsTheOrderForTheQuote(): void
    {
        $orderMock = $this->buildOrderMock(42, 'W001369548');
        $this->setupOrderCollection(1, $orderMock);

        $this->assertSame($orderMock, $this->helper->findOrderByQuoteId(4189563));
    }

    /**
     * A quote can end up with more than one order when the webhook and the
     * browser both place it. The newest row is the one the shopper just paid for.
     */
    public function testFindOrderByQuoteIdReturnsTheNewestOfDuplicateOrders(): void
    {
        $newest = $this->buildOrderMock(43, 'W001369549');
        $collectionMock = $this->setupOrderCollection(2, $newest);

        $collectionMock->expects($this->once())->method('setOrder')->with('entity_id', 'DESC');
        $collectionMock->expects($this->once())->method('setPageSize')->with(1);

        $this->assertSame($newest, $this->helper->findOrderByQuoteId(4189563));
    }

    public function testFindOrderByQuoteIdReturnsNullWhenTheLookupFails(): void
    {
        $this->orderCollectionFactoryMock->method('create')
            ->willThrowException(new \RuntimeException('db gone'));

        $this->assertNull($this->helper->findOrderByQuoteId(4189563));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param int $size
     * @param Order|MockObject|null $firstItem
     * @return OrderCollection|MockObject
     */
    private function setupOrderCollection(int $size, $firstItem)
    {
        $collectionMock = $this->getMockBuilder(OrderCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'setOrder', 'setPageSize', 'getSize', 'getFirstItem'])
            ->getMock();
        $collectionMock->method('addFieldToFilter')->willReturnSelf();
        $collectionMock->method('setOrder')->willReturnSelf();
        $collectionMock->method('setPageSize')->willReturnSelf();
        $collectionMock->method('getSize')->willReturn($size);
        $collectionMock->method('getFirstItem')->willReturn($firstItem);

        $this->orderCollectionFactoryMock->method('create')->willReturn($collectionMock);

        return $collectionMock;
    }

    /**
     * @param int $id
     * @param string $incrementId
     * @return Order|MockObject
     */
    private function buildOrderMock(int $id, string $incrementId)
    {
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId'])
            ->getMock();
        $orderMock->method('getId')->willReturn($id);
        $orderMock->method('getIncrementId')->willReturn($incrementId);
        return $orderMock;
    }

    private function setupMask(?int $realQuoteId): void
    {
        $maskMock = $this->getMockBuilder(QuoteIdMask::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getQuoteId'])
            ->getMock();
        $maskMock->method('load')->willReturnSelf();
        $maskMock->method('getQuoteId')->willReturn($realQuoteId);
        $this->quoteIdMaskFactoryMock->method('create')->willReturn($maskMock);
    }

    /**
     * @param int $id
     * @param int|null $customerId
     * @return Quote|MockObject
     */
    private function buildQuoteMock(int $id, $customerId)
    {
        // getCustomerId is a magic data accessor on Quote — addMethods, not onlyMethods.
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quoteMock->method('getId')->willReturn($id);
        $quoteMock->method('getCustomerId')->willReturn($customerId);
        return $quoteMock;
    }
}
