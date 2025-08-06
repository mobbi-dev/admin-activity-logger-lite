=== Admin Activity Logger Lite ===
Contributors: mobbidev
Donate link: https://mobbi.dev
Tags: logs, admin log, post log, user activity, security
Requires at least: 5.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs key admin activities like post changes, media deletions, and user actions. Includes auto-purge.

== Description ==

**Admin Activity Logger Lite** is a minimal yet powerful logging plugin for WordPress administrators. It silently tracks important actions in the backend to help site owners stay informed.

**What does it log?**

- 📄 When a new post is published
- 🗑️ When a post is moved to trash
- ❌ When a post is permanently deleted
- ✏️ When a post is updated
- 👤 When a new user is created or their role is changed
- 🚫 When a user is deleted
- 🖼️ When a media file is permanently deleted

== Features ==

- Clean and readable dashboard widget to view recent admin actions
- Manual log clear button with confirmation
- Auto-purge logs older than X days (customizable)

== Frequently Asked Questions ==

= Is this plugin GDPR compliant? =
Yes. The plugin only logs admin-side actions and does not log any frontend user activity.

= Where are logs stored? =
Logs are stored in a dedicated table in your database: `aal_logs`.

= Can I clear the log manually? =
Yes, there is a "Clear Logs" button with a confirmation prompt.

= How does auto-purge work? =
You can set how many days to retain logs. Older entries will be automatically deleted daily using WordPress cron.

== Changelog ==

= 1.0.0 =
* Initial release with logging for posts, users, and media actions
* Manual log clearing
* Auto-clean logs older than X days

== Upgrade Notice ==

= 1.0.0 =
First release. Logs key admin actions to a custom database table.

== License ==

GPLv2 or later

https://www.gnu.org/licenses/gpl-2.0.html