# Online payments — design (phase 1: Mollie)

Status: **in progress** on branch `feature/mollie-payments`.

## Goal

Let a member pay their open membership fee online through a payment provider. When the payment
succeeds, the plugin marks the fee as paid automatically, exactly as if an admin had entered it on
the *Payments* screen.

Only **Mollie** is supported, which covers Bancontact, iDEAL, cards, PayPal and more. The code is
nevertheless written **provider-neutral**: everything Mollie-specific lives in one provider class
behind an interface. Another provider (Stripe, PayPal direct, a crypto gateway such as BTCPay Server,
…) could be added later as one more class, without changes to the rest of the plugin and without
a data migration.

## Scope

In scope (phase 1):

- One-off payment of the member's current open fee (`FEE{org}`, purpose `CONTRIBUTORY_TEXT{org}`).
- A per-member payment link that can be mailed with the existing mail function.
- Automatic update of `PAID{org}` when the provider confirms the payment.
- Per-organisation configuration: on/off, active provider, and that provider's settings.
- Providers: `MollieProvider` (real) and `FakeProvider` (development and tests).

Out of scope:

- Mandates / recurring payments at the provider. The plugin's own SEPA mandates and pain.008 export
  are left untouched.
- Payment reminders. They are handled by the plugin's existing mail functions.
- Refunds, partial payments.
- Providers other than Mollie. The interface allows them; they are not implemented.

## Feature is optional and off by default

Installations that don't enable it see no change. No provider code runs, and no new required
profile fields block the plugin's update check.

## Architecture

```text
classes/Payment/PaymentProviderInterface.php   getName(), createPayment(), getPayment(), extractWebhookPaymentId()
classes/Payment/PaymentResult.php              value object: id, status, checkout URL, amount, metadata
classes/Payment/PaymentException.php           provider errors (no Admidio dependency, so unit-testable)
classes/Payment/FakeProvider.php               in-memory provider for development and testing
classes/Payment/Mollie/MollieProvider.php      only code that talks to the Mollie REST API
classes/Payment/PaymentService.php             plugin logic: open fee → payment, payment → PAID
system/pay.php                                 public: member's link → creates payment → redirect
system/payment_webhook.php?provider=<name>     public: status callback for every provider
```

- **No runtime Composer dependency and no SDKs.** Composer is used for dev tooling only (PHPUnit)
  and never shipped. Providers call their REST APIs directly with curl.
- **Plugin logic doesn't know any provider.** `PaymentService`, `pay.php` and the webhook endpoint
  only use `PaymentProviderInterface`, so they can be developed and tested against `FakeProvider`.
- **Everything provider-specific stays in the provider class:** API authentication, amount format
  (e.g. Stripe uses integer cents, Mollie `"25.00"`), mapping the provider's statuses onto
  `PaymentResult::STATUS_*`, and webhook verification.

### Provider contract

| Method | Purpose |
|---|---|
| `getName(): string` | Stable lowercase key (`mollie`, `fake`, …), used in config, the webhook URL and the stored `PAYMENT_PROVIDER{org}` |
| `createPayment(amount, currency, description, redirectUrl, webhookUrl, metadata)` | Creates a one-off payment and returns it including the checkout URL |
| `getPayment(id)` | Fetches the authoritative current state from the provider |
| `extractWebhookPaymentId(post, headers, rawBody): ?string` | Verifies a webhook call the provider's way and returns the payment id to look up, or `null` to ignore the call |

The webhook endpoint never acts on the webhook body itself. It always calls `getPayment()` on the id
that `extractWebhookPaymentId()` returns. Providers that sign webhooks (Stripe, PayPal) would verify
the signature there. Mollie doesn't sign them, so `MollieProvider` only validates the id format.
That is safe because the subsequent `getPayment()` is authoritative.

## Flow

1. An admin calculates fees as usual, so `FEE{org}` and `CONTRIBUTORY_TEXT{org}` are set.
2. The admin mails members with an open fee. The mail template gets a new placeholder
   **`#payment_link#`**, which expands to that member's personal `pay.php` link.
3. The member clicks the link. `pay.php` checks the token, re-reads the **current** open fee, creates
   a payment with the active provider and redirects (303) to the provider's checkout page.
   - If the fee is already paid or is zero, it shows a "nothing to pay" message instead.
   - A new payment is created on every click, so short checkout expiries never matter.
4. The provider calls `payment_webhook.php?provider=<name>&org=<id>`.
5. The webhook asks the provider class for the payment id, then **fetches the payment from the
   provider** and only trusts that answer. If the status is `paid` and the metadata and amount match
   the member's open fee, it sets `PAID{org}` to the payment date via `User::setValue()` + `save()`,
   the same path used by `payments.php`. Admidio's change history records the change.
6. After checkout the member lands on a plain "thank you / status" page (`redirectUrl`).

### Why not Mollie's Payment Links API?

Payment links don't expire, which is convenient, but they don't accept `metadata`. That makes it
harder to map a payment back to a member and organisation. Our own `pay.php` endpoint keeps the
mapping in our hands, always charges the current fee and works the same for every provider.

## Data

| What | Where |
|---|---|
| Feature on/off (`0`), active provider (`mollie`) | config section `payment` in `adm_plugin_preferences` |
| Mollie API key (`''`), test mode (`1`) | config section `mollie` (one section per provider) |
| Per-member pay token | new profile field `PAYTOKEN{org}` (hidden, disabled, like the other plugin fields) |
| Last payment id | new profile field `PAYMENT_ID{org}` (for support and idempotency) |
| Provider of that payment | new profile field `PAYMENT_PROVIDER{org}` (`mollie`, …) |
| Payment ↔ member mapping | provider `metadata`: `{ "org_id": …, "user_uuid": …, "fee": "…" }` |

Defaults live in `configdata.php`. The new profile fields are created only when the feature is
enabled. They are **not** added to `ConfigTable::checkforupdate()`'s required list, so existing
installations aren't forced into a reinstall.

## Security

- **Webhook** (`payment_webhook.php`): no login, deliberately no `isUserAuthorized()`, no CSRF
  check. This is the one documented exception to the plugin's rules. It is safe because:
  - `provider` must be the configured active provider. Anything else → ignored.
  - The provider class verifies the call and extracts only a payment id. For Mollie: `id`, validated
    against `^tr_[A-Za-z0-9]+$`.
  - It acts only on the payment as returned by the provider's API with the org's own credentials. A
    forged call can at most trigger a re-check of a real payment.
  - It is idempotent: if `PAID{org}` is already set, it does nothing. Providers retry (Mollie up to
    about 26 hours).
  - It always answers HTTP 200, also for unknown ids, so it leaks nothing. It must respond within
    15 s.
  - The organisation is taken from `metadata.org_id` of the fetched payment. The URL's `org` only
    selects which credentials to use for the fetch, and a wrong value just makes the fetch fail.
- **Pay endpoint** (`pay.php`): no login, so members don't need an Admidio account. Access is
  through an unguessable token (`random_bytes(32)`, hex), compared with `hash_equals()`. It is
  regenerated after a successful payment, so an old link stops working. It shows no personal data
  beyond the purpose text and amount.
- **API keys / secrets**: stored per organisation, editable only via
  `isUserAuthorizedForPreferences()`. They are shown masked (`live_••••abcd`), never logged, never
  echoed back into the form, and never put in mail or exports. An empty input field means "keep the
  current key".
- Amounts are passed to providers as strings with two decimals (`"25.00"`) in `EUR`. The fee is
  normalised with the existing helpers, never parsed ad hoc.

## Permissions

Admidio's role rights are fixed columns of the roles table, so a plugin can't add checkboxes to the
role editor. Like the existing *Access to preferences*, each payment permission is therefore a
**role multi-select in the plugin preferences**, stored as a list of role ids in
`config['access'][<permission>]`. **Administrators always have every permission.** Everyone else
needs membership in one of the selected roles. Default: no roles, so admin only.

| Permission (config key) | Allows |
|---|---|
| `payment_settings` | Enable/disable online payments, choose the provider, view/change provider settings (API key, test/live mode), see the webhook URL |
| `payment_details` | Read-only view of per-member payment data: payment id, provider, status, paid timestamp, link to the payment in the provider's dashboard |
| `payment_links` | Send mails containing `#payment_link#`; invalidate/regenerate a member's personal pay link |
| `payment_refunds` | Reserved for refunds through the provider. Refunds are out of scope for phase 1; nothing uses it yet |

- **Only administrators can assign these permissions.** The panel *Access permissions for online
  payments* is shown only to administrators, and its save handler rejects everyone else. Otherwise a
  user with preferences access could grant themselves `payment_settings` and see the API key.
- The permissions are **independent**: none implies another, and *Access to preferences* does **not**
  include `payment_settings`. A treasurer can send links without seeing the API key.
- All of them additionally require normal plugin access (`isUserAuthorized()`).
- Checked with one generic helper, `isUserAuthorizedFor('<permission>')`. The decision logic lives in
  the Admidio-independent class `classes/Service/AccessControl.php` and is unit-tested. Unknown
  permission names throw instead of silently allowing or denying.
- The public endpoints `pay.php` and `payment_webhook.php` can't use role permissions: neither the
  paying member nor the provider is logged in. They are protected as described under *Security*.

## Configuration UI

A new panel *Online payments* in the plugin preferences, saved through
`membership_fee_function.php` like the other panels. It holds:

- Enabled (checkbox)
- Provider (select; phase 1: Mollie only)
- Provider settings. For Mollie: API key (masked) and mode test / live. The key prefix `test_` /
  `live_` must match the mode, otherwise a validation error is shown.
- A read-only display of the webhook URL, for reference.

All strings go through `$gL10n` with new `PLG_MEMBERSHIPFEE_PAYMENT_*` / `PLG_MEMBERSHIPFEE_MOLLIE_*`
keys in all language files.

## Development and testing

- `FakeProvider` covers the full flow without an API key.
- Unit tests for everything in `classes/Payment/` (see README, *Development: automated tests*).
- Real API: a free Mollie account gives a `test_` key immediately, with simulated outcomes (paid /
  failed / expired). Never commit or share the key; paste it only into the local instance's
  preferences.
- Webhooks need a public URL. For the local Docker instance (`localhost:8090`), use a tunnel
  (ngrok / cloudflared) and set `ADMIDIO_ROOT_PATH` accordingly while testing.

## Implementation steps (small commits)

1. ✅ This design doc.
2. ✅ Config defaults: section `payment` (`enabled`, `provider`) and section `mollie` (`api_key`,
   `test_mode`). Language keys are added with the UI that uses them.
3. ✅ `PaymentProviderInterface`, `PaymentResult`, `FakeProvider`, with unit tests; then
   `getName()` and `extractWebhookPaymentId()` for multi-provider support.
4. Permissions: config defaults, `AccessControl` (unit-tested), `isUserAuthorizedFor()`, and the
   role-selection panel in the preferences.
5. `PaymentService`: open-fee lookup, token handling, mark paid (tested against `FakeProvider`).
6. Optional profile fields (`PAYTOKEN`, `PAYMENT_ID`, `PAYMENT_PROVIDER`): created on enable,
   removed on uninstall.
7. `pay.php` + `#payment_link#` mail placeholder.
8. `payment_webhook.php`.
9. `MollieProvider` (curl) + preferences panel.
10. README + user documentation notes (`documentation-*.docx` need regenerating by hand).

## Decisions

- **Upstream:** developed in the `JurgenG` fork first; offered upstream once it works end to end.
- **Transaction costs:** included in the membership fee. No surcharge; the member pays exactly
  `FEE{org}`.
- **Payment date:** `PAID{org}` is set to the provider's paid timestamp (Mollie: `paidAt`; date part,
  organisation timezone), not the webhook processing date, so late webhook retries don't shift the
  date.
- **Providers:** only Mollie is supported and implemented. The code and data model are kept
  provider-neutral, so adding a provider later is one new class and needs no data migration.
