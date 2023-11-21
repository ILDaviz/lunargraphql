<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lunar\Models\Customer;
use Lunargraphql\Exceptions\AuthenticationException;
use Lunargraphql\Traits\UseLunargraphqlUsers;

class UserResolver {

    use UseLunargraphqlUsers;

    /**
     * Create a new user and associate it with a customer if exits.
     */
    public function createUser(mixed $_, array $args): array
    {
        $name = Arr::get($args, 'name');
        $email = Arr::get($args, 'email');
        $password = Arr::get($args, 'password');

        $customer = Arr::get($args, 'customerID');

        $this->getUserModel()
            ->where('email', $email)
            ->exists();

        throw_if($this->getUserModel()
            ->where('email', $email)
            ->exists(), AuthenticationException::userAlreadyExists());

        $user = $this->getUserModel()
            ->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

        if ($customer = Customer::query()
            ->where('id', $customer)
            ->first()){

            $user->customers()->attach($customer);
        }

        $tokenName = Str::uuid()->toString();
        $token = $user->createToken($tokenName)->plainTextToken;

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

        throw_if(! $user, AuthenticationException::userNotFound());

        throw_if(! Hash::check($password, $user->password), AuthenticationException::passwordIncorrect());

        $tokenName = Str::uuid()->toString();
        $token = $user->createToken($tokenName)->plainTextToken;

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
        $user = Auth::guard('sanctum')->user();
        $user->tokens()->delete();

        return true;
    }

    /**
     * Reset password of the user.
     */
    public function resetPassword(mixed $_, array $args): bool
    {
        $password = Arr::get($args, 'password');

        $user = Auth::guard('sanctum')->user();
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
            ->sendResetLink(
                Arr::get($args, 'email')
            );

        return match ($status) {
            Password::RESET_LINK_SENT => true,
            Password::INVALID_USER => throw AuthenticationException::invalidUser(),
            Password::INVALID_TOKEN => throw AuthenticationException::invalidToken(),
            Password::RESET_THROTTLED => throw AuthenticationException::resetThrottled(),
            default => false,
        };
    }

    /**
     * Reset password of the user from email.
     */
    public function resetPasswordFromToken(mixed $_, array $args): bool
    {
        $broker = $this->getUserAuthProvider();

        $email = Arr::get($args, 'email');
        $password = Arr::get($args, 'password');
        $passwordConfirmation = Arr::get($args, 'password_confirmation');
        $token = Arr::get($args, 'token');

        $status = Password::broker($broker)
            ->reset(
                [
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $passwordConfirmation,
                    'token' => $token,
                ],
                function ($user, $password) {
                    $user->forceFill([
                        'password' => $password,
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
}
