<?php

namespace PayStand\PayStandMagento\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PayStand\PayStandMagento\Model\PaymentAmountGate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentAmountGateTest extends TestCase
{
    /** @var PaymentAmountGate */
    private $gate;

    protected function setUp(): void
    {
        $this->gate = new PaymentAmountGate();
    }

    public function testMatchingChargeIsInvoiced(): void
    {
        $decision = $this->gate->decide(109.63, 109.63, 'USD', 'USD', 0, true, [
            'amount' => '109.63',
            'currency' => 'usd',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(PaymentAmountGate::REASON_MATCH, $decision['reason']);
    }

    public function testOneCentDifferenceIsInvoiced(): void
    {
        $decision = $this->gate->decide(109.63, 109.63, 'USD', 'USD', 0, true, [
            'amount' => '109.64',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
    }

    public function testTaxAddressMismatchHoldsAndDoesNotInvoice(): void
    {
        $decision = $this->gate->decide(109.63, 109.63, 'USD', 'USD', 0, true, [
            'amount' => '106.14',
            'currency' => 'USD',
            'settlementAmount' => '109.63',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_HOLD, $decision['action']);
        $this->assertSame(PaymentAmountGate::REASON_MISMATCH, $decision['reason']);
        $this->assertSame(106.14, $decision['charged']);
        $this->assertSame(109.63, $decision['expected']);
        $comment = $this->gate->comment($decision, 'mp0m4r5x8ovwo0i1btgop38l');
        $this->assertStringContainsString('Paystand charged 106.14 USD', $comment);
        $this->assertStringContainsString('The order total is 109.63 USD', $comment);
    }

    public function testMissingAmountHoldsAsUnverified(): void
    {
        $decision = $this->gate->decide(10, 10, 'USD', 'USD', 0, true, [
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_HOLD, $decision['action']);
        $this->assertSame(PaymentAmountGate::REASON_UNVERIFIED, $decision['reason']);
    }

    public function testAdjustmentIsAddedOnceWhenItIsNotAlreadyInTheTotal(): void
    {
        $decision = $this->gate->decide(100.00, 100.00, 'USD', 'USD', 2.50, false, [
            'amount' => '102.50',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(102.50, $decision['expected']);
    }

    public function testAdjustmentAlreadyInTheTotalIsNotAddedAgain(): void
    {
        $decision = $this->gate->decide(102.50, 102.50, 'USD', 'USD', 2.50, true, [
            'amount' => '102.50',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(102.50, $decision['expected']);
    }

    public function testDisplayAmountUsesThePaystandAmount(): void
    {
        $this->assertSame(106.14, $this->gate->displayAmount([
            'amount' => '106.14',
        ], 109.63));
    }

    public function testTaxAddressMismatchWithAFeeSplitHoldsAndPrintsTheOrderTotal(): void
    {
        $decision = $this->gate->decide(109.63, 109.63, 'USD', 'USD', 0, true, [
            'amount' => '106.14',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '106.14', 'payerTotal' => '106.14', 'currency' => 'USD'],
        ]);

        $this->assertSame(PaymentAmountGate::REASON_MISMATCH, $decision['reason']);
        $this->assertSame(109.63, $decision['expected']);
        $comment = $this->gate->comment($decision, 'mp0m4r5x8ovwo0i1btgop38l');
        $this->assertStringContainsString(
            'Paystand charged 106.14 USD. The order total is 109.63 USD. Payment mp0m4r5x8ovwo0i1btgop38l.',
            $comment
        );
    }

    /**
     * With the adjustment setting off, the order total has no payer fee in it.
     */
    public function testPaystandPayerFeeMatchesWhenTheOrderHasNoAdjustment(): void
    {
        $decision = $this->gate->decide(100.00, 100.00, 'USD', 'USD', 0, true, [
            'amount' => '103.20',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '100.00', 'payerTotal' => '103.20', 'payerTotalFees' => '3.20'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(103.20, $decision['expected']);
    }

    public function testRescuedOrderWithoutAnAdjustmentMatchesOnThePaystandFee(): void
    {
        $decision = $this->gate->decide(100.00, 100.00, 'USD', 'USD', null, true, [
            'amount' => '103.20',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '100.00', 'payerTotal' => '103.20'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
    }

    public function testPaystandFeeReplacesTheBrowserAdjustment(): void
    {
        $decision = $this->gate->decide(103.00, 103.00, 'USD', 'USD', 3.00, true, [
            'amount' => '103.18',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '100.00', 'payerTotal' => '103.18'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(103.18, $decision['expected']);
    }

    public function testPaystandDiscountIsTakenOffTheExpectedPayment(): void
    {
        $decision = $this->gate->decide(97.00, 97.00, 'USD', 'USD', -3.00, true, [
            'amount' => '97.00',
            'currency' => 'USD',
            'feeSplit' => [
                'subtotal' => '100.00',
                'payerTotal' => '97.00',
                'payerDiscount' => '3.00',
                'discount' => '3.00',
            ],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(97.00, $decision['expected']);
    }

    /**
     * A forged payer discount lowers the order total to the charge. The fee split has none.
     */
    public function testBrowserDiscountThatPaystandDidNotGiveIsHeld(): void
    {
        $decision = $this->gate->decide(60.00, 60.00, 'USD', 'USD', -40.00, true, [
            'amount' => '60.00',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '60.00', 'payerTotal' => '60.00', 'payerDiscount' => '0.00'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_HOLD, $decision['action']);
        $this->assertSame(PaymentAmountGate::REASON_MISMATCH, $decision['reason']);
        $this->assertSame(100.00, $decision['expected']);
        $comment = $this->gate->comment($decision, 'pay-1');
        $this->assertStringContainsString('Paystand charged 60.00 USD. The order total is 60.00 USD.', $comment);
        $this->assertStringContainsString('the expected payment is 100.00 USD', $comment);
    }

    public function testFeeSplitWithoutAmountsUsesTheOrderTotal(): void
    {
        $decision = $this->gate->decide(102.50, 102.50, 'USD', 'USD', 2.50, true, [
            'amount' => '102.50',
            'currency' => 'USD',
            'feeSplit' => ['feeSplitType' => 'recoup_all_fees'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(102.50, $decision['expected']);
    }

    public function testFeeSplitInAnotherCurrencyIsNotUsed(): void
    {
        $decision = $this->gate->decide(103.00, 103.00, 'USD', 'USD', 3.00, true, [
            'amount' => '103.00',
            'currency' => 'USD',
            'feeSplit' => ['subtotal' => '90.00', 'payerTotal' => '100.00', 'currency' => 'EUR'],
        ]);

        $this->assertSame(PaymentAmountGate::ACTION_INVOICE, $decision['action']);
        $this->assertSame(103.00, $decision['expected']);
    }

    public function testHoldPutsTheOrderOnHoldAndRecordsBothAmounts(): void
    {
        $order = $this->buildOrder(Order::STATE_PROCESSING);
        $order->method('canHold')->willReturn(true);
        $order->expects($this->once())->method('hold')->willReturnSelf();
        $order->expects($this->once())->method('addCommentToStatusHistory')->with(
            $this->callback(function ($comment) {
                return strpos((string)$comment, 'Paystand charged 106.14 USD. The order total is 109.63 USD.') !== false;
            }),
            false
        );
        $order->expects($this->once())->method('save');

        $this->gate->hold($order, $this->mismatch(), 'mp0m4r5x8ovwo0i1btgop38l');
    }

    public function testHoldSetsTheHoldStateWhenMagentoCannotHold(): void
    {
        $order = $this->buildOrder('pending_payment');
        $order->method('canHold')->willReturn(false);
        $order->expects($this->never())->method('hold');
        $order->expects($this->once())->method('setState')->with(Order::STATE_HOLDED);
        $order->expects($this->once())->method('setStatus')->with(Order::STATE_HOLDED);
        $order->expects($this->once())->method('save');

        $this->gate->hold($order, $this->mismatch(), 'pay-1');
    }

    public function testHoldSetsTheHoldStateWhenMagentoHoldThrows(): void
    {
        $order = $this->buildOrder(Order::STATE_PROCESSING);
        $order->method('canHold')->willReturn(true);
        $order->method('hold')->willThrowException(new LocalizedException(__('A hold action is not available.')));
        $order->expects($this->once())->method('setState')->with(Order::STATE_HOLDED);
        $order->expects($this->once())->method('setStatus')->with(Order::STATE_HOLDED);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $order->expects($this->once())->method('save');

        $this->gate->hold($order, $this->mismatch(), 'pay-1');
    }

    public function testHoldLeavesAnOrderThatIsAlreadyOnHold(): void
    {
        $order = $this->buildOrder(Order::STATE_HOLDED);
        $order->expects($this->never())->method('hold');
        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('addCommentToStatusHistory');
        $order->expects($this->never())->method('save');

        $this->gate->hold($order, $this->mismatch(), 'pay-1');
    }

    private function mismatch(): array
    {
        return $this->gate->decide(109.63, 109.63, 'USD', 'USD', 0, true, [
            'amount' => '106.14',
            'currency' => 'USD',
        ]);
    }

    /** @return Order|MockObject */
    private function buildOrder(string $state)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getState', 'canHold', 'hold', 'setState', 'setStatus',
                'addCommentToStatusHistory', 'save', 'getQuoteId',
            ])
            ->getMock();
        $order->method('getState')->willReturn($state);
        $order->method('getQuoteId')->willReturn(4277928);
        return $order;
    }
}
