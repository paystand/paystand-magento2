<?php

namespace PayStand\PayStandMagento\Test\Unit\Model;

use PayStand\PayStandMagento\Model\CheckoutQuoteMemo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Model\CheckoutQuoteMemo. It keeps the first quote id it sees, so the success
 * validator can name the ordered quote after Magento nulls it.
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
     * getQuote() nulls the quote id and creates a new cart, so a later id is the wrong one.
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
