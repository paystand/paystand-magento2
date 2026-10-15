<?php

namespace PayStand\PayStandMagento\Test\Unit\Controller\Checkout;

use PayStand\PayStandMagento\Controller\Checkout\SavePaymentData;
use PayStand\PayStandMagento\Helper\CaptureSnapshot;
use PayStand\PayStandMagento\Model\Config\Source\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SavePaymentData. Totals freeze only on a captured status, so a cart
 * whose payment never completed still collects.
 */
class SavePaymentDataTest extends TestCase
{
    /** @var SavePaymentData */
    private $controller;

    protected function setUp(): void
    {
        $this->controller = $this->getMockBuilder(SavePaymentData::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * @param string|null $paymentId
     * @param string|null $paymentStatus
     * @return string|null
     */
    private function gate($paymentId, $paymentStatus)
    {
        $method = new \ReflectionMethod(SavePaymentData::class, 'captureStatusFor');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $paymentId, $paymentStatus);
    }

    public function testPostedFreezes(): void
    {
        $this->assertSame('posted', $this->gate('nlvsnvr0ska9i7ugvoab9917', 'posted'));
    }

    public function testPaidFreezes(): void
    {
        $this->assertSame('paid', $this->gate('nlvsnvr0ska9i7ugvoab9917', 'paid'));
    }

    /**
     * ACH sits in processing before the money lands, so it must not freeze.
     */
    public function testProcessingDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', 'processing'));
    }

    public function testFailedDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', 'failed'));
    }

    /**
     * A payment id alone does not prove the money was taken.
     */
    public function testPaymentIdWithoutStatusDoesNotFreeze(): void
    {
        $this->assertNull($this->gate('nlvsnvr0ska9i7ugvoab9917', null));
    }

    public function testStatusWithoutPaymentIdDoesNotFreeze(): void
    {
        $this->assertNull($this->gate(null, 'posted'));
    }

    public function testEmptyStringsDoNotFreeze(): void
    {
        $this->assertNull($this->gate('', ''));
    }

    /**
     * The widget's status casing and padding are not guaranteed.
     */
    public function testStatusIsNormalisedBeforeMatching(): void
    {
        $this->assertSame('posted', $this->gate('nlvsnvr0ska9i7ugvoab9917', '  POSTED '));
    }

    /**
     * The gate accepts the same statuses as the updateOrderOn dropdown.
     */
    public function testGateAcceptsExactlyTheSharedCapturedStatuses(): void
    {
        $this->assertSame(PaymentStatus::CAPTURED_STATUSES, SavePaymentData::CAPTURED_STATUSES);

        foreach (PaymentStatus::CAPTURED_STATUSES as $status) {
            $this->assertSame($status, $this->gate('nlvsnvr0ska9i7ugvoab9917', $status));
        }

        $this->assertNotContains('processing', PaymentStatus::CAPTURED_STATUSES);
        $this->assertContains('processing', PaymentStatus::PLACE_ORDER_STATUSES);
    }

    public function testProcessingDoesNotReplaceACapturedStatus(): void
    {
        $this->assertSame('paid', PaymentStatus::keepStrongerStatus('paid', 'processing'));
        $this->assertSame('posted', PaymentStatus::keepStrongerStatus('posted', 'processing'));
        $this->assertSame('paid', PaymentStatus::keepStrongerStatus('processing', 'paid'));
        $this->assertSame('posted', PaymentStatus::keepStrongerStatus('paid', 'posted'));

        $webhook = file_get_contents(__DIR__ . '/../../../../Controller/webhook/PayStand.php');
        $this->assertStringContainsString('PaymentStatus::keepStrongerStatus(', $webhook);
    }

    /**
     * @param mixed $windowPricedByServer
     * @param int $clears Times clearCheckoutIntent must run
     * @param mixed $paymentId The payment the request reports
     */
    private function dropFor($windowPricedByServer, int $clears, $paymentId = 'pay0123456789abcdef'): void
    {
        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $snapshot = $this->getMockBuilder(CaptureSnapshot::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearCheckoutIntent'])
            ->getMock();
        $snapshot->expects($this->exactly($clears))->method('clearCheckoutIntent')
            ->with($quote, (string)$paymentId);

        $property = new \ReflectionProperty(SavePaymentData::class, 'captureSnapshot');
        $property->setAccessible(true);
        $property->setValue($this->controller, $snapshot);

        $method = new \ReflectionMethod(SavePaymentData::class, 'dropSnapshotFromEarlierOpen');
        $method->setAccessible(true);
        $method->invoke($this->controller, $quote, $windowPricedByServer, $paymentId);
    }

    /**
     * getquotedata failed, so the snapshot on the quote is from an earlier open.
     */
    public function testWindowPricedByTheBrowserDropsTheEarlierSnapshot(): void
    {
        $this->dropFor(false, 1);
    }

    /**
     * The webhook can record this payment first. The drop must still clear the quote.
     */
    public function testTheDropNamesThePaymentThisRequestReports(): void
    {
        $this->dropFor(false, 1, 'otff1duzi52yjudijnifi8fy');
    }

    public function testWindowPricedByTheServerKeepsItsSnapshot(): void
    {
        $this->dropFor(true, 0);
    }

    /**
     * Checkout JS cached from before this release sends no flag at all.
     */
    public function testNoFlagFromOlderCheckoutJsKeepsTheSnapshot(): void
    {
        $this->dropFor(null, 0);
    }

    public function testEarlierSnapshotIsDroppedBeforeThePaymentIdIsRecorded(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../Controller/Checkout/SavePaymentData.php');

        $drop = strpos($source, '$this->dropSnapshotFromEarlierOpen($quote, $windowPricedByServer, $paymentId)');
        $record = strpos($source, "\$quote->setData('paystand_payment_id', \$paymentId)");
        $this->assertNotFalse($drop);
        $this->assertNotFalse($record);
        $this->assertLessThan($record, $drop);
    }

    /**
     * SavePaymentData stamps through the helper and saves only the Paystand columns.
     * Whole calls are matched, since a bare method name also matches comments.
     */
    public function testSavePaymentDataUsesCopyPaidHelper(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../Controller/Checkout/SavePaymentData.php');

        $this->assertStringContainsString(
            'stampPaidWithoutRecollect($quote)',
            $source
        );
        $this->assertStringContainsString('persistPaystandMarkers($quote)', $source);
        $this->assertStringNotContainsString(
            "copyPaidRecollectAndStamp(\$quote, 'savepaymentdata')",
            $source
        );
        $this->assertStringNotContainsString('$this->captureSnapshot->paidBag', $source);
        $this->assertStringNotContainsString('$this->captureSnapshot->ensureStamped', $source);
        $this->assertStringNotContainsString('$this->quoteShipping->recollectPreservingShipping', $source);
    }

    /**
     * grand_total and customer_email are on the quote but must not be written.
     */
    public function testPersistPaystandMarkersWritesOnlyThePaystandColumns(): void
    {
        $values = [
            'paystand_adjustment' => '-3.00',
            'paystand_payment_id' => 'mp0m4r5x8ovwo0i1btgop38l',
            'paystand_capture_status' => 'posted',
            CaptureSnapshot::QUOTE_FIELD => '{"grand_total":"106.14"}',
            'grand_total' => '109.63',
            'customer_email' => 'shopper@example.com',
        ];

        $connection = $this->getMockBuilder(\Magento\Framework\DB\Adapter\AdapterInterface::class)
            ->getMockForAbstractClass();
        $connection->expects($this->once())->method('update')->with(
            'quote',
            [
                'paystand_adjustment' => '-3.00',
                'paystand_payment_id' => 'mp0m4r5x8ovwo0i1btgop38l',
                'paystand_capture_status' => 'posted',
                'paystand_capture_snapshot' => '{"grand_total":"106.14"}',
            ],
            ['entity_id = ?' => 4277928]
        )->willReturn(1);

        $resource = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');

        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResource', 'getData', 'getId'])
            ->getMock();
        $quote->method('getResource')->willReturn($resource);
        $quote->method('getId')->willReturn('4277928');
        $quote->method('getData')->willReturnCallback(function ($key = '') use ($values) {
            return $values[$key] ?? null;
        });

        $method = new \ReflectionMethod(SavePaymentData::class, 'persistPaystandMarkers');
        $method->setAccessible(true);
        $method->invoke($this->controller, $quote);
    }
}
