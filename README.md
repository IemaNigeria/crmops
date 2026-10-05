# IEMA CRMOps

Internal CRM for IEMA Standards Limited: leads, clients, pipeline, proposals,
invoices, renewals, surveillance tracking, follow-ups, mail and reporting.

Plain PHP (no framework) + MySQL. Email via the bundled PHPMailer library.

## Running it locally

1. Install a PHP + MySQL stack (e.g. XAMPP or Laragon on Windows) and put this folder in the web root.
2. Create the database and load the structure:
   ```
   mysql -u root -p -e "CREATE DATABASE iema_crmops"
   mysql -u root -p iema_crmops < schema.sql
   ```
   (`schema.sql` is structure only — no client data is kept in this repository.)
3. Copy the example configs and fill in your local values:
   - `config.example.php` → `config.php`
   - `iema-sso/sso-config.example.php` → `iema-sso/sso-config.php`

   Every value marked `CHANGE_ME` or `PASTE_SECRET_FROM_PORTAL` must be set.
   The real files are git-ignored and must never be committed.
4. Open the app in your browser and sign in.

## Layout

| Path | What it is |
|---|---|
| `config.php` | Settings **and** shared helper functions (auth, roles, mail, DB). Every page loads it first. |
| `*.php` (root) | One file per page/module, e.g. `leads.php`, `clients.php`, `invoices.php` |
| `includes/` | Shared header, footer and render helpers for proposals, invoices and mail |
| `cron/` | Scheduled jobs: CertAdmin sync, follow-up draft generation |
| `iema-sso/` | Connector for single sign-on through the IEMA Portal |
| `assets/` | Stylesheet and logo |
| `PHPMailer/` | Third-party email library |

## Rules

- Never commit `config.php`, real keys, error logs or database dumps containing client data (NDPA).
- This repository is for reference and study. Do not push changes to `main` without review.
