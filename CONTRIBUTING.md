# Contributing

Open an issue before large changes. Bug reports should include the WordPress and PHP versions, reproduction steps, expected and actual results, and sanitized logs. Pull requests should be focused, explain compatibility impact, update tests and documentation, and avoid changing stored-data contracts without explicit agreement.

Follow WordPress security practices: validate input, use capabilities and nonces, prepare SQL, and escape output. Preserve existing table names, `tya_*` options, REST contracts, event fields, and localStorage keys. Do not add required runtime services or packages.

Before submitting, run:

```sh
composer validate --strict
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find assets -name '*.js' -print0 | xargs -0 -n1 node --check
tools/build-release.sh
```

English is the source language. Add user-facing strings with WordPress gettext functions and translator comments for placeholders. Keep Japanese translations in standard Japanese. Never translate, alias, or normalize raw MaxMind ASN organization values.
