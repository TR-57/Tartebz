<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock reservations, so two customers never pay for the same last piece.
 *
 *  - A product in a cart is held for that cart for a few minutes (Settings › Website), renewed while the customer is active.
 *  - Placing an order moves the cart's holds to the order: card orders keep them until the payment window closes,
 *    WhatsApp orders until staff confirm the payment. Paying takes the stock for good and drops the holds.
 *  - What another customer can buy = stock − everything held by other carts and other unpaid orders.
 *
 * Every change runs inside a transaction that locks the product rows first, so two requests for the last piece
 * are handled one after the other. Products without stock counting (or with versions) are never held.
 */
class Holds
{
    /** No single connection can hold more than this many pieces at once across its carts (stops anyone emptying the shop). */
    public const MAX_UNITS_PER_IP = 60;

    public static function cartMinutes(): int
    {
        return max(1, min(120, (int) (Settings::get('storefront')['cartHoldMinutes'] ?? 15)));
    }

    public static function orderUntil(string $method): Carbon
    {
        $o = Settings::get('orders');

        return $method === 'whatsapp'
            ? now()->addHours(max(1, min(168, (int) ($o['whatsappHoldHours'] ?? 24))))
            : now()->addMinutes(max(10, min(1440, (int) ($o['holdMinutes'] ?? 60))));
    }

    public static function counted(object $p): bool
    {
        return $p->type === 'simple' && (bool) $p->manage_stock;
    }

    /** product id => pieces held by everyone except this cart / this order (active holds only). */
    public static function heldByOthers(array $productIds, ?string $cart = null, ?int $orderId = null): array
    {
        if (! $productIds) {
            return [];
        }

        return DB::table('stock_holds')->whereIn('product_id', $productIds)->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('cart_key')->orWhere('cart_key', '<>', (string) $cart))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhere(function ($q) use ($cart, $orderId) {
                $q->where('order_id', '<>', (int) $orderId);
                if ($cart) {
                    // An unpaid order placed from this same cart is the same customer, not a competitor.
                    $q->whereNotIn('order_id', DB::table('orders')->select('id')->where('cart_key', $cart));
                }
            }))
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** product id => all active held pieces (for the shop feed and the admin). */
    public static function heldAll(?array $productIds = null): array
    {
        return DB::table('stock_holds')->where('expires_at', '>', now())
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Pieces this customer may still take: null = not counted (no limit). */
    public static function free(object $p, array $others): ?int
    {
        return self::counted($p) ? max(0, (int) $p->stock_qty - ($others[$p->id] ?? 0)) : null;
    }

    /**
     * Makes the cart's holds match its contents (as far as stock allows) and renews them.
     * Returns [['id' => …, 'wanted' => n, 'qty' => granted], …] and when the holds end.
     */
    public static function syncCart(string $cart, array $items, string $ip): array
    {
        $wanted = [];
        foreach (array_slice($items, 0, Checkout::MAX_LINES) as $it) {
            $id = (int) (((array) $it)['id'] ?? 0);
            $qty = max(0, min(99, (int) (((array) $it)['qty'] ?? 0)));
            if ($id > 0 && $qty > 0) {
                $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            }
        }
        $until = now()->addMinutes(self::cartMinutes());

        return DB::transaction(function () use ($cart, $wanted, $ip, $until) {
            $ids = array_values(array_unique(array_merge(array_keys($wanted),
                DB::table('stock_holds')->where('cart_key', $cart)->pluck('product_id')->all())));
            $products = DB::table('products')->whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $others = self::heldByOthers($ids, $cart);
            // Pieces this connection already holds in its other carts.
            $ipElsewhere = $ip === '' ? 0 : (int) DB::table('stock_holds')->where('ip', $ip)->where('expires_at', '>', now())
                ->where(fn ($q) => $q->whereNull('cart_key')->orWhere('cart_key', '<>', $cart))->whereNull('order_id')->sum('qty');
            $room = max(0, self::MAX_UNITS_PER_IP - $ipElsewhere);
            $out = [];
            foreach ($wanted as $id => $qty) {
                $p = $products[$id] ?? null;
                if (! $p || $p->status !== 'active') {
                    $out[] = ['id' => $id, 'wanted' => $qty, 'qty' => 0];

                    continue;
                }
                $free = self::free($p, $others);
                if ($free === null) {
                    $out[] = ['id' => $id, 'wanted' => $qty, 'qty' => Catalog::available($p) ? $qty : 0];

                    continue;
                }
                $granted = min($qty, $free, $room);
                $room -= $granted;
                if ($granted > 0) {
                    DB::table('stock_holds')->updateOrInsert(['cart_key' => $cart, 'product_id' => $id],
                        ['qty' => $granted, 'ip' => $ip, 'order_id' => null, 'expires_at' => $until, 'updated_at' => now(), 'created_at' => now()]);
                } else {
                    DB::table('stock_holds')->where('cart_key', $cart)->where('product_id', $id)->delete();
                }
                $out[] = ['id' => $id, 'wanted' => $qty, 'qty' => $granted];
            }
            DB::table('stock_holds')->where('cart_key', $cart)->whereNotIn('product_id', array_keys($wanted) ?: [0])->delete();

            return ['items' => $out, 'expiresAt' => $until->toIso8601String(), 'minutes' => self::cartMinutes()];
        });
    }

    /** The cart's holds become the order's. Call inside the transaction that locked the products. */
    public static function toOrder(?string $cart, object $order, array $lines): void
    {
        if ($cart) {
            DB::table('stock_holds')->where('cart_key', $cart)->delete();
            // A new order from the same cart replaces an earlier unpaid one: that one stops keeping stock
            // (if it is paid after all, Pay again / the payment re-checks what is free).
            DB::table('stock_holds')->whereIn('order_id', DB::table('orders')->select('id')->where('cart_key', $cart)
                ->whereNull('paid_at')->where('id', '<>', $order->id))->delete();
            DB::table('orders')->where('id', $order->id)->update(['cart_key' => $cart]);
        }
        $until = self::orderUntil($order->payment_method);
        $counted = DB::table('products')->whereIn('id', array_column($lines, 'id') ?: [0])->get()->keyBy('id');
        foreach ($lines as $l) {
            $p = $counted[$l['id']] ?? null;
            if ($p && self::counted($p)) {
                DB::table('stock_holds')->insert(['order_id' => $order->id, 'product_id' => $l['id'], 'qty' => $l['qty'], 'ip' => $order->ip,
                    'expires_at' => $until, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Before paying again: keeps (or takes back) the order's pieces. Returns false when someone else has them now.
     * Call inside a transaction.
     */
    public static function renewOrder(object $order): bool
    {
        $items = DB::table('order_items')->where('order_id', $order->id)->get();
        $ids = $items->pluck('product_id')->filter()->all();
        $products = DB::table('products')->whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        // The customer's own cart (same browser) does not count against their order.
        $others = self::heldByOthers($ids, $order->cart_key, $order->id);
        foreach ($items as $i) {
            $p = $products[$i->product_id] ?? null;
            if (! $p || $p->status !== 'active') {
                return false;
            }
            $free = self::free($p, $others);
            if ($free !== null && $free < $i->qty) {
                return false;
            }
        }
        DB::table('stock_holds')->where('order_id', $order->id)->delete();
        self::toOrder(null, $order, $items->map(fn ($i) => ['id' => $i->product_id, 'qty' => (int) $i->qty])->all());

        return true;
    }

    public static function releaseOrder(int $orderId): void
    {
        DB::table('stock_holds')->where('order_id', $orderId)->delete();
    }

    public static function orderHeldUntil(int $orderId): ?string
    {
        $t = DB::table('stock_holds')->where('order_id', $orderId)->where('expires_at', '>', now())->min('expires_at');

        return $t ? (string) $t : null;
    }

    public static function purge(): int
    {
        return DB::table('stock_holds')->where('expires_at', '<=', now())->delete();
    }

    /** Changes when any hold starts or ends – part of the shop feed's version stamp. */
    public static function stamp(): string
    {
        $r = DB::table('stock_holds')->where('expires_at', '>', now())->selectRaw('COUNT(*) c, COALESCE(SUM(qty),0) q, MIN(expires_at) m, MAX(updated_at) u')->first();

        return $r->c.'/'.$r->q.'/'.$r->m.'/'.$r->u;
    }
}
