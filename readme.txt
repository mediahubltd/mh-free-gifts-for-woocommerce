=== MH Free Gifts for WooCommerce ===
Contributors: mediahub
Plugin URI: https://www.mediahubsolutions.com/mh-free-gifts-for-woocommerce/
Description: Let customers choose or auto-add a free gift when cart criteria are met (threshold, qty, dependencies).
Tags: free gifts for woocommerce, buy one get one, free gift, Gift Product Woocommerce, WooCommerce gift
Requires at least: 6.0
Tested up to: 6.9.4
Stable tag: 1.1.1
Version: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://ko-fi.com/adk77

Offer free gifts automatically in WooCommerce! Set up smart rules based on cart value, items, or user roles — fully supports WooCommerce Blocks.

== Description ==

**MH Free Gifts for WooCommerce** gives store owners a powerful yet intuitive way to reward customers with complimentary products based on custom cart rules.

### ✨ Key Features

* 💯 **COMPLETELY FREE** — no upsells or pro version. 100% functional out of the box.
* 🎁 **Add Multiple Free Gift Rules** — create unlimited gift rules with different conditions and products.
* 🤖 **Auto-add Single Gift Rules** — automatically add a qualifying gift to the cart when the rule is met, including repeated copies when quantity multiples apply.
* 1️⃣ **Optional Non-Stacking Mode** — limit customers to gifts from one eligible rule at a time when multiple rules qualify.
* 🔁 **Quantity-Based Gift Multiples** — scale the number of allowed gifts as customers hit higher cart-quantity multiples.
* 🎯 **Dependency-Scoped Thresholds** — optionally count quantity and subtotal thresholds against only the matching dependency items instead of the whole cart.
* 🧭 **Configurable Checkout Placement** — choose where the free gift section appears on classic WooCommerce checkout.
* ✍️ **Custom Gift Text & Sizing** — change main gift labels and control button/heading font sizes without editing code.
* ⚙️ **Smart Rule Conditions & Limits** — restrict by subtotal, quantity, date range, product, or user.
* 🧩 **WooCommerce Blocks Support** — supports both classic and block-based cart and checkout, including shared gift-rule logic and block-aware gift panels.
* 🚀 **Lightweight & Optimized** — uses a dedicated database table for speed and reliability.


### 🛒 How It Works

1. Define your free gift rules in the admin — choose eligible products, usage limits, and visibility options.  
2. Choose whether subtotal and quantity thresholds should use the whole cart or only the dependency-matching products when you want rules like “buy 2 from category X”.  
3. Customers who qualify see a responsive **“Choose Your Free Gift”** section on the cart (and optionally checkout) page.  
4. Customers can either choose a gift manually or let a single-gift rule auto-add it to the cart at $0.  
5. MH Free Gifts handles all validation and limits automatically.

Behind the scenes, the plugin intelligently evaluates cart contents, enforces limits, and prevents abuse — creating a **seamless, self-contained gifting experience** that enhances WooCommerce’s promotion capabilities without extra plugins or conflicts.

###Free Gift Admin settings

General Settings
* **Status** (Active or Disabled)
* **Rule Name** (name it something meaningful) - only for admin use
* **Description** (describe your rule) - only for admin use
* **Select Gifts** (Select 1 or more gifts you would like in your gift rule)
* **Auto-add Gift** (Automatically adds the selected gift to the cart when the rule is met. Requires exactly 1 selected gift and uses a base quantity of 1)
Display Settings
* **Display Gifts On** (Toggle between Cart or Cart/Checkout mode)
* **Items Per Row (Cart)** (decide how many items in a row you want displayed)
Usage Restrictions
* **Product Dependency** (Lock down rule to only allow free gifts to activate if any of these products are in the cart)
* **Product Category Dependency** (Limit rule to selected categories)
* **Threshold Scope** (Choose whether Cart Subtotal, Cart Quantity, and quantity multiples use the whole purchased cart or only items matching the selected product/category dependencies. Example: if your rule is set to Cart Quantity >= 2 and a category dependency is set, Whole Purchased Cart qualifies with 1 matching item + 1 non-matching item, while Matching Dependency Items Only requires 2 matching items.)
* **User Dependency** (Limit the rule to individual customers)
* **Registered Users** Only (Only allowed existing customers to activate the rule)
* **Number of Gifts Allowed** (Restrict the number of gifts a customer can add to their cart)
* **Cart Subtotal** (Is Less Than, Is Greater Than, Is Less Than or Equal To, Is Greater Than or Equal To, Is Equal To) Set you Subtotal threshold amount
* **Cart Quantity** (Is Less Than, Is Greater Than, Is Less Than or Equal To, Is Greater Than or Equal To, Is Equal To) Set you Quantity threshold amount
* **Repeat Gifts For Quantity Multiples** (Scales the Number of Gifts Allowed for each qualifying Cart Quantity multiple)
* **Valid From** (Set valid from date)
* **Valid To** (Set valid to date)
Usage Limits
* **Usage Limit per Rule** (Limits how many time the gift rule can be used)
* **Usage Limit per User** (Limits how many times an individual user can use the gift rule)
Plugin Settings
* **Allow Gift Accumulation** (When disabled, customers can keep gifts from only one eligible rule at a time)
* **Checkout Placement** (Choose the classic WooCommerce checkout hook used for the free gift toggle)
* **Cart Heading Text** (Customize the main “Choose Your Free Gift” heading)
* **Checkout Toggle Text** (Customize the classic checkout toggle label, for example “Free Gift”)
* **Add Button Text** (Customize the add button label)
* **Remove Button Text** (Customize the remove button label)
* **Cart Heading Font Size** (Adjust the cart/section heading size)
* **Checkout Toggle Font Size** (Adjust the classic checkout toggle size)
* **Button Font Size** (Adjust the Add/Remove button text size)
* **Button Text / Background / Border Colors** (Style the gift buttons)
* **Button Border Size** (Adjust button border thickness)
* **Button Border Radius** (Adjust button corner roundness)


== Screenshots ==
1. The “Choose Your Free Gift” section on the WooCommerce cart page.
2. Free Gift section at checkout. Can remove & add gifts here also.
3. Add/Edit Rule page in Admin
4. Settings for custom button styles

== Installation ==

1. Login to your WordPress admin.
2. Navigate to "Plugins > Add New".
3. Type "Free Gifts" into the Keyword search box and press the Enter key.
4. Find the "MH Free Gifts for WooCommerce" plugin. Note: the plugin is made by "mediahub".
5. Click the "Install Now" button.
6. Click the "Activate" button.
7. Navigate to "Free Gifts" to add and maintain free gifts.

== Frequently Asked Questions ==

= Does it work with WooCommerce Blocks? =
Yes — MH Free Gifts for WooCommerce supports WooCommerce Cart and Checkout blocks for the core gift-rule logic, manual gift panel, auto-add behavior, and quantity locking. Classic checkout hook placement and toggle settings remain classic-checkout-only.

= Can I create multiple gift rules? =
Absolutely! You can define unlimited rules, each with unique conditions and eligible products.

= Can I limit gifts per user or order? =
Yes — the plugin supports per-user and per-rule usage limits.

= Is it really free? =
Yes! There’s no premium version or upsells. Everything is included for free.

= What do I do if I need help? =
Support is provided via the WordPress.org forums or through the Mediahub support site.

== Changelog ==

= 1.1.1 (2026-03-31) =
* [Fixed] Rules can now scope Cart Quantity and Cart Subtotal thresholds to dependency-matching items, so offers such as “buy 2 from category X” no longer qualify with 1 item from category X plus 1 from another category.
* [Improved] Quantity-multiple gift scaling now follows the same dependency-scoped quantity basis when that rule option is enabled.
* [Improved] The rule editor now includes a Threshold Scope setting and only shows it when product or category dependencies are configured, making quantity-based dependency rules clearer to set up.
* [Fixed] Upgraded installs now explicitly add the new Threshold Scope database column if dbDelta misses it, so dependency-scoped quantity and subtotal rules persist correctly after plugin updates.
* [Fixed] Rule saves now retry after forcing the latest schema upgrade and show an admin error notice instead of silently failing when the rules table is missing a required column.
* [Fixed] Dependency-scoped category rules now correctly recognize variation products by using the parent product categories during rule evaluation.
* [Improved] Threshold Scope help text now includes whole-cart versus dependency-only examples so quantity-based category rules are easier to understand.
* [Improved] Busy rule-editor help text is now being moved into compact info-icon tooltips, starting with the category dependency and Threshold Scope fields.
* [Improved] Expanded compact info-icon tooltips to other long rule-form descriptions, including gift selection, auto-add, cart layout, gift quantity, and quantity-multiple guidance.
* [Improved] Threshold Scope tooltip wording has been simplified with shorter whole-cart versus dependency-only examples.

= 1.1.0 (2026-03-26) =
* [Added] Per-rule Auto-add Gift support for single-gift promotions.
* [Added] Global setting to disable gift accumulation so customers can keep gifts from only one eligible rule at a time.
* [Added] Per-rule gift quantity multipliers for Cart Quantity thresholds such as 2 items = 1 gift, 4 items = 2 gifts, 6 items = 3 gifts.
* [Fixed] Quantity-multiplied gift rules now support selecting multiple copies of the same gift product instead of treating repeated selections as a single gift.
* [Fixed] Quantity-based gift multipliers now ignore gift line items in the cart, and the non-accumulation setting now blocks stacking across rules without blocking multiple gifts within the same eligible rule.
* [Fixed] Gift eligibility is now re-pruned during the same cart recalculation cycle when cart quantities drop, so excess gifts are removed immediately when the rule no longer allows them.
* [Fixed] Rule edits now bump the frontend rules revision so changes such as enabling auto-add gift take effect immediately in cart and checkout sessions.
* [Improved] Auto-add rules can now work with Repeat Gifts For Quantity Multiples, automatically adding multiple copies of the single configured gift product as cart quantity increases.
* [Added] Checkout placement setting for classic WooCommerce checkout, plus customizable cart/checkout gift labels and button/heading font sizes from the plugin settings page.
* [Fixed] Classic checkout gift toggle arrows now point in the correct direction, and the settings live preview now appears lower on the settings page with the button styling controls.
* [Fixed] Initial checkout loads and checkout refreshes now keep the Free Gift toggle arrow and expanded/collapsed panel state in sync instead of reopening the panel with the wrong icon state.
* [Improved] Classic cart and checkout free-gift rows now hide the $0.00 price display and show a cleaner bold italic “Free gift” label under the product name.
* [Improved] WooCommerce Blocks gift rows now match the classic free-gift presentation more closely, block panels can show inactive manual tiers as disabled cards, and rule display location is now respected when rendering cart versus checkout gifts.
* [Improved] Confirmed compatibility and updated plugin metadata for WordPress 6.9.4.
* [Improved] In non-stacking mode, auto-added gifts now prefer the highest qualifying tier instead of the first matching rule.
* [Improved] Auto-added gifts are hidden from the manual selector UI and locked to a quantity of 1.
* [Fixed] Registered-users-only rules now stay hidden for guest customers on checkout and AJAX-rendered gift sections.
* [Fixed] Variation gifts now map correctly in the cart for selector state and cleanup.

= 1.0.12 (2026-02-08) =
* [Improved] Gift eligibility calculations to exclude free gifts and respect tax-inclusive totals.
* [Improved] Gift grid layout when multiple rules are active.
* [Fixed] Incorrect gift rule activation after adding a free gift in some scenarios.

= 1.0.11 (2026-01-15) =
* [Fixed] Improved rule date handling

= 1.0.10 (2026-01-05) =
* [Fixed] Cart Evaluation flow upgraded

= 1.0.9 (2025-11-13) =
* [Fixed] Gift wasn't being removed from cart when threshold was no longer met
* [Fixed] State change fix on checkout page

= 1.0.8 (2025-10-17) =
* [Added] Category dependancy functionality

= 1.0.7 (2025-10-16) =
* [Added] Color picker added to color fields
  [Fixed] Button preview when no radius applied

= 1.0.6 (2025-10-15) =
* [Added] Button Styling functionality in plugin settings
  [Fixed] Minor styling tweaks

= 1.0.5 (2025-10-08) =
* [Fixed] Plugin icon change

= 1.0.4 (2025-10-06) =
* [Added] Added Support for Woocommerce Blocks

= 1.0.3 (2025-09-24) =
* [Fixed] Locked the ability for customers to increase quantity on the free product line
* [Added] Security enhancements

= 1.0.2 (2025-07-11) =
* [Fixed] Rules cache was not cleared upon save, resulting in eligible gift rules not showing

= 1.0.1 (2025-06-25) =
* [Fixed] Save notice missing after successful save

= 1.0.0 (2025-04-05) =
* Initial release

== License ==

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html
