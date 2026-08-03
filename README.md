# Hallie

[![Testing](https://github.com/Loubal70/hallie-wordpress-plugin/actions/workflows/testing.yml/badge.svg)](https://github.com/Loubal70/hallie-wordpress-plugin/actions/workflows/testing.yml)

Sync customer reviews from your Google Business Profile into WordPress, and publish them
with structured data that says plainly where each review came from and who marked it up.

## Why

Reviews republished on your own site look like claims. The same reviews, marked up with
`publisher` pointing at the platform that published them and `sdPublisher` pointing at your
site, read as what they are: content someone else wrote, quoted here.

That distinction is the whole point. It will not put stars in search results — a business
marking up reviews about itself is ineligible for that, and no plugin changes it — but it
is what makes the reviews legible to anything reading the page.

## Principles

- **Reviews are read, never written.** There is no way to type one in the admin. A
  testimonial written here and published under the same markup as a real review is exactly
  what the structured data is meant to rule out.
- **The rating comes from the source.** Never a hand-typed number: a total that disagrees
  with the public listing destroys the credibility of every review shown beside it.
- **Editor customisations survive a sync.** The displayed name and the picture are yours;
  the rating, body and date stay as published.
- **Everything is batched.** Import, reconciliation, picture downloads and deletions all
  handle a slice per pass, so a listing of any size syncs without a request running long.
- **The source is swappable.** Nothing outside `src/Reviews/Provider/` knows which API is
  in use. Adding one means writing a class against `ReviewProvider` and registering it
  through the `hallie_register_providers` filter.

## Requirements

- WordPress 7.0.2 or later
- PHP 8.4 or later
- A Hallie account and API token — https://hallie.app

## Development

```bash
composer install
yarn install && yarn build

composer phpcs          # coding standards
composer test           # PHPUnit, needs bin/install-wp-tests.sh first
composer i18n           # regenerate .pot, .po, .mo and .json
composer plugin-check   # WordPress.org Plugin Check
```

`wp hallie sync` pulls every page of reviews and imports the pictures they need, without
waiting for cron. It is the dependable way to drive the plugin from a system scheduler —
and the only one on a site that defines `DISABLE_WP_CRON` without running a real cron.

## Distribution

`readme.txt` is what WordPress.org reads; this file is for GitHub. `.distignore` lists what
stays out of the published package — sources, tooling and tests. `build/` is committed on
purpose: WordPress.org has no build step.

## Contributing

Commits follow [Conventional Commits](https://www.conventionalcommits.org/), releases follow
[SemVer](https://semver.org/). Before opening a pull request, `composer phpcs`,
`composer test` and `composer plugin-check` should all pass, and `composer i18n` should
leave no uncommitted changes.

Security issues: please write to hello@hallie.app rather than opening a public issue.

## License

GPL-2.0-or-later. Copyright Hallie — https://hallie.app
