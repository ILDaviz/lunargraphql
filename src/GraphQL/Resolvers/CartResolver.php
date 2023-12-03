<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Lunar\Models\Cart;
use Lunar\Models\ProductVariant;
use Lunargraphql\Exceptions\CartException;
use Lunargraphql\Traits\WithGlobalID;
use Throwable;

class CartResolver {

    use WithGlobalID;

    public function __construct(
        protected \Lunar\Base\CartSessionInterface $cartSession
    ) {
        // ...
    }

    public function getCartQuery(mixed $model, array $args): Cart
    {
        $cart = $this->cartSession->current();
        $user = $this->getUserLoggedIn();

        if ($cart === null){

            $cart = Cart::create([
                'currency_id' => $this->cartSession->getCurrency()->id,
                'channel_id' => $this->cartSession->getChannel()->id,
                'user_id' => $user?->id ?? null,
            ]);

            $this->cartSession->use($cart);
        }

        return $cart;
    }

    /**
     * @throws Throwable
     */
    public function addProductVariantToCartMutation(mixed $model, array $args): Cart
    {
        $cart = $this->getCartQuery(null, []);

        $productVariant = $this->getModelFromGlobalId($args, 'productVariantID', ProductVariant::class);

        $quantity = Arr::get($args, 'quantity');

        throw_unless($productVariant, CartException::productVariantNotFound());

        if ($quantity <= 0) {
            $cartLine = $cart->lines()->where([
                'purchasable_type' => ProductVariant::class,
                'purchasable_id' => $productVariant->id,
            ])->first();

            throw_unless($cartLine, CartException::cartLineNotFound());

            return $cart->refresh();
        }

        $cart->lines()->updateOrCreate([
            'purchasable_type' => ProductVariant::class,
            'purchasable_id' => $productVariant->id,
        ], [
            'quantity' => $quantity,
            'meta' => json_encode(Arr::get($args, 'meta'))
        ]);

        return $cart->refresh();
    }

    protected function getUserLoggedIn(): ?Authenticatable
    {
        if (Auth::guard('sanctum')->check()) {
            return Auth::guard('sanctum')->user();
        }

        return null;
    }
}
