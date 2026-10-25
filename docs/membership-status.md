# Membership status on the start page — design

Status: **implemented** on branch `feature/mollie-payments`. Settings: *Preferences → Membership status*.

## Goal

A logged-in member sees their own membership status in a box on Admidio's start page
(`modules/overview.php`): paid or open, until when they are a member, the payment deadline, and a
*Pay now* button when a fee is open and online payments are on.

## Status rules

Organisation settings (preferences panel *Membership status*, config section `membership_status`):

| Setting | Key | Default |
|---|---|---|
| End of the membership period (day, month) | `period_end_day`, `period_end_month` | 31, 12 |
| Warn this many days ahead | `warning_days` | 30 |

The **period end on or after a date** is that date's year with the configured day and month, or the
next year's if that is earlier than the date. 29 February can't be configured.

A payment counts for the period it falls in: with period end 30.06, paid 15.08.2026 → member until
30.06.2027; with 31.12, paid 25.10.2026 → member until 31.12.2026.

| State | When | Shown |
|---|---|---|
| `paid` | `PAID{org}` set, *member until* = period end on/after the paid date, more than *warning days* away | "Paid – member until 31.12.2026" |
| `ending` | paid, *member until* is today or within *warning days* | "Paid – your membership ends on …", highlighted |
| `expired` | paid, *member until* is in the past (the admin hasn't opened the new period's fee yet) | "Your membership ended on …; the fee for the new period has not been set yet" |
| `open` | not paid, fee > 0, *deadline* more than *warning days* away | "Open – please pay before …" + *Pay now* |
| `due_soon` | not paid, fee > 0, *deadline* today or within *warning days* | highlighted + *Pay now* |
| `overdue` | not paid, fee > 0, *deadline* in the past | "Overdue …" + *Pay now* |
| `no_fee` | not paid and no fee (> 0) | the existing "no data" text |

The **deadline** of an open fee is the SEPA due date `DUEDATE{org}` if set, otherwise the period end
on/after today.

*Pay now* appears only for `open`, `due_soon` and `overdue`, and only while online payments are
active (`isOnlinePaymentActive()`). It links to the member's own pay link (`pay.php`), creating the
token if needed. "Renewing" means paying the next period's fee once the admin has calculated it, as
before; the box never creates fees.

The logic lives in the Admidio-independent class `classes/Service/MembershipStatus.php` and is
unit-tested: `periodEndFor(date)` and `evaluate(fee, paidDate, dueDate, today)` → array with keys
`state`, `paidUntil` (date or null) and `deadline` (date or null).

## The box

`system/membership_fee_overview.php` (already part of the plugin) shows for the logged-in member:
the status line, the fee, the purpose text, the paid date, and the *Pay now* button. It only ever
shows the current user's own data. Texts go through `$gL10n` (`PLG_MEMBERSHIPFEE_STATUS_*`).

## Putting the box on the start page

Admidio 5 has no setting for start-page boxes: they are listed in the theme template
`themes/simple/templates/system/overview.tpl`. To avoid editing the shipped theme (an Admidio
update would overwrite it), use a **child theme** that contains only that one template; Admidio
takes everything else from the fallback theme.

1. Create `themes/simple-membershipfee/` with an empty `index.html`.
2. Copy `themes/simple/templates/system/overview.tpl` to
   `themes/simple-membershipfee/templates/system/overview.tpl` and add, inside the `row` div:

   ```smarty
   <div class="admidio-overview-plugin col-sm-6 col-lg-4 col-xl-3" id="admidio-plugin-membership-fee">
       <div class="card admidio-card">
           <div class="card-body">
               {load_admidio_plugin plugin="MembershipFee" file="system/membership_fee_overview.php"}
           </div>
       </div>
   </div>
   ```

3. *Preferences → Appearance*: theme `simple-membershipfee`, fallback theme `simple`.

For `admidio-dev`, the child theme folder lives on the host and is mounted into the container.
