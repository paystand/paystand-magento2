<?php

namespace PayStand\PayStandMagento\Test\Unit\Helper;

use PayStand\PayStandMagento\Helper\CaptureFingerprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Helper\CaptureFingerprint — decides whether a quote still holds the
 * cart its capture was taken on, which is what keeps the totals freeze honest.
 *
 * The freeze protects the amount the shopper was charged. It must therefore survive a
 * cart rule expiring on its own, and must not survive the shopper changing the cart.
 */
class CaptureFingerprintTest extends TestCase
{
    /** @var CaptureFingerprint */
    private $helper;

    protected function setUp(): void
    {
        $this->helper = new CaptureFingerprint(
            $this->getMockBuilder(LoggerInterface::class)->getMockForAbstractClass()
        );
    }

    /**
     * @param array<string, float> $skuQty  sku => qty
     * @param array<int, string> $destination country, region, postcode, city.
     *        Region is carried but never fingerprinted, so the helper must not read it.
     * @param float $price Carried but never fingerprinted
     */
    private function quote(array $skuQty, array $destination = ['US', 'CA', '95060', 'Santa Cruz'], $price = 10.0)
    {
        $items = [];
        foreach ($skuQty as $sku => $qty) {
            $items[] = new class ((string)$sku, $qty, $price) {
                private $sku;
                private $qty;
                private $price;

                public function __construct($sku, $qty, $price)
                {
                    $this->sku = $sku;
                    $this->qty = $qty;
                    $this->price = $price;
                }

                public function getSku()
                {
                    return $this->sku;
                }

                public function getQty()
                {
                    return $this->qty;
                }

                public function getPrice()
                {
                    return $this->price;
                }
            };
        }

        $address = new class ($destination) {
            private $parts;

            public function __construct($parts)
            {
                $this->parts = $parts;
            }

            public function getCountryId()
            {
                return $this->parts[0] ?? '';
            }

            public function getPostcode()
            {
                return $this->parts[2] ?? '';
            }

            public function getCity()
            {
                return $this->parts[3] ?? '';
            }
        };

        return new class ($items, $address) {
            public $data = [];
            private $items;
            private $address;

            public function __construct($items, $address)
            {
                $this->items = $items;
                $this->address = $address;
            }

            public function getAllVisibleItems()
            {
                return $this->items;
            }

            public function isVirtual()
            {
                return false;
            }

            public function getShippingAddress()
            {
                return $this->address;
            }

            public function getBillingAddress()
            {
                return $this->address;
            }

            public function getId()
            {
                return 4267713;
            }

            public function getData($key)
            {
                return $this->data[$key] ?? null;
            }

            public function setData($key, $value)
            {
                $this->data[$key] = $value;
                return $this;
            }
        };
    }

    public function testStampRecordsTheFingerprintOnTheQuote(): void
    {
        $quote = $this->quote(['SKU-1' => 2.0]);

        $stamped = $this->helper->stamp($quote);

        $this->assertNotSame('', $stamped);
        $this->assertSame($stamped, $quote->getData(CaptureFingerprint::QUOTE_FIELD));
    }

    public function testUnchangedCartMatchesItsCapture(): void
    {
        $quote = $this->quote(['SKU-1' => 2.0, 'SKU-2' => 1.0]);
        $this->helper->stamp($quote);

        $this->assertTrue($this->helper->matchesCapture($quote));
    }

    /**
     * The stuck-cart case: the shopper added an item after paying, so the rates the
     * freeze was holding no longer describe this cart.
     */
    public function testAddedItemDoesNotMatch(): void
    {
        $paid = $this->quote(['SKU-1' => 2.0]);
        $stamp = $this->helper->stamp($paid);

        $changed = $this->quote(['SKU-1' => 2.0, 'SKU-2' => 1.0]);
        $changed->setData(CaptureFingerprint::QUOTE_FIELD, $stamp);

        $this->assertFalse($this->helper->matchesCapture($changed));
    }

    public function testQuantityChangeDoesNotMatch(): void
    {
        $paid = $this->quote(['SKU-1' => 2.0]);
        $stamp = $this->helper->stamp($paid);

        $changed = $this->quote(['SKU-1' => 3.0]);
        $changed->setData(CaptureFingerprint::QUOTE_FIELD, $stamp);

        $this->assertFalse($this->helper->matchesCapture($changed));
    }

    /**
     * Rates are quoted per destination, so a new one invalidates the capture the same
     * way a new item does.
     */
    public function testNewDestinationDoesNotMatch(): void
    {
        $paid = $this->quote(['SKU-1' => 2.0]);
        $stamp = $this->helper->stamp($paid);

        $changed = $this->quote(['SKU-1' => 2.0], ['US', 'NY', '10001', 'New York']);
        $changed->setData(CaptureFingerprint::QUOTE_FIELD, $stamp);

        $this->assertFalse($this->helper->matchesCapture($changed));
    }

    /**
     * The freeze exists for exactly this: a cart rule stops qualifying after capture
     * and the price moves on its own. That is not a shopper change, so it must hold.
     */
    public function testPriceIsNotPartOfTheFingerprint(): void
    {
        $atCapture = $this->quote(['SKU-1' => 2.0], ['US', 'CA', '95060', 'Santa Cruz'], 10.0);
        $repriced = $this->quote(['SKU-1' => 2.0], ['US', 'CA', '95060', 'Santa Cruz'], 12.5);

        $this->assertSame($this->helper->forQuote($atCapture), $this->helper->forQuote($repriced));
    }

    public function testItemOrderDoesNotChangeTheFingerprint(): void
    {
        $one = $this->quote(['SKU-1' => 1.0, 'SKU-2' => 2.0]);
        $other = $this->quote(['SKU-2' => 2.0, 'SKU-1' => 1.0]);

        $this->assertSame($this->helper->forQuote($one), $this->helper->forQuote($other));
    }

    /**
     * A quote captured before this column existed carries no stamp, so it cannot show
     * the cart is the one that was paid for. Those quotes collect totals as normal.
     */
    public function testQuoteWithNoStampDoesNotMatch(): void
    {
        $quote = $this->quote(['SKU-1' => 2.0]);

        $this->assertFalse($this->helper->matchesCapture($quote));
    }

    /**
     * Stamping runs inside the capture write, so a failure there must not stop a
     * capture being recorded.
     */
    public function testStampFailureIsContained(): void
    {
        $broken = new class {
            public function getAllVisibleItems()
            {
                throw new \Error('boom');
            }

            public function getId()
            {
                return 4267713;
            }
        };

        $this->assertSame('', $this->helper->stamp($broken));
    }
}
