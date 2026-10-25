# Mitgliedsbeitrag / Membership fee
Membership fee calculates membership fees based on a member's role affiliations.

Membership fee is a plugin for the online membership management system Admidio (https://www.admidio.org) and cannot be used without it.

Online documentation is available here: https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#mitgliedsbeitrag

## Default values and languages
Default values that the plugin writes to the database come from the language files, in the language that is active at that moment:

- the default settings in `system/configdata.php` (fee text prefix and suffix, family role prefix and description, example bank and account holder, invoice file name, mandate reference prefixes), written when the plugin is first started or a setting is added;
- the descriptions of the profile fields the plugin creates (`system/install_db.php`);
- the e-mail texts, written when they are first shown.

These language keys start with `PLG_MEMBERSHIPFEE_DEFAULT_`. Once written, a value belongs to the organisation: changing the language later does not change it. The default fee text prefix contains the current year. Values that end up in SEPA files only use characters allowed there.

The payment information ID in the SEPA XML file (`PLG_MEMBERSHIPFEE_SEPA_PAYMENT_ID`) is also taken from the language files.

## Change history
The [change history](https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#aenderungshistorie) provides detailed information about the bug fixes and improvements in each version.


## Development: automated tests
Plugin logic that does not depend on Admidio's globals (currently `classes/Payment/`, `classes/Service/AccessControl.php` and `classes/Service/MembershipStatus.php`) is covered by
PHPUnit tests in `tests/`. The test tooling is development-only and is not part of a release.
No local PHP or Composer is needed — run it with the official Composer Docker image:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 php vendor/bin/phpunit
```

Code under test must stay compatible with PHP 8.1 (Admidio 5's minimum); Composer is pinned to that
platform.

## Development: trying online payments without a provider
Online payments (work in progress, see `docs/online-payments.md`) can be clicked through in a local
Admidio instance with the built-in **fake provider**: no account, no API key, no tunnel. It is only
offered when the web server's environment contains `MEMBERSHIPFEE_PAYMENT_FAKE=1`; never set that
on a real installation. Details and setup: *Trying it out with the fake provider* in the design doc.

## Membership status on the start page
`system/membership_fee_overview.php` shows a logged-in member their own status (paid until, payment
deadline, *Pay now* when a fee is open and online payments are on). Admidio has no setting for
start-page boxes, so it is added with a small child theme; see `docs/membership-status.md`.
