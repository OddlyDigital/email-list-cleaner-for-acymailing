# Email List Cleaner for AcyMailing

A WordPress admin plugin for removing bounced or stale email addresses from AcyMailing subscriber tables in bulk.

This is an unofficial, independently developed plugin. It is not endorsed by, affiliated with, or supported by AcyMailing or its developers.

Plugin by [ODDLY DIGITAL](https://oddly.digital/) — WordPress website design, development and support.

---

## The Problem

The Starter and Essential plans of AcyMailing for WordPress do not include any bulk subscriber management tools. This means that when you receive a list of bounced email addresses after a campaign, the only way to remove them is manually — one subscriber at a time. For campaigns with even a modest number of bounces, this becomes slow and time-consuming.

Leaving bounced addresses in your subscribers list is also bad for deliverability. A high bounce rate signals to email providers that your list is poorly maintained, which can result in your campaigns being marked as spam or your sending domain being flagged.

## The Solution

Email List Cleaner for AcyMailing lets you paste any number of email addresses directly into your WordPress admin and remove them all from your AcyMailing subscribers list in a single operation. It keeps your list clean and your deliverability rate high, without requiring a more expensive AcyMailing plan or manual one-by-one removal.

---

## Features

- Paste a list of email addresses (one per line or comma-separated) and remove them from all AcyMailing subscriber tables in a single operation
- Validates every address before any data is modified
- All deletions run inside a single database transaction — if any step fails, no changes are saved
- Distinguishes between addresses submitted for removal and addresses actually found and removed in the audit log
- Audit log records who ran the tool, when, which addresses were submitted, and which were actually removed
- Configurable automatic log retention (7 days through to 2 years, or keep forever)
- Option to manually delete all log entries
- Logging can be disabled entirely from the Settings tab

---

## Requirements

- WordPress 5.9 or higher
- PHP 7.4 or higher
- AcyMailing installed and active (the plugin expects AcyMailing's database tables to be present)

---

## Installation

1. Download the latest release from the [Releases](../../releases) page
2. In your WordPress admin, go to Plugins > Add New Plugin > Upload Plugin
3. Upload the zip file and click Install Now
4. Activate the plugin

Alternatively, upload the `email-list-cleaner-for-acymailing` folder directly to your `/wp-content/plugins/` directory via FTP and activate it from the Plugins page.

Once activated, the plugin is available under Tools > Email List Cleaner for AcyMailing in your WordPress admin.

---

## Database Tables

This plugin interacts with the following AcyMailing database tables:

| Table | Action |
|---|---|
| `{prefix}acym_user_has_list` | Removes list associations for the matching subscriber |
| `{prefix}acym_user_stat` | Removes campaign statistics for the matching subscriber |
| `{prefix}acym_user` | Removes the subscriber record itself |

Deletions run in the order shown above, inside a single transaction. The `_stat` deletion is performed before `_user` so that its JOIN back to `acym_user` can still resolve.

---

## Disclaimer

This tool permanently deletes records from your database. The plugin author accepts no responsibility for any data loss or damage to your website resulting from its use. Always back up your database before running this tool.

---

## Security

- Access is restricted to WordPress administrators (`manage_options` capability) only
- All form submissions are protected with WordPress nonces to prevent CSRF attacks
- All email addresses are validated with WordPress's `is_email()` before any SQL is executed
- All database queries use `$wpdb->prepare()` with parameterised placeholders to prevent SQL injection
- All output is escaped using WordPress escaping functions
- A pre-deletion `SELECT` runs inside the transaction to determine which submitted addresses actually exist, so the audit log accurately reflects what was removed versus what was not found

---

## Changelog

### 1.0.0

Initial public release.

---

## Contributing

Bug reports and suggestions are welcome. Please [open an issue](../../issues) or [send a message via oddly.digital](https://oddly.digital/contact).

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
