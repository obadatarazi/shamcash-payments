=== ShamCash Payments ===
Contributors: obadaaltarazi
Tags: shamcash, payments, woocommerce, syria, e-wallet, gateway
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
WC requires at least: 8.0
WC tested up to: 11.2

Accept ShamCash e-wallet payments in WooCommerce. Secure encrypted communication, automatic order status updates via webhooks, and full refund support.

== Description ==

ShamCash Payments is a professional WooCommerce payment gateway for the ShamCash platform (Syria).

Developed by [Obada Altarazi](https://sy.linkedin.com/in/obada-tarazi).

The bundled PHP client is based on the [ShamCash PHP SDK](https://github.com/OkToCode-L-L-C/Sham-Cash-PHP-SDK) by OkToCode, maintained via [this fork](https://github.com/obadatarazi/Sham-Cash-PHP-SDK).

**Key features**

* Create payment bills and redirect customers to the ShamCash payment page
* Automatic order status updates via encrypted webhooks with server-side bill verification
* Full refund support (partial and full) with idempotency
* Classic checkout and WooCommerce Blocks (block checkout) support
* Clean settings page inside WooCommerce → Settings → Payments
* Debug logging integrated with WooCommerce logs
* HPOS (High-Performance Order Storage) compatible
* Uses WordPress HTTP API (no Composer / Guzzle required on the site)
* English and Arabic language files included
* PHP 8.2+ and latest WordPress / WooCommerce ready

**Supported currencies**

* USD
* SYP

== Installation ==

1. Upload the `shamcash-payments` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments → ShamCash
4. Enter your Agent Key, Secret Key and Base URL
5. Enable the gateway and save
6. (Optional) Copy the displayed Webhook URL into your ShamCash merchant settings if required

== Frequently Asked Questions ==

= Do I need a ShamCash merchant account? =

Yes. You must complete service creation in the ShamCash dashboard to obtain the Agent Key and Secret Key.

= Is the communication secure? =

Yes. All requests and webhooks use Direct JWE encryption (A256GCM). The secret key never leaves your server. Paid webhooks are re-verified with getBill before the order is marked paid.

= What happens if the customer does not pay? =

ShamCash expires unpaid bills after a short period. The plugin receives the expired webhook and can mark the order as failed or cancelled according to your settings.

= Can I refund an order? =

Yes. Full and partial refunds are supported from the WooCommerce order screen.

== Credits ==

* Plugin developed by [Obada Altarazi](https://sy.linkedin.com/in/obada-tarazi)
* Upstream PHP SDK: [OkToCode ShamCash PHP SDK](https://github.com/OkToCode-L-L-C/Sham-Cash-PHP-SDK)
* Maintained fork: [obadatarazi/Sham-Cash-PHP-SDK](https://github.com/obadatarazi/Sham-Cash-PHP-SDK)

== Changelog ==

= 1.0.0 =
* Initial commercial release for Syria
* WooCommerce payment gateway (classic + Blocks)
* WordPress HTTP transport (no Guzzle)
* Encrypted webhook handling with verification, locks, and idempotency
* Refund support including refund webhooks
* Admin order meta box
* HPOS compatible
* English + Arabic translations
