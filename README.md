# Tonyapps_B2bQuotes

Negotiable quotes for Tonyapps_B2b company accounts on Magento Open Source. A buyer sends their cart as a price
request, the seller answers with prices per line, and the buyer checks out at the agreed prices. Adobe sells this
only in Commerce B2B (`Magento_NegotiableQuote`).

**Status: scope agreed 2026-11-03 (all three proposals accepted). Phases 1 and 2 built 2026-11-03; manual QA (phase 3) pending.**

"Quote" in this file means the B2B price request. Magento's own `quote` table, the cart, is called "cart".

## Scope and decisions

| Feature | Decision |
|---|---|
| Who can request | Active company members with a new company permission **Request quotes** (`quotes_request`, added to `Tonyapps_B2b` `Permissions`). The company administrator always has it. A buyer sees their own quotes. The company administrator sees every quote in the company. |
| Request | On the cart page, **Request a quote** with a required comment. The cart lines are copied into the quote: product, SKU, name, buy request (options), qty, and the catalog price at request time. The cart itself is left unchanged. |
| Products | Simple, virtual and configurable products. A cart containing anything else (bundle, grouped, gift card, downloadable) cannot be sent, and the cart page says why. |
| Statuses | `open` (waiting for the seller) → `offered` (seller set prices) → `ordered`. Also `declined` by the seller, `closed` by the buyer, and `expired`. The buyer can send an offered quote back with a comment, which returns it to `open`. Every change adds a line to the quote history. |
| Seller side (admin) | **Sales > B2B Quotes**: a grid, and an edit page. The edit page shows the lines with their catalog price, qty and a **negotiated unit price** field, the comment thread, and an expiry date (default N days from config). Actions: **Send offer**, **Decline**. ACL `Tonyapps_B2bQuotes::quotes`. In phase 1 the seller cannot change qty or add lines. |
| Buyer side (storefront) | **My Account > Quotes**: a list, and a quote page with lines, prices, total, thread and expiry. Actions: comment and send back, close, and **Checkout with this quote**. Pages are not full-page cached. |
| Checkout at the agreed price | **Checkout with this quote** replaces the current cart with the quote lines and sets each line's negotiated price as Magento's custom price, so totals, tax and every payment method keep working. If the cart was not empty, the buyer confirms the replacement first. The cart remembers the quote id. |
| Locked lines | At order submit (`QuoteManagement::submit`, the same hook B2bOrderApproval uses), a cart that carries a quote id must still match the quote lines and qty, and the quote must still be `offered` and not expired. Otherwise checkout is refused with a message. The cart page shows a **Leave quote** button that removes the negotiated prices and the link. |
| Discounts | Cart price rules and coupons do not apply to lines of a negotiated cart; Tonyapps_FreeGift gifts still do (see the 2026-11-09 QA fix). The negotiated price is the final item price. |
| Shipping | Calculated normally at checkout. It is not negotiated. |
| Currency and tax | Negotiated prices are stored in the website base currency and converted to the cart currency when applied. They are entered in the same tax mode (incl. or excl. tax) as catalog prices. |
| After the order | The quote becomes `ordered` and stores the order id. Both quote pages link to the order. An ordered quote cannot be used again. |
| Expiry | Checked when the quote is shown and at checkout, with no cron. A quote past its expiry date is shown and treated as `expired`. |
| Emails | New request and buyer reply go to a configured sales email. Offer and decline go to the buyer, with a copy to the company administrator when the administrator is not the buyer. |
| Interplay | B2bOrderApproval still applies to a negotiated cart above its threshold. Approval is the company's internal control, and its cart fingerprint already includes prices. Store credit and payment restrictions are unchanged. |
| Configuration | Per website: enable switch (disabled by default), default expiry in days, sales email recipient, and email templates. |

## Data model

- `tonyapps_b2b_quote`: id, company id, buyer id, name and email, website id, store id, base currency code, status, expiry date, cart id (set at checkout), order id, base subtotal of the request and of the offer, created and updated at.
- `tonyapps_b2b_quote_item`: id, quote id, product id, SKU, name, buy request (JSON), qty, base catalog price, base negotiated price (nullable until an offer).
- `tonyapps_b2b_quote_history`: id, quote id, author type (buyer/seller/system), author id, comment, status after, created at.

## Out of scope (phase 1)

- The seller changing qty, adding or removing lines, or creating a quote from admin on the buyer's behalf.
- Negotiated shipping price, a percentage discount for the whole quote, and quote PDFs.
- File attachments in the thread, quote templates, and quotes for guests or non-company customers.
- Quotes for bundle, grouped, gift card and downloadable products.

## Phases

1. **Request and negotiation:** schema, the `quotes_request` permission in B2b, request from the cart, My Account pages, admin grid and edit page, thread, statuses, emails, and unit tests.
2. **Checkout at the agreed price:** load into cart with custom prices and `no_discount`, the submit guard, **Leave quote**, the order link and the `ordered` status. Unit tests, and integration tests for the submit guard.
3. **Manual verification:** request, offer, send back, re-offer and checkout with Adyen, store credit and order approval. Then a changed cart refused at checkout, an expired quote, and a coupon not applied to a negotiated cart.

## Phase 1 notes

- The admin list is a paged table, not a UI-component grid; there is no filtering or sorting yet.
- The seller form has three buttons: **Send Offer**, **Send Comment Only** (emails the buyer, status unchanged) and **Decline**. An expired offer can be renewed with a new offer.
- The catalog price stored at request time is the cart line's `price`: base currency, in the catalog's tax mode. This is the same mode Magento uses for a custom price.
- Buyers reply and close from the quote page. A reply always returns the quote to `open`.
- Emails use the `sales` sender identity. The seller email does not link to the admin panel; it names the menu path.

## Phase 2 notes

- Only the buyer who requested the quote can check out with it. The company administrator can see the quote but cannot check out with it.
- The cart is linked through `tonyapps_b2b_quote.cart_id`. The order gate is a plugin on `QuoteManagement::submit()`, which also covers PayPal Express and admin orders. Multishipping is refused for a linked cart, and `/checkout` redirects back to the cart when the gate would refuse the order.
- The cart matches the quote when it has the same SKUs at the same quantities, each still at its negotiated price, compared to 2 decimals in the cart currency. Tonyapps_FreeGift gift lines are ignored when requesting, matching and leaving a quote.
- A buyer reply, a close, a decline or expiry takes away the cart's right to the prices. The cart page then shows why and offers **Leave Quote**. **Leave Quote** is not gated by the permission or the enable switch, so a buyer who loses either can still return the cart to regular prices.
- A linked cart cannot request a new quote until the buyer leaves the current one.

## 2026-11-09 QA fix

- Negotiated lines are marked with the cart item option `tonyapps_b2b_quote` instead of `no_discount`. Core skips `no_discount` lines for every cart price rule, which also stopped Tonyapps_FreeGift rules, so a quoted cart over the gift threshold got no gift. A plugin on `Magento\SalesRule\Model\Validator::process()` now lets only free-gift rules reach a quoted line; discounts and coupons still never apply to it. **Leave Quote** removes the option (and still clears `no_discount` on carts loaded before this change).

## 2026-11-09 admin UX

- The quote page follows the admin order view: header buttons (**Back**, **Decline** with a confirmation, **Send Comment Only**, **Send Offer**), *Quote & Account Information* (status badge, dates, linked order number, company and buyer), *Items Quoted* (SKU under the name, catalog and negotiated price, discount % and row total that update as prices are typed, and **Discount all lines by X%**), *Comments History* newest first and *Quote Totals* with the offer's validity date.
- The admin shows statuses from the seller's side (*Awaiting your offer*, *Offer sent*, *Closed by buyer*, ...) as grid badges; the quote list uses the same badges and names its amount columns *Catalog Subtotal* and *Offer Subtotal*.

## Install

Install `tonyapps/module-b2b-quotes` with `tonyapps/module-b2b`, run `bin/magento setup:upgrade`, enable **Stores > Configuration > Tonyapps > B2B Quotes**, and give company roles the **Request quotes** permission. Company administrators always have it.

## Tests

`vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Tonyapps/B2bQuotes/Test/Unit`
