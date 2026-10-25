# Mitgliedsbeitrag / Membership fee
Membership fee calculates membership fees based on a member's role affiliations.

Membership fee is a plugin for the online membership management system Admidio (https://www.admidio.org) and cannot be used without it.

Online documentation is available here: https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#mitgliedsbeitrag

## Change history
The [change history](https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#aenderungshistorie) provides detailed information about the bug fixes and improvements in each version.


## Development: automated tests
Plugin logic that does not depend on Admidio's globals (currently `classes/Payment/`) is covered by
PHPUnit tests in `tests/`. The test tooling is development-only and is not part of a release.
No local PHP or Composer is needed — run it with the official Composer Docker image:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 php vendor/bin/phpunit
```

Code under test must stay compatible with PHP 8.1 (Admidio 5's minimum); Composer is pinned to that
platform.
