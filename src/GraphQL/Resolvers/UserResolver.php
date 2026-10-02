<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lunar\Core\Contracts\LunarUser;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Customer;
use Lunargraphql\Exceptions\AuthenticationException;
use Lunargraphql\Traits\UseLunargraphqlUsers;
use Lunargraphql\Traits\WithGlobalID;

class UserResolver
{
    use UseLunargraphqlUsers, WithGlobalID;

    /**
     * Create a new user and associate it with a customer record.
     */
    public function createUser(mixed $_, array $args): array
    {
        $name = trim((string) Arr::get($args, 'name'));
        $email = trim((string) Arr::get($args, 'email'));
        $password = (string) Arr::get($args, 'password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw AuthenticationException::invalidEmail();
        }

        if (strlen($password) < 8) {
            throw AuthenticationException::weakPassword();
        }

        $userModel = $this->getUserModel();

        if ($userModel->where('email', $email)->exists()) {
            throw AuthenticationException::userAlreadyExists();
        }

        $user = $userModel->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        if (method_exists($user, 'customers')) {
            // Automatically initialize a dedicated customer profile for the newly registered user
            $nameParts = explode(' ', (string) $name, 2);
            $customer = Customer::create([
                'first_name' => $nameParts[0] ?? $name,
                'last_name' => $nameParts[1] ?? '',
            ]);
            $user->customers()->attach($customer);
        }

        // Merge or associate guest cart if passed or present in session
        $this->associateCartWithUser($user, $args);

        $tokenName = Str::uuid()->toString();
        $token = method_exists($user, 'createToken')
            ? $user->createToken($tokenName)->plainTextToken
            : Str::random(60);

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Login a user.
     */
    public function login(mixed $_, array $args): array
    {
        $email = trim((string) Arr::get($args, 'email'));
        $password = (string) Arr::get($args, 'password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw AuthenticationException::invalidEmail();
        }

        $user = $this->getUserModel()
            ->where('email', $email)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw AuthenticationException::incorrectAccessData();
        }

        // Merge or associate guest cart if passed or present in session
        $this->associateCartWithUser($user, $args);

        $tokenName = Str::uuid()->toString();
        $token = method_exists($user, 'createToken')
            ? $user->createToken($tokenName)->plainTextToken
            : Str::random(60);

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Logout a user.
     */
    public function logout(mixed $_, array $args): bool
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if ($user) {
            if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            } elseif (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }
        }

        return true;
    }

    /**
     * Return authenticated user.
     */
    public function me(mixed $_, array $args): mixed
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            throw AuthenticationException::incorrectAccessData();
        }

        return $user;
    }

    /**
     * Reset password of the user.
     */
    public function resetPassword(mixed $_, array $args): bool
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            throw AuthenticationException::incorrectAccessData();
        }

        $currentPassword = Arr::get($args, 'currentPassword') ?? Arr::get($args, 'current_password');
        if ($currentPassword !== null && ! Hash::check($currentPassword, $user->password)) {
            throw AuthenticationException::passwordIncorrect();
        }

        $password = (string) Arr::get($args, 'password');
        $confirmation = Arr::get($args, 'passwordConfirmation') ?? Arr::get($args, 'password_confirmation');

        if ($confirmation !== null && $confirmation !== $password) {
            throw AuthenticationException::passwordConfirmationMismatch();
        }

        if (strlen($password) < 8) {
            throw AuthenticationException::weakPassword();
        }

        $user->password = Hash::make($password);
        $user->save();

        return true;
    }

    /**
     * Send email for reset password of the user.
     */
    public function sendResetPasswordEmail(mixed $_, array $args): bool
    {
        $status = Password::broker($this->getUserAuthProvider())
            ->sendResetLink([
                'email' => Arr::get($args, 'email'),
            ]);

        return match ($status) {
            Password::RESET_LINK_SENT => true,
            Password::INVALID_USER => true, // Return generic true to prevent user enumeration
            Password::RESET_THROTTLED => throw AuthenticationException::resetThrottled(),
            default => false,
        };
    }

    /**
     * Reset password of the user from email token.
     */
    public function resetPasswordFromToken(mixed $_, array $args): bool
    {
        $broker = $this->getUserAuthProvider();

        $email = Arr::get($args, 'email');
        $password = (string) Arr::get($args, 'password');
        $passwordConfirmation = Arr::get($args, 'passwordConfirmation') ?? Arr::get($args, 'password_confirmation', $password);
        $token = Arr::get($args, 'token');

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw AuthenticationException::invalidEmail();
        }

        if (empty($token)) {
            throw AuthenticationException::invalidToken();
        }

        if ($passwordConfirmation !== $password) {
            throw AuthenticationException::passwordConfirmationMismatch();
        }

        if (strlen($password) < 8) {
            throw AuthenticationException::weakPassword();
        }

        $status = Password::broker($broker)->reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
                'token' => $token,
            ],
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        return match ($status) {
            Password::PASSWORD_RESET => true,
            Password::INVALID_USER => throw AuthenticationException::invalidUser(),
            Password::INVALID_TOKEN => throw AuthenticationException::invalidToken(),
            Password::RESET_THROTTLED => throw AuthenticationException::resetThrottled(),
            default => false,
        };
    }

    /**
     * Update the authenticated user's account details.
     */
    public function updateUserProfileMutation(mixed $_, array $args): mixed
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            throw AuthenticationException::incorrectAccessData();
        }

        $email = Arr::get($args, 'email');
        if ($email !== null) {
            $email = trim((string) $email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw AuthenticationException::invalidEmail();
            }

            if ($email !== $user->email && $this->getUserModel()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                throw AuthenticationException::emailAlreadyInUse();
            }
        }

        $data = array_filter([
            'name' => Arr::get($args, 'name'),
            'email' => $email,
        ], fn ($v) => $v !== null);

        $user->update($data);

        return $user->refresh();
    }

    protected function associateCartWithUser(mixed $user, array $args): void
    {
        $cartId = $this->extractIdFromArgs($args, 'cartId');

        /** @var Cart|null $cart */
        $cart = $cartId
            ? (is_numeric($cartId) ? Cart::find($cartId) : Cart::where('public_id', $cartId)->first())
            : CartSession::current();

        if (! $cart) {
            return;
        }

        // Prevent taking over another user's cart
        if ($cart->user_id !== null && (int) $cart->user_id !== (int) $user->id) {
            return;
        }

        if ($cartId !== null && is_numeric($cartId)) {
            $sessionCartId = session(config('lunar.cart_session.session_key'));
            if ((int) $sessionCartId !== (int) $cart->id) {
                return;
            }
        }

        try {
            if ($user instanceof LunarUser) {
                CartSession::associate($cart, $user, 'merge');
            } else {
                $cart->user_id = $user->id;
                $cart->save();
            }
        } catch (\Throwable) {
            // Cart merge or association is non-fatal to login/registration
        }
    }

    public function resolveLatestCustomer(mixed $user, array $args): ?Customer
    {
        if (method_exists($user, 'latestCustomer')) {
            $customer = $user->latestCustomer();
            if ($customer) {
                return $customer;
            }
        }

        if (method_exists($user, 'customers')) {
            if ($user->relationLoaded('customers')) {
                return $user->customers->sortByDesc('created_at')->first();
            }

            return $user->customers()->latest()->first();
        }

        return null;
    }
}
