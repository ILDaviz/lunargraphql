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

- PHP `^8.2`, `^8.3`, or `^8.4`
- Laravel `^11.0` or `^12.0`
- Lunar Core `^2.0`
- Nuwave Lighthouse `^6.0`
- Laravel Sanctum `^4.0`

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
