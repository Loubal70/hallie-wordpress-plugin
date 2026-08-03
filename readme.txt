=== Hallie ===
Contributors: loubal70
Tags: reviews, local seo, google business profile, testimonials, schema
Requires at least: 7.0.2
Tested up to: 7.0
Requires PHP: 8.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync customer reviews from your Google Business Profile and publish them with honest structured data attribution.

== Description ==

Hallie stores your customer reviews in WordPress and publishes them with structured data that states, unambiguously, where each review was published and who generated the markup.

= What it does =

* Stores reviews as a private post type, with the review body and date kept exactly as published.
* Lets you replace an author's display name and profile picture without touching the review itself — useful when someone reviewed you from an account with no photo.
* Keeps those customisations when a review is edited or re-synced.
* Publishes `Review` markup that credits the source platform as `publisher` and your site as `sdPublisher`.
* Attaches that markup to the business entity your SEO plugin already declares, instead of declaring a second one.

= What it does not do =

It will not put star ratings in Google search results, and nothing can. A business marking up reviews about itself is ineligible for that feature — Google's review snippet documentation states this explicitly. What the markup does is state provenance clearly to anything reading the page.

The plugin also never rewrites the text of a review. Editing what someone published elsewhere while still attributing it to them would be misleading markup, and it breaks the consistency with the public listing that makes reviews credible in the first place.

= Providers =

Reviews come from a provider. One ships with the plugin: **Hallie**, which syncs reviews from your Google Business Profile through the Hallie API.

Reviews cannot be written by hand. A testimonial typed in the admin and published next to real ones, under the same markup, is exactly what the structured data here is meant to rule out.

Developers can register another source with the `hallie_register_providers` filter.

== External services ==

This plugin connects to the Hallie API (https://hallie.app) to sync reviews from your Google Business Profile. That connection is what the plugin is for: reviews are read from a source, never written here.

Nothing is transmitted until you enter an API token in Settings → Hallie. Installing and activating the plugin contacts no one.

* **What is sent:** your API token, the identifier of the business profile you selected, and pagination parameters. No visitor data, no personal data of your site's users.
* **When:** on a scheduled sync (every six hours by default), when you trigger a sync manually, and when you open the settings screen to list your available profiles.
* **What is received:** your reviews — rating, text, author name and profile picture URL, dates, and your replies.

Service provided by Hallie: [terms of service](https://hallie.app/terms) — [privacy policy](https://hallie.app/privacy).

Author profile pictures are handled in one of three ways, which you choose in the settings:

* **Author initials** — the default. Nothing is downloaded and nothing is requested from a third party.
* **Download to the media library** — the pictures are copied into `uploads/hallie/`, so displaying reviews exposes no visitor IP address to anyone.
* **Serve from Google** — the pictures stay on Google's servers, which means every visitor's browser requests them from Google directly.

= Where your API token is stored =

The token is kept in the `hallie_settings` option, encrypted at rest with authenticated XChaCha20-Poly1305. It is never written to the database in clear text, and never sent back to your browser: the settings screen only ever shows the last four characters.

The encryption key is derived from the `HALLIE_ENCRYPTION_KEY` constant when you define one in `wp-config.php`, which keeps it out of the database entirely:

`define( 'HALLIE_ENCRYPTION_KEY', 'a long random string' );`

Without that constant, the key derives from your WordPress `auth` salt instead. That works, but it ties the token to your salts: rotating them — a routine security measure — makes the stored token unreadable, and you will have to enter it again. The settings screen tells you when this has happened rather than letting syncing fail in silence. Defining the constant avoids the situation, as long as its value never changes.

== Development ==

Source code, build instructions and issue tracker: https://github.com/Loubal70/hallie-wordpress-plugin

The admin interface is built with @wordpress/scripts. Unminified sources live in
`assets/src/`; run `npm install && npm run build` to regenerate `build/`.

`wp hallie sync` pulls every page of reviews and imports the pictures they need, without
waiting for cron. It is the dependable way to drive the plugin from a system scheduler.

Found a security issue? Please write to hello@hallie.app rather than opening a public
report, so it can be fixed before it is described.

== Frequently Asked Questions ==

= Will this show star ratings in Google search results? =

No, and no plugin can. Google does not display review rich results for a business publishing reviews about itself.

= Can I edit the text of a review? =

No, deliberately. You can change the displayed name and the picture. The rating, body and date of a synced review stay as published.

= What happens if a review is deleted from the source? =

It is removed here too. What this plugin stores mirrors the newest reviews on your listing rather than archiving them, so a review leaving the set is routine: every new one published pushes the oldest kept one out.

= The reviews arrived but the author pictures did not. Why? =

Pictures are downloaded in the background, after the reviews are stored, so that a slow source never holds up a sync. That background pass is a WP-Cron event.

If your site defines `DISABLE_WP_CRON` — several hosts and frameworks do — nothing triggers it and the queue simply waits. Either have a system cron run `wp cron event run --due-now`, which a site defining that constant should already do, or schedule `wp hallie sync`: it pulls the reviews and empties the picture queue in one go.

= The settings screen says my API token can no longer be read. What happened? =

Your token is encrypted with a key derived from your WordPress `auth` salt, unless you defined `HALLIE_ENCRYPTION_KEY`. Rotating the salts in `wp-config.php` changes that key, and the stored token can no longer be decrypted. Enter it again to restore syncing. Defining `HALLIE_ENCRYPTION_KEY` makes the token survive a salt rotation.

= Does it work with Yoast SEO or The SEO Framework? =

Yes. It detects them and attaches the reviews to the business entity they already declare, rather than publishing a competing one. With no SEO plugin, it declares the entity itself.

== Changelog ==

= 0.1.0 =
* Initial release.
