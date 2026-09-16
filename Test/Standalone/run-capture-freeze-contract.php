<?php

declare(strict_types=1);

namespace Psr\Log {
    if (!interface_exists('Psr\Log\LoggerInterface')) {
        interface LoggerInterface
        {
            public function emergency($message, array $context = []);
            public function alert($message, array $context = []);
            public function critical($message, array $context = []);
            public function error($message, array $context = []);
            public function warning($message, array $context = []);
            public function notice($message, array $context = []);
            public function info($message, array $context = []);
            public function debug($message, array $context = []);
            public function log($level, $message, array $context = []);
        }
    }

    class CaptureFreezeNullLogger implements LoggerInterface
    {
        public function emergency($message, array $context = [])
        {
        }

        public function alert($message, array $context = [])
        {
        }

        public function critical($message, array $context = [])
        {
        }

        public function error($message, array $context = [])
        {
        }

        public function warning($message, array $context = [])
        {
        }

        public function notice($message, array $context = [])
        {
        }

        public function info($message, array $context = [])
        {
        }

        public function debug($message, array $context = [])
        {
        }

        public function log($level, $message, array $context = [])
        {
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    function expectCaptureFreeze(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function captureSource(string $root, string $relative): string
    {
        $contents = file_get_contents($root . '/' . $relative);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $relative);
        }
        return $contents;
    }

    use PayStand\PayStandMagento\Helper\CaptureFingerprint;

    require_once $root . '/Helper/CaptureFingerprint.php';

    /**
     * Stand-ins for the quote parts the fingerprint reads. The helper takes no Magento
     * types, so the contract exercises the real class rather than asserting on source.
     */
    function captureFreezeQuote(array $skuQty, array $destination = ['US', 'CA', '95060', 'Santa Cruz'], float $price = 10.0)
    {
        $items = [];
        foreach ($skuQty as $sku => $qty) {
            $items[] = new class ((string)$sku, (float)$qty, $price) {
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

            public function getRegionId()
            {
                return $this->parts[1] ?? '';
            }

            public function getRegion()
            {
                return $this->parts[1] ?? '';
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

    $helper = new CaptureFingerprint(new \Psr\Log\CaptureFreezeNullLogger());

    // A quote that still holds the cart it was paid for keeps its totals frozen.
    $paid = captureFreezeQuote(['SKU-1' => 2.0, 'SKU-2' => 1.0]);
    $stamp = $helper->stamp($paid);
    expectCaptureFreeze($stamp !== '', 'Capture must stamp a fingerprint');
    expectCaptureFreeze($paid->getData(CaptureFingerprint::QUOTE_FIELD) === $stamp,
        'Fingerprint must be recorded on the quote');
    expectCaptureFreeze($helper->matchesCapture($paid), 'Unchanged cart must keep its freeze');

    // Every shopper change to the cart releases it: the paid cart's rates no longer
    // describe this one, and Magento must be free to price the new one.
    foreach ([
        'added item'      => [['SKU-1' => 2.0, 'SKU-2' => 1.0, 'SKU-3' => 1.0], ['US', 'CA', '95060', 'Santa Cruz']],
        'removed item'    => [['SKU-1' => 2.0], ['US', 'CA', '95060', 'Santa Cruz']],
        'quantity change' => [['SKU-1' => 3.0, 'SKU-2' => 1.0], ['US', 'CA', '95060', 'Santa Cruz']],
        'new destination' => [['SKU-1' => 2.0, 'SKU-2' => 1.0], ['US', 'NY', '10001', 'New York']],
    ] as $label => $change) {
        $changed = captureFreezeQuote($change[0], $change[1]);
        $changed->setData(CaptureFingerprint::QUOTE_FIELD, $stamp);
        expectCaptureFreeze(!$helper->matchesCapture($changed), 'Freeze must release on ' . $label);
    }

    // The case the freeze exists for: a cart rule stops qualifying and the price moves
    // on its own. That is not a shopper change, so the freeze has to hold.
    $atCapture = captureFreezeQuote(['SKU-1' => 2.0], ['US', 'CA', '95060', 'Santa Cruz'], 10.0);
    $repriced = captureFreezeQuote(['SKU-1' => 2.0], ['US', 'CA', '95060', 'Santa Cruz'], 12.5);
    expectCaptureFreeze($helper->forQuote($atCapture) === $helper->forQuote($repriced),
        'Price must not take part in the fingerprint');

    $one = captureFreezeQuote(['SKU-1' => 1.0, 'SKU-2' => 2.0]);
    $other = captureFreezeQuote(['SKU-2' => 2.0, 'SKU-1' => 1.0]);
    expectCaptureFreeze($helper->forQuote($one) === $helper->forQuote($other),
        'Item order must not change the fingerprint');

    // A quote captured before the column existed carries no stamp and cannot show the
    // cart is the one that was paid for, so it collects totals as normal.
    $legacy = captureFreezeQuote(['SKU-1' => 2.0]);
    expectCaptureFreeze(!$helper->matchesCapture($legacy), 'Unstamped capture must not hold a freeze');

    // Stamping runs inside the capture write, so its failure must not stop a capture
    // from being recorded.
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
    expectCaptureFreeze($helper->stamp($broken) === '', 'A failed stamp must stay contained');

    // The release must cost the shopper nothing but the freeze: clearing the payment id
    // would drop the re-charge lock that QuotePaymentStatus reports as alreadyPaid.
    $plugin = captureSource($root, 'Plugin/CapturedQuoteTotals.php');
    expectCaptureFreeze(strpos($plugin, 'matchesCapture') !== false,
        'Plugin must gate the freeze on the fingerprint');
    expectCaptureFreeze(strpos($plugin, 'EVENT_CAPTURE_FREEZE_RELEASED') !== false,
        'A released freeze must be reported');
    expectCaptureFreeze(strpos($plugin, "setData('paystand_payment_id'") === false
        && strpos($plugin, "setData('paystand_capture_status'") === false,
        'Plugin must never clear the capture markers');

    // Both writers of the markers must stamp the cart alongside them, or the capture
    // they record can never be matched to a cart again.
    foreach (['Controller/Checkout/SavePaymentData.php', 'Controller/webhook/PayStand.php'] as $writer) {
        $writerSource = captureSource($root, $writer);
        expectCaptureFreeze(strpos($writerSource, 'captureFingerprint->stamp(') !== false,
            'Capture writer must stamp the cart fingerprint: ' . $writer);
    }

    echo json_encode([
        'ok' => true,
        'unchangedCartStaysFrozen' => true,
        'shopperChangeReleases' => true,
        'priceMoveHolds' => true,
        'unstampedCaptureCollects' => true,
        'markersNeverCleared' => true,
        'bothWritersStamp' => true,
        'remoteWrites' => 0
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
