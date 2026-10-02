<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Lunar\Core\Contracts\Actions\Carts\SetsShippingOption;
use Lunar\Core\Contracts\LunarUser;
use Lunar\Core\Enums\SellingPolicy;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\Discounts;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Channel;
use Lunar\Core\Models\Country;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Customer;
use Lunar\Core\Models\Discount;
use Lunar\Core\Models\ProductVariant;
use Lunar\Core\Models\Region;
use Lunargraphql\Exceptions\CartException;
use Lunargraphql\Traits\WithGlobalID;
use Throwable;

class CartResolver
{
    use WithGlobalID;

    public function getCartQuery(mixed $model, array $args): Cart
    {
        return $this->resolveCart($args);
    }

    public function resolveCart(array $args = []): Cart
    {
        $cartId = $this->extractIdFromArgs($args, 'cartId') ?? $this->extractIdFromArgs($args, 'cartID');
        $user = $this->getUserLoggedIn();

        if ($cartId !== null) {
            /** @var Cart|null $cart */
            $query = Cart::with(config('lunar.cart.eager_load', []));
            $cart = is_numeric($cartId)
                ? $query->find($cartId)
                : $query->where('public_id', $cartId)->first();
            throw_unless($cart, CartException::cartNotFound());

            if (is_numeric($cartId)) {
                $sessionCart = CartSession::current(calculate: false);
                if (! $sessionCart || (int) $sessionCart->id !== (int) $cart->id) {
                    throw CartException::cartNotFound();
                }
            }

            if ($cart->user_id && (! $user || (int) $cart->user_id !== (int) $user->getAuthIdentifier())) {
                throw CartException::cartNotFound();
            }
            if ($user && ! $cart->user_id) {
                if ($user instanceof LunarUser) {
                    CartSession::associate($cart, $user, config('lunar.cart.auth_policy', 'merge'));
                    $cart = CartSession::current(calculate: false) ?? Cart::with(config('lunar.cart.eager_load', []))->findOrFail($cart->id);
                } else {
                    $cart->user_id = $user->getAuthIdentifier();
                    $cart->save();
                }
            }
            try {
                $cart->calculate();
            } catch (Throwable) {
            }

            return $cart;
        }

        $cart = CartSession::current();
        $user = $this->getUserLoggedIn();

        if ($cart === null) {
            $currency = CartSession::getCurrency() ?? Currency::getDefault() ?? Currency::first();
            $channel = CartSession::getChannel() ?? Channel::getDefault() ?? Channel::first();
            $region = Region::getDefault() ?? Region::first();

            $cart = Cart::create([
                'currency_id' => $currency?->id,
                'channel_id' => $channel?->id,
                'region_id' => $region?->id,
                'user_id' => $user?->getAuthIdentifier(),
            ]);

            CartSession::use($cart);
        } elseif ($cart->user_id && (! $user || (int) $cart->user_id !== (int) $user->getAuthIdentifier())) {
            throw CartException::cartNotFound();
        } elseif ($user && ! $cart->user_id) {
            if ($user instanceof LunarUser) {
                CartSession::associate($cart, $user, config('lunar.cart.auth_policy', 'merge'));
                $cart = CartSession::current(calculate: false) ?? Cart::findOrFail($cart->id);
            } else {
                $cart->user_id = $user->getAuthIdentifier();
                $cart->save();
            }
        }

        try {
            $cart->calculate();
        } catch (Throwable) {
            // Cart calculation may fail if empty or during partial state; that's normal
        }

        return $cart;
    }

    public function resolveId(Cart $cart): string
    {
        return (string) $cart->public_id;
    }

    protected function assertCartNotCompleted(Cart $cart): void
    {
        if ($cart->hasCompletedOrders() && ! config('lunar.cart_session.allow_multiple_orders_per_cart', false)) {
            throw new CartException(CartException::trans('cart_already_ordered', 'This cart has already been converted into an order and cannot be modified.'), 422);
        }
    }

    /**
     * @throws Throwable
     */
    public function addProductVariantToCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        /** @var ProductVariant|null $productVariant */
        $productVariant = $this->getModelFromGlobalId($args, 'productVariantID', ProductVariant::class);
        $quantity = (int) Arr::get($args, 'quantity', 1);

        throw_unless($productVariant, CartException::productVariantNotFound());

        // Validate purchasability and cumulative stock through Lunar v2 APIs.
        if ($quantity > 0) {
            if (method_exists($productVariant, 'isPurchasable') && ! $productVariant->isPurchasable()) {
                throw new CartException(CartException::trans('not_purchasable', 'This product variant is currently not available for purchase.'), 422);
            }

            if ($productVariant->selling_policy !== SellingPolicy::Always) {
                $existingLine = $cart->lines()->where([
                    'purchasable_type' => $productVariant->getMorphClass(),
                    'purchasable_id' => $productVariant->id,
                ])->first();
                $currentQty = $existingLine ? (int) $existingLine->quantity : 0;

                if (! $productVariant->canBeFulfilledAtQuantity($currentQty + $quantity)) {
                    throw new CartException(CartException::trans('insufficient_stock', 'Insufficient stock available for this product variant.'), 422);
                }
            }
        }

        $meta = Arr::get($args, 'meta');
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : ['notes' => $meta];
        }

        if ($quantity <= 0) {
            $cartLine = $cart->lines()->where([
                'purchasable_type' => $productVariant->getMorphClass(),
                'purchasable_id' => $productVariant->id,
            ])->first();

            throw_unless($cartLine, CartException::cartLineNotFound());

            $cart->remove($cartLine->id);

            return $cart->refresh()->calculate();
        }

        $cart->add($productVariant, quantity: $quantity, meta: $meta ?? []);

        return $cart->refresh()->calculate();
    }

    /**
     * @throws Throwable
     */
    public function updateCartLineMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $lineId = $this->extractIdFromArgs($args, 'cartLineID');
        $quantity = (int) Arr::get($args, 'quantity');

        $cartLine = $cart->lines()->find($lineId);
        throw_unless($cartLine, CartException::cartLineNotFound());

        if ($quantity > 0 && $cartLine->purchasable) {
            $purchasable = $cartLine->purchasable;
            if (method_exists($purchasable, 'isPurchasable') && ! $purchasable->isPurchasable()) {
                throw new CartException(CartException::trans('not_purchasable', 'This product variant is currently not available for purchase.'), 422);
            }

            if ($purchasable->selling_policy !== SellingPolicy::Always) {
                if (! $purchasable->canBeFulfilledAtQuantity($quantity)) {
                    throw new CartException(CartException::trans('insufficient_stock', 'Insufficient stock available for this product variant.'), 422);
                }
            }
        }

        $meta = Arr::get($args, 'meta');
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : ['notes' => $meta];
        }

        if ($quantity <= 0) {
            $cart->remove($cartLine->id);
        } else {
            $lineMeta = $meta ?? ($cartLine->meta instanceof \ArrayObject ? $cartLine->meta->toArray() : (is_array($cartLine->meta) ? $cartLine->meta : []));
            $cart->updateLine($cartLine->id, quantity: $quantity, meta: $lineMeta);
        }

        return $cart->refresh()->calculate();
    }

    /**
     * @throws Throwable
     */
    public function removeCartLineMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $lineId = $this->extractIdFromArgs($args, 'cartLineID');

        $cartLine = $cart->lines()->find($lineId);
        throw_unless($cartLine, CartException::cartLineNotFound());

        $cart->remove($cartLine->id);

        return $cart->refresh()->calculate();
    }

    public function clearCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $cart->lines()->delete();

        return $cart->refresh()->calculate();
    }

    public function setCartShippingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $addressInput = Arr::get($args, 'address', []);

        $contactEmail = Arr::get($addressInput, 'contactEmail');
        if ($contactEmail !== null && ! filter_var(trim($contactEmail), FILTER_VALIDATE_EMAIL)) {
            throw new CartException(CartException::trans('invalid_contact_email', 'Invalid contact email address provided.'), 422);
        }

        $countryId = $this->extractIdFromArgs($addressInput, 'countryId');
        if (! $countryId && isset($addressInput['country'])) {
            $country = Country::where('iso2', $addressInput['country'])
                ->orWhere('iso3', $addressInput['country'])
                ->first();
            $countryId = $country?->id;
        }

        $taxIdentifier = Arr::get($addressInput, 'taxIdentifier') ?? Arr::get($addressInput, 'vatNo') ?? Arr::get($addressInput, 'tax_identifier');

        $addressData = [
            'country_id' => $countryId,
            'title' => Arr::get($addressInput, 'title'),
            'first_name' => Arr::get($addressInput, 'firstName'),
            'last_name' => Arr::get($addressInput, 'lastName'),
            'company_name' => Arr::get($addressInput, 'companyName'),
            'tax_identifier' => $taxIdentifier,
            'line_one' => Arr::get($addressInput, 'lineOne'),
            'line_two' => Arr::get($addressInput, 'lineTwo'),
            'line_three' => Arr::get($addressInput, 'lineThree'),
            'city' => Arr::get($addressInput, 'city'),
            'state' => Arr::get($addressInput, 'state'),
            'postcode' => Arr::get($addressInput, 'postcode'),
            'contact_email' => $contactEmail,
            'contact_phone' => Arr::get($addressInput, 'contactPhone'),
            'delivery_instructions' => Arr::get($addressInput, 'deliveryInstructions'),
            'shipping_option' => Arr::get($addressInput, 'shippingOption'),
        ];

        $cleanData = array_filter($addressData, fn ($v) => $v !== null);
        $cart->setShippingAddress($cleanData);

        if (Arr::get($addressInput, 'saveAddress') && $user = $this->getUserLoggedIn()) {
            $this->saveAddressToCustomerProfile($user, $cleanData, 'shipping');
        }

        // Automatic billing sync if requested
        if (Arr::get($args, 'sameAsBilling', false)) {
            $billingData = $cleanData;
            unset($billingData['shipping_option'], $billingData['delivery_instructions']);
            $cart->setBillingAddress($billingData);

            if (Arr::get($addressInput, 'saveAddress') && $user = $this->getUserLoggedIn()) {
                $this->saveAddressToCustomerProfile($user, $billingData, 'billing');
            }
        }

        return $cart->refresh()->calculate();
    }

    public function setCartBillingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        // Option 1: Copy from existing shipping address
        if (Arr::get($args, 'sameAsShipping', false)) {
            $shippingAddress = $cart->shippingAddress;
            throw_unless($shippingAddress, new CartException(CartException::trans('invalid_shipping_address', 'A valid shipping address is required before copying to billing.'), 422));

            $billingData = [
                'country_id' => $shippingAddress->country_id,
                'title' => $shippingAddress->title,
                'first_name' => $shippingAddress->first_name,
                'last_name' => $shippingAddress->last_name,
                'company_name' => $shippingAddress->company_name,
                'tax_identifier' => $shippingAddress->tax_identifier,
                'line_one' => $shippingAddress->line_one,
                'line_two' => $shippingAddress->line_two,
                'line_three' => $shippingAddress->line_three,
                'city' => $shippingAddress->city,
                'state' => $shippingAddress->state,
                'postcode' => $shippingAddress->postcode,
                'contact_email' => $shippingAddress->contact_email,
                'contact_phone' => $shippingAddress->contact_phone,
            ];

            $cleanData = array_filter($billingData, fn ($v) => $v !== null);
            $cart->setBillingAddress($cleanData);

            if ($user = $this->getUserLoggedIn()) {
                $this->saveAddressToCustomerProfile($user, $cleanData, 'billing');
            }

            return $cart->refresh()->calculate();
        }

        // Option 2: Explicit billing / invoicing address
        $addressInput = Arr::get($args, 'address', []);
        throw_if(empty($addressInput), new CartException(CartException::trans('invalid_billing_address', 'A valid billing address is required.'), 422));

        $contactEmail = Arr::get($addressInput, 'contactEmail');
        if ($contactEmail !== null && ! filter_var(trim($contactEmail), FILTER_VALIDATE_EMAIL)) {
            throw new CartException(CartException::trans('invalid_contact_email', 'Invalid contact email address provided.'), 422);
        }

        $countryId = $this->extractIdFromArgs($addressInput, 'countryId');
        if (! $countryId && isset($addressInput['country'])) {
            $country = Country::where('iso2', $addressInput['country'])
                ->orWhere('iso3', $addressInput['country'])
                ->first();
            $countryId = $country?->id;
        }

        $taxIdentifier = Arr::get($addressInput, 'taxIdentifier') ?? Arr::get($addressInput, 'vatNo') ?? Arr::get($addressInput, 'tax_identifier');

        $addressData = [
            'country_id' => $countryId,
            'title' => Arr::get($addressInput, 'title'),
            'first_name' => Arr::get($addressInput, 'firstName'),
            'last_name' => Arr::get($addressInput, 'lastName'),
            'company_name' => Arr::get($addressInput, 'companyName'),
            'tax_identifier' => $taxIdentifier,
            'line_one' => Arr::get($addressInput, 'lineOne'),
            'line_two' => Arr::get($addressInput, 'lineTwo'),
            'line_three' => Arr::get($addressInput, 'lineThree'),
            'city' => Arr::get($addressInput, 'city'),
            'state' => Arr::get($addressInput, 'state'),
            'postcode' => Arr::get($addressInput, 'postcode'),
            'contact_email' => $contactEmail,
            'contact_phone' => Arr::get($addressInput, 'contactPhone'),
        ];

        $cleanData = array_filter($addressData, fn ($v) => $v !== null);
        $cart->setBillingAddress($cleanData);

        if (Arr::get($addressInput, 'saveAddress') && $user = $this->getUserLoggedIn()) {
            $this->saveAddressToCustomerProfile($user, $cleanData, 'billing');
        }

        return $cart->refresh()->calculate();
    }

    public function setCartCustomerShippingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $user = $this->getUserLoggedIn();
        throw_unless($user, new CartException('Authentication required to use saved customer address.', 401));

        $customer = $this->getCustomerFromUser($user);
        throw_unless($customer, new CartException('Customer profile not found.', 404));

        $addressId = $this->extractIdFromArgs($args, 'customerAddressId') ?? $this->extractIdFromArgs($args, 'addressId');
        $customerAddress = $customer->addresses()->find($addressId);
        throw_unless($customerAddress, new CartException('Customer address not found or not owned by the authenticated customer.', 404));

        $addressData = [
            'country_id' => $customerAddress->country_id,
            'title' => $customerAddress->title,
            'first_name' => $customerAddress->first_name,
            'last_name' => $customerAddress->last_name,
            'company_name' => $customerAddress->company_name,
            'tax_identifier' => $customerAddress->tax_identifier,
            'line_one' => $customerAddress->line_one,
            'line_two' => $customerAddress->line_two,
            'line_three' => $customerAddress->line_three,
            'city' => $customerAddress->city,
            'state' => $customerAddress->state,
            'postcode' => $customerAddress->postcode,
            'contact_email' => $customerAddress->contact_email,
            'contact_phone' => $customerAddress->contact_phone,
            'delivery_instructions' => $customerAddress->delivery_instructions,
            'shipping_option' => Arr::get($args, 'shippingOption'),
        ];

        $cart->setShippingAddress(array_filter($addressData, fn ($v) => $v !== null));

        if (Arr::get($args, 'sameAsBilling', false)) {
            $billingData = $addressData;
            unset($billingData['shipping_option'], $billingData['delivery_instructions']);
            $billingData['tax_identifier'] = $customerAddress->tax_identifier;
            $cart->setBillingAddress(array_filter($billingData, fn ($v) => $v !== null));
        }

        return $cart->refresh()->calculate();
    }

    public function setCartCustomerBillingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        if (Arr::get($args, 'sameAsShipping', false)) {
            $shippingAddress = $cart->shippingAddress;
            throw_unless($shippingAddress, new CartException(CartException::trans('invalid_shipping_address', 'A valid shipping address is required before copying to billing.'), 422));

            $billingData = [
                'country_id' => $shippingAddress->country_id,
                'title' => $shippingAddress->title,
                'first_name' => $shippingAddress->first_name,
                'last_name' => $shippingAddress->last_name,
                'company_name' => $shippingAddress->company_name,
                'tax_identifier' => $shippingAddress->tax_identifier,
                'line_one' => $shippingAddress->line_one,
                'line_two' => $shippingAddress->line_two,
                'line_three' => $shippingAddress->line_three,
                'city' => $shippingAddress->city,
                'state' => $shippingAddress->state,
                'postcode' => $shippingAddress->postcode,
                'contact_email' => $shippingAddress->contact_email,
                'contact_phone' => $shippingAddress->contact_phone,
            ];

            $cart->setBillingAddress(array_filter($billingData, fn ($v) => $v !== null));

            return $cart->refresh()->calculate();
        }

        $user = $this->getUserLoggedIn();
        throw_unless($user, new CartException('Authentication required to use saved customer address.', 401));

        $customer = $this->getCustomerFromUser($user);
        throw_unless($customer, new CartException('Customer profile not found.', 404));

        $addressId = $this->extractIdFromArgs($args, 'customerAddressId') ?? $this->extractIdFromArgs($args, 'addressId');
        $customerAddress = $customer->addresses()->find($addressId);
        throw_unless($customerAddress, new CartException('Customer address not found or not owned by the authenticated customer.', 404));

        $addressData = [
            'country_id' => $customerAddress->country_id,
            'title' => $customerAddress->title,
            'first_name' => $customerAddress->first_name,
            'last_name' => $customerAddress->last_name,
            'company_name' => $customerAddress->company_name,
            'tax_identifier' => $customerAddress->tax_identifier,
            'line_one' => $customerAddress->line_one,
            'line_two' => $customerAddress->line_two,
            'line_three' => $customerAddress->line_three,
            'city' => $customerAddress->city,
            'state' => $customerAddress->state,
            'postcode' => $customerAddress->postcode,
            'contact_email' => $customerAddress->contact_email,
            'contact_phone' => $customerAddress->contact_phone,
        ];

        $cart->setBillingAddress(array_filter($addressData, fn ($v) => $v !== null));

        return $cart->refresh()->calculate();
    }

    public function applyCouponToCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $coupon = trim((string) Arr::get($args, 'coupon'));
        if (empty($coupon)) {
            throw new CartException(CartException::trans('coupon_required', 'A valid coupon code is required.'), 422);
        }

        if (class_exists(Discounts::class)) {
            $discount = Discount::where('coupon', strtoupper($coupon))->first();
            if ($discount && ! Discounts::validateCoupon($coupon)) {
                throw new CartException(CartException::trans('coupon_invalid', 'The coupon code provided is expired or has reached its usage limit.'), 422);
            }
        }

        $cart->coupon_code = $coupon;
        $cart->save();

        return $cart->refresh()->calculate();
    }

    public function removeCouponFromCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $cart->coupon_code = null;
        $cart->save();

        return $cart->refresh()->calculate();
    }

    public function calculateCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);

        return $cart->recalculate();
    }

    protected function getCustomerFromUser(mixed $user): ?Customer
    {
        if (method_exists($user, 'latestCustomer') && $customer = $user->latestCustomer()) {
            return $customer;
        }

        if (method_exists($user, 'customers')) {
            $customer = $user->customers()->first();
            if ($customer) {
                return $customer;
            }

            $nameParts = explode(' ', (string) $user->name, 2);
            $customer = Customer::create([
                'first_name' => $nameParts[0] ?? $user->name,
                'last_name' => $nameParts[1] ?? '',
            ]);
            $user->customers()->attach($customer);

            return $customer;
        }

        return null;
    }

    protected function saveAddressToCustomerProfile(mixed $user, array $data, string $type = 'shipping'): void
    {
        try {
            $customer = $this->getCustomerFromUser($user);
            if (! $customer) {
                return;
            }

            unset($data['shipping_option']);
            if ($type === 'shipping') {
                $data['shipping_default'] = ! $customer->addresses()->where('shipping_default', true)->exists();
            } else {
                $data['billing_default'] = ! $customer->addresses()->where('billing_default', true)->exists();
            }

            $customer->addresses()->create($data);
        } catch (Throwable) {
        }
    }

    public function getShippingOptionsQuery(mixed $model, array $args): Collection
    {
        $cart = $this->resolveCart($args);

        if (! $cart->isShippable()) {
            return collect();
        }

        $options = ShippingManifest::getOptions($cart);

        return $options->map(function ($option) {
            return [
                'name' => $option->name,
                'description' => $option->description,
                'identifier' => $option->identifier,
                'price' => $option->price->value,
                'priceFormatted' => $option->price->format(),
                'priceDecimal' => $option->price->decimal(),
                'collect' => $option->collect,
                'taxClass' => $option->taxClass,
                'meta' => $option->meta ? json_encode($option->meta) : null,
            ];
        });
    }

    public function setCartShippingOptionMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);
        $optionIdentifier = Arr::get($args, 'shippingOption');

        $shippingAddress = $cart->shippingAddress;
        throw_unless($shippingAddress, new CartException('Shipping address must be set before selecting a shipping option.'));

        $option = ShippingManifest::getOption($cart, $optionIdentifier);

        if ($option) {
            app(SetsShippingOption::class)->execute($cart, $option);
        } else {
            $shippingAddress->update([
                'shipping_option' => $optionIdentifier,
            ]);
        }

        return $cart->refresh()->calculate();
    }

    // Resolvers for Cart computed totals

    public function resolveSubTotal(Cart $cart, array $args): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->subTotal?->value;
    }

    public function resolveSubTotalFormatted(Cart $cart, array $args): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->subTotal?->format();
    }

    public function resolveTaxTotal(Cart $cart, array $args): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->taxTotal?->value;
    }

    public function resolveTaxTotalFormatted(Cart $cart, array $args): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->taxTotal?->format();
    }

    public function resolveDiscountTotal(Cart $cart, array $args): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->discountTotal?->value;
    }

    public function resolveDiscountTotalFormatted(Cart $cart, array $args): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->discountTotal?->format();
    }

    public function resolveShippingTotal(Cart $cart, array $args): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->shippingTotal?->value;
    }

    public function resolveShippingTotalFormatted(Cart $cart, array $args): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->shippingTotal?->format();
    }

    public function resolveTotal(Cart $cart, array $args): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->total?->value;
    }

    public function resolveTotalFormatted(Cart $cart, array $args): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->total?->format();
    }

    // Resolvers for Cart lines and computed totals

    public function resolveLines(Cart $cart): iterable
    {
        $this->ensureCalculated($cart);

        return $cart->lines;
    }

    public function resolveLineSubTotal($cartLine, array $args): ?int
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->subTotal?->value;
    }

    public function resolveLineSubTotalFormatted($cartLine, array $args): ?string
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->subTotal?->format();
    }

    public function resolveLineTotal($cartLine, array $args): ?int
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->total?->value;
    }

    public function resolveLineTotalFormatted($cartLine, array $args): ?string
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->total?->format();
    }

    public function resolveLineUnitPrice($cartLine, array $args): ?int
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->unitPrice?->value;
    }

    public function resolveLineUnitPriceFormatted($cartLine, array $args): ?string
    {
        $this->ensureLineCalculated($cartLine);

        return $cartLine->unitPrice?->format();
    }

    protected function ensureLineCalculated($cartLine): void
    {
        if ($cartLine->unitPrice === null && $cartLine->cart) {
            $cartLine->cart->calculate();
            $calculatedLine = $cartLine->cart->lines->firstWhere('id', $cartLine->id);
            if ($calculatedLine) {
                $cartLine->unitPrice = $calculatedLine->unitPrice;
                $cartLine->unitPriceInclTax = $calculatedLine->unitPriceInclTax;
                $cartLine->subTotal = $calculatedLine->subTotal;
                $cartLine->subTotalDiscounted = $calculatedLine->subTotalDiscounted;
                $cartLine->discountTotal = $calculatedLine->discountTotal;
                $cartLine->taxAmount = $calculatedLine->taxAmount;
                $cartLine->total = $calculatedLine->total;
            }
        }
    }

    public function setCartCurrencyMutation(mixed $model, array $args): Cart
    {
        $cart = $this->resolveCart($args);
        $this->assertCartNotCompleted($cart);

        $code = Arr::get($args, 'currencyCode');
        $currency = Currency::where('code', $code)->where('enabled', true)->first();
        throw_unless($currency, new CartException(CartException::trans('currency_not_found_or_disabled', "Currency {$code} not found or is disabled.", ['code' => $code])));

        $cart->currency_id = $currency->id;
        $cart->save();
        CartSession::setCurrency($currency);

        return $cart->refresh()->calculate();
    }

    public function estimateShippingQuery(mixed $model, array $args): Collection
    {
        $cart = $this->resolveCart($args);

        if (! $cart->isShippable()) {
            return collect();
        }

        $countryId = $this->extractIdFromArgs($args, 'countryId');
        if (! $countryId && isset($args['country'])) {
            $country = Country::where('iso2', $args['country'])
                ->orWhere('iso3', $args['country'])
                ->first();
            $countryId = $country?->id;
        }

        $params = array_filter([
            'country_id' => $countryId,
            'state' => Arr::get($args, 'state'),
            'postcode' => Arr::get($args, 'postcode'),
        ], fn ($v) => $v !== null);

        $cart->shippingEstimateMeta = $params;

        $options = ShippingManifest::getOptions($cart);

        return $options->map(function ($option) {
            return [
                'name' => $option->name,
                'description' => $option->description,
                'identifier' => $option->identifier,
                'price' => $option->price->value,
                'priceFormatted' => $option->price->format(),
                'priceDecimal' => $option->price->decimal(),
                'collect' => $option->collect,
                'taxClass' => $option->taxClass,
                'meta' => $option->meta ? json_encode($option->meta) : null,
            ];
        });
    }

    public function resolveIsShippable(Cart $cart): bool
    {
        return $cart->isShippable();
    }

    public function resolveTotalQuantity(Cart $cart): int
    {
        return (int) $cart->lines->sum('quantity');
    }

    public function resolveSubTotalDiscounted(Cart $cart): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->subTotalDiscounted?->value;
    }

    public function resolveSubTotalDiscountedFormatted(Cart $cart): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->subTotalDiscounted?->format();
    }

    public function resolveShippingSubTotal(Cart $cart): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->shippingSubTotal?->value;
    }

    public function resolveShippingSubTotalFormatted(Cart $cart): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->shippingSubTotal?->format();
    }

    public function resolveShippingTaxTotal(Cart $cart): ?int
    {
        $this->ensureCalculated($cart);

        return $cart->shippingTaxTotal?->value;
    }

    public function resolveShippingTaxTotalFormatted(Cart $cart): ?string
    {
        $this->ensureCalculated($cart);

        return $cart->shippingTaxTotal?->format();
    }

    public function resolveTaxBreakdown(Cart $cart): array
    {
        $this->ensureCalculated($cart);
        $taxBreakdown = $cart->taxBreakdown;

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

    public function resolveDiscountBreakdown(Cart $cart): array
    {
        $this->ensureCalculated($cart);
        $breakdown = $cart->discountBreakdown;

        if (! $breakdown) {
            return [];
        }

        return $breakdown->map(function ($item) {
            $price = $item->price ?? $item->total ?? null;

            return [
                'discount' => $item->discount ?? null,
                'name' => $item->discount?->name ?? 'Discount',
                'price' => $price?->value ?? 0,
                'priceFormatted' => $price?->format() ?? '',
                'priceDecimal' => $price?->decimal() ?? 0.0,
            ];
        })->values()->toArray();
    }

    protected function ensureCalculated(Cart $cart): void
    {
        if (! $cart->isCalculated()) {
            try {
                $cart->calculate();
            } catch (Throwable) {
                // Ignore calculation errors during view
            }
        }
    }

    protected function getUserLoggedIn(): ?Authenticatable
    {
        if (Auth::guard('sanctum')->check()) {
            return Auth::guard('sanctum')->user();
        }

        if (Auth::check()) {
            return Auth::user();
        }

        return null;
    }
}
