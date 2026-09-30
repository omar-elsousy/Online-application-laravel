<?php

namespace Tests\Unit;

use App\Services\Incentive\MixRuleCalculator;
use PHPUnit\Framework\TestCase;

class MixRuleCalculatorTest extends TestCase
{
    public function test_percentage_discount_accepts_decimal_rule_quantity_and_applies_to_line_total(): void
    {
        $rule = (object) [
            'from_sales_val' => 0.1,
            'to_sales_val' => 99999999,
            'qty' => 0.1,
            'val' => 0,
            'gift_product_id' => 0,
            'discount_perc' => 6,
        ];

        $oneUnit = MixRuleCalculator::evaluate([
            'quantity' => 1,
            'unit_price' => 64,
            'line_price' => 64,
        ], $rule);
        $twoUnits = MixRuleCalculator::evaluate([
            'quantity' => 2,
            'unit_price' => 64,
            'line_price' => 128,
        ], $rule);

        $this->assertSame(3.84, $oneUnit['discount']);
        $this->assertSame(7.68, $twoUnits['discount']);
    }

    public function test_fixed_amount_rule_keeps_its_quantity_multiplier(): void
    {
        $rule = (object) [
            'from_sales_val' => 2,
            'to_sales_val' => 3.9,
            'qty' => 2,
            'val' => 20,
            'gift_product_id' => 0,
            'discount_perc' => 0,
        ];

        $result = MixRuleCalculator::evaluate([
            'quantity' => 2,
            'unit_price' => 64,
            'line_price' => 128,
        ], $rule);

        $this->assertSame(20.0, $result['discount']);
    }

    public function test_fractional_cart_quantity_matches_fractional_fixed_amount_tier(): void
    {
        // Existing database incentive 1632: product 30000, 0.5-0.9 qty tier,
        // 0.5 required quantity and 10 EGP fixed discount.
        $rule = (object) [
            'from_sales_val' => 0.5,
            'to_sales_val' => 0.9,
            'qty' => 0.5,
            'val' => 10,
            'gift_product_id' => 0,
            'discount_perc' => 0,
        ];

        $result = MixRuleCalculator::evaluate([
            'quantity' => 0.5,
            'unit_price' => 1016,
            'line_price' => 508,
        ], $rule);

        $this->assertSame(1, $result['multiplier']);
        $this->assertSame(10.0, $result['discount']);
    }
}
