# EntryVault

Turns form submissions into a small CRM inside WordPress. It catches entries from the form plugins you already use and puts them on one Kanban board, where you can move them through stages, tag them, assign them and reply to them.

[Website](https://jnkplugins.com/entryvault/?utm_source=github) · [Demo video](https://www.youtube.com/watch?v=JmBZw7nU8R8) · [Download](https://license.jnkplugins.com/download/entryvault?src=github)

## What's free

- Captures from Contact Form 7, WPForms (Lite too), Gravity Forms, Fluent Forms, Forminator, Ninja Forms, Formidable, Elementor Pro forms and more. No shortcode changes needed.
- Drag-and-drop Kanban board with a default pipeline (New, Contacted, Qualified, Won, Lost)
- Tags, threaded notes, an activity timeline, owner assignment
- Reply to a lead by email from their card
- Reports: by stage, by source, daily volume, funnel, time in stage
- Unlimited entries, CSV import and export
- Public submissions table (shortcode + block)
- Entries your form plugin already marked as spam stay off the board

Pro adds automations, IMAP reply tracking, Mailchimp/MailerLite, signed webhooks, multiple pipelines and backfill of old entries.

## Install

The easy way is the download page:

**[Download EntryVault free](https://license.jnkplugins.com/download/entryvault?src=github)**

Upload the zip under Plugins > Add New > Upload Plugin and activate it. The zip from that page has a free licence built in, so the plugin updates itself from your WordPress dashboard. You don't type in a key and you don't need an account. Email is optional.

You can also clone this repo into `wp-content/plugins/entryvault`. That copy works fine, but it **won't update itself**, because the licence comes with the download. Pull new tags yourself, or grab the zip above once and let it handle updates from then on.

Requires WordPress 6.2+ and PHP 8.2+.

## Updates and privacy

The download-page build checks `api.jnkplugins.com` for new versions. That request carries your site address, the free licence key and version numbers. Nothing else leaves your site: entries, notes and replies stay in your own database.

## Pro

Pro is a separate add-on: [EntryVault pricing](https://jnkplugins.com/entryvault/pricing.php?utm_source=github). It's $29 a year for 3 sites, $49 for 10 or $99 for unlimited, and your renewal price stays what you paid. Lifetime licences start at $89. All three JnK plugins also come as a bundle.

This repo is the free version only. Pro code isn't published here.

## Help

Questions or bugs: open an issue here, or email support@jnkplugins.com.

## License

GPLv2 or later. See `LICENSE.txt`.

The code in this repo is generated from each release, so pull requests can't be merged directly. If you send one anyway, we'll port the change by hand and credit you.
