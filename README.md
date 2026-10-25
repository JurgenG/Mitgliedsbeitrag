# Mitgliedsbeitrag / Membership fee
Membership fee calculates membership fees based on a member's role affiliations.

Membership fee is a plugin for the online membership management system Admidio (https://www.admidio.org) and cannot be used without it.

Online documentation is available here: https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#mitgliedsbeitrag

## Change history
The [change history](https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#aenderungshistorie) provides detailed information about the bug fixes and improvements in each version.


## Development: automated tests
Plugin logic that does not depend on Admidio's globals (currently `classes/Payment/` and `classes/Service/AccessControl.php`) is covered by
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
