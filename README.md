# IEMA CRMOps

The internal CRM of IEMA Standards Limited: leads, clients, pipeline, proposals,
invoices, renewals, surveillance tracking, follow-ups, mail and reporting.

**This repository is the starting point for the IEMA Institute CRM (iiema.org).**
It is shared so the Institute CRM can be built on the same proven structure,
and so a developer new to PHP can learn the codebase by working in it.

Built in **plain PHP (no framework) + MySQL**. Email is sent with the bundled PHPMailer library.

---

## How to build the Institute CRM from this

1. **Do not change this repository.** It is IEMA Standards' live CRM.
   Create a new repository for the Institute (for example `iiema-crm`) and copy this code into it.
2. Follow **Running it locally** below until CRMOps runs on your machine. Click through
   every page before changing anything; it is the fastest way to see how it fits together.
3. Then adapt it for the Institute (see **Adapting for IEMA Institute**).
4. Commit small changes often and open pull requests, so the Head of IT can review.

---

## Running it locally

1. Install **Laragon** or **XAMPP** on Windows (PHP 8.1+ and MySQL), and put the project folder in the web root
   (`C:\laragon\www\` or `C:\xampp\htdocs\`).
2. Create a database and load the table structure:
   ```
   mysql -u root -p -e "CREATE DATABASE iiema_crm"
   mysql -u root -p iiema_crm < schema.sql
   ```
   `schema.sql` is structure only. No client data is kept in this repository.
3. Copy the example configs and fill in local values:
   - `config.example.php` → `config.php`
   - `iema-sso/sso-config.example.php` → `iema-sso/sso-config.php`

   Every `CHANGE_ME` must be set. For local work, the API keys (CertAdmin, Groq) can stay
   as `CHANGE_ME`; only the features that use them will not work.
   **The real config files are git-ignored. Never commit them.**
4. Open `http://localhost/<folder>/login.php` in your browser.

---

## PHP crash course: how this code works

If you know Go, most of this will feel familiar. The main differences:
PHP runs **one file per request**, with no long-running server and no `main()`,
and HTML and code live in the same file.

### What happens when someone opens a page

Take `pipeline.php` as the example:

```
Browser requests pipeline.php
   │
   ├─ require_once 'config.php'   → loads settings, starts the session,
   │                                connects to MySQL as $pdo, defines helper functions
   ├─ require_login()             → sends the user to login.php if not signed in
   ├─ if POST: handle the form    → validate, save with $pdo, then redirect
   ├─ run SELECT queries          → load the data the page shows
   ├─ require 'includes/header.php' → sidebar and top bar, opens <main>
   ├─ HTML with <?php ... ?> bits → the page itself
   └─ require 'includes/footer.php' → closes the page
```

Every page follows this same pattern. Once you understand one, you understand them all.

### Go → PHP cheat sheet

| Go | PHP |
|---|---|
| `x := 5` | `$x = 5;` (every variable starts with `$`, every line ends with `;`) |
| `fmt.Println(x)` | `echo $x;` |
| `[]string{"a","b"}`, `map[string]int{}` | `['a','b']`, `['key' => 1]` (both are just "arrays") |
| `for _, v := range items` | `foreach ($items as $v)` |
| `for k, v := range m` | `foreach ($m as $k => $v)` |
| `len(items)` | `count($items)` |
| `strings.TrimSpace(s)` | `trim($s)` |
| `if err != nil { return }` | `try { ... } catch (Exception $e) { ... }` |
| `r.FormValue("name")` | `$_POST['name']` or `$_GET['name']` |
| `db.Query("... WHERE id = ?", id)` | `$stmt = $pdo->prepare('... WHERE id = ?'); $stmt->execute([$id]);` |
| `rows.Scan(...)` | `$row = $stmt->fetch();` or `$rows = $stmt->fetchAll();` |
| `html/template` auto-escaping | **Manual.** Always wrap output in `e()`: `<?php echo e($name); ?>` |
| `import "pkg"` | `require_once __DIR__ . '/file.php';` |
| `const X = 1` | `define('X', 1);` or `const X = 1;` |

### Two rules that matter most

1. **Always use prepared statements** (`$pdo->prepare(...)` with `?`). Never put `$_POST` values directly into SQL.
2. **Always escape output** with `e()`. PHP does not do it automatically like Go templates do.

### Key helpers in `config.php`

| Function | What it does |
|---|---|
| `require_login()` | Blocks the page unless the user is signed in |
| `current_role()`, `role_is([...])` | Check the signed-in user's role |
| `nav_for_role($role)` | Builds the sidebar menu (edit the `ROLE_NAV` array to change menus) |
| `e($value)` | Escapes text for safe HTML output |
| `smtp_send_mail(...)` | Sends an email through the user's mailbox |
| `ai_generate($prompt)` | Calls the AI service (needs `GROQ_API_KEY`) |
| `log_sync_event(...)` | Writes to the sync log |

Roles are defined in the `ROLES` array and menus in `ROLE_NAV`, both near the bottom of `config.php`.

---

## Adapting for IEMA Institute

**Keep and reuse:** login and roles, leads, clients, pipeline, follow-ups, proposals, invoices,
mail, reports, users and team, audit log, the header, footer and stylesheet.

**Probably remove or replace:** these are specific to ISO certification work:
- `surveillance.php`, `surveillance-tasks.php`, `renewals.php` (certificate surveillance cycles)
- The CertAdmin and AuditOps functions in `config.php` (`get_certadmin_*`, `sync_client_to_auditops`)
  and `cron/sync-certadmin.php`

**Likely new for a training institute:** courses, course schedules, enrolments, payments per
enrolment, and certificate issuing and verification.

**Change the branding:** `APP_NAME`, `APP_ORG`, `BILLING_*` and `ORG_WEBSITE_*` in `config.php`,
and `assets/logo.png`.

**Worth adding:** CSRF tokens on forms. The current forms don't have them, so this is a good
improvement to make in the new CRM.

---

## Layout

| Path | What it is |
|---|---|
| `config.php` | Settings **and** shared helper functions. Every page loads it first. |
| `*.php` (root) | One file per page, e.g. `leads.php`, `clients.php`, `invoices.php` |
| `includes/` | Shared header, footer, and render helpers for proposals, invoices and mail |
| `cron/` | Scheduled jobs: CertAdmin sync, follow-up draft generation |
| `iema-sso/` | Single sign-on through the IEMA Portal |
| `assets/` | Stylesheet and logo |
| `PHPMailer/` | Third-party email library (don't edit) |

## Learning resources

- PHP basics: https://www.php.net/manual/en/langref.php
- PDO (database access): https://www.php.net/manual/en/book.pdo.php
- *PHP for Beginners* (free video series) on Laracasts

## Rules

- Never commit `config.php`, real keys, error logs or database dumps containing client data (NDPA).
- Don't push to this repository's `main` branch. The Institute CRM lives in its own repository.
