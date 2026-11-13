# Tonyapps_B2bQuotes — notes for AI agents and developers

Read this before changing the module. Workspace-wide rules are in ../AGENTS.md.

Package `tonyapps/module-b2b-quotes` v0.1.0, proprietary. Negotiable quotes for `Tonyapps_B2b` company accounts on
Magento Open Source (Adobe sells this only in Commerce B2B, `Magento_NegotiableQuote`).

Terminology: "quote" = the B2B price request (`tonyapps_b2b_quote`). Magento's own `quote` table, the cart, is called
"cart".

## Status

- Scope agreed 2026-11-03 (all three proposals accepted).
- Phases 1 and 2 built 2026-11-03. 2026-11-09: QA fix, admin UX and email redesign (see Change history).
- **Phase 3 (manual QA) pending.**
- **Phase 4 (sales reps and commission): scope draft 2026-11-09, awaiting owner approval. Not built.**

Phases:

1. **Request and negotiation:** schema, the `quotes_request` permission in B2b, request from the cart, My Account
   pages, admin grid and edit page, thread, statuses, emails, unit tests.
2. **Checkout at the agreed price:** load into cart with custom prices and `no_discount` (since 2026-11-09 the
   `tonyapps_b2b_quote` option instead), the submit guard, **Leave quote**, the order link and the `ordered` status.
   Unit tests, and integration tests for the submit guard.
3. **Manual verification:** request, offer, send back, re-offer and checkout with Adyen, store credit and order
   approval. Then a changed cart refused at checkout, an expired quote, and a coupon not applied to a negotiated cart.
4. **Sales reps and commission** — draft below.

### Phase 4 draft: sales reps and commission (2026-11-09, awaiting approval)

An admin user who handles a quote is its **sales rep** and earns a percentage of what the quote sells. Decisions taken
with the owner on 2026-11-09: per-rep default rate with a per-quote override; commission on the quote's net value;
earned on invoice, reduced by refunds; quotes assigned automatically with manual reassignment.

| Feature | Decision |
|---|---|
| Rep's default rate | A **Commission Rate (%)** field on the admin user form (**System > Permissions > All Users**), stored in a new table `tonyapps_b2b_sales_rep` (admin user id, rate). Empty means 0%. |
| Assignment | The first admin who sends an offer, a comment or a decline on an unassigned quote becomes its rep. The quote page has a **Sales Rep** dropdown (active admin users) to reassign it at any time. |
| Rate on the quote | The quote page has a **Commission (%)** field, prefilled with the rep's default. The rate is fixed on the quote when the offer is sent, so later changes to the rep's default don't change it. Reassigning the quote before the offer takes the new rep's default. |
| Basis | The net value of the quote lines on the invoice: the invoice's base subtotal excluding tax, without shipping. Free gifts are $0 and add nothing. |
| When it is earned | When an order placed from the quote is invoiced, one commission line is written per invoice. A credit memo writes a negative line for its refunded subtotal. A cancelled order that was never invoiced earns nothing. |
| Who earns it | The quote's rep and rate at invoice or refund time, so a refund reduces the commission of the rep who earned it. |
| Commission lines | A new table `tonyapps_b2b_quote_commission`: quote, order, rep, type (invoice or refund), invoice or credit memo id (unique per type), base basis amount, rate, base commission, base currency, created at. Lines are never edited. |
| Quote list | A **Sales Rep** column and a **My quotes** filter. |
| Commission report | **Sales > B2B Quotes > Commissions**: a date range and a rep filter, a total per rep, and the lines (date, order #, quote #, invoice or refund, basis, rate, commission), with CSV export. ACL `Tonyapps_B2bQuotes::commissions`, separate from handling quotes. |
| Emails | Buyer replies and closes on an assigned quote also go to the rep's email. New requests still go to the configured sales email. |

Phase 4 out of scope: payouts and marking commission as paid, a rep seeing only their own quotes (ACL by owner), split
commission between several reps, commission on margin, and commission on orders that don't come from a quote.

## Architecture

Agreed scope (phases 1–2, as decided 2026-11-03):

| Feature | Decision |
|---|---|
| Who can request | Active company members with company permission **Request quotes** (`quotes_request`, `Tonyapps\B2b\Model\Permissions::QUOTES_REQUEST`). The company administrator always has it. A buyer sees their own quotes; the company administrator sees every quote in the company. |
| Request | Cart page, **Request a quote** with a required comment. Cart lines copied into the quote: product, SKU, name, buy request (options), qty, catalog price at request time. The cart is left unchanged. |
| Products | Simple, virtual, configurable (`QuoteService::SUPPORTED_TYPES`). A cart with anything else (bundle, grouped, gift card, downloadable) cannot be sent; the cart page says why. |
| Statuses | `open` (waiting for seller) → `offered` (seller set prices) → `ordered`. Also `declined` (seller), `closed` (buyer), `expired`. Buyer can send an offered quote back with a comment → `open`. Every change adds a history line. |
| Seller side (admin) | **Sales > B2B Quotes** (menu under `Magento_Sales::sales_operation`, route `tonyapps_b2bquotes/quote/index`): list + edit page with lines, catalog price, qty, **negotiated unit price** field, comment thread, expiry date (default N days from config). Actions **Send offer**, **Decline**. ACL `Tonyapps_B2bQuotes::quotes`. Seller cannot change qty or add lines. |
| Buyer side (storefront) | **My Account > Quotes** (link label "My Quotes"): list + quote page with lines, prices, total, thread, expiry. Actions: comment and send back, close, **Checkout with this quote**. Pages are not full-page cached. |
| Checkout at the agreed price | **Checkout with this quote** replaces the current cart with the quote lines and sets each negotiated price as Magento's custom price, so totals, tax and every payment method keep working. If the cart was not empty, the buyer confirms the replacement first. The cart remembers the quote id. |
| Locked lines | At order submit (`QuoteManagement::submit`, same hook as B2bOrderApproval), a cart carrying a quote id must still match the quote lines and qty, and the quote must still be `offered` and not expired; otherwise checkout is refused with a message. Cart page shows **Leave quote** to remove negotiated prices and the link. |
| Discounts | Cart price rules and coupons don't apply to negotiated lines; Tonyapps_FreeGift gifts still do (2026-11-09 QA fix). The negotiated price is the final item price. |
| Shipping | Calculated normally at checkout; not negotiated. |
| Currency and tax | Negotiated prices stored in website base currency, converted to the cart currency when applied. Entered in the same tax mode (incl./excl.) as catalog prices. |
| After the order | Quote becomes `ordered` and stores the order id. Both quote pages link to the order. An ordered quote can't be reused. |
| Expiry | Checked when the quote is shown and at checkout; no cron. A quote past its expiry date is shown and treated as `expired`. |
| Emails | New request and buyer reply → configured sales email. Offer and decline → buyer, copy to the company administrator when not the buyer. |
| Interplay | B2bOrderApproval still applies to a negotiated cart above its threshold (approval is the company's internal control; its fingerprint includes prices). Store credit and payment restrictions unchanged. |
| Configuration | Per website: enable switch (disabled by default), default expiry days, sales email recipient, email templates. |

Main classes:

| Class | Role |
|---|---|
| `Model/QuoteService` | Request, reply, close, offer/decline; status changes and history. `FREE_GIFT_OPTION`/`FREE_GIFT_ACTION` = `tonyapps_free_gift`. |
| `Model/QuoteCheckout` | Load into cart, match check, leave quote. `NEGOTIATED_OPTION = 'tonyapps_b2b_quote'`. |
| `Model/Notifier` | Emails. |
| `Model/Config` | Config reader. |
| `Model/ResourceModel/QuoteRequest` | Tables and status constants. |
| `Plugin/QuoteManagementPlugin` | Around `QuoteManagement::submit()` (sortOrder 20). Also runs in admin area: an admin reorder of a linked cart must not carry negotiated prices through. |
| `Plugin/SalesRule/NegotiatedLinesPlugin` | Around `Magento\SalesRule\Model\Validator::process()`: only free-gift rules reach a quoted line. |
| `Observer/BlockMultishippingForQuotes` | `checkout_type_multishipping_create_orders_single`: multishipping refused for a linked cart. |
| `Observer/RedirectInvalidQuoteCheckout` | Frontend `controller_action_predispatch_checkout_index_index`: `/checkout` back to cart when the gate would refuse. |
| Controllers | Storefront `Cart/{Request,Leave}`, `Quote/{Index,View,Reply,Checkout}` (base `AbstractQuoteAction`); admin `Adminhtml/Quote/{Index,Edit,Save}`. |
| Blocks / view | `Block/Cart/RequestQuote`, `Block/Quote/{Listing,View}`, `Block/Account/QuotesLink`, `Block/Adminhtml/{Listing,Quote/Toolbar}`, `ViewModel/QuoteDetails`; admin `edit.phtml`, `list.phtml`, `web/css/quote.css`. |

Implementation notes (phase 1):

- Admin list is a paged table, not a UI-component grid; no filtering or sorting yet.
- Seller form buttons: **Send Offer**, **Send Comment Only** (emails the buyer, status unchanged), **Decline**. An
  expired offer can be renewed with a new offer.
- Catalog price stored at request time is the cart line's `price`: base currency, catalog tax mode — the same mode
  Magento uses for a custom price.
- Buyers reply and close from the quote page. A reply always returns the quote to `open`.
- Emails use the `sales` sender identity.

Implementation notes (phase 2):

- Only the buyer who requested the quote can check out with it. The company administrator can see it but not check out.
- Cart linked through `tonyapps_b2b_quote.cart_id`. Order gate is the `QuoteManagement::submit()` plugin, which also
  covers PayPal Express and admin orders. Multishipping is refused for a linked cart; `/checkout` redirects to the
  cart when the gate would refuse.
- Cart matches the quote when it has the same SKUs at the same quantities, each still at its negotiated price,
  compared to 2 decimals in the cart currency. Tonyapps_FreeGift gift lines are ignored when requesting, matching and
  leaving a quote.
- A buyer reply, close, decline or expiry removes the cart's right to the prices. The cart page shows why and offers
  **Leave Quote**. **Leave Quote** is not gated by the permission or the enable switch, so a buyer who loses either
  can still return the cart to regular prices.
- A linked cart cannot request a new quote until the buyer leaves the current one.

## Data model

| Table | Columns |
|---|---|
| `tonyapps_b2b_quote` | `entity_id` PK, `company_id`, `customer_id` (buyer), `customer_name`, `customer_email`, `website_id`, `store_id`, `base_currency_code`, `status` (default `open`), `expires_at` (date), `base_request_subtotal`, `base_offer_subtotal` (nullable), `cart_id` (set at checkout), `order_id`, `created_at`, `updated_at`. Indexes: company_id, customer_id, status, cart_id. |
| `tonyapps_b2b_quote_item` | `item_id` PK, `quote_id` (FK → quote, CASCADE), `product_id`, `sku`, `name`, `buy_request` (JSON), `qty`, `base_catalog_price`, `base_negotiated_price` (nullable until an offer). |
| `tonyapps_b2b_quote_history` | `entry_id` PK, `quote_id` (FK → quote, CASCADE), `author_type` (buyer/seller/system), `author_id` (customer or admin user id), `comment`, `status` (status after), `created_at`. |

Cart item option `tonyapps_b2b_quote` marks negotiated lines.

## Configuration and ACL

| Path | Default | Scope |
|---|---|---|
| `tonyapps_b2b_quotes/general/enabled` | `0` | default, website |
| `tonyapps_b2b_quotes/general/expiry_days` | `30` | default, website |
| `tonyapps_b2b_quotes/general/notify_email` | empty (= Sales Representative store email) | default, website |
| `tonyapps_b2b_quotes/email/seller_template` | `tonyapps_b2b_quotes_email_seller_template` (`seller_notice.html`) | default, website |
| `tonyapps_b2b_quotes/email/buyer_template` | `tonyapps_b2b_quotes_email_buyer_template` (`buyer_notice.html`) | default, website |

- Admin section **Stores > Configuration > Tonyapps > B2B Quotes**. ACL `Tonyapps_B2bQuotes::config`.
- Quote handling ACL `Tonyapps_B2bQuotes::quotes` (under Sales > Operations).
- Company permission `quotes_request` lives in `Tonyapps_B2b` `Permissions`.
- Email template variables: `items`, `request_subtotal`, `offer_subtotal`, `has_offer`, `valid_until`, `admin_url`.
- Templates can be replaced by a `Tonyapps_EmailDesigner` design (**Marketing › Communications › Email Designer**)
  picked in the email template setting.

## Decisions and constraints

- Scope table above is agreed (2026-11-03); don't re-litigate it.
- Negotiated prices applied as Magento custom price so totals, tax and all payment methods keep working.
- Negotiated lines use cart item option `tonyapps_b2b_quote`, not `no_discount`: core skips `no_discount` lines for
  every cart price rule, which also blocked Tonyapps_FreeGift.
- Expiry has no cron; evaluated on view and at checkout.
- The seller email links straight to the admin quote URL without a secret key, so it works from an email (admin login
  still applies).

## Known limitations and open points

Out of scope (phase 1):

- Seller changing qty, adding/removing lines, or creating a quote from admin on the buyer's behalf.
- Negotiated shipping price, whole-quote percentage discount, quote PDFs.
- File attachments in the thread, quote templates, quotes for guests or non-company customers.
- Quotes for bundle, grouped, gift card and downloadable products.

Other:

- Admin list has no filtering or sorting.
- Phase 3 manual QA not done yet; phase 4 awaiting approval.

## Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Tonyapps/B2bQuotes/Test/Unit
```

- Unit tests: `QuoteService`, `QuoteCheckout`, `Notifier`, `NegotiatedLinesPlugin`, `QuotesLink`, admin `Toolbar`,
  `QuoteDetails`, account-page layout, email templates.
- Integration test for the submit guard: `Test/Integration/Model/QuoteCheckoutFlowTest.php` (Magento integration
  PHPUnit config).

## Change history

### 2026-11-13 package metadata

`composer.json` requires `magento/module-sales-rule` (`NegotiatedLinesPlugin`) and `magento/module-catalog` (`QuoteCheckout`).

### 2026-11-09 email redesign

- Buyer and seller emails show the quote lines (qty, catalog price, quoted price or — before an offer), both
  subtotals, the latest comment in a highlighted box, the offer's validity date and a button. The seller email links
  straight to the quote in the admin (no secret key, so it works from an email; previously it only named the menu
  path). New template variables: `items`, `request_subtotal`, `offer_subtotal`, `has_offer`, `valid_until`,
  `admin_url`.
- Screenshots (Luma theme, 2026-11-09) in `docs/emails/` (`quote-to-buyer.png`, `quote-to-seller.png`), shown in
  README.

### 2026-11-09 admin UX

- Quote page follows the admin order view: header buttons (**Back**, **Decline** with confirmation, **Send Comment
  Only**, **Send Offer**), *Quote & Account Information* (status badge, dates, linked order number, company, buyer),
  *Items Quoted* (SKU under the name, catalog and negotiated price, discount % and row total updating as prices are
  typed, **Discount all lines by X%**), *Comments History* newest first, *Quote Totals* with the offer's validity date.
- Admin shows statuses from the seller's side (*Awaiting your offer*, *Offer sent*, *Closed by buyer*, ...) as grid
  badges; the quote list uses the same badges and names its amount columns *Catalog Subtotal* and *Offer Subtotal*.

### 2026-11-09 QA fix

- Negotiated lines marked with cart item option `tonyapps_b2b_quote` instead of `no_discount`. Core skips
  `no_discount` lines for every cart price rule, which also stopped Tonyapps_FreeGift rules, so a quoted cart over
  the gift threshold got no gift. A plugin on `Magento\SalesRule\Model\Validator::process()` lets only free-gift rules
  reach a quoted line; discounts and coupons still never apply. **Leave Quote** removes the option (and still clears
  `no_discount` on carts loaded before this change).

### 2026-11-03

- Scope agreed; phases 1 and 2 built.
