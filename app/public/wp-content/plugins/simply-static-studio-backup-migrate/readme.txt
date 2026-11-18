=== Simply Static Studio Backup and Migrate ===
Contributors: patrickposner
Tags: backup, migration, export, database
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.33
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create a downloadable backup of your WordPress site, or move it to Simply Static Studio for managed static WordPress hosting.

== Description ==

**Back up your WordPress site. Move it to Studio when you're ready.**

Simply Static Studio Backup and Migrate brings your database, media, active plugins, and theme together in a downloadable ZIP. Create a backup directly in WordPress, or send a complete backup to Simply Static Studio to migrate your site.

Creating and downloading backups is free. No account or subscription is needed.

= What you can do =

* Back up your WordPress database and site files.
* Choose whether to include plugins, themes, media, and the database.
* Start, pause, resume, or cancel a backup and follow its progress.
* Download your backup as a ZIP.
* Connect to Studio to transfer your site without downloading and uploading the archive yourself.
* Run backups from WP-CLI with `wp sssbm backup run`.

= What is Simply Static Studio? =

[Simply Static Studio](https://simplystatic.com/simply-static-studio/) is managed hosting for static WordPress websites. You keep editing pages and posts in WordPress, while Studio hosts a fast, static version for your visitors.

Studio brings hosting, publishing, backups, and website management into one dashboard. Import a copy of your existing site, check it in a private preview, and choose when to go live.

= Move your site to Studio =

Open **Push to Studio**, connect your Studio account, and create a complete backup. While connected, completed backups transfer automatically when the backup page is open. Studio imports the archive into your selected destination.

Migration requires a Studio account and an eligible service plan. You can use local backups without connecting to Studio.

[Learn more about Simply Static Studio](https://simplystatic.com/simply-static-studio/)

== Installation ==

1. In WordPress, go to **Plugins > Add New**, search for **Simply Static Studio Backup and Migrate**, and install and activate it.
2. Open **Tools > Backup and Migrate**.
3. Choose what to include, save any changes, and start a backup.
4. Download the ZIP when it finishes, or use **Push to Studio** to connect your account and migrate a complete backup.

You can also install the plugin by uploading its ZIP under **Plugins > Add New > Upload Plugin**.

Requires WordPress 6.6+, PHP 7.4+, a writable local backup directory, and enough free disk space for the archive. PHP's ZipArchive extension is recommended; without it, backups must stay below 4 GB and 65,535 files.

== Frequently Asked Questions ==

= Do I need Simply Static or a Studio account? =

No. This plugin creates and downloads backups on its own. Simply Static is not required. A Studio account and an eligible plan are only needed for migration to Studio.

= What is included in a backup? =

Your selected database, media, active plugins, active theme, and supported wp-content files. Customer MU plugins are included by default. WordPress core, inactive plugins and themes, caches, hosting-specific integrations, and Studio-managed MU plugins are excluded. The archive is intended for migration, rather than a complete copy of every server file.

= Can I restore a backup on this site? =

This plugin exports backups and transfers them to Studio. It does not include a restore or import screen for your current WordPress installation.

= Which backups can I migrate to Studio? =

A complete, validated backup containing the database and supported site files. Partial backups cannot be pushed to Studio. The service accepts archives up to 10 GB.

= Are my backups private? =

Downloads require administrator access or a secret link. Backups can contain private site data and credentials, so keep archives and links private. Use **Delete all backups** to remove local copies. Disconnecting Studio stops future transfers but does not delete backups already sent there.

= Does it support multisite and SQLite? =

Network administrators can export individual subsites, but not the main site or an entire network. SQLite is supported through the official SQLite Database Integration plugin, alongside MySQL and MariaDB.

== External services ==

Local backups and downloads run on your server without external services. Connecting to Studio uses:

* **[Simply Static Studio](https://static.studio/)** for sign-in and migration. It receives your email and login/session data, migration details, and the backup archive you transfer. Eligible HardyPress source URLs are sent for customer recognition. [Terms](https://simplystatic.com/terms-of-service/) · [Privacy](https://simplystatic.com/data-privacy/).
* **[Supabase](https://supabase.com/)** for Studio authentication and private archive uploads. It processes login/session data and transferred backup archives. [Privacy](https://supabase.com/privacy).
* **[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/)** to protect sign-in code requests. It receives IP address, browser, and security-check information when you request a code. [Privacy](https://www.cloudflare.com/turnstile-privacy-policy/).

Optional **migration progress in chat** needs separate approval. Pairing shares your site URL, a random installation ID, and the plugin version with Studio. Approved sharing sends backup status, progress, and timestamps for up to 24 hours; transfer and destination tracking need another permission. It shares no logs, filenames, archive links, or backup contents and cannot control backups or transfers. Stop sharing in WordPress or revoke access in Studio; previously shared information remains.

== Changelog ==

= 1.1.33 =
* Exclude incompatible iThemes Security and Solid Security editions from Studio migrations.

= 1.1.32 =
* Use WordPress APIs for HTTP requests, directory resolution, and database access.
* Improve backup safety for multisite, numeric themes, low-memory uploads, and file locks.
* Correct admin translation dependencies and strengthen release validation.

= 1.1.31 =
* Add opt-in Static Studio MCP progress sharing with explicit administrator consent and LocalWP support.
* Resume interrupted Studio transfers for the exact backup and destination, with separate transfer-tracking consent.
* Clarify MCP setup and improve the backup progress layout.

= 1.1.30 =
* Prepare the plugin for WordPress.org with backup documentation, license notices, and bundled source.
* Harden backup file boundaries, authentication tokens, and diagnostic logs.
* Simplify optional sign-in and update translations.

== Upgrade Notice ==

= 1.1.33 =
Improves Studio migration compatibility with security plugins.

= 1.1.32 =
WordPress.org review fixes, safer backups, and improved compatibility.

= 1.1.31 =
Adds optional MCP progress sharing, resumable Studio transfers, and clearer backup progress controls.

= 1.1.30 =
Backup reliability and security improvements, updated translations, and WordPress.org submission metadata.
