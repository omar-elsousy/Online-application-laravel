<?php

namespace App\Services\Incentive;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncentiveService
{
    public function posCodeForUser(object $user): ?string
    {
        $pos = DB::connection('oracle_lmidc')->table('pos')->where('mobile', $user->mobile)->first();
        return $pos ? $pos->ter_id . '_' . $pos->pos_id : null;
    }

    public function previewForUser(object $user, Collection $cartItems, array $walletCredits = [], array $removedGiftIncentives = [], string $locale = 'en'): array
    {
        $posCode = $this->posCodeForUser($user);
        $lines = $this->pricedLines($cartItems);
        $mixes = $posCode ? $this->evaluateMixes($posCode, $lines, $removedGiftIncentives, $locale) : ['discounts' => [], 'gifts' => [], 'applied_ids' => [], 'banners' => []];

        foreach ($lines as &$line) {
            $line['incentive_banner'] = $mixes['banners'][$line['product_id']] ?? null;
            $line['discount'] = round($mixes['discounts'][$line['product_id']] ?? 0, 2);
            $line['discount'] = min($line['discount'], $line['line_price']);
            $line['tax'] = round(($line['line_price'] - $line['discount']) * ($line['tax_percentage'] / 100), 2);
            $line['total'] = round($line['line_price'] - $line['discount'] + $line['tax'], 2);
        }
        unset($line);

        $gifts = $this->giftLines($mixes['gifts']);
        $subtotal = round(collect($lines)->sum('line_price') + collect($gifts)->sum('line_price'), 2);
        $discount = round(collect($lines)->sum('discount') + collect($gifts)->sum('discount'), 2);
        $tax = round(collect($lines)->sum('tax'), 2);
        $preWallet = round($subtotal - $discount + $tax, 2);
        $wallet = round(collect($walletCredits)->sum('incentive_value'), 2);
        $wallet = $preWallet > $wallet ? $wallet : 0;

        return [
            'pos_code' => $posCode,
            'items' => $lines,
            'gift_items' => $gifts,
            'applied_incentive_ids' => array_values(array_unique($mixes['applied_ids'])),
            'totals' => [
                'subtotal' => $subtotal,
                'total_discount' => $discount,
                'total_tax' => $tax,
                'wallet_used' => $wallet,
                'grand_total' => round($preWallet - $wallet, 2),
                'pre_wallet_total' => $preWallet,
            ],
        ];
    }

    public function teasersForProducts(object $user, array $productIds, string $locale = 'en', bool $includeGiftName = false): array
    {
        $posCode = $this->posCodeForUser($user);
        if (!$posCode || !$productIds) return [];
        $seen = $this->seenIncentiveIdsForPos($posCode);
        if (!$seen) return [];
        $used = DB::connection('oracle_lmidc')->table('online_app_mix_log')->where('pos_code', $posCode)->where('status', 'USED')->pluck('incentive_id')->all();
        $rules = DB::connection('oracle_lmidc')->table('online_app_v_mix_active')
            ->whereIn('incentive_id', $seen)->whereIn('product_id', $productIds)
            ->orderBy('product_id')->orderBy('from_sales_val')->get();
        $giftProductIds = $rules->where('gift_product_id', '!=', 0)->pluck('gift_product_id')->unique()->values();
        $giftNames = $giftProductIds->isEmpty()
            ? collect()
            : DB::connection('oracle_lmidc')->table('to_sfa_products_android')
                ->whereIn('product_id', $giftProductIds)->pluck('product_ename', 'product_id');
        $teasers = [];
        foreach ($rules as $rule) {
            $productId = (string) $rule->product_id;
            if (isset($teasers[$productId])) continue;
            $taxProducts = array_map('trim', explode(',', (string) $rule->tax_prods));
            if (!in_array($productId, $taxProducts, true)) continue;
            if ((int) $rule->repeated === 0 && in_array($rule->incentive_id, $used)) continue;
            if ((int) $rule->gift_product_id !== 0) {
                $giftLabel = $locale === 'ar' ? 'هدية' : 'Gift';
                $giftName = $giftNames[(string) $rule->gift_product_id] ?? $giftNames[$rule->gift_product_id] ?? null;
                $teasers[$productId] = $includeGiftName && $giftName
                    ? $giftLabel . ($locale === 'ar' ? ': ' : ': ') . $giftName
                    : $giftLabel;
            } elseif ((float) $rule->discount_perc !== 0.0) {
                $teasers[$productId] = $locale === 'ar'
                    ? 'خصم ' . $rule->discount_perc . '%'
                    : 'Discount ' . $rule->discount_perc . '%';
            } else {
                $teasers[$productId] = $locale === 'ar'
                    ? 'خصم ' . $rule->val . ' ج.م'
                    : $rule->val . ' EGP off';
            }
        }
        return $teasers;
    }
    public function availableWallet(object $user, ?array $cartProductIds = null): Collection
    {
        $posCode = $this->posCodeForUser($user);
        if (!$posCode) return collect();
        [$terId, $posId] = explode('_', $posCode, 2);

        $cartProductIds ??= DB::connection('oracle_sales')->table('cart_online_app')
            ->where('user_id', $user->id)
            ->pluck('product_id')
            ->all();
        $cartProductIds = collect($cartProductIds)
            ->map(fn ($productId) => trim((string) $productId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (!$cartProductIds) return collect();

        return DB::connection('oracle_lmidc')->table('incentive_assigning as ia')
            ->join('incentive_types as it', 'it.incentive_id', '=', 'ia.incentive_type_id')
            ->where('ia.ter_id', $terId)->where('ia.pos_id', $posId)
            ->where('ia.recieved_flag', 0)->where('it.active_inc', 1)->where('it.incentive_type', 'FIX')
            ->whereRaw('SYSDATE BETWEEN ia.from_date AND ia.to_date')
            ->select('ia.incentive_type_id', 'ia.incentive_value', 'ia.from_date', 'ia.to_date', 'it.tax_prods')
            ->orderBy('ia.to_date')
            ->get()
            ->filter(function ($credit) use ($cartProductIds) {
                $eligibleProductIds = array_map(
                    'trim',
                    explode(',', (string) ($credit->tax_prods ?? ''))
                );
                return count(array_intersect($cartProductIds, $eligibleProductIds)) > 0;
            })
            ->map(function ($credit) {
                unset($credit->tax_prods);
                return $credit;
            })
            ->values();
    }

    private function seenIncentiveIdsForPos(string $posCode): array
    {
        $connection = DB::connection('oracle_lmidc');
        $dateFormat = $connection->selectOne("SELECT value FROM nls_session_parameters WHERE parameter = 'NLS_DATE_FORMAT'")->value
            ?? 'YYYY-MM-DD HH24:MI:SS';
        $restoreFormat = preg_match('/^[A-Za-z0-9 .:\/-]+$/', $dateFormat)
            ? $dateFormat
            : 'YYYY-MM-DD HH24:MI:SS';

        $connection->setSessionVars(['NLS_DATE_FORMAT' => 'DD/Mon/YYYY']);
        try {
            return $connection->table('online_app_mix_seen')
                ->where('pos_code', $posCode)
                ->pluck('incentive_id')
                ->all();
        } finally {
            $connection->setSessionVars(['NLS_DATE_FORMAT' => $restoreFormat]);
        }
    }

    private function pricedLines(Collection $cartItems): array
    {
        if ($cartItems->isEmpty()) return [];
        $productIds = $cartItems->pluck('product_id')->unique()->values();
        $prices = DB::connection('oracle_lmidc')->table('product_price_list')
            ->whereIn('product_id', $productIds)
            ->where('line_price_id', 1)
            ->get(['product_id', 'pricelist_carton', 'tax_percentage'])
            ->keyBy(fn ($price) => (string) $price->product_id);

        return $cartItems->map(function ($cartItem) use ($prices) {
            $price = $prices->get((string) $cartItem->product_id);
            if (!$price || $price->pricelist_carton <= 0) return null;
            return [
                'product_id' => (string) $cartItem->product_id,
                'quantity' => (float) $cartItem->quantity,
                'unit_price' => round($price->pricelist_carton, 2),
                'line_price' => round($price->pricelist_carton * $cartItem->quantity, 2),
                'tax_percentage' => (float) ($price->tax_percentage ?? 0),
                'discount' => 0,
                'tax' => 0,
                'total' => 0,
            ];
        })->filter()->values()->all();
    }

    private function evaluateMixes(string $posCode, array $lines, array $removedGiftIncentives, string $locale): array
    {
        $empty = ['discounts' => [], 'gifts' => [], 'applied_ids' => [], 'banners' => []];
        $productIds = collect($lines)->pluck('product_id')->all();
        if (!$productIds) return $empty;
        $seen = $this->seenIncentiveIdsForPos($posCode);
        if (!$seen) return $empty;
        $used = DB::connection('oracle_lmidc')->table('online_app_mix_log')->where('pos_code', $posCode)->where('status', 'USED')->pluck('incentive_id')->all();
        $rules = DB::connection('oracle_lmidc')->table('online_app_v_mix_active')
            ->whereIn('incentive_id', $seen)->whereIn('product_id', $productIds)->get();
        $result = $empty;

        foreach ($lines as $line) {
            foreach ($rules->where('product_id', $line['product_id']) as $rule) {
                $taxProducts = array_map('trim', explode(',', (string) $rule->tax_prods));
                if (!in_array((string) $line['product_id'], $taxProducts, true)) continue;
                if ((int) $rule->repeated === 0 && in_array($rule->incentive_id, $used)) continue;
                $calculation = MixRuleCalculator::evaluate($line, $rule);
                if ($calculation === null) continue;

                if ((int) $rule->gift_product_id !== 0) {
                    if (in_array((int) $rule->incentive_id, $removedGiftIncentives, true)) continue;
                    $result['applied_ids'][] = (int) $rule->incentive_id;
                    $result['banners'][$line['product_id']] = trim(($result['banners'][$line['product_id']] ?? '') . ' • ' . ($locale === 'ar' ? 'هدية' : 'Gift'), ' •');
                    $result['gifts'][] = ['product_id' => $rule->gift_product_id, 'quantity' => $calculation['multiplier'] * (int) $rule->val, 'incentive_id' => $rule->incentive_id];
                } elseif ((float) $rule->discount_perc !== 0.0) {
                    $result['applied_ids'][] = (int) $rule->incentive_id;
                    $label = $locale === 'ar' ? 'خصم ' . $rule->discount_perc . '%' : 'Discount ' . $rule->discount_perc . '%';
                    $result['banners'][$line['product_id']] = trim(($result['banners'][$line['product_id']] ?? '') . ' • ' . $label, ' •');
                    $result['discounts'][$line['product_id']] = ($result['discounts'][$line['product_id']] ?? 0) + $calculation['discount'];
                } else {
                    $result['applied_ids'][] = (int) $rule->incentive_id;
                    $label = $locale === 'ar' ? 'خصم ' . $rule->val . ' ج.م' : $rule->val . ' EGP off';
                    $result['banners'][$line['product_id']] = trim(($result['banners'][$line['product_id']] ?? '') . ' • ' . $label, ' •');
                    $result['discounts'][$line['product_id']] = ($result['discounts'][$line['product_id']] ?? 0) + $calculation['discount'];
                }
            }
        }
        return $result;
    }
    private function giftLines(array $gifts): array
    {
        $giftCollection = collect($gifts);
        if ($giftCollection->isEmpty()) return [];
        $giftProductIds = $giftCollection->pluck('product_id')->unique()->values();
        $products = DB::connection('oracle_lmidc')->table('to_sfa_products_android')
            ->whereIn('product_id', $giftProductIds)
            ->get(['product_id', 'product_ename'])
            ->keyBy(fn ($product) => (string) $product->product_id);
        $prices = DB::connection('oracle_lmidc')->table('product_price_list')
            ->whereIn('product_id', $giftProductIds)
            ->where('line_price_id', 1)
            ->get(['product_id', 'pricelist_carton'])
            ->keyBy(fn ($price) => (string) $price->product_id);

        return $giftCollection->map(function ($gift) use ($products, $prices) {
            $product = $products->get((string) $gift['product_id']);
            $price = $prices->get((string) $gift['product_id']);
            if (!$product || !$price || $price->pricelist_carton <= 0) return null;
            $linePrice = round($price->pricelist_carton * $gift['quantity'], 2);
            return ['product_id' => (string) $gift['product_id'], 'name' => $product->product_ename, 'quantity' => $gift['quantity'], 'unit_price' => round($price->pricelist_carton, 2), 'line_price' => $linePrice, 'discount' => $linePrice, 'tax' => 0, 'total' => 0, 'is_gift' => true, 'source_incentive_id' => $gift['incentive_id']];
        })->filter()->values()->all();
    }
    public function redeemForOrder(object $user, int $orderId, array $preview, Collection $credits): void
    {
        $posCode = $this->posCodeForUser($user);
        if (!$posCode) return;
        [$terId, $posId] = explode('_', $posCode, 2);
        DB::connection('oracle_lmidc')->transaction(function () use ($posCode, $terId, $posId, $orderId, $preview, $credits) {
            $nonRepeatable = DB::connection('oracle_lmidc')->table('incentive_types')
                ->whereIn('incentive_id', $preview['applied_incentive_ids'])->where('repeated', 0)->pluck('incentive_id');
            foreach ($nonRepeatable as $incentiveId) {
                DB::connection('oracle_lmidc')->table('online_app_mix_log')->insert(['pos_code' => $posCode, 'incentive_id' => $incentiveId, 'order_id' => $orderId, 'status' => 'USED', 'redeemed_at' => now()]);
            }
            foreach ($credits as $credit) {
                $updated = DB::connection('oracle_lmidc')->table('incentive_assigning')
                    ->where('ter_id', $terId)->where('pos_id', $posId)->where('incentive_type_id', $credit->incentive_type_id)
                    ->where('from_date', $credit->from_date)->where('to_date', $credit->to_date)->where('recieved_flag', 0)
                    ->update(['recieved_flag' => 1]);
                if ($updated !== 1) throw new \RuntimeException('Wallet credit is no longer available.');
                DB::connection('oracle_lmidc')->table('online_app_fix_log')->insert(['pos_code' => $posCode, 'incentive_type_id' => $credit->incentive_type_id, 'assign_from_date' => $credit->from_date, 'assign_to_date' => $credit->to_date, 'incentive_value' => $credit->incentive_value, 'order_id' => $orderId, 'status' => 'USED', 'redeemed_at' => now()]);
            }
        });
    }

    public function releaseForOrder(object $user, int $orderId): void
    {
        $posCode = $this->posCodeForUser($user);
        if (!$posCode) return;
        [$terId, $posId] = explode('_', $posCode, 2);
        DB::connection('oracle_lmidc')->transaction(function () use ($posCode, $terId, $posId, $orderId) {
            DB::connection('oracle_lmidc')->table('online_app_mix_log')->where('pos_code', $posCode)->where('order_id', $orderId)->where('status', 'USED')->update(['status' => 'CANCELLED']);
            $logs = DB::connection('oracle_lmidc')->table('online_app_fix_log')->where('pos_code', $posCode)->where('order_id', $orderId)->where('status', 'USED')->get();
            foreach ($logs as $log) {
                DB::connection('oracle_lmidc')->table('incentive_assigning')->where('ter_id', $terId)->where('pos_id', $posId)->where('incentive_type_id', $log->incentive_type_id)->where('from_date', $log->assign_from_date)->where('to_date', $log->assign_to_date)->update(['recieved_flag' => 0]);
            }
            DB::connection('oracle_lmidc')->table('online_app_fix_log')->where('pos_code', $posCode)->where('order_id', $orderId)->where('status', 'USED')->update(['status' => 'CANCELLED']);
        });
    }
}
