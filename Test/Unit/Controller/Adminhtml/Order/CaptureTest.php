<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PayStand\PayStandMagento\Controller\Adminhtml\Order\Capture;
use PayStand\PayStandMagento\Helper\PaystandApi;
use PayStand\PayStandMagento\Helper\PreauthStore;
use PayStand\PayStandMagento\Model\PreauthCaptureGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admin capture sends the full remaining hold once, and stores the result only on success.
 */
class CaptureTest extends TestCase
{
    private const ORDER_ID = 42;
    private const PREAUTH_ID = 'preauth-1';

    /** @var Capture */
    private $controller;

    /** @var PaystandApi|MockObject */
    private $api;

    /** @var ManagerInterface|MockObject */
    private $messages;

    /** @var DataObject */
    private $order;

    /** @var array|null The columns written to sales_order, or null when nothing was written. */
    private $written;

    protected function setUp(): void
    {
        $this->written = null;
        $this->order = new DataObject([
            'id' => self::ORDER_ID,
            'paystand_preauth_id' => self::PREAUTH_ID,
            'paystand_preauth_status' => 'authorized',
            'paystand_preauth_amount' => '10.00',
            'paystand_preauth_amount_captured' => '0',
            'paystand_preauth_amount_remaining' => '10.00',
            'paystand_preauth_currency' => 'USD',
            'paystand_preauth_expires_at' => '2099-01-01 00:00:00'
        ]);

        $this->api = $this->createMock(PaystandApi::class);
        $this->messages = $this->getMockForAbstractClass(ManagerInterface::class);

        $orders = $this->getMockForAbstractClass(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($this->order);

        $this->controller = $this->getMockBuilder(Capture::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->set('orderRepository', $orders);
        $this->set('api', $this->api);
        $this->set('preauthStore', new PreauthStore($this->resourceRecordingWrites()));
        $this->set('guard', new PreauthCaptureGuard());
        $this->set('messageManager', $this->messages);
        $this->set('resultRedirectFactory', $this->redirectFactory());
        $this->set('_request', $this->request());
    }

    public function testExpiredHoldDoesNotCallCapture(): void
    {
        $this->order->setData('paystand_preauth_expires_at', '2000-01-01 00:00:00');
        $this->api->expects($this->never())->method('post');

        $this->controller->execute();
    }

    public function testExpiredHoldLeavesTheOrderUnchanged(): void
    {
        $this->order->setData('paystand_preauth_expires_at', '2000-01-01 00:00:00');

        $this->controller->execute();

        $this->assertNull($this->written);
    }

    public function testCaptureSendsTheFullRemainingAmount(): void
    {
        $this->api->expects($this->once())
            ->method('post')
            ->with('/preAuths/' . self::PREAUTH_ID . '/capture', ['amount' => '10.00'])
            ->willReturn($this->rejection());

        $this->controller->execute();
    }

    public function testRejectionIsShownInAdmin(): void
    {
        $this->api->method('post')->willReturn($this->rejection());
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Card declined.');

        $this->controller->execute();
    }

    public function testRejectionLeavesTheOrderUnchanged(): void
    {
        $this->api->method('post')->willReturn($this->rejection());

        $this->controller->execute();

        $this->assertNull($this->written);
    }

    public function testCaptureStoresThePaymentIdFromThePipelineResult(): void
    {
        $this->api->method('post')->willReturn($this->success([
            'preAuth' => $this->capturedRecord(),
            'payment' => ['id' => 'pay-1']
        ]));

        $this->controller->execute();

        $this->assertSame('pay-1', $this->written['paystand_payment_id']);
    }

    public function testCaptureStoresThePaymentIdFromAPreAuthRecord(): void
    {
        $this->api->method('post')->willReturn($this->success($this->capturedRecord() + ['paymentId' => 'pay-2']));

        $this->controller->execute();

        $this->assertSame('pay-2', $this->written['paystand_payment_id']);
    }

    public function testCaptureStoresTheStatusPaystandReturns(): void
    {
        $this->api->method('post')->willReturn($this->success(['preAuth' => $this->capturedRecord()]));

        $this->controller->execute();

        $this->assertSame('captured', $this->written['paystand_preauth_status']);
    }

    public function testCaptureStoresTheRemainingAmountPaystandReturns(): void
    {
        $this->api->method('post')->willReturn($this->success(['preAuth' => $this->capturedRecord()]));

        $this->controller->execute();

        $this->assertEquals(0, $this->written['paystand_preauth_amount_remaining']);
    }

    private function capturedRecord(): array
    {
        return [
            'id' => self::PREAUTH_ID,
            'status' => 'captured',
            'amount' => 10.0,
            'amountCaptured' => 10.0,
            'currency' => 'USD',
            'expiresAt' => '2099-01-01 00:00:00'
        ];
    }

    private function success(array $body): array
    {
        return ['ok' => true, 'body' => $body, 'error' => ''];
    }

    private function rejection(): array
    {
        return ['ok' => false, 'body' => [], 'error' => 'Card declined.'];
    }

    private function resourceRecordingWrites(): ResourceConnection
    {
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function ($table, array $bind) {
            $this->written = $bind;
            return 1;
        });

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    private function redirectFactory(): RedirectFactory
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();

        $factory = $this->createMock(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        return $factory;
    }

    private function request(): RequestInterface
    {
        $request = $this->getMockForAbstractClass(RequestInterface::class);
        $request->method('getParam')->with('order_id')->willReturn((string) self::ORDER_ID);

        return $request;
    }

    private function set(string $name, $value): void
    {
        $class = new \ReflectionClass($this->controller);
        while ($class) {
            if ($class->hasProperty($name)) {
                $prop = $class->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($this->controller, $value);
                return;
            }
            $class = $class->getParentClass() ?: null;
        }
        $this->fail('No property ' . $name);
    }
}
