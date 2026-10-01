<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lunar\Core\Models\Customer;
use Lunargraphql\Exceptions\AuthenticationException;
use Lunargraphql\Traits\UseLunargraphqlUsers;
use Lunargraphql\Traits\WithGlobalID;

class UserResolver
{
    use UseLunargraphqlUsers, WithGlobalID;

    /**
     * Create a new user and associate it with a customer if exists or requested.
     */
    public function createUser(mixed $_, array $args): array
    {
        $name = Arr::get($args, 'name');
        $email = Arr::get($args, 'email');
        $password = Arr::get($args, 'password');
        $customerInput = Arr::get($args, 'customerID');

        $userModel = $this->getUserModel();

        if ($userModel->where('email', $email)->exists()) {
            throw AuthenticationException::userAlreadyExists();
        }

        $user = $userModel->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        if ($customerInput) {
            $customerId = $this->extractIdFromArgs(['id' => $customerInput], 'id');
            $customer = Customer::query()->find($customerId);

            if ($customer && method_exists($user, 'customers')) {
                $user->customers()->attach($customer);
            }
        } elseif (method_exists($user, 'customers')) {
            // Automatically create a default customer record for the new user if none provided
            $nameParts = explode(' ', (string) $name, 2);
            $customer = Customer::create([
                'first_name' => $nameParts[0] ?? $name,
                'last_name' => $nameParts[1] ?? '',
            ]);
            $user->customers()->attach($customer);
        }

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
        $email = Arr::get($args, 'email');
        $password = Arr::get($args, 'password');

        $user = $this->getUserModel()
            ->where('email', $email)
            ->first();

        if (! $user) {
            throw AuthenticationException::userNotFound();
        }

        if (! Hash::check($password, $user->password)) {
            throw AuthenticationException::passwordIncorrect();
        }

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

        if ($user && method_exists($user, 'tokens')) {
            $user->tokens()->delete();
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
        $password = Arr::get($args, 'password');

        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            throw AuthenticationException::incorrectAccessData();
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
            Password::INVALID_USER => throw AuthenticationException::invalidUser(),
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
        $password = Arr::get($args, 'password');
        $passwordConfirmation = Arr::get($args, 'password_confirmation', $password);
        $token = Arr::get($args, 'token');

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

        $data = array_filter([
            'name' => Arr::get($args, 'name'),
            'email' => Arr::get($args, 'email'),
        ], fn ($v) => $v !== null);

        $user->update($data);

        return $user->refresh();
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
