<?php

namespace PayStand\PayStandMagento\Test\Unit\Model;

use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Model\CheckoutQuoteMemo.
 *
 * The memo exists so the success validator can still name the quote after
 * Magento has nulled it. Its whole contract is "first non-empty id wins" — a
 * last-write-wins memo would hand back the replacement cart's id instead of the
 * one that was ordered, and the repair would restore the wrong order.
 */
class CheckoutQuoteMemoTest extends TestCase
{
    /** @var CheckoutQuoteMemo */
    private $memo;

    protected function setUp(): void
    {
        $this->memo = new CheckoutQuoteMemo();
    }

    public function testStartsEmpty(): void
    {
        $this->assertSame(0, $this->memo->get());
    }

    public function testRemembersTheFirstQuoteId(): void
    {
        $this->memo->remember(4189563);

        $this->assertSame(4189563, $this->memo->get());
    }

    /**
     * getQuote() nulls the session's quote id and then creates a replacement
     * cart, so a later id is the wrong one to keep.
     */
    public function testLaterQuoteIdsDoNotDisplaceTheFirst(): void
    {
        $this->memo->remember(4189563);
        $this->memo->remember(4189999);

        $this->assertSame(4189563, $this->memo->get());
    }

    /**
     * The clear itself arrives as null/0 and must not count as a value.
     */
    public function testEmptyValuesAreIgnored(): void
    {
        $this->memo->remember(null);
        $this->memo->remember(0);
        $this->memo->remember('');

        $this->assertSame(0, $this->memo->get());

        $this->memo->remember(777);
        $this->memo->remember(null);

        $this->assertSame(777, $this->memo->get());
    }

    /**
     * The session hands back the id as a string on some paths.
     */
    public function testNumericStringsAreNormalizedToInt(): void
    {
        $this->memo->remember('4189563');

        $this->assertSame(4189563, $this->memo->get());
    }

    public function testNegativeIdsAreIgnored(): void
    {
        $this->memo->remember(-5);

        $this->assertSame(0, $this->memo->get());
    }
}
