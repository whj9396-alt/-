=== HAOJ1E Multi-Site Direct USDT ===
Contributors: haoj1e
Tags: woocommerce, usdt, trc20, erc20, tron, ethereum, crypto
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Direct, self-monitored USDT payments on TRON and Ethereum for WooCommerce.

== Description ==

Customers transfer USDT directly to the merchant's public TRON or Ethereum address. Separate TRC20 and ERC20 gateways verify finalized events from the official Tether contracts and call WooCommerce payment_complete only after the exact order amount is confirmed.

The plugin has no merchant-configured minimum payment amount. Wallet withdrawal minimums and network fees still apply outside the plugin. The plugin never requests or stores a seed phrase or private key.

== Installation ==

1. Upload and activate the plugin.
2. Open WooCommerce > Settings > Payments.
3. Configure the HAOJ1E TRC20 and/or ERC20 gateway with the matching public address and manual exchange rate.
4. Save and confirm that node health passes before enabling the gateway.
5. Test each enabled network with a small real order before production use.

== Security ==

Never enter a seed phrase, private key, or wallet password into WordPress. The plugin validates the network, official Tether contract, receiving address, exact amount, successful receipt and finalized block before completing an order.

== Changelog ==

= 1.2.0 =
* Added a separate USDT-ERC20 WooCommerce gateway and Ethereum scanner.
* Added per-network addresses, RPC settings, health status, cursors and audit links.
* Kept the plugin free of a minimum payment rule; external wallet and network limits still apply.

= 1.1.0 =
* Added HAOJ1E multi-site naming based on the current domain.
* Revalidated quote matching, finalized-block verification, and WooCommerce completion behavior.

= 1.0.0 =
* Initial direct USDT-TRC20 release.
