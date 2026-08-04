# Contributing

Thanks for taking the time. This document says what the project expects, so a pull request
does not stall on something avoidable.

## Getting set up

```bash
composer install
yarn install && yarn build
```

The test suite needs the WordPress test library, which is not bundled:

```bash
bash bin/install-wp-tests.sh wordpress_tests root '' 127.0.0.1 latest
```

## Before opening a pull request

All four must pass. The CI runs the same commands, so a failure here is a failure there.

```bash
composer phpcs          # WordPress Coding Standards
composer test           # PHPUnit
composer plugin-check   # WordPress.org Plugin Check
composer i18n           # must leave nothing uncommitted
```

`composer i18n` last of all, and again after a version bump: the POT header carries the
plugin version, so bumping it without regenerating leaves the file stale.

`composer i18n` last of all, and again after a version bump: the POT header carries the
plugin version, so raising it without regenerating leaves the file stale.

`yarn build` too, if you touched anything under `assets/src/`. The compiled bundle in
`build/` is committed on purpose — WordPress.org runs no build step — and the CI rejects a
bundle that does not match its sources.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/), in English, subject and body.
Releases follow [SemVer](https://semver.org/).

The body is where a reviewer learns *why*. A subject line saying what changed is rarely
enough on its own.

## What the code is expected to look like

- PHP 8.4, typed everywhere, no `mixed` where a real type fits.
- Comments explain what the code cannot: a decision, a trap, a constraint from outside.
  A comment that restates the line below it is noise, and gets removed in review.
- Prose belongs in the docblock. A method body reads better without paragraphs in it.
- Nothing outside `src/Reviews/Provider/` may know which API is in use.
- Reviews are read from a source. Nothing may make them writable.

## Reporting a bug

Say what you expected, what happened, and how to reproduce it. WordPress and PHP versions
help. If it involves syncing, the summary line the plugin shows after a sync usually says
more than a description of the symptom.

For anything with a security dimension, see [SECURITY.md](SECURITY.md) instead — please do
not open a public issue.
