<?php

namespace Lunargraphql\GraphQL\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Lunar\Core\Models\Address;
use Lunar\Core\Models\Country;
use Lunar\Core\Models\Customer;
use Lunargraphql\Exceptions\AuthenticationException;
use Lunargraphql\Exceptions\CartException;
use Lunargraphql\Traits\WithGlobalID;

class CustomerAddressResolver
{
    use WithGlobalID;

    public function getCustomerProfileQuery(mixed $root, array $args): ?Customer
    {
        return $this->getAuthenticatedCustomer();
    }

    public function updateCustomerProfileMutation(mixed $root, array $args): Customer
    {
        $customer = $this->getAuthenticatedCustomer();
        throw_unless($customer, AuthenticationException::incorrectAccessData());

        $taxIdentifier = Arr::get($args, 'taxIdentifier', Arr::get($args, 'vatNo'));
        $data = array_filter([
            'title' => Arr::get($args, 'title'),
            'first_name' => Arr::get($args, 'firstName'),
            'last_name' => Arr::get($args, 'lastName'),
            'company_name' => Arr::get($args, 'companyName'),
            'tax_identifier' => $taxIdentifier,
            'account_ref' => Arr::get($args, 'accountRef'),
        ], fn ($v) => $v !== null);

        $customer->update($data);

        return $customer->refresh();
    }

    public function getCustomerAddressesQuery(mixed $root, array $args): Collection
    {
        $customer = $this->getAuthenticatedCustomer();

        if (! $customer) {
            return collect();
        }

        return $customer->addresses()->get();
    }

    public function createCustomerAddressMutation(mixed $root, array $args): Address
    {
        $customer = $this->getAuthenticatedCustomer();

        throw_unless($customer, AuthenticationException::incorrectAccessData());

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

        $data = [
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
            'delivery_instructions' => Arr::get($addressInput, 'deliveryInstructions'),
            'contact_email' => $contactEmail,
            'contact_phone' => Arr::get($addressInput, 'contactPhone'),
            'shipping_default' => (bool) Arr::get($addressInput, 'shippingDefault', false),
            'billing_default' => (bool) Arr::get($addressInput, 'billingDefault', false),
        ];

        if ($data['shipping_default']) {
            $customer->addresses()->update(['shipping_default' => false]);
        }

        if ($data['billing_default']) {
            $customer->addresses()->update(['billing_default' => false]);
        }

        return $customer->addresses()->create(array_filter($data, fn ($v) => $v !== null));
    }

    public function updateCustomerAddressMutation(mixed $root, array $args): Address
    {
        $customer = $this->getAuthenticatedCustomer();
        throw_unless($customer, AuthenticationException::incorrectAccessData());

        $addressId = $this->extractIdFromArgs($args, 'id');
        $address = $customer->addresses()->find($addressId);

        throw_unless($address, new CartException(CartException::trans('address_not_found', 'Address not found'), 404));

        $addressInput = Arr::get($args, 'address', []);
        $contactEmail = Arr::get($addressInput, 'contactEmail', $address->contact_email);
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

        $taxIdentifier = Arr::get($addressInput, 'taxIdentifier') ?? Arr::get($addressInput, 'vatNo') ?? Arr::get($addressInput, 'tax_identifier', $address->tax_identifier);

        $data = [
            'title' => Arr::get($addressInput, 'title', $address->title),
            'first_name' => Arr::get($addressInput, 'firstName', $address->first_name),
            'last_name' => Arr::get($addressInput, 'lastName', $address->last_name),
            'company_name' => Arr::get($addressInput, 'companyName', $address->company_name),
            'tax_identifier' => $taxIdentifier,
            'line_one' => Arr::get($addressInput, 'lineOne', $address->line_one),
            'line_two' => Arr::get($addressInput, 'lineTwo', $address->line_two),
            'line_three' => Arr::get($addressInput, 'lineThree', $address->line_three),
            'city' => Arr::get($addressInput, 'city', $address->city),
            'state' => Arr::get($addressInput, 'state', $address->state),
            'postcode' => Arr::get($addressInput, 'postcode', $address->postcode),
            'delivery_instructions' => Arr::get($addressInput, 'deliveryInstructions', $address->delivery_instructions),
            'contact_email' => $contactEmail,
            'contact_phone' => Arr::get($addressInput, 'contactPhone', $address->contact_phone),
        ];

        if ($countryId) {
            $data['country_id'] = $countryId;
        }

        if (Arr::has($addressInput, 'shippingDefault')) {
            $data['shipping_default'] = (bool) $addressInput['shippingDefault'];
            if ($data['shipping_default']) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['shipping_default' => false]);
            }
        }

        if (Arr::has($addressInput, 'billingDefault')) {
            $data['billing_default'] = (bool) $addressInput['billingDefault'];
            if ($data['billing_default']) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['billing_default' => false]);
            }
        }

        $address->update($data);

        return $address->refresh();
    }

    public function deleteCustomerAddressMutation(mixed $root, array $args): bool
    {
        $customer = $this->getAuthenticatedCustomer();
        throw_unless($customer, AuthenticationException::incorrectAccessData());

        $addressId = $this->extractIdFromArgs($args, 'id');
        $address = $customer->addresses()->find($addressId);

        if (! $address) {
            return false;
        }

        $wasShippingDefault = (bool) $address->shipping_default;
        $wasBillingDefault = (bool) $address->billing_default;

        $address->delete();

        if ($wasShippingDefault) {
            $next = $customer->addresses()->first();
            if ($next) {
                $next->update(['shipping_default' => true]);
            }
        }

        if ($wasBillingDefault) {
            $next = $customer->addresses()->first();
            if ($next) {
                $next->update(['billing_default' => true]);
            }
        }

        return true;
    }

    public function setDefaultCustomerAddressMutation(mixed $root, array $args): Address
    {
        $customer = $this->getAuthenticatedCustomer();
        throw_unless($customer, AuthenticationException::incorrectAccessData());

        $addressId = $this->extractIdFromArgs($args, 'id');
        $type = strtoupper((string) Arr::get($args, 'type', 'SHIPPING'));

        /** @var Address|null $address */
        $address = $customer->addresses()->find($addressId);
        throw_unless($address, new CartException('Address not found', 404));

        if ($type === 'SHIPPING' || $type === 'BOTH') {
            $customer->addresses()->update(['shipping_default' => false]);
            $address->shipping_default = true;
        }

        if ($type === 'BILLING' || $type === 'BOTH') {
            $customer->addresses()->update(['billing_default' => false]);
            $address->billing_default = true;
        }

        $address->save();

        return $address->refresh();
    }

    protected function getAuthenticatedCustomer(): ?Customer
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'latestCustomer')) {
            $customer = $user->latestCustomer();
            if ($customer) {
                return $customer;
            }
        }

        if (method_exists($user, 'customers')) {
            $customer = $user->customers()->first();
            if ($customer) {
                return $customer;
            }

            // Create a default customer record for user if missing
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

    public function getCustomerAddressQuery(mixed $root, array $args): ?Address
    {
        $customer = $this->getAuthenticatedCustomer();
        if (! $customer) {
            return null;
        }

        $id = $this->extractIdFromArgs($args, 'id');

        return $customer->addresses()->find($id);
    }

    public function resolveDefaultShippingAddress(Customer $customer): ?Address
    {
        if ($customer->relationLoaded('addresses')) {
            return $customer->addresses->firstWhere('shipping_default', true);
        }

        return $customer->addresses()->where('shipping_default', true)->first();
    }

    public function resolveDefaultBillingAddress(Customer $customer): ?Address
    {
        if ($customer->relationLoaded('addresses')) {
            return $customer->addresses->firstWhere('billing_default', true);
        }

        return $customer->addresses()->where('billing_default', true)->first();
    }
}
