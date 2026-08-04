# Security policy

## Reporting a vulnerability

Write to **hello@hallie.app**. Please do not open a public issue: a description published
before a fix exists is a description of how to exploit every site running the plugin.

Useful in a report: what an attacker can do, what access they need to start, and how to
reproduce it. A proof of concept helps, even a rough one.

You should get an acknowledgement within a few days. If a fix is warranted, you will be
told when it ships, and credited in the changelog unless you would rather not be.

## Supported versions

The latest release. This plugin has no long-term support branches; fixes land in the next
version rather than being backported.

## What is worth reporting

- Anything letting a visitor, or a user without `manage_options`, read the API token.
- Anything letting a user without `edit_posts` read or alter stored reviews.
- Anything letting review data reach the page unescaped.
- Anything letting the sync be pointed at a host it was not configured for.

## What is not a vulnerability

- **Reviews being publicly visible.** They are published on a public listing. The plugin
  copies them so they can be displayed.
- **Author names and pictures being stored.** That is the feature. Whether republishing
  them suits a given jurisdiction is a question for the site owner, not a flaw here.
- **`hallie_*` filters changing behaviour.** They run in the site's own code, which already
  has every privilege the plugin does.
