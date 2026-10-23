=== EntryVault – Form Entries, Submissions & Leads CRM ===
Contributors: suresh1986
Tags: form entries, contact form, crm, leads, csv export
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.15.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kanban CRM for your form entries — a self-hosted alternative to Gravity Flow + GravityView, at honest flat pricing.

== Description ==

**Your form submissions are stranded in your inbox — or in a form plugin that was never meant to be a CRM.**

https://www.youtube.com/watch?v=JmBZw7nU8R8

You collected the lead. Now what? To actually *work* those entries — move them through stages, assign them to a teammate, keep notes, reply — the usual answer is to bolt Gravity Flow ($99/year) onto GravityView ($99/year), roughly $198/year in add-ons on top of the form plugin you already bought, all tied to one form builder.

EntryVault takes a different path: it sits on top of the form plugins you already use and turns their submissions into one unified Kanban board — drag leads through stages, tag and assign them, keep notes, and reply from inside WordPress. Your data stays in your own database. No SaaS, no per-contact fees, no tracking.

It's built for agencies, freelancers, and small teams who outgrew "submissions live in your inbox" but don't want a heavyweight external CRM — and who'd rather pay a flat price than rent a pricing ladder.

= What you can do today (free version) =

* **Capture from 17 form builders**: Contact Form 7, WPForms (Lite included), Gravity Forms, Fluent Forms, Forminator, SureForms, Ninja Forms, Formidable Forms, WS Form, Everest Forms, Trinity Forms, Jetpack Forms, MetForm, Kadence Forms, Otter Forms, Spectra Forms and Elementor Pro forms. EntryVault notices which ones are active and only listens to those. No shortcode changes, no field re-mapping.
* **Work a Kanban board** — a drag-and-drop React board with a default Inbox pipeline (New / Contacted / Qualified / Won / Lost). Search thousands of entries and see what's new since you last looked.
* **Tag, note, and assign** — colour-coded tags, threaded notes with a full activity timeline, and owner assignment to any WordPress user.
* **Bulk actions** — assign an owner, add a tag, or archive/unarchive many submissions at once from the list screen.
* **Reply from the card** — send an email to a lead from inside their card, with the thread kept alongside the submission.
* **Dashboard reports** — submissions by stage, by source, daily volume, funnel conversion, time-in-stage, and a per-owner breakdown.
* **Unlimited entries** — no caps on how many submissions you store or manage.
* **CSV import and export** — backfill from another tool and export your data any time. Re-importing the same file never creates duplicates.
* **Public submissions table** — the `[entryvault_table]` shortcode and a matching Gutenberg block, with columns configurable per page and access gated by capability or a per-pipeline token.
* **Health screen** — system status for database schema and environment, plus a JSON endpoint for monitoring.
* **Spam stays out**: entries your form plugin already marked as spam (Gravity Forms, Fluent Forms, Forminator, Formidable, Ninja Forms with Akismet, Everest Forms, WS Form, Jetpack) never reach your board. You can switch this off.
* **Privacy tools**: Tools > Export Personal Data and Erase Personal Data include EntryVault entries, found by email address.

= Get Pro =

EntryVault Pro is available today at https://jnkplugins.com/entryvault/ — not "coming soon". Pro adds the automation and scale a growing team needs:

* Automation engine — triggers (new submission, stage change, tag added, reply received) × actions (email, webhook, Mailchimp, MailerLite, set stage, assign owner), with AND/OR conditions.
* Email reply tracking over IMAP — replies are matched back to the right submission automatically from a connected mailbox.
* Integrations: Mailchimp, MailerLite, and signed webhooks that send the whole entry, so Zapier, Make or n8n can use it.
* Send each form to its own pipeline, and move entries between pipelines with an automation.
* Auto-delete entries after a number of days.
* Multiple pipelines with per-pipeline access control.
* Auto-tag rules (form → tag) and round-robin owner assignment.
* Historical backfill of past entries (Gravity Forms, WPForms, Fluent Forms, Forminator, SureForms, Trinity Forms, Formidable, Ninja Forms, Everest Forms, MetForm, and Contact Form 7 messages saved by Flamingo or CFDB7).
* Public Kanban board block for the front end.

Pricing is flat and by site count (Personal / Freelancer / Agency / Unlimited) — every paid tier includes every Pro feature; the tiers differ only by how many sites you run and your support level. See https://jnkplugins.com/entryvault/pricing.php.

Or bundle any two JnK plugins for ~20% off, or all three for ~25% off: https://license.jnkplugins.com/bundles

= Why EntryVault instead of Gravity Flow + GravityView? =

The common way to turn Gravity Forms entries into a workflow is Gravity Flow (~$99/year) plus GravityView (~$99/year) — about $198/year, and tied to Gravity Forms specifically.

* EntryVault works with 17 form builders, not one.
* One flat price by site count, no renewal price hikes.
* Your data stays in your own WordPress database — no SaaS, no telemetry.
* Free tier is a complete single-site CRM; Pro is optional.

See the full feature comparison at https://jnkplugins.com/entryvault/features.php

== Installation ==

1. In your WordPress admin, go to Plugins → Add New, or upload the `entryvault` folder to `/wp-content/plugins/`.
2. Activate **EntryVault**. Activation creates EntryVault's own tables and seeds a default Inbox pipeline.
3. Go to **EntryVault → Kanban** to see your board.
4. Submit one of your existing forms (or import a CSV) and the entry appears on the board within moments.
5. Drag cards between stages, add notes, assign owners, and reply — all from the board.

== Frequently Asked Questions ==

= Is this really free? =

Yes. The free version is a complete single-site CRM — capture, Kanban, tags, notes, owners, bulk actions, dashboard reports, email out, and CSV in/out — and it stays free. Pro is available at jnkplugins.com if you need automation, IMAP reply tracking, multiple pipelines, integrations, or historical backfill.

= What happens if I stop renewing Pro? =

Your data is never touched. The free tier keeps working — you keep capturing entries, working the Kanban board, and exporting. Premium features switch off after a short grace period, so the plugin reverts to its free tier rather than soft-bricking. Renew any time to restore Pro.

= Which form plugins does EntryVault work with? =

17 form builders, out of the box: Contact Form 7, WPForms (Lite included), Gravity Forms, Fluent Forms, Forminator, SureForms, Ninja Forms, Formidable Forms, WS Form, Everest Forms, Trinity Forms, Jetpack Forms, MetForm, Kadence Forms, Otter Forms, Spectra Forms and Elementor Pro forms. EntryVault auto-detects which are active and only loads matching adapters, so there's no overhead for plugins you don't use.

= Do I need all of those plugins installed? =

No. EntryVault loads zero adapters by default and adds whichever ones detect their host plugin at runtime.

= I use Gravity Flow / GravityView. How do I migrate? =

Export your entries to CSV and import them into EntryVault. The importer previews the mapping and never creates duplicates on re-import. On Pro, historical backfill can pull past entries directly from Gravity Forms (and WPForms, Fluent Forms, Forminator, SureForms, Trinity Forms, Formidable, Ninja Forms, Everest Forms, MetForm, and Contact Form 7 messages saved by Flamingo or CFDB7) without a CSV round-trip.

= Does EntryVault modify my form plugin's data? =

No. Submissions are copied into EntryVault's own tables (`wp_entryvault_submissions` and `wp_entryvault_submission_data`), so your original entries are untouched.

= Where does my data live? =

Everything stays in your own WordPress database. No SaaS, no telemetry, no external dashboards. The free zip from jnkplugins.com includes a small update client: it asks our license server on jnkplugins.com whether a newer EntryVault is out, sending your site address, the license key the zip came with and the installed version, so WordPress can offer the update. Your submissions are never sent anywhere.

= Does it run on multisite? =

EntryVault is tested on single-site WordPress 6.2 through 7.0. Multisite is not officially supported yet.

= What happens to my data if I uninstall? =

Your data is kept by default. Deactivating or deleting the plugin leaves your submissions, tags, and notes in place. To wipe everything on delete, tick "Delete all EntryVault data when the plugin is deleted" under Settings → General → Data before deleting.

= Do you have other plugins that work with this? =

Yes — EntryVault is one of three JnK Plugins:

* [JnK Linkweave](https://jnkplugins.com/jnk-linkweave/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — server-side internal linking suggestions.
* [Trinity Forms](https://jnkplugins.com/trinity-forms/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — flat-priced form builder with Stripe.
* [EntryVault](https://jnkplugins.com/entryvault/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — Kanban CRM for form submissions.

Buy any two at ~20% off or all three at ~25% off. See [the bundle pricing](https://license.jnkplugins.com/bundles?utm_source=wporg&utm_medium=readme&utm_campaign=listing).

= Where do I report bugs or request features? =

Use the contact page at https://jnkplugins.com/entryvault/, or Feedback & Support inside EntryVault.

== Screenshots ==

1. The board: entries from every form plugin you use, as cards you drag from stage to stage.
2. One entry: its stage, owner and tags, what the person sent, and a timeline of what happened.
3. Overview: what came in this week, what is waiting on you, and what changed lately.
4. Submissions: every entry in one list you can filter, change in bulk or export.
5. Import: bring in entries from a spreadsheet and match each column before anything is saved.
6. Show entries on your site as a table, with a shortcode or a block.
7. Pro: automations that tag, assign, move or email entries for you.

== Changelog ==

= 1.15.0 =
* New: Tools > Site Health checks that EntryVault's database tables exist. If the database refused one, it says which, with the database's own error, and EntryVault's screens show the same notice.

= 1.14.3 =
* Fixed: "Update now" could fail with "Download link expired" when clicked a while after WordPress last checked for updates. The plugin now fetches a fresh download link right before the update starts.

= 1.14.2 =
* Fixed: "Check again" on Dashboard > Updates now finds a new version straight away. Before, a release could stay hidden for up to 12 hours unless you used "Check for updates now" in the License card.

= 1.14.1 =
* Changed: the readme says plainly what the free zip's update check sends and where, with current prices, and how to install from the jnkplugins.com zip. No code changes.

= 1.14.0 =
* New: bought Pro? Paste your Pro license key in Settings, under License, and click "Install Pro now". Pro is installed over this version for you, the same way WordPress applies an update, and everything you set up stays. Downloading and uploading the Pro zip still works.
* Changed: the upgrade page points to that box first.

= 1.13.0 =
* Changed: the License card looks and works the same in every JnK plugin. In the free version it shows your free license, which keeps the plugin updated, and links to your account if you bought Pro.
* Changed: the upgrade page has a "Download the Pro zip" button and the three steps, and says what WordPress shows when you upload Pro over this version.
* Changed: "licence" is now spelled "license" everywhere.
* Pro: license handling fixes, see the Pro changelog.

= 1.12.1 =
* Pro: fixed an update notice that could show again after updating. No change in the free version.

= 1.12.0 =
* New: entries your form plugin already marked as spam stay off your board. Checked against each form plugin: Gravity Forms, Fluent Forms, Forminator, Formidable, Ninja Forms (Akismet), Everest Forms, WS Form and Jetpack. On by default; untick it under Settings > General > Spam to bring spam in too.
* New: WordPress privacy tools. Tools > Export Personal Data and Erase Personal Data now include EntryVault entries (fields, notes, emails), found by email address.
* Fixed: EntryVault now also listens to the submission hook WS Form's free version fires, and skips WS Form drafts saved for later.
* Changed: the support line in this readme points to our contact page.

= 1.11.0 =
* New: Spanish and Korean translations ship with the plugin, so the admin shows in your site's language.

= 1.10.0 =
* New: EntryVault now captures from 17 form builders. Added: Jetpack Forms, MetForm, Kadence Forms, Otter Forms, Spectra Forms and Elementor Pro forms. Nothing to set up: if one of them is active, its entries arrive on your board.
* Improved: with Spectra Forms, a submission is kept even when the notification email fails to send.
* Note: Elementor Pro support follows Elementor's published developer documentation. If anything looks off with your Elementor forms, tell us and we will fix it quickly.

= 1.9.3 =
* Fixed: in the submissions list, short messages drifted to the right of their column instead of lining up.

= 1.9.2 =
* Improved: tags in the submissions list look the same as on the board.
* Improved: the Approval column only appears once you have approved or rejected an entry, instead of showing "Pending" on every row.

= 1.9.1 =
* Improved: an entry's panel shows the name of the form it came from instead of its number, a readable date, and a capitalised status.

= 1.9.0 =
* New: a cleaner admin. A real overview of what came in, what is waiting on you and what changed lately, plus a tidier board, entry panel, submissions list and settings.
* Fixed: deleting an entry left its emails behind. They are deleted with it now.
* Fixed: Health showed form plugin names like "Wpforms" instead of "WPForms".

= 1.8.0 =
* Maintenance release. Nothing changes in the free plugin.

= 1.7.0 =
* New: deactivating the plugin asks one optional question about what went wrong. Answering is optional, skipping deactivates straight away, and nothing from your site content is sent.

= 1.6.0 =
* New: WPForms Lite submissions are captured. Lite never saves an entry, so EntryVault used to skip them; they now arrive on the board like any other form. Paid WPForms forms with entry storage turned off are captured too.
* Fixed: tag pills on the Submissions list had no text colour and were hard to read on most tag colours. Text is now dark or white, whichever contrasts with the tag.
* Fixed: the Submissions list squeezed twelve columns into equal widths, so emails and messages wrapped one word per line. Columns now size to their content, messages show two lines, and ID, Pipeline, Owner and Updated start hidden (turn them back on under Screen Options).

= 1.5.0 =
* Fixed: the public table showed a lead's email address as their name when the form had no name field. It now shows "(no name)", visitors cannot search by email, and addresses inside a title are hidden.
* Fixed: the table's sort and page links were built from the current request, so rendering a page in the background could store links to wp-cron.php or admin-ajax.php. They now use the page's own address.
* Fixed: checkbox, dropdown, consent and file fields from Trinity Forms arrived as stored codes and raw JSON (`["a"]`, `1`, a file object with the server path). Choices now show their labels, consent reads Yes or No, files show their name, and no server path is ever shown or exported. Older entries are cleaned up on screen and in exports too.
* Fixed: Reports "Today", "This week", "This month" and "Sources" counted every pipeline while the rest of the screen showed one.
* Fixed: Export CSV ignored the tag and owner filters, so a filtered export held every lead. It now exports exactly what the list shows.
* Fixed: the Submissions list showed some owners as "#3" and only offered administrators in the owner filter and bulk "Assign owner". It now offers the same people as the board, including the roles ticked under Settings > Owners.
* Fixed: a crafted link could put any text into a Submissions notice. Notices now come only from the action you just ran (and the doubled period is gone).
* Fixed: a deleted note stayed readable on the open Timeline tab and through the REST API. Its text is gone from both straight away.
* Fixed: the CSV export repeated the "Email" heading when a form had its own Email field, and listed sources as ids like "contact-form-7".
* Fixed: after installing the free zip over Pro, WP-Cron logged "Cron reschedule event error" for the reply poll on every run.
* Changed: the Owner and Tags columns of the Submissions Table block say they are shown publicly, and Settings explains it.
* Small fixes: the stage filter groups stages by pipeline, message line breaks show in the detail panel, the timeline no longer prints internal event names, drag and drop announces card and stage names to screen readers, "Updated" never says "in 1 second", card dots show source names, the Message-ID uses your site's domain, and only the board screen loads the board's scripts.
* Docs: the readme now says what the update client sends, and Settings warns that a deactivated plugin prints the shortcode (token included) as text.

= 1.4.2 =
* Fixed: with another JnK plugin active, the whole site could stop loading ("There has been a critical error on this website"), and WordPress blamed the other plugin. Several plugins bundle the same licensing client, and PHP's code cache could load it twice. It is now safe to run any of them together.
* Fixed: "Send replies as" under Settings said "General saved." but never stored the name or address, so replies still went out as the site admin. It saves now, and replies use it.
* Fixed: the Submissions Table block showed every lead's email address to anyone who could open the page. The block now defaults to date, name and stage, like the shortcode, and the email column says it is shown publicly.
* Fixed: the timeline showed a stage move as numbers ("Moved from 1 → 2") and wrote it twice. It now reads "Moved from New to Contacted", once. Changing the owner from the card is recorded too, and a deleted note's text no longer stays readable on the timeline.
* Fixed: Reports "Time in stage" showed 0 for every stage. It now measures real moves.
* Fixed: a reply sent from a card carried two Message-ID headers, which mail clients and spam filters can disagree about. It carries one.
* Fixed: after sending a reply, the Email tab showed only an empty form. It now shows the conversation, and the subject defaults to what the person wrote instead of their own name.
* Fixed: filtering the submissions list to nothing said "No submissions yet"; clicking a submission's title opened the board instead of that submission; and every edit reset a card's "waiting" age. All three now do what they say.
* Fixed: the Health screen said "OK" with no form plugin detected, sources showed raw ids like "contact-form-7", the public table's "Access required." gave an editor no hint about the missing token, and links to a Settings tab did not open it.
* Changed: the Plugins-row Support link opens EntryVault's own Feedback & Support form instead of the closed wordpress.org forum.

= 1.4.0 =
* Maintenance release. The entry detail panel gains an empty extension slot for add-ons; nothing else in the free build changes.

= 1.3.2 =
* Fixed: replies sent from a card set a Reply-To address that nothing was collecting, unless you run the premium inbound-email feature. When the customer pressed Reply their message went to a mailbox that does not exist on most hosting, so it bounced or vanished and you never knew it had been sent. Replies now come back to your normal address.
* New: a sender name and address for outbound replies, under Settings. Without one WordPress sends as "WordPress", so somebody who wrote to your business received the answer from a stranger. Your site name and admin address are used by default.
* Fixed: the CSV export contained no submitted data at all — no subject, no message, no field values — and showed pipeline and stage as raw numbers. It now includes one column per submitted field, plus the tags and owner, with pipelines and stages by name.
* Fixed: re-importing a CSV reported the rows as imported when it had recognised them as duplicates and skipped them, so it always said "Imported: 3" and sent you looking for leads that were never added. Imports, duplicates and skipped rows are now counted separately.
* Fixed: the import's suggested mapping could point two columns at the title, and the loser was dropped entirely — importing Name, Email, Subject, Message stored the subject and silently lost the name. Only one column is chosen now, and picking two is refused with an explanation.
* Fixed: the Kanban board labelled every assigned card with a user number, such as "#4", because the list of people was never sent to it. It shows initials and the person's name again.
* Fixed: the funnel report showed 0% for a stage that genuinely held submissions, whenever the stage before it was empty. An undefined rate now shows as "—".
* New: the Submissions list shows a preview of the message, plus tags and owner columns, and can be filtered by tag and by owner. Tagging and assigning in bulk previously left no way to find those submissions again.
* New: the Health screen lists which form plugins EntryVault is capturing from, so "works with the forms you already use" can be confirmed without submitting a test entry.
* New: Settings shows each pipeline's ready-to-paste shortcode for the public submissions table, including its access token. The token was generated at activation and displayed nowhere, and the documented shortcode did nothing on its own because it needs a pipeline.
* Changed: the public submissions table no longer includes the email address by default. The documented usage published every lead's address on a public page. Add columns="…,email" if you want it.
* Improved: the board explains itself while empty, instead of showing five columns of "No submissions" with no indication that entries arrive automatically from your form plugins.
* Improved: replying from a card starts with a "Re:" subject and the original message quoted, rather than two empty boxes on a tab away from what was written.
* Improved: a dry-run import says "Would import", not "Imported", directly beneath the line stating that no rows were inserted.

= 1.3.1 =
* Fixed: filtering submissions by source returned no results for any source whose name contains a capital letter or a space — Gravity Forms, WPForms, Contact Form 7, Fluent Forms, Forminator and SureForms were all affected.
* Fixed: the same bug silently produced an empty CSV export when an export was filtered by source.
* Fixed: the plugin reported its version as 1.2.0 on the Health screen, in support emails and to the licence server, because the internal version constant was not bumped in the 1.3.0 release.

= 1.3.0 =
* New: a getting-started card on the Reports screen until the first submission arrives. Every figure reads zero on a fresh install, which looks like a plugin that does nothing rather than one with nothing to show yet — so it now says that EntryVault picks up your existing forms with nothing to reconfigure.
* Improved: an empty submissions list distinguishes "no submissions match these filters" from "nothing has arrived yet", and in the second case links the CSV importer.
* Improved: the submissions screen carries one plain line describing what Pro automates. No locked controls and nothing disabled.

= 1.2.0 =
* Fixed: the public submissions table shipped with no styling at all. Its stylesheet was written against class names the template never used, so not one rule applied and the `[entryvault_table]` shortcode inherited whatever your theme did to a bare table — stage labels in particular rendered as hard-edged blocks of raw colour. The table now has a proper card, header row and readable stage pills, with the text colour chosen per stage for contrast so a pale colour is never white-on-white.
* Fixed: the activity timeline printed raw JSON for tag, owner and stage-change events — entries like `{"tag_id":3,"tag_name":"Priority"}` instead of "Tagged Priority". Every event type now reads as a sentence.
* Fixed: the Daily Volume chart on the Reports screen drew a fixed-width row of bars that occupied the left sixth of its panel however wide the screen was. Bars now share the full width.
* Changed: the "Get EntryVault Pro" link carries campaign parameters so we can tell how many people reach the pricing page from inside the plugin. No content or usage data is collected, and the URL remains filterable via `entryvault_upgrade_url`.

= 1.1.4 =
* Fixed: the Submissions Table block now works in the block editor. It previously registered on the server but shipped no editor script, so the editor treated it as an unsupported block. The `[entryvault_table]` shortcode was unaffected.

= 1.1.3 =
* Improved: consistent author branding and new Settings, Upgrade, Docs and Support links on the Plugins screen. No functional changes.

= 1.1.2 =
* Maintenance and compatibility. The free tier is unchanged.

= 1.1.1 =
* Fixed: the Submissions list "Created" and "Updated" columns could overlap on narrow screens. They now show a relative time (e.g. "2 hours ago") with the full timestamp on hover.

= 1.1.0 =
* New: entry-capture adapters for Contact Form 7, Ninja Forms, Formidable Forms, WS Form and Everest Forms — now 11 supported form builders. Only adapters whose host plugin is active are loaded. Contact Form 7 support synthesizes a stable per-submission id, since CF7 stores no entries of its own.

= 1.0.8 =
* Fixed: two database-insert bugs that could silently drop submission data.

= 1.0.7 =
* Changed: internal identifiers (database tables, options, constants) now use the full `entryvault_` prefix to avoid conflicts with other plugins. Existing data is migrated automatically on update — no action needed.
* Changed: leaner package — the free build now contains only free-tier code.

= 1.0.6 =
* Security: the public submissions-table endpoint now requires a per-pipeline capability or a valid access token; the generic post-editing capability is no longer accepted for this data.
* Security: an access token is only ever created for a real pipeline from the admin, never from an unauthenticated front-end request.
* Changed: bulk actions (assign owner, add tag, archive/unarchive) are available to everyone who can manage submissions.

= 1.0.5 =
* Fixed: the Trinity Forms entry-capture adapter had drifted out of sync with Trinity Forms' own post-rename hook/table names and stopped capturing submissions; restored and renamed accordingly.

= 1.0.4 =
* Changed: minimum supported PHP is now 8.2 (8.1 reached end-of-life).
* Fixed: notes on a submission could not be deleted from the Kanban board (the delete control never appeared).

= 1.0.0 =
* Initial release.
* Entry capture adapters: WPForms, Gravity Forms, Forminator, Fluent Forms, SureForms.
* Kanban board (React + @dnd-kit), submission detail, notes, tags, owner assignment.
* Frontend submissions table via shortcode and Gutenberg block.
* CSV import (preview / dry-run / commit) and CSV export.
* Health page and REST endpoint.
* Feedback & Support form (sends to the author via your site email).

== Upgrade Notice ==

= 1.1.4 =
Fixes the Submissions Table block in the block editor. The shortcode was unaffected.

= 1.1.0 =
Adds Contact Form 7, Ninja Forms, Formidable Forms, WS Form and Everest Forms entry capture.

= 1.0.6 =
Security hardening for the public submissions-table endpoint. Recommended for all users.

== Development ==

EntryVault is open source (GPLv2-or-later) and ships uncompiled alongside its build output, so no external download is needed to read or rebuild it.

* All PHP is human-readable and shipped as-is (there is no PHP build step).
* The Kanban board's React source is included under `admin/assets/kanban/src/`. The compiled file `admin/assets/kanban/build/index.js` is generated from that source.
* The Submissions Table block's editor script (`blocks/submissions-table/index.js`) is authored by hand and shipped as-is — no build step.
* The board build uses @wordpress/scripts (https://www.npmjs.com/package/@wordpress/scripts):

`npm install`
`npm run build`

Running that regenerates the board's build output from source.
