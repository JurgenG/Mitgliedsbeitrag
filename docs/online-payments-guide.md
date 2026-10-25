# Online payments: guide for administrators

This guide is for the people who run the Membership fee plugin in an organisation: administrators
and treasurers. It explains how members can pay their membership fee online and what you have to
set up for that. The technical design is in `online-payments.md`.

> **Status:** the plugin side is complete. The connection to **Mollie** is still being built. Until
> it is released, the only provider is a fake one for developers, so online payments can't be used
> on a real installation yet.

## What it does

You calculate the fees as usual. Then each member with an open fee gets a **personal pay link**,
for example in the e-mail you already send. The member opens it, lands on the payment page of the
payment provider, and pays by card, Bancontact, iDEAL, … When the provider confirms the payment,
the plugin marks the fee as paid. You don't have to do anything.

- A member never needs an Admidio account to pay. The link is enough.
- The link always charges the fee as it is **now**. If you recalculate a fee, the old link charges
  the new amount.
- A link stops working as soon as the fee is paid, so nobody can pay twice by accident.
- Transaction costs are paid by the organisation. They aren't added to the member's fee.
- Online payments are **off by default**. Bank transfers and SEPA direct debits keep working
  exactly as before, also when online payments are on.

## Setting it up

1. **Calculate the fees** as usual (*Membership fee → Fees → Recalculation*). Online payments use
   each member's *Fee* and *Contributory text* (shown to the member as the purpose of the payment).
2. **Switch it on:** *Preferences → Online payments*:
   - *Enable online payments*.
   - *Payment provider*: Mollie (once available).
   - *Allow paying more than the fee* (optional, see below).
   - The *Webhook URL* is shown for information. The provider sends its payment confirmations to
     that address, so your Admidio must be reachable from the internet over https.
3. **Give permissions** to the people who need them (see the next section).

When you save with *Enable online payments* on, the plugin creates three extra profile fields for
the organisation if they don't exist yet: *Pay token*, *Payment ID* and *Payment provider*. They're filled in automatically; don't edit
them by hand.

## Permissions

*Preferences → Access permissions for online payments*. Administrators always have every
permission, and only administrators can change these settings. For each permission you choose the
roles whose members get it.

| Permission | Allows |
|---|---|
| Payment settings | Switching online payments on or off, choosing the provider, managing its settings including the API key |
| Send payment links | Seeing and copying a member's pay link, and sending e-mails that contain `#payment_link#` |
| View payment details | Reserved for a read-only view of payment ID, provider and date (not available yet) |
| Refunds | Reserved for refunds through the provider (not available yet) |

Give *Send payment links* only to people who may contact members about their fee: a pay link lets
anyone who has it pay that member's fee.

## Sending pay links

**By e-mail.** In *Preferences → Email notifications*, add the placeholder `#payment_link#` to the
text you send to members, for example:

```text
Dear #user_first_name#,

Your fee for #membership_fee_text# is € #fee#.
You can pay online here: #payment_link#
```

When you send the mail, every member gets their own link. The placeholder stays empty if online
payments are off or if the sender doesn't have *Send payment links*.

**One member at a time.** On *Fees → Contribution payments*, people with *Send payment links* see a
link icon next to each member. It opens a page with the member's pay link, a *Copy link* button and
a *Pay now* button. Use it when a member asks for the link by phone or chat.

## What the member sees

1. The member opens the link. If the fee is open, they go straight to the provider's payment page.
   If *paying more* is allowed, they first see the amount and can raise it.
2. After paying, they come back to a page that says whether the payment is confirmed. Usually it's
   confirmed within seconds. If not, the page asks them to reload a moment later.
3. If there is nothing to pay (already paid, or no fee), the link says so. An old link of someone
   who has paid says "This payment link is no longer valid. If you have just paid, your payment has
   been registered."

Members who log in to Admidio can also see their status on the start page, with a *Pay now* button
when their fee is open. See `membership-status.md` for how to show that box.

## When a payment comes in

The plugin fills in the same fields that you'd fill in by hand on *Contribution payments*:

- *Paid*: the date the provider received the money.
- The member's *Due date* is cleared, so a planned SEPA direct debit doesn't collect the fee again.
- *Payment ID* and *Payment provider*, so you can find the payment in the provider's dashboard.
- The pay link is replaced by a new one, so the old link stops working.

Admidio's change history shows these changes like any other profile change.

### Paying more than the fee

With *Allow paying more than the fee* on, a member can raise the amount on the payment page, but
never lower it. There is no maximum. The amount paid becomes that member's fee, so your reports
show what they actually paid. Partial payments are never accepted.

### Payments that need your attention

Sometimes the plugin can't safely mark a payment as paid on its own. It then leaves the member
unpaid and writes a warning to Admidio's log file (`adm_my_files/logs/admidio-<date>.log`):

- **"paid amount differs from the current fee"**: you recalculated the fee after the member
  started paying, or the amount doesn't match. Look the payment up in the provider's dashboard
  with the member, then enter the payment by hand on *Contribution payments* or refund it in the
  provider's dashboard.
- **"payment provider unavailable"**: the plugin couldn't reach the provider to check a payment.
  Nothing to do: the provider tries again later (Mollie keeps trying for about a day).

## Common questions

**A member paid but still shows as unpaid.** Check the provider's dashboard. If the payment is
there and paid, look for a warning in the log file. If there is none, check that the *Webhook URL* is
reachable from the internet (not only from your own network), then enter the payment by hand.

**Can I send the same mail twice?** Yes. A member's link stays the same until they pay.

**What if I switch online payments off?** Links stop working immediately, and `#payment_link#`
becomes empty. Payments already made are kept.

**Does it work with several organisations?** Only for the organisation Admidio shows by default.
Each organisation has its own settings and fees, but a member who opens a link isn't logged in, so
Admidio serves its default organisation and links of other organisations don't work.

## For developers: trying it without a provider

On a development machine you can click through the whole flow with a fake provider, without an
account or API key. It is only offered when the web server's environment contains
`MEMBERSHIPFEE_PAYMENT_FAKE=1`; never set that on a real installation. See *Trying it out with the
fake provider* in `online-payments.md`.
