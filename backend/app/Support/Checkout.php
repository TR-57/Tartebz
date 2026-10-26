<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Prices a cart on the server. Only product ids and quantities are taken from the browser;
 * prices, stock, weight, delivery and the currency rate all come from the database and settings.
 */
class Checkout
{
    public const MAX_LINES = 50;

    /**
     * @param  array<int, array{id:int, qty:int}>  $items
     * @return array{lines: array, issues: array, subtotal: float, shipping: float, total: float, weightGrams: int,
     *               country: string, currency: string, rate: float, decimals: int, chargeCurrency: string, chargeAmount: float}
     */
    public static function quote(array $items, string $country): array
    {
        $country = strtoupper($country);
        $want = [];
        foreach (array_slice($items, 0, self::MAX_LINES) as $it) {
            $id = (int) (((array) $it)['id'] ?? 0);
            $qty = max(0, min(99, (int) (((array) $it)['qty'] ?? 0)));
            if ($id > 0 && $qty > 0) {
                $want[$id] = ($want[$id] ?? 0) + $qty;
            }
        }
        $tree = Catalog::categoryTree();
        $products = DB::table('products')->whereIn('id', array_keys($want) ?: [0])->get()->keyBy('id');
        $lines = [];
        $issues = [];
        $subtotal = 0.0;
        $grams = 0;
        foreach ($want as $id => $qty) {
            $p = $products[$id] ?? null;
            $live = $p && $p->status === 'active' && $p->category_id && ($tree[$p->category_id]->live ?? false);
            if (! $live || ! Catalog::available($p)) {
                $issues[] = ['id' => $id, 'code' => 'unavailable', 'name' => $p->name ?? '', 'nameAr' => $p->name_ar ?? ''];

                continue;
            }
            if ($p->type === 'simple' && $p->manage_stock && $qty > $p->stock_qty) {
                $issues[] = ['id' => $id, 'code' => 'only', 'left' => (int) $p->stock_qty, 'name' => $p->name, 'nameAr' => $p->name_ar];
                $qty = (int) $p->stock_qty;
            }
            $price = round((float) $p->price, 3);
            $w = Catalog::weightGrams($p, $tree);
            $images = json_decode((string) $p->images, true) ?: [];
            $lines[] = [
                'id' => $p->id, 'name' => $p->name, 'nameAr' => $p->name_ar, 'image' => $images[0] ?? '',
                'price' => $price, 'qty' => $qty, 'lineTotal' => round($price * $qty, 3), 'weightGrams' => $w,
            ];
            $subtotal += $price * $qty;
            $grams += $w * $qty;
        }
        $subtotal = round($subtotal, 3);
        $shipping = $lines ? Shipping::cost($grams / 1000, $country) : 0.0;
        $total = round($subtotal + $shipping, 3);

        // The customer's currency (by country) and what the card will actually be charged in.
        $cur = Currency::get(self::currencyFor($country)) ?? Currency::get(Currency::base());
        $chargeCurrencies = (array) (Settings::get('payments')['tap']['chargeCurrencies'] ?? ['BHD']);
        $charge = in_array($cur['code'], $chargeCurrencies, true) ? $cur : Currency::get(Currency::base());

        return [
            'lines' => $lines, 'issues' => $issues, 'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $total,
            'weightGrams' => (int) $grams, 'country' => $country,
            'currency' => $cur['code'], 'rate' => (float) $cur['rate'], 'decimals' => (int) $cur['decimals'],
            'chargeCurrency' => $charge['code'],
            'chargeAmount' => Currency::fromBase($total, $charge['code']),
        ];
    }

    public static function currencyFor(string $country): string
    {
        foreach (Currency::enabled() as $c) {
            if (in_array(strtoupper($country), (array) $c['countries'], true)) {
                return $c['code'];
            }
        }

        return Currency::base();
    }
}
