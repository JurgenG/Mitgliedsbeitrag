# Online payments via Mollie — design (phase 1)

Status: **draft / proposal**. Nothing here is implemented yet.

## Goal

Let a member pay their open membership fee online (Bancontact, iDEAL, credit card, …) through
[Mollie](https://www.mollie.com). When the payment succeeds, the plugin marks the fee as paid
automatically, exactly as if an admin had entered it on the *Payments* screen.

## Scope

In scope (phase 1):

- One-off payment of the member's current open fee (`FEE{org}`, purpose `CONTRIBUTORY_TEXT{org}`).
- A per-member payment link that can be mailed with the existing mail function.
- Automatic update of `PAID{org}` when Mollie confirms the payment.
- Per-organisation configuration: on/off, API key, test/live mode.

Out of scope:

- Mollie mandates / recurring payments. The plugin's own SEPA mandates and pain.008 export are left
  untouched.
- Payment reminders. They are handled by the plugin's existing mail functions.
- Refunds, partial payments, other providers. The provider interface leaves room for these later.

## Feature is optional and off by default

Installations that don't enable it see no change. No Mollie code runs, and no new required profile
fields block the plugin's update check.

## Architecture

```
classes/Payment/PaymentProviderInterface.php   createPayment(), getPayment()
classes/Payment/PaymentResult.php              value object: id, status, checkout URL, amount, metadata
classes/Payment/FakeProvider.php               in-memory provider for development and testing
classes/Payment/Mollie/MollieProvider.php      only code that talks to the Mollie REST API
classes/Payment/PaymentService.php             plugin logic: open fee → payment, payment → PAID
system/pay.php                                 public: member's link → creates payment → redirect
system/mollie_webhook.php                      public: Mollie status callback
```

- **No Composer and no SDK.** The plugin has no Composer setup, so `MollieProvider` calls the
  Mollie v2 REST API directly with curl. It only needs *create payment* and *get payment*.
- **Plugin logic doesn't depend on Mollie.** `PaymentService` only knows the interface, so it can
  be developed and tested against `FakeProvider` without an API key.

## Flow

1. An admin calculates fees as usual, so `FEE{org}` and `CONTRIBUTORY_TEXT{org}` are set.
2. The admin mails members with an open fee. The mail template gets a new placeholder
   **`#payment_link#`**, which expands to that member's personal `pay.php` link.
3. The member clicks the link. `pay.php` checks the token, re-reads the **current** open fee, creates
   a Mollie payment and redirects (303) to Mollie's checkout page.
   - If the fee is already paid or is zero, it shows a "nothing to pay" message instead.
   - A new payment is created on every click, so Mollie's short checkout expiry never matters.
4. Mollie calls `mollie_webhook.php` with `id=tr_…`.
5. The webhook **fetches the payment from the Mollie API** and only trusts that answer. If the
   status is `paid` and the metadata and amount match the member's open fee, it sets `PAID{org}`
   to the payment date via `User::setValue()` + `save()`, the same path used by `payments.php`.
   Admidio's change history records the change.
6. After checkout the member lands on a plain "thank you / status" page (`redirectUrl`).

### Why not Mollie's Payment Links API?

Payment links don't expire, which is convenient, but they don't accept `metadata`. That makes it
harder to map a payment back to a member and organisation. Our own `pay.php` endpoint keeps the
mapping in our hands and always charges the current fee.

## Data

| What | Where |
|---|---|
| Enabled, API key, test mode, redirect text | `adm_plugin_preferences`, new config section `Mollie` (defaults in `configdata.php`) |
| Per-member pay token | new profile field `PAYTOKEN{org}` (hidden, disabled, like the other plugin fields) |
| Last Mollie payment id | new profile field `MOLLIE_PAYMENTID{org}` (for support and idempotency) |
| Payment ↔ member mapping | Mollie `metadata`: `{ "org_id": …, "user_uuid": …, "fee": "…" }` |

The new profile fields are created only when the feature is enabled. They are **not** added to
`ConfigTable::checkforupdate()`'s required list, so existing installations aren't forced into a
reinstall.

## Security

- **Webhook** (`mollie_webhook.php`): no login, deliberately no `isUserAuthorized()`, no CSRF
  check. This is the one documented exception to the plugin's rules. It is safe because:
  - It accepts **only** `id`, validated against `^tr_[A-Za-z0-9]+$`.
  - It acts only on the payment as returned by the Mollie API with the org's own key. A forged call
    can at most trigger a re-check of a real payment.
  - It is idempotent: if `PAID{org}` is already set, it does nothing. Mollie retries up to about 26
    hours.
  - It always answers HTTP 200, also for unknown ids, so it leaks nothing. It must respond within
    15 s.
  - The organisation is taken from `metadata.org_id` of the fetched payment. The webhook URL only
    carries `org` to pick which API key to use for the fetch, and a wrong value just makes the fetch
    fail.
- **Pay endpoint** (`pay.php`): no login, so members don't need an Admidio account. Access is
  through an unguessable token (`random_bytes(32)`, hex), compared with `hash_equals()`. It is
  regenerated after a successful payment, so an old link stops working. It shows no personal data
  beyond the purpose text and amount.
- **API key**: stored per organisation, editable only via `isUserAuthorizedForPreferences()`. It is
  shown masked (`live_••••abcd`), never logged, never echoed back into the form, and never put in
  mail or exports. An empty input field means "keep the current key".
- Amounts are sent as strings with two decimals (`"25.00"`) in `EUR`. The fee is normalised with the
  existing helpers, never parsed ad hoc.

## Configuration UI

A new panel *Online payments (Mollie)* in the plugin preferences, saved through
`membership_fee_function.php` like the other panels. It holds:

- Enabled (checkbox)
- API key (masked)
- Mode: test / live. The key prefix `test_` / `live_` must match the mode, otherwise a validation
  error is shown.
- A read-only display of the webhook URL, for reference.

All strings go through `$gL10n` with new `PLG_MEMBERSHIPFEE_MOLLIE_*` keys in all language files.

## Development and testing

- `FakeProvider` covers the full flow without an API key.
- Real API: a free Mollie account gives a `test_` key immediately, with simulated outcomes (paid /
  failed / expired). Never commit or share the key; paste it only into the local instance's
  preferences.
- Webhooks need a public URL. For the local Docker instance (`localhost:8090`), use a tunnel
  (ngrok / cloudflared) and set `ADMIDIO_ROOT_PATH` accordingly while testing.

## Implementation steps (small commits)

1. This design doc.
2. Config section `Mollie` + defaults + language keys.
3. `PaymentProviderInterface`, `PaymentResult`, `FakeProvider`.
4. `PaymentService`: open-fee lookup, token handling, mark paid (tested against `FakeProvider`).
5. Optional profile fields (`PAYTOKEN`, `MOLLIE_PAYMENTID`): created on enable, removed on uninstall.
6. `pay.php` + `#payment_link#` mail placeholder.
7. `mollie_webhook.php`.
8. `MollieProvider` (curl) + preferences panel.
9. README + user documentation notes (`documentation-*.docx` need regenerating by hand).

## Decisions

- **Upstream:** developed in the `JurgenG` fork first; offered upstream once it works end to end.
- **Transaction costs:** included in the membership fee. No surcharge; the member pays exactly
  `FEE{org}`.
- **Payment date:** `PAID{org}` is set to Mollie's `paidAt` (date part, organisation timezone), not
  the webhook processing date, so late webhook retries don't shift the date.
