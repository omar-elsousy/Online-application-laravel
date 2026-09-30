<?php

namespace App\Services\Incentive;

class MixRuleCalculator
{
    /**
     * Calculate a MIX rule for one cart line. Percentage discounts apply to
     * the eligible line total; qty is a decimal rule field and must not be
     * truncated to an integer before checking whether the rule is usable.
     */
    public static function evaluate(array $line, object $rule): ?array
    {
        $quantity = (float) $line['quantity'];
        if ($quantity < (float) $rule->from_sales_val || $quantity > (float) $rule->to_sales_val) {
            return null;
        }

        $ruleQuantity = (float) $rule->qty;
        if ($ruleQuantity <= 0) {
            return null;
        }

        if ((int) $rule->gift_product_id !== 0) {
            $multiplier = (int) floor($quantity / $ruleQuantity);
            if ($multiplier < 1) {
                return null;
            }

            return ['multiplier' => $multiplier];
        }

        $discountPercentage = (float) $rule->discount_perc;
        if ($discountPercentage !== 0.0) {
            return [
                'multiplier' => 1,
                'discount' => round((float) $line['line_price'] * $discountPercentage / 100, 2),
            ];
        }

        $multiplier = (int) floor($quantity / $ruleQuantity);
        if ($multiplier < 1) {
            return null;
        }

        return [
            'multiplier' => $multiplier,
            'discount' => $multiplier * (float) $rule->val,
        ];
    }
}
