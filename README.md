# Lunar GraphQL for LunarPHP 2.x

A **ALPHA** feature GraphQL API layer for [LunarPHP 2.x](https://lunarphp.com) built on top of [Nuwave Lighthouse 6.x](https://lighthouse-php.com).

Designed for headless e-commerce frontends (Next.js, Nuxt, Remix, Mobile Apps) with support for catalog browsing, multi-currency pricing, cart management, shipping estimations, customer accounts, and order processing.

---

## Features

- **Storefront & Catalog**:
  - Full product browsing with pagination, attribute filtering, channel/collection scoping, and price range filters.
  - Direct price resolution (`product.price`, `productVariant.price`, `basePrices`, `priceBreaks`).
  - Hierarchical collection navigation with nested sets, ancestors, descendants, and breadcrumbs.
  - Product options, option values, and image galleries with thumbnail conversions.
  - SEO-friendly lookup by URL slug for products (`productBySlug`) and collections (`collectionBySlug`).
- **Cart & Shipping**:
  - Session and authenticated user cart lifecycle with automatic currency/channel resolution.
  - Add, update quantity, remove, and clear cart lines with metadata support.
  - Dynamic currency switching (`setCartCurrency`) with automatic line item recalculations.
  - Shipping options discovery (`shippingOptions`) and pre-address shipping estimations (`estimateShipping`).
  - Detailed totals, discounted subtotal, shipping breakdown, tax breakdown, and coupon handling.
- **Checkout & Orders**:
  - Order creation from cart with automatic line mapping and tax calculations.
  - Order lifecycle tracking (`isOpen`, `isClosed`, `isCancelled`, `lifecycleStatus`, `cancelReasonLabel`).
  - Specialized line subsets (`shippingLines`, `productLines`) and formatted currency amounts.
  - Payment transaction recording (`recordOrderTransaction`) supporting captures, intents, and refunds.
  - Secure guest order tracking (`guestOrder(reference, email)`) and customer order history (`myOrders`).
- **Customer & Authentication**:
  - Built-in Sanctum authentication (`login`, `createUser`, `logout`, `resetPassword`, `me`).
  - Full customer address book management with default shipping/billing addresses.
  - Customer profile updates (`taxIdentifier`, company, name, contact details).
- **Global ID & Relay Compatibility**:
  - `SmartGlobalId` directive supporting Relay base64 IDs, `Type:ID` strings, and raw database IDs seamlessly.

---

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`
- Lunar Core `2.0.0-alpha.6` (LunarPHP v2 prerelease)
- Nuwave Lighthouse `^6.0`
- Laravel Sanctum `^4.0`

> This release targets Lunar Core `2.0.0-alpha.6`; compatibility with a stable LunarPHP v2 release is not yet certified.

## Storefront and payment security

- Guest cart IDs use Lunar's opaque `public_id`; treat them as bearer credentials and do not expose them in public URLs or logs.
- An authenticated customer can access only their own carts and orders. A guest looking up an order outside its owning cart session must provide the reference and matching billing/shipping email.
- `recordOrderTransaction` is restricted to authenticated callers with the host application's `record-order-transaction` Gate ability and accepts only manual entries. Record card/provider outcomes from a verified server-side webhook, never from browser-supplied success values.
- Payment providers must be configured in `lunar.payments.types`. Missing or failing providers return an error; the API does not fabricate payment intents or successful transactions.
- To allow a trusted staff role to record transactions, define the `record-order-transaction` Gate ability in the host Laravel application and ensure it checks a privileged role/policy rather than ordinary customer ownership.

---

## Installation

Install the package via Composer:

```bash
composer require ildaviz/lunargraphql
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag="lunargraphql-config"
```

If you wish to customize or override the GraphQL schemas directly, publish them to your project:

```bash
php artisan vendor:publish --tag="lunargraphql-schema"
```

## Security

If you discover any security-related issues, please email `davidgalet+lunargraphql@gmail.com` rather than using the issue tracker.

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).
