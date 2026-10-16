=== Paystack Integration Health Checker ===
Contributors: bnyekefamo
Tags: paystack, woocommerce, payments, diagnostics, webhooks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnose Paystack payment integrations: configuration, API authentication, webhooks, WooCommerce compatibility and payment flow. Read-only.

== Description ==

**Independent project:** not affiliated with, endorsed by or supported by Paystack.

When Paystack payments fail or orders don't update, the cause can be keys, the environment, the network, webhooks, WooCommerce compatibility or the gateway itself. This plugin checks all of them and reports each finding as PASS, WARNING, FAIL or SKIPPED, with the evidence behind it and what to do next.

* Works with the official Paystack WooCommerce Payment Gateway, with Gateway for Paystack on WooCommerce, and with custom integrations (manual keys), including sites without WooCommerce.
* Confirms connectivity and authentication with one read-only, documented Paystack API request.
* Webhook diagnostics that only contact your own site, including an optional signed, non-payment test event.
* Reads HPOS and Checkout Blocks compatibility of your gateway from WooCommerce itself.
* Verifies a test transaction reference and compares it with an order, without changing the order.
* Diagnostic history, run-to-run comparison and sanitized JSON/text reports.
* Never displays, logs or exports secret keys. Never changes orders or settings.

= External service =

When diagnostics run, the plugin sends requests to the Paystack API (https://api.paystack.co) using the secret key configured on your site:
* GET /integration/payment_session_timeout, to confirm connectivity and authentication;
* GET /transaction/verify/{reference}, only for a reference you enter in Payment Diagnostics.

No other data is sent. No requests are made to any other third-party service. Paystack terms: https://paystack.com/terms. Privacy policy: https://paystack.com/privacy.

== Installation ==

1. Upload the ZIP under Plugins > Add New Plugin > Upload Plugin and activate it.
2. Open WooCommerce > Paystack Health Check (or Tools > Paystack Health Check without WooCommerce).
3. Click Run Diagnostics.

For custom integrations, enter your keys under Configuration > Health checker settings, or define PIHC_TEST_SECRET_KEY / PIHC_LIVE_SECRET_KEY in wp-config.php.

== Frequently Asked Questions ==

= Does it change my orders or settings? =

No. Orders are only read; gateway and store settings are only read. The optional signed test event is a non-payment event sent to your own site and cannot change orders.

= Why is a check SKIPPED? =

Its prerequisite was missing (for example no WooCommerce or no secret key), or the result cannot be observed from inside your site. Skipped checks never affect the score.

= Can it check my Paystack dashboard settings? =

No. It cannot read your Paystack dashboard. Where that matters (for example the configured webhook URL or webhook delivery logs) the results tell you what to check there.

= What is removed on uninstall? =

The checker's own settings, diagnostic history tables and temporary data. Nothing belonging to WooCommerce or Paystack gateways is touched.

== Changelog ==

= 1.0.0 =
* Initial release.
