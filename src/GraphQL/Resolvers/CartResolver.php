<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Lunar\Core\Contracts\Actions\Carts\SetsShippingOption;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\ShippingManifest;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Channel;
use Lunar\Core\Models\Country;
use Lunar\Core\Models\Currency;
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
                'user_id' => $user?->id,
            ]);

            CartSession::use($cart);
        } elseif ($user && ! $cart->user_id) {
            $cart->user_id = $user->id;
            $cart->save();
        }

        try {
            $cart->calculate();
        } catch (\Throwable) {
            // Cart calculation may fail if empty or during partial state; that's normal
        }

        return $cart;
    }

    /**
     * @throws Throwable
     */
    public function addProductVariantToCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);

        /** @var ProductVariant|null $productVariant */
        $productVariant = $this->getModelFromGlobalId($args, 'productVariantID', ProductVariant::class);
        $quantity = (int) Arr::get($args, 'quantity', 1);

        throw_unless($productVariant, CartException::productVariantNotFound());

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
        $cart = $this->getCartQuery(null, []);
        $lineId = $this->extractIdFromArgs($args, 'cartLineID');
        $quantity = (int) Arr::get($args, 'quantity');

        $cartLine = $cart->lines()->find($lineId);
        throw_unless($cartLine, CartException::cartLineNotFound());

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
        $cart = $this->getCartQuery(null, []);
        $lineId = $this->extractIdFromArgs($args, 'cartLineID');

        $cartLine = $cart->lines()->find($lineId);
        throw_unless($cartLine, CartException::cartLineNotFound());

        $cart->remove($cartLine->id);

        return $cart->refresh()->calculate();
    }

    public function clearCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);
        $cart->lines()->delete();

        return $cart->refresh()->calculate();
    }

    public function setCartShippingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);
        $addressInput = Arr::get($args, 'address', []);

        $countryId = $this->extractIdFromArgs($addressInput, 'countryId');
        if (! $countryId && isset($addressInput['country'])) {
            $country = Country::where('iso2', $addressInput['country'])
                ->orWhere('iso3', $addressInput['country'])
                ->first();
            $countryId = $country?->id;
        }

        $addressData = [
            'country_id' => $countryId,
            'title' => Arr::get($addressInput, 'title'),
            'first_name' => Arr::get($addressInput, 'firstName'),
            'last_name' => Arr::get($addressInput, 'lastName'),
            'company_name' => Arr::get($addressInput, 'companyName'),
            'line_one' => Arr::get($addressInput, 'lineOne'),
            'line_two' => Arr::get($addressInput, 'lineTwo'),
            'line_three' => Arr::get($addressInput, 'lineThree'),
            'city' => Arr::get($addressInput, 'city'),
            'state' => Arr::get($addressInput, 'state'),
            'postcode' => Arr::get($addressInput, 'postcode'),
            'contact_email' => Arr::get($addressInput, 'contactEmail'),
            'contact_phone' => Arr::get($addressInput, 'contactPhone'),
            'delivery_instructions' => Arr::get($addressInput, 'deliveryInstructions'),
            'shipping_option' => Arr::get($addressInput, 'shippingOption'),
        ];

        $cart->setShippingAddress(array_filter($addressData, fn ($v) => $v !== null));

        return $cart->refresh()->calculate();
    }

    public function setCartBillingAddressMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);
        $addressInput = Arr::get($args, 'address', []);

        $countryId = $this->extractIdFromArgs($addressInput, 'countryId');
        if (! $countryId && isset($addressInput['country'])) {
            $country = Country::where('iso2', $addressInput['country'])
                ->orWhere('iso3', $addressInput['country'])
                ->first();
            $countryId = $country?->id;
        }

        $addressData = [
            'country_id' => $countryId,
            'title' => Arr::get($addressInput, 'title'),
            'first_name' => Arr::get($addressInput, 'firstName'),
            'last_name' => Arr::get($addressInput, 'lastName'),
            'company_name' => Arr::get($addressInput, 'companyName'),
            'line_one' => Arr::get($addressInput, 'lineOne'),
            'line_two' => Arr::get($addressInput, 'lineTwo'),
            'line_three' => Arr::get($addressInput, 'lineThree'),
            'city' => Arr::get($addressInput, 'city'),
            'state' => Arr::get($addressInput, 'state'),
            'postcode' => Arr::get($addressInput, 'postcode'),
            'contact_email' => Arr::get($addressInput, 'contactEmail'),
            'contact_phone' => Arr::get($addressInput, 'contactPhone'),
        ];

        $cart->setBillingAddress(array_filter($addressData, fn ($v) => $v !== null));

        return $cart->refresh()->calculate();
    }

    public function applyCouponToCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);
        $coupon = Arr::get($args, 'coupon');

        $cart->coupon_code = $coupon;
        $cart->save();

        return $cart->refresh()->calculate();
    }

    public function removeCouponFromCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);
        $cart->coupon_code = null;
        $cart->save();

        return $cart->refresh()->calculate();
    }

    public function calculateCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);

        return $cart->recalculate();
    }

    public function getShippingOptionsQuery(mixed $model, array $args): \Illuminate\Support\Collection
    {
        $cart = $this->getCartQuery(null, []);

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
        $cart = $this->getCartQuery(null, []);
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
        $cart = $this->getCartQuery(null, []);
        $code = Arr::get($args, 'currencyCode');
        $currency = Currency::where('code', $code)->first();
        throw_unless($currency, new CartException("Currency {$code} not found."));

        $cart->currency_id = $currency->id;
        $cart->save();
        CartSession::setCurrency($currency);

        return $cart->refresh()->calculate();
    }

    public function estimateShippingQuery(mixed $model, array $args): \Illuminate\Support\Collection
    {
        $cart = $this->getCartQuery(null, []);

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
            } catch (\Throwable) {
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
