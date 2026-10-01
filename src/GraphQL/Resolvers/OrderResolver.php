<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Discount;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\OrderLine;
use Lunar\Core\Models\Transaction;
use Lunar\Core\Pricing\PriceFormatterInterface;
use Lunargraphql\Exceptions\CartException;
use Lunargraphql\Traits\WithGlobalID;

class OrderResolver
{
    use WithGlobalID;

    protected static array $currencyCache = [];
    protected static array $orderCurrencyMap = [];

    protected function rememberOrderCurrency(Order $order): ?Currency
    {
        $currency = $order->relationLoaded('currency')
            ? $order->currency
            : (static::$currencyCache[$order->currency_code] ??= Currency::where('code', $order->currency_code)->first());

        if ($currency && $order->id) {
            static::$orderCurrencyMap[$order->id] = $currency;
        }

        return $currency;
    }

    public function createOrderFromCart(mixed $root, array $args): Order
    {
        $cartId = $this->extractIdFromArgs($args, 'cartID');

        /** @var Cart|null $cart */
        $cart = $cartId ? Cart::find($cartId) : CartSession::current();

        throw_unless($cart, CartException::cartNotFound());

        if ($cart->lines()->count() === 0) {
            throw CartException::cartEmpty();
        }

        $user = Auth::guard('sanctum')->user() ?? Auth::user();
        if ($user && ! $cart->user_id) {
            $cart->user_id = $user->id;
            $cart->save();
        }

        try {
            $cart->calculate();
            /** @var Order $order */
            $order = $cart->createOrder();
        } catch (\Throwable $e) {
            throw CartException::orderCreationFailed($e->getMessage());
        }

        return $order->refresh();
    }

    public function getOrderQuery(mixed $root, array $args): ?Order
    {
        $id = $this->extractIdFromArgs($args, 'id');

        if (! $id) {
            return null;
        }

        $query = Order::query();

        $order = is_numeric($id)
            ? $query->find($id)
            : $query->where('public_id', $id)->orWhere('reference', $id)->first();

        return $order;
    }

    public function getOrderByReferenceQuery(mixed $root, array $args): ?Order
    {
        $reference = Arr::get($args, 'reference');
        if (! $reference) {
            return null;
        }

        return Order::query()->where('reference', $reference)->first();
    }

    public function recordOrderTransactionMutation(mixed $root, array $args): Transaction
    {
        $orderId = $this->extractIdFromArgs($args, 'orderId');
        $order = Order::find($orderId);

        throw_unless($order, new \Exception('Order not found'));

        $meta = Arr::get($args, 'meta');
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : ['raw' => $meta];
        }

        $transaction = $order->transactions()->create([
            'success' => (bool) Arr::get($args, 'success', true),
            'type' => Arr::get($args, 'type', 'capture'),
            'driver' => Arr::get($args, 'driver', 'manual'),
            'amount' => (int) Arr::get($args, 'amount'),
            'reference' => Arr::get($args, 'reference'),
            'status' => Arr::get($args, 'status', 'success'),
            'notes' => Arr::get($args, 'notes'),
            'card_type' => Arr::get($args, 'cardType'),
            'last_four' => Arr::get($args, 'lastFour'),
            'meta' => $meta,
        ]);

        return $transaction->refresh();
    }

    public function myOrdersQuery(mixed $root, array $args): Builder
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            return Order::query()->whereRaw('1 = 0');
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->with('currency')
            ->orderBy('created_at', 'desc');
    }

    public function resolveSubTotalFormatted(Order $order, array $args): ?string
    {
        $this->rememberOrderCurrency($order);

        if (method_exists($order, 'format')) {
            return $order->format('sub_total');
        }

        return number_format($order->sub_total / 100, 2);
    }

    public function resolveTotalFormatted(Order $order, array $args): ?string
    {
        $this->rememberOrderCurrency($order);

        if (method_exists($order, 'format')) {
            return $order->format('total');
        }

        return number_format($order->total / 100, 2);
    }

    public function getGuestOrderQuery(mixed $root, array $args): ?Order
    {
        $reference = Arr::get($args, 'reference');
        $email = Arr::get($args, 'email');

        if (! $reference || ! $email) {
            return null;
        }

        /** @var Order|null $order */
        $order = Order::query()->where('reference', $reference)->first();
        if (! $order) {
            return null;
        }

        $billingEmail = $order->addresses()->whereType('billing')->value('contact_email');
        $shippingEmail = $order->addresses()->whereType('shipping')->value('contact_email');

        if (
            strcasecmp((string) $billingEmail, (string) $email) === 0 ||
            strcasecmp((string) $shippingEmail, (string) $email) === 0
        ) {
            return $order;
        }

        return null;
    }

    public function resolveLifecycleStatus(Order $order): string
    {
        return $order->lifecycleStatus();
    }

    public function resolveIsOpen(Order $order): bool
    {
        return $order->isOpen();
    }

    public function resolveIsClosed(Order $order): bool
    {
        return $order->isClosed();
    }

    public function resolveIsCancelled(Order $order): bool
    {
        return $order->isCancelled();
    }

    public function resolveIsPlaced(Order $order): bool
    {
        return $order->isPlaced();
    }

    public function resolveIsDraft(Order $order): bool
    {
        return $order->isDraft();
    }

    public function resolveCancelReasonLabel(Order $order): ?string
    {
        return $order->cancelReasonLabel();
    }

    public function resolveDiscountTotalFormatted(Order $order, array $args): ?string
    {
        if (method_exists($order, 'format')) {
            return $order->format('discount_total');
        }

        return number_format($order->discount_total / 100, 2);
    }

    public function resolveShippingTotalFormatted(Order $order, array $args): ?string
    {
        if (method_exists($order, 'format')) {
            return $order->format('shipping_total');
        }

        return number_format($order->shipping_total / 100, 2);
    }

    public function resolveTaxTotalFormatted(Order $order, array $args): ?string
    {
        $this->rememberOrderCurrency($order);

        if (method_exists($order, 'format')) {
            return $order->format('tax_total');
        }

        return number_format($order->tax_total / 100, 2);
    }

    public function resolveDiscountBreakdown(Order $order): array
    {
        $breakdown = $order->discount_breakdown;

        if (! $breakdown) {
            return [];
        }

        return collect($breakdown)->map(function ($item) {
            $discount = null;
            if (isset($item->discount_id)) {
                $discount = Discount::find($item->discount_id);
            } elseif (isset($item->discount) && $item->discount instanceof Discount) {
                $discount = $item->discount;
            }

            $price = $item->total ?? $item->price ?? null;
            return [
                'discount' => $discount,
                'name' => $discount?->name ?? 'Discount',
                'price' => $price?->value ?? 0,
                'priceFormatted' => $price?->format() ?? '',
                'priceDecimal' => $price?->decimal() ?? 0.0,
            ];
        })->values()->toArray();
    }

    public function resolveTaxBreakdown(Order $order): array
    {
        $taxBreakdown = $order->tax_breakdown;

        if (! $taxBreakdown || ! $taxBreakdown->amounts) {
            return [];
        }

        return $taxBreakdown->amounts->map(function ($amount) {
            return [
                'identifier' => $amount->identifier,
                'description' => $amount->description,
                'percentage' => (float) $amount->percentage,
                'price' => $amount->price->value,
                'priceFormatted' => $amount->price->format(),
                'priceDecimal' => $amount->price->decimal(),
            ];
        })->values()->toArray();
    }

    protected function formatOrderLinePrice(OrderLine $line, string $field): string
    {
        $value = $line->getAttribute($field);
        if ($value === null) {
            return '';
        }

        $currency = null;
        if ($line->relationLoaded('order') && $line->order) {
            $currency = $this->rememberOrderCurrency($line->order);
        } elseif ($line->order_id && isset(static::$orderCurrencyMap[$line->order_id])) {
            $currency = static::$orderCurrencyMap[$line->order_id];
        }

        if ($currency) {
            return app(PriceFormatterInterface::class, [
                'value' => (int) $value,
                'currency' => $currency,
            ])->formatted();
        }

        if (method_exists($line, 'format')) {
            return $line->format($field) ?? '';
        }

        return number_format(((int) $value) / 100, 2);
    }

    public function resolveLineUnitPriceFormatted(OrderLine $line): string
    {
        return $this->formatOrderLinePrice($line, 'unit_price');
    }

    public function resolveLineSubTotalFormatted(OrderLine $line): string
    {
        return $this->formatOrderLinePrice($line, 'sub_total');
    }

    public function resolveLineTotalFormatted(OrderLine $line): string
    {
        return $this->formatOrderLinePrice($line, 'total');
    }

    public function resolveLineDiscountTotalFormatted(OrderLine $line): string
    {
        return $this->formatOrderLinePrice($line, 'discount_total');
    }

    public function resolveLineTaxTotalFormatted(OrderLine $line): string
    {
        return $this->formatOrderLinePrice($line, 'tax_total');
    }
}
