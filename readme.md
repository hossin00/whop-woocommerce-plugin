# Whop WooCommerce Checkout

Replace the WooCommerce checkout with an embedded Whop checkout while keeping customers on the same domain.

## Description

This plugin replaces the standard WooCommerce checkout experience with an embedded Whop checkout flow, while keeping customers on the same domain.

## Installation

1. Upload the `whop-woocommerce-plugin` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure settings in WooCommerce if available.

## Requirements

- PHP 8.2+
- WordPress 6.0+
- WooCommerce 8.0+

## Changelog

### 0.1.3
- Stabilized the production runtime package by restoring Composer --no-dev dependencies and removing the unsafe development autoload payload.
- Restored the checkout loader asset version constant and retained the current embedded checkout configuration flow.

### 0.1.1
- Added embedded local checkout routes (`/checkout/` and `/checkout/complete`) wiring and templates.
- Added upgrade-safe rewrite flush scheduling on plugin version change.
- Updated redirect layer to keep checkout flow on store domain.

### 0.1.0
- Initial plugin scaffolding.

## Languages
The customer-facing checkout and plugin notices use WordPress/WooCommerce locale detection and include English (source), French (fr_FR), Spanish (es_ES), and German (de_DE) translations. The active store language is used automatically when WordPress or the site language plugin sets the locale.
