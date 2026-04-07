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
- Email addresses in the audit log are anonymised by default — only SHA-256 hashes are stored, not the raw addresses
- Configurable automatic log retention (7 days through to 2 years, or keep forever)
- Option to manually delete all log entries
- Logging can be disabled entirely from the Settings tab
- Maximum of 2,000 addresses per submission to ensure server stability

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

## Privacy and Data Handling

By default, email addresses are anonymised before being written to the audit log. Each address is replaced with its SHA-256 hash — a one-way transformation that cannot be reversed to recover the original address. This means the log retains an auditable record of how many addresses were processed and whether each was found in the database, without storing personally identifiable information (PII).

Anonymisation can be disabled from the Settings tab if you prefer to store raw addresses in the log. If disabled, you are responsible for ensuring that retention of those addresses is consistent with your privacy policy and any applicable data protection obligations, such as GDPR.

The audit log table (`{prefix}acym_bc_log`) and all plugin settings are removed automatically when the plugin is deleted via the WordPress admin.

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
- Database errors are written to the server error log and are not exposed to the browser
- Submissions are limited to 2,000 addresses per request to prevent resource exhaustion

---

## Changelog

### 1.0.3

- Audit log anonymisation is now enabled by default — SHA-256 hashes are stored instead of raw email addresses

### 1.0.2

- Added a per-request submission limit of 2,000 addresses to prevent PHP memory exhaustion and server timeouts
- The limit is displayed in the textarea description and shown in the rejection message if exceeded
- The limit can be overridden by defining `ACYM_BC_MAX_EMAILS` in `wp-config.php`

### 1.0.1

- Database errors are now written to the server error log via `error_log()` rather than being displayed in the browser, preventing potential information disclosure
- Added an option to anonymise email addresses in the audit log using SHA-256 hashes

### 1.0.0

Initial public release.

---

## Contributing

Bug reports and suggestions are welcome. Please [open an issue](../../issues) or [send a message via oddly.digital](https://oddly.digital/contact).

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
