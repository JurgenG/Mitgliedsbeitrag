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

- One-off payment of the member's current open fee (`FEE{org}`, purpose `CONTRIBUTORY_TEXT{org}`),
  or of a higher amount if the organisation allows it.
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
classes/Payment/PaymentMember.php              value object: a member's fee, paid flag, pay token, purpose
classes/Payment/PaymentMemberStoreInterface.php  reads/writes PaymentMember data (Admidio-backed in production)
classes/Payment/PaymentUnavailableException.php  provider can't be reached or has a temporary error
classes/Payment/InvalidPayLinkException.php    unknown member or wrong token on the pay link
classes/Payment/InvalidAmountException.php     chosen amount below the fee, or above it when not allowed
classes/Payment/AdmidioPaymentMemberStore.php  PaymentMemberStoreInterface on top of Admidio's User entity
classes/Payment/PaymentProviderFactory.php     which providers may be selected, and builds the active one
system/payment_common.php                      Admidio glue: build the PaymentService, pay link, profile fields
system/pay.php                                 public: member's link → creates payment → redirect; return page
system/payment_webhook.php?provider=<name>     public: status callback for every provider
system/payment_link.php                        shows a member's pay link to users with payment_links
system/fake_checkout.php                       development only: checkout page of the fake provider
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
| `getPayment(id)` | Fetches the authoritative current state from the provider. Throws `PaymentUnavailableException` if the provider can't be reached or answers with a temporary error (timeout, 5xx, rate limit), `PaymentException` for anything else (e.g. unknown id) |
| `extractWebhookPaymentId(post, headers, rawBody): ?string` | Verifies a webhook call the provider's way and returns the payment id to look up, or `null` to ignore the call |

The webhook endpoint never acts on the webhook body itself. It always calls `getPayment()` on the id
that `extractWebhookPaymentId()` returns. Providers that sign webhooks (Stripe, PayPal) would verify
the signature there. Mollie doesn't sign them, so `MollieProvider` only validates the id format.
That is safe because the subsequent `getPayment()` is authoritative.

### PaymentService

`PaymentService` holds the plugin's payment logic for **one organisation**. It is constructed with
the active provider, a member store, the org id, the org's timezone, a fallback description and
whether paying more than the fee is allowed. It
doesn't touch Admidio globals, so it is unit-tested against `FakeProvider` and an in-memory store.

Member data reaches it through `PaymentMemberStoreInterface`:

| Method | Purpose |
|---|---|
| `findMember(userUuid): ?PaymentMember` | Current fee (already normalised with `normalize_fee()`), paid flag (`PAID{org}` set), pay token, purpose text |
| `saveToken(userUuid, token)` | Stores a new pay token in `PAYTOKEN{org}` |
| `markPaid(userUuid, paidDate, paymentId, provider, newToken, paidAmount)` | Sets `PAID{org}` (`Y-m-d`), `PAYMENT_ID{org}`, `PAYMENT_PROVIDER{org}`, the new token and `FEE{org}` = `paidAmount` (`"40.00"`; unchanged if the member paid exactly the fee) in one `save()`. Like `payments.php` it also clears `DUEDATE{org}`, so a planned SEPA collection isn't executed as well |

The Admidio implementation of the store comes with the profile fields (step 6).

| Method | Behaviour |
|---|---|
| `getOrCreateToken(userUuid)` | Returns the member's pay token, creating and storing one first if there is none. Used for `#payment_link#` |
| `checkPayLink(userUuid, token)` | Returns the member if the link is valid (member exists, has a token, token matches), otherwise `null`. Used by the return page |
| `startPayment(userUuid, token, redirectUrl, webhookUrl, amount = null)` | Throws `InvalidPayLinkException` if the member is unknown, has no token, or the token doesn't match (`hash_equals()`). Returns `null` if there is nothing to pay (already paid, or fee ≤ 0). Otherwise creates a payment and returns it: for the **current** fee, or for `amount` if given. `amount` is rounded to cents; less than the fee, or more than the fee while paying more isn't allowed, throws `InvalidAmountException` |
| `handleWebhook(post, headers, rawBody)` | Runs the webhook logic below and returns one of the `WEBHOOK_*` outcomes. It never throws for bad input or provider errors, so the endpoint decides the HTTP status from the outcome |
| `formatAmount(float)` (static) | Fee → provider amount string with two decimals and a dot, e.g. `25` → `"25.00"` |

Payments are created with currency `EUR`, the member's purpose text (or the fallback if it is empty)
and metadata `org_id`, `user_uuid` and `fee` (the member's fee at checkout, formatted; the minimum).

`handleWebhook()` outcomes, checked in this order:

| Outcome | When | Effect |
|---|---|---|
| `ignored` | provider's `extractWebhookPaymentId()` returns `null` | none |
| `provider_unavailable` | `getPayment()` throws `PaymentUnavailableException` (provider can't be reached, timeout, server error) | none; the endpoint answers 503 so the provider retries |
| `unknown_payment` | `getPayment()` fails otherwise (e.g. the payment doesn't exist) | none |
| `not_paid` | status is anything other than `paid` | none |
| `not_ours` | `metadata.org_id` isn't this org, or the member doesn't exist | none |
| `already_paid` | the member's `PAID{org}` is already set (webhook retries) | none |
| `amount_mismatch` | currency isn't `EUR`, `metadata.fee` differs from the member's **current** fee (e.g. the fee was recalculated after checkout), or the paid amount is lower than `metadata.fee` | none; an admin has to check it by hand |
| `marked_paid` | all checks pass | `markPaid()` with the provider's paid timestamp converted to the org's timezone (date only), the paid amount, and a new token so the old link stops working |

A paid amount above the fee is accepted even if *paying more* was switched off after checkout: the
payment was created by the plugin with the organisation's own credentials.

### Paying more than the fee

Optional, per organisation, off by default: setting `payment.allow_higher_amount` (*Allow paying
more than the fee* in the *Online payments* panel). There is no maximum; the provider's own limits
apply.

- Off: the pay link goes straight to the checkout, as before.
- On: the pay link first shows a small form with the purpose, the fee as the minimum and an amount
  field prefilled with the fee. The member may raise the amount, never lower it. The amount is
  normalised with `normalize_fee()` and checked again by `startPayment()`; a lower amount shows the
  form again with an error.
- After payment, `FEE{org}` of **that member only** is set to the amount paid, so the profile,
  exports and `#fee#` show what the member actually contributed. The calculated fee stays visible
  in the change history. A later recalculation in *overwrite* or *summation* mode sets the fee back
  to the calculated value unless *only members who haven't paid* is ticked.

The pay link has the form `pay.php?org=<org id>&user=<user uuid>&token=<token>`. The token is
`bin2hex(random_bytes(32))` (64 hex characters).

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
| Feature on/off (`0`), active provider (`mollie`), paying more allowed (`0`) | config section `payment` in `adm_plugin_preferences` |
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
  - It answers HTTP 200 for every outcome, also for unknown ids, so it leaks nothing. Only if the
    payment can't be checked right now (`provider_unavailable`, or an unexpected error such as a
    database failure) it answers 503, so the provider retries later. That is safe because the
    webhook is idempotent, and it reveals nothing about the payment. It must respond within 15 s.
  - The organisation is taken from `metadata.org_id` of the fetched payment. The URL's `org` only
    selects which credentials to use for the fetch, and a wrong value just makes the fetch fail.
- **Pay endpoint** (`pay.php`): no login, so members don't need an Admidio account. Access is
  through an unguessable token (`random_bytes(32)`, hex), compared with `hash_equals()`. It is
  regenerated after a successful payment, so an old link stops working. It shows no personal data
  beyond the purpose text and amount.
- **API keys / secrets**: see *API key handling* below.
- Amounts are passed to providers as strings with two decimals (`"25.00"`) in `EUR`. The fee is
  normalised with the existing helpers, never parsed ad hoc.

## API key handling

The Mollie API key is the first real secret the plugin stores. It is kept in the config section
`mollie` (the plugin's existing storage), but treated as a secret everywhere else:

- **Who:** only users with `payment_settings` (administrators by default) may view or change it.
  The permission is checked when the panel is rendered *and* in the save handler.
- **Never echoed back:** the form field is a password input with an empty value. Next to it, a hint
  shows the stored key masked (`live_••••abcd`: prefix and last four characters). On save, an empty
  field keeps the stored key.
- **Never logged or dumped:** not in exceptions, error messages, `tests.php`, mails or exports. Error
  texts from the provider are shown without the key.
- **Format check on save:** the key must match `^(test|live)_[A-Za-z0-9]{30,}$`, and its prefix must
  match the selected mode (`test_` ↔ test mode, `live_` ↔ live mode). Otherwise a validation error is
  shown and nothing is saved. This keeps a live key from running in test mode and the reverse.
- **Optional override:** if the environment variable `MEMBERSHIPFEE_MOLLIE_API_KEY` is set, it wins
  over the stored key, and the panel says so and disables the field. This lets administrators who
  can configure the server keep the key out of the database entirely. The same format check applies.
- **Rotation:** revoke the key in the Mollie dashboard and paste the new one. Treat the key as exposed
  if a database backup leaks.

The logic (format check, mode match, masking, "empty keeps stored", override) lives in the
Admidio-independent class `classes/Payment/Mollie/MollieApiKey.php` and is unit-tested.

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
- Allow paying more than the fee (checkbox, off by default; see *Paying more than the fee*)
- Provider settings. For Mollie: API key (masked) and mode test / live. The key prefix `test_` /
  `live_` must match the mode, otherwise a validation error is shown.
- A read-only display of the webhook URL, for reference.

All strings go through `$gL10n` with new `PLG_MEMBERSHIPFEE_PAYMENT_*` / `PLG_MEMBERSHIPFEE_MOLLIE_*`
keys in all language files.

## Development and testing

- `FakeProvider` covers the full flow without an API key, in unit tests and in a local Admidio
  instance (see *Trying it out with the fake provider*).
- Unit tests for everything in `classes/Payment/` (see README, *Development: automated tests*).
- Real API: a free Mollie account gives a `test_` key immediately, with simulated outcomes (paid /
  failed / expired). Never commit or share the key; paste it only into the local instance's
  preferences.
- Webhooks need a public URL. For the local Docker instance (`localhost:8090`), use a tunnel
  (ngrok / cloudflared) and set `ADMIDIO_ROOT_PATH` accordingly while testing.

### Trying it out with the fake provider

The fake provider lets a developer click through the whole flow in a local Admidio instance, with
no provider account, no API key and no tunnel.

- **Only on development machines.** The provider `fake` can only be selected, and `pay.php`,
  `payment_webhook.php` and `fake_checkout.php` only accept it, when the web server's environment
  has `MEMBERSHIPFEE_PAYMENT_FAKE=1`. A normal installation never has that variable, so it never
  offers the fake provider. `PaymentProviderFactory` decides this and is unit-tested.
- **Payments survive between requests.** In Admidio, `FakeProvider` keeps its payments in a JSON
  file, `adm_my_files/membershipfee_fake_payments.json` (the plugin folder may be read-only). Without
  a file (unit tests) it stays in memory.
- **Fake checkout page.** The checkout URL of a fake payment is `fake_checkout.php?payment=<id>`.
  It shows the amount and purpose and lets you choose what the "provider" does:

  | Button | Simulates |
  |---|---|
  | Pay | successful payment → status `paid` |
  | Fail | declined card or bank → `failed` |
  | Cancel | payer aborts at checkout → `canceled` |
  | Expire | payer leaves checkout open too long → `expired` |
  | Pending | bank hasn't confirmed yet → `pending`; the page can be opened again later to pay |

  A checkbox *Send webhook* (on by default) makes the page call the plugin's webhook logic right
  away, in the same request. Untick it to simulate a late or lost webhook, then trigger it by hand:
  `curl -d id=<payment id> '<webhook URL>'`. Afterwards the page sends you to the payment's return
  page, like a real provider.
- An unreachable provider is only simulated in unit tests: `FakeProvider::setUnavailable(true)`
  makes `getPayment()` throw `PaymentUnavailableException`.
- Other realistic cases need no extra buttons: changing the fee after checkout gives
  `amount_mismatch`, paying twice through the same link is impossible because the token changes,
  and opening an old link shows the "link no longer valid" page.

Setup for `admidio-dev`: add `MEMBERSHIPFEE_PAYMENT_FAKE: "1"` to the `admidio` service's
`environment` and recreate the container. Then, in the plugin preferences: *Online payments* →
enable, provider *Fake (development only)* → save. *Payments* now shows a pay-link icon per
member.

## Pay link, return page and webhook endpoint

- **Profile fields** `PAYTOKEN{org}`, `PAYMENT_ID{org}` and `PAYMENT_PROVIDER{org}` (category
  *Membership fee*, hidden and disabled like `PAID{org}`) are created when online payments are
  enabled and saved, and removed on uninstall.
- **`AdmidioPaymentMemberStore`** reads and writes them through `User`. It uses
  `saveChangesWithoutRights()`, because nobody is logged in when the webhook or the pay page runs and
  Admidio otherwise refuses to save disabled fields.
- **Showing a pay link:** on *Payments*, users with `payment_links` get a link icon per member.
  It opens `payment_link.php`, which creates the member's token if needed and shows the link to
  copy. In mail texts, `#payment_link#` expands to the same link. It stays empty if online payments
  are off or the sender lacks `payment_links`.
- **`pay.php`** (no login). Parameters: `org`, `user`, `token`, and `mode` = `pay` (default) or
  `return`. `org` must be the current organisation, and online payments must be on.
  - `pay`: `startPayment()` → 303 redirect to the checkout, or a "nothing to pay" or "link no
    longer valid" message. A provider error shows "try again later" without details. With *paying
    more* on, a GET shows the amount form and its POST (CSRF-checked) starts the payment.
  - `return` (the payment's `redirectUrl`) uses `checkPayLink()`. If the link is valid and the fee
    is open, the payment isn't confirmed yet: "not completed or not confirmed yet" plus a *Pay now*
    button. Valid but nothing open (e.g. an admin entered the payment by hand): "nothing to pay".
    If the token is no longer valid,
    the neutral "link no longer valid; if you just paid, your payment has been registered" message
    appears. That never confirms or denies a payment to someone who only knows the uuid.
- **`payment_webhook.php`** (no login). It ignores calls for another provider than the active one,
  another org or when payments are off. Otherwise it runs `handleWebhook()`, writes the outcome to
  Admidio's log (`amount_mismatch` and `provider_unavailable` as a warning) and answers 200, or 503
  if the payment couldn't be checked (see *Security*).
- **Multiple organisations:** links and webhooks only work for the organisation Admidio currently
  serves (`$gCurrentOrgId`). That is the default organisation unless the visitor's session selects
  another one.

## Implementation steps (small commits)

1. ✅ This design doc.
2. ✅ Config defaults: section `payment` (`enabled`, `provider`) and section `mollie` (`api_key`,
   `test_mode`). Language keys are added with the UI that uses them.
3. ✅ `PaymentProviderInterface`, `PaymentResult`, `FakeProvider`, with unit tests; then
   `getName()` and `extractWebhookPaymentId()` for multi-provider support.
4. ✅ Permissions: config defaults, `AccessControl` (unit-tested), `isUserAuthorizedFor()`, and the
   role-selection panel in the preferences.
5. ✅ `PaymentService` with `PaymentMemberStoreInterface`: open-fee lookup, token handling, webhook
   logic, mark paid (tested against `FakeProvider` and an in-memory store).
6. ✅ Fake provider usable in Admidio: file storage for `FakeProvider`, `PaymentProviderFactory`
   (unit-tested), then the *Online payments* panel (enable + provider), profile fields
   (`PAYTOKEN`, `PAYMENT_ID`, `PAYMENT_PROVIDER`), `AdmidioPaymentMemberStore`.
7. ✅ `pay.php`, `payment_link.php`, `#payment_link#` mail placeholder.
8. ✅ `payment_webhook.php`, `fake_checkout.php`.
9. `MollieApiKey` (key handling, unit-tested), then `MollieProvider` (curl) + preferences panel.
10. README + user documentation notes (`documentation-*.docx` need regenerating by hand).

## Decisions

- **Upstream:** developed in the `JurgenG` fork first; offered upstream once it works end to end.
- **Transaction costs:** included in the membership fee. No surcharge; the member pays `FEE{org}`,
  or more if the organisation allows it.
- **Paying more:** allowed per organisation, no maximum; the amount paid becomes that member's
  `FEE{org}`. Paying less is never accepted (no partial payments).
- **Payment date:** `PAID{org}` is set to the provider's paid timestamp (Mollie: `paidAt`; date part,
  organisation timezone), not the webhook processing date, so late webhook retries don't shift the
  date.
- **Providers:** only Mollie is supported and implemented. The code and data model are kept
  provider-neutral, so adding a provider later is one new class and needs no data migration.
