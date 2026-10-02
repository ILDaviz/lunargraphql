<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lunar\Core\Contracts\LunarUser;
use Lunar\Core\DataObjects\PaymentAuthorize;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Discount;
use Lunar\Core\Models\Order;
use Lunar\Core\Models\OrderLine;
use Lunar\Core\Models\Transaction;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Core\Pricing\PriceFormatterInterface;
use Lunar\Stripe\Facades\StripePayments;
use Lunargraphql\Exceptions\AuthenticationException;
use Lunargraphql\Exceptions\CartException;
use Lunargraphql\Exceptions\OrderException;
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
        $cartId = $this->extractIdFromArgs($args, 'cartID') ?? $this->extractIdFromArgs($args, 'cartId');

        /** @var Cart|null $cart */
        $cart = $this->findCart($cartId);

        throw_unless($cart, CartException::cartNotFound());
        $this->authorizeCartAccess($cart, $cartId);

        if (method_exists($cart, 'completedOrder') && $cart->completedOrder()->exists()) {
            throw CartException::orderCreationFailed(CartException::trans('cart_already_ordered', 'This cart has already been converted into an order.'));
        }

        if ($cart->lines()->count() === 0) {
            throw CartException::cartEmpty();
        }

        $user = Auth::guard('sanctum')->user() ?? Auth::user();
        if ($user && ! $cart->user_id) {
            if ($user instanceof LunarUser) {
                CartSession::associate($cart, $user, config('lunar.cart.auth_policy', 'merge'));
                $cart = CartSession::current(calculate: false) ?? Cart::findOrFail($cart->id);
            } else {
                $cart->user_id = $user->getAuthIdentifier();
                $cart->save();
            }
        }

        if ($cart->isShippable() && ! $cart->shippingAddress) {
            throw CartException::invalidAddress('shipping');
        }

        if (! $cart->billingAddress) {
            if ($cart->shippingAddress) {
                $shipping = $cart->shippingAddress;
                $cart->setBillingAddress([
                    'title' => $shipping->title,
                    'first_name' => $shipping->first_name,
                    'last_name' => $shipping->last_name,
                    'company_name' => $shipping->company_name,
                    'tax_identifier' => $shipping->tax_identifier,
                    'line_one' => $shipping->line_one,
                    'line_two' => $shipping->line_two,
                    'line_three' => $shipping->line_three,
                    'city' => $shipping->city,
                    'state' => $shipping->state,
                    'postcode' => $shipping->postcode,
                    'country_id' => $shipping->country_id,
                    'contact_email' => $shipping->contact_email,
                    'contact_phone' => $shipping->contact_phone,
                    'meta' => $shipping->meta instanceof \ArrayObject ? $shipping->meta->toArray() : (is_array($shipping->meta) ? $shipping->meta : []),
                ]);
                $cart->load('billingAddress');
            } else {
                throw CartException::invalidAddress('billing');
            }
        }

        $lock = Cache::lock("create-order-cart-{$cart->id}", 15);
        if (! $lock->get()) {
            throw CartException::orderCreationFailed(CartException::trans('order_creation_already_in_progress', 'Order creation is already in progress for this cart.'));
        }

        try {
            $cart->calculate();
            /** @var Order $order */
            $order = $cart->createOrder();
        } catch (\Throwable $e) {
            throw CartException::orderCreationFailed($e->getMessage());
        } finally {
            $lock->release();
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

        if (! $order) {
            return null;
        }

        $this->authorizeOrderAccess($order);

        return $order;
    }

    public function getOrderByReferenceQuery(mixed $root, array $args): ?Order
    {
        $reference = Arr::get($args, 'reference');
        if (! $reference) {
            return null;
        }

        $order = Order::query()->where('reference', $reference)->first();

        if (! $order) {
            return null;
        }

        $this->authorizeOrderAccess($order);

        return $order;
    }

    public function recordOrderTransactionMutation(mixed $root, array $args): Transaction
    {
        $orderId = $this->extractIdFromArgs($args, 'orderId');
        $order = Order::find($orderId);

        throw_unless($order, OrderException::orderNotFound());

        $user = Auth::guard('sanctum')->user() ?? Auth::user();
        Gate::forUser($user)->authorize('record-order-transaction', $order);

        $driver = Arr::get($args, 'driver', 'manual');
        throw_unless(
            $driver === 'manual',
            OrderException::paymentFailed('External provider transactions must be recorded from a verified server-side webhook.')
        );

        $type = Arr::get($args, 'type', 'capture');
        $success = (bool) Arr::get($args, 'success', true);
        $lock = null;
        if ($type === 'capture' && $success) {
            $lock = Cache::lock("order-capture-{$order->id}", 30);
            throw_unless($lock->get(), OrderException::paymentFailed('A payment capture is already being processed for this order.'));
            $order->refresh();
        }

        try {
            $amount = (int) Arr::get($args, 'amount');
            throw_if($amount <= 0, OrderException::invalidAmount());

            if ($type === 'capture' && $success) {
                $currentPaymentStatus = is_object($order->payment_status) ? (string) $order->payment_status : $order->payment_status;
                throw_if($currentPaymentStatus === 'paid', OrderException::alreadyPaid());
                $captured = (int) $order->transactions()->where('success', true)->where('type', 'capture')->sum('amount');
                throw_if($amount > ((int) $order->total - $captured), OrderException::amountExceedsTotal());
            }

            $meta = Arr::get($args, 'meta');
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : ['raw' => $meta];
            }

            $reference = Arr::get($args, 'reference');
            if ($reference && $order->transactions()->where('reference', $reference)->where('type', $type)->exists()) {
                throw OrderException::paymentFailed('A transaction with this reference has already been recorded.');
            }

            $transaction = $order->transactions()->create([
                'success' => $success,
                'type' => $type,
                'driver' => $driver,
                'amount' => $amount,
                'reference' => $reference ?? ('txn_'.Str::random(16)),
                'status' => Arr::get($args, 'status', $success ? 'success' : 'failed'),
                'notes' => Arr::get($args, 'notes'),
                'card_type' => Arr::get($args, 'cardType'),
                'last_four' => Arr::get($args, 'lastFour'),
                'meta' => $meta,
            ]);

            if ($success && $type === 'capture') {
                $totalCaptured = $order->transactions()->where('success', true)->where('type', 'capture')->sum('amount');
                if ($totalCaptured >= (int) $order->total) {
                    $order->payment_status = 'paid';
                    $order->placed_at ??= now();
                    $order->save();
                }
            }

            if (class_exists('Lunar\Core\Events\PaymentAttemptEvent') && class_exists('Lunar\Core\DataObjects\PaymentAuthorize')) {
                PaymentAttemptEvent::dispatch(
                    new PaymentAuthorize(
                        success: $success,
                        message: Arr::get($args, 'notes'),
                        orderId: $order->id,
                        paymentType: $driver
                    )
                );
            }

            return $transaction->refresh();
        } finally {
            $lock?->release();
        }
    }

    public function initiatePaymentMutation(mixed $root, array $args): array
    {
        $cart = null;
        $order = null;

        $orderId = $this->extractIdFromArgs($args, 'orderId');
        if ($orderId) {
            $order = Order::find($orderId);
            throw_unless($order, OrderException::orderNotFound());
            $this->authorizeOrderAccess($order);
            $this->assertOrderNotPaid($order);
        } else {
            $cartId = $this->extractIdFromArgs($args, 'cartId') ?? $this->extractIdFromArgs($args, 'cartID');
            $cart = $this->findCart($cartId);
            throw_unless($cart, CartException::cartNotFound());
            $this->authorizeCartAccess($cart, $cartId);
            $this->assertCartNotCompleted($cart);
            if ($cart->lines()->count() === 0) {
                throw CartException::cartEmpty();
            }
        }

        $provider = Arr::get($args, 'provider', 'stripe');
        $providerConfig = config("lunar.payments.types.{$provider}");
        throw_unless(is_array($providerConfig) && ($providerConfig['enabled'] ?? true), OrderException::paymentProviderUnavailable());

        // Stripe is optional; do not mask a missing or failed integration with a fake intent.
        if (($providerConfig['driver'] ?? null) === 'stripe') {
            throw_unless(class_exists(StripePayments::class), OrderException::paymentProviderUnavailable());

            try {
                $target = $order ?? $cart;
                $intent = StripePayments::createPaymentIntent($target);
                throw_unless(is_string($intent->client_secret ?? null) && $intent->client_secret !== '', OrderException::paymentFailed());

                return [
                    'success' => true,
                    'clientSecret' => $intent->client_secret ?? null,
                    'orderId' => $order?->id,
                    'transactionId' => null,
                    'status' => $intent->status ?? 'requires_payment_method',
                    'requiresAction' => ($intent->status ?? '') === 'requires_action',
                    'redirectUrl' => null,
                    'meta' => json_encode(['intent_id' => $intent->id ?? null]),
                ];
            } catch (\Throwable $e) {
                throw OrderException::paymentFailed();
            }
        }

        try {
            $driver = Payments::driver($provider);
            $result = $this->authorizeWithLunarDriver($driver, $cart, $order, $provider, Arr::get($args, 'meta'));
        } catch (\Throwable $e) {
            if ($e instanceof OrderException || $e instanceof CartException) {
                throw $e;
            }

            throw OrderException::paymentFailed();
        }

        return [
            'success' => true,
            'clientSecret' => null,
            'orderId' => $result['order']->id,
            'transactionId' => $result['transactionId'],
            'status' => $result['captured'] ? 'succeeded' : 'authorized',
            'requiresAction' => false,
            'redirectUrl' => null,
            'meta' => json_encode(['paymentType' => $result['paymentType']]),
        ];
    }

    public function authorizePaymentMutation(mixed $root, array $args): array
    {
        $cart = null;
        $order = null;

        $orderId = $this->extractIdFromArgs($args, 'orderId');
        if ($orderId) {
            $order = Order::find($orderId);
            throw_unless($order, OrderException::orderNotFound());
            $this->authorizeOrderAccess($order);
            $this->assertOrderNotPaid($order);
        } else {
            $cartId = $this->extractIdFromArgs($args, 'cartId') ?? $this->extractIdFromArgs($args, 'cartID');
            $cart = $this->findCart($cartId);
            throw_unless($cart, CartException::cartNotFound());
            $this->authorizeCartAccess($cart, $cartId);
            $this->assertCartNotCompleted($cart);
            if ($cart->lines()->count() === 0) {
                throw CartException::cartEmpty();
            }

            if ($cart->isShippable() && ! $cart->shippingAddress) {
                throw CartException::invalidAddress('shipping');
            }

            if (! $cart->billingAddress && $cart->shippingAddress) {
                $shipping = $cart->shippingAddress;
                $cart->setBillingAddress([
                    'title' => $shipping->title,
                    'first_name' => $shipping->first_name,
                    'last_name' => $shipping->last_name,
                    'company_name' => $shipping->company_name,
                    'tax_identifier' => $shipping->tax_identifier,
                    'line_one' => $shipping->line_one,
                    'line_two' => $shipping->line_two,
                    'line_three' => $shipping->line_three,
                    'city' => $shipping->city,
                    'state' => $shipping->state,
                    'postcode' => $shipping->postcode,
                    'country_id' => $shipping->country_id,
                    'contact_email' => $shipping->contact_email,
                    'contact_phone' => $shipping->contact_phone,
                    'meta' => $shipping->meta instanceof \ArrayObject ? $shipping->meta->toArray() : (is_array($shipping->meta) ? $shipping->meta : []),
                ]);
                $cart->load('billingAddress');
            }

            $user = Auth::guard('sanctum')->user() ?? Auth::user();
            if ($user && ! $cart->user_id) {
                if ($user instanceof LunarUser) {
                    CartSession::associate($cart, $user, config('lunar.cart.auth_policy', 'merge'));
                    $cart = CartSession::current(calculate: false) ?? Cart::findOrFail($cart->id);
                } else {
                    $cart->user_id = $user->getAuthIdentifier();
                    $cart->save();
                }
            }
        }

        $paymentType = Arr::get($args, 'paymentType') ?? Arr::get($args, 'provider', 'cash-in-hand');
        $providerConfig = config("lunar.payments.types.{$paymentType}");
        throw_unless(is_array($providerConfig) && ($providerConfig['enabled'] ?? true), OrderException::paymentProviderUnavailable());

        try {
            $driver = Payments::driver($paymentType);
            $result = $this->authorizeWithLunarDriver($driver, $cart, $order, $paymentType, Arr::get($args, 'meta'));
        } catch (\Throwable $e) {
            if ($e instanceof OrderException || $e instanceof CartException) {
                throw $e;
            }

            throw OrderException::paymentFailed();
        }

        return [
            'success' => true,
            'message' => 'Payment authorized successfully.',
            'orderId' => $result['order']->id,
            'order' => $result['order'],
            'paymentType' => $result['paymentType'],
            'status' => $result['captured'] ? 'paid' : 'authorized',
        ];
    }

    /** @return array{order: Order, transactionId: int|string|null, captured: bool, paymentType: string} */
    protected function authorizeWithLunarDriver(mixed $driver, ?Cart $cart, ?Order $order, string $provider, mixed $meta): array
    {
        if ($order) {
            $driver->order($order);
        } elseif ($cart) {
            $driver->cart($cart);
        }

        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                $driver->withData(['meta' => $decoded]);
            }
        } elseif (is_array($meta)) {
            $driver->withData(['meta' => $meta]);
        }

        $response = $driver->authorize();
        throw_unless($response && $response->success, OrderException::paymentFailed());

        $targetOrderId = $response->orderId ?? $order?->id;
        $targetOrder = $targetOrderId ? Order::find($targetOrderId) : null;
        throw_unless($targetOrder, OrderException::paymentFailed('The payment driver did not return an order.'));

        $captured = config("lunar.payments.types.{$provider}.authorized") === 'captured';
        $transactionId = null;

        if ($driver instanceof OfflinePayment) {
            $targetOrder->placed_at ??= now();
            if ($captured) {
                $targetOrder->payment_status = 'paid';
            }
            $targetOrder->save();

            $transaction = $targetOrder->transactions()->create([
                'success' => true,
                'type' => $captured ? 'capture' : 'authorization',
                'driver' => $provider,
                'amount' => (int) $targetOrder->total,
                'reference' => 'lunar_'.Str::random(24),
                'status' => 'success',
                'notes' => 'Authorized via Lunar payment driver.',
            ]);
            $transactionId = $transaction->id;
        }

        return [
            'order' => $targetOrder->refresh(),
            'transactionId' => $transactionId,
            'captured' => $captured,
            'paymentType' => $response->paymentType ?? $provider,
        ];
    }

    protected function findCart(string|int|null $cartId): ?Cart
    {
        if ($cartId === null) {
            return CartSession::current();
        }

        $query = Cart::query();

        return is_numeric($cartId)
            ? $query->find($cartId)
            : $query->where('public_id', $cartId)->first();
    }

    protected function authorizeCartAccess(Cart $cart, string|int|null $providedId): void
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if ($cart->user_id !== null && (! $user || (int) $cart->user_id !== (int) $user->getAuthIdentifier())) {
            throw CartException::cartNotFound();
        }

        if ($providedId !== null && is_numeric($providedId)) {
            $sessionCartId = session(config('lunar.cart_session.session_key'));
            if ((int) $sessionCartId !== (int) $cart->id) {
                throw CartException::cartNotFound();
            }
        }
    }

    protected function assertCartNotCompleted(Cart $cart): void
    {
        if ($cart->hasCompletedOrders() && ! config('lunar.cart_session.allow_multiple_orders_per_cart', false)) {
            throw new CartException(CartException::trans('cart_already_ordered', 'This cart has already been converted into an order and cannot be modified.'), 422);
        }
    }

    protected function assertOrderNotPaid(Order $order): void
    {
        $paymentStatus = is_object($order->payment_status)
            ? (string) $order->payment_status
            : (string) $order->payment_status;

        if ($paymentStatus === 'paid' || $order->transactions()->where('success', true)->where('type', 'capture')->exists()) {
            throw OrderException::alreadyPaid();
        }
    }

    public function paymentProvidersQuery(mixed $root, array $args): array
    {
        $types = config('lunar.payments.types', [
            'card' => [
                'driver' => 'stripe',
                'name' => 'Credit/Debit Card',
            ],
            'cash-in-hand' => [
                'driver' => 'offline',
                'name' => 'Cash on Delivery',
            ],
        ]);

        $providers = [];
        foreach ($types as $handle => $config) {
            $name = $config['name'] ?? match ($handle) {
                'cash-in-hand' => 'Cash on Delivery',
                'card' => 'Credit/Debit Card',
                default => Str::headline((string) $handle),
            };

            $providers[] = [
                'id' => (string) $handle,
                'handle' => (string) $handle,
                'name' => $name,
                'driver' => $config['driver'] ?? 'manual',
                'enabled' => (bool) ($config['enabled'] ?? true),
            ];
        }

        return $providers;
    }

    public function authorizeOrderAccess(Order $order): void
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if ($order->user_id !== null && (! $user || (int) $order->user_id !== (int) $user->getAuthIdentifier())) {
            throw AuthenticationException::unauthorized(AuthenticationException::trans('order_unauthorized', 'You are not authorized to access this order.'));
        }

        if ($order->user_id === null && ! $this->isOrderInCurrentSession($order)) {
            throw AuthenticationException::unauthorized(AuthenticationException::trans('order_authentication_required', 'Authentication is required to access this order.'));
        }
    }

    protected function isOrderInCurrentSession(Order $order): bool
    {
        try {
            $sessionCartId = session(config('lunar.cart_session.session_key'));

            return $sessionCartId !== null
                && (int) $order->cart_id === (int) $sessionCartId
                && Cart::whereKey($sessionCartId)
                    ->whereHas('completedOrders', fn ($query) => $query->whereKey($order->id))
                    ->exists();
        } catch (\Throwable) {
        }

        return false;
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
