# Chitty Register — Cooperative Society Chit Fund Platform (PHP + MySQL)

A self-hosted web app for running a cooperative society's chitty (chit fund):
scheme setup, member enrollment, monthly auctions, dividend/commission
calculation, payment collection, and per-member ledgers. Admin (society
staff) and member logins are separate.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension (`php-mysql` package on Debian/Ubuntu)
- MySQL 5.7+ or MariaDB 10.3+
- Apache (with `mod_rewrite`/`.htaccess` support) or Nginx + PHP-FPM

## 1. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE chitty CHARACTER SET utf8mb4;"
mysql -u root -p -e "CREATE USER 'chitty'@'localhost' IDENTIFIED BY 'choose-a-strong-password';"
mysql -u root -p -e "GRANT ALL ON chitty.* TO 'chitty'@'localhost'; FLUSH PRIVILEGES;"
mysql -u chitty -p chitty < sql/schema.sql
```

## 2. Configure database credentials

Edit `config.php` directly, **or** set environment variables in your web
server config (recommended so credentials aren't in a file under the
document root):

```
DB_HOST=127.0.0.1
DB_NAME=chitty
DB_USER=chitty
DB_PASS=choose-a-strong-password
```

For Apache with mod_php, you can set these with `SetEnv` in your vhost, or
just edit the defaults in `config.php`.

## 3. Create the first admin login

```bash
php seed.php
```

This creates an admin with phone `9999999999` / password `admin123` by
default. **Log in and either change the password from a future settings
page you add, or delete/re-create the user** — there's no in-app "change
password" screen yet, so for now re-run with your own values:

```bash
ADMIN_PHONE=9847012345 ADMIN_PASSWORD='a-real-password' ADMIN_NAME="Your Name" php seed.php
```

## 4. Point your web server at this folder

Apache example vhost:

```apache
<VirtualHost *:80>
    ServerName chitty.yourcollege.example
    DocumentRoot /var/www/chitty-php
    <Directory /var/www/chitty-php>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The included `.htaccess` files already block direct browser access to
`config.php`, `includes/`, and `sql/`. If you're on Nginx instead of Apache,
you'll need equivalent `location` blocks denying those paths, since
`.htaccess` only works with Apache.

Then visit `http://your-server/` and log in.

## How the chitty math works

This follows the common Kerala-style cooperative chitty convention:

- A **scheme** has a `chit_value` (total pot) and `duration_months`
  (= number of tickets/members).
- `monthly_subscription = chit_value / duration_months`, owed by every
  ticket every month.
- Each month, members bid a discount at auction. The winner takes the pot
  early at a discount.
- The society keeps a **commission** (`commission_percent` × chit_value),
  taken out of the winning bid.
- What's left of the bid (the **dividend pool**) is split evenly across
  every ticket and reduces what everyone owes that month:
  `net_installment = monthly_subscription − (dividend_pool / duration_months)`.
- The winner receives `prize_amount = chit_value − bid_amount`.

Every cooperative society's bylaws differ slightly on the fine print
(whether commission is taken every month vs. only on a win, whether the
dividend is split among all tickets or only non-prized ones, etc.). If your
society's rule is different, you can just re-enter that month's auction
with different numbers — nothing is hardcoded beyond the formula above, and
admins can override any month by resubmitting the auction form.

## What's included

- **Admin**: create schemes, add members, enroll members with ticket
  numbers, record each month's auction, collect payments per ticket,
  per-scheme ledger reports
- **Member**: view enrolled chitties, month-by-month dues and payment
  history, auction history
- Session-based login (PHP native sessions), passwords hashed with
  `password_hash()` (bcrypt)
- Plain PHP + PDO — no framework or Composer dependency required

## What's not included yet (natural next steps)

- In-app password change / "forgot password" flow
- SMS/email payment reminders for defaulters
- CSV/PDF export of ledgers and receipts
- Multi-admin roles/permissions (currently any `admin` user can do
  everything)
- Editing/deleting a scheme or member once created

## File layout

```
chitty-php/
  config.php          # DB credentials (edit or override with env vars)
  index.php            # redirects based on login state
  login.php / logout.php
  error.php
  seed.php             # CLI: create first admin
  css/style.css
  includes/            # PHP logic — not web-accessible (.htaccess denied)
    db.php             # PDO connection
    auth.php           # sessions, login guards, user CRUD
    chitty.php         # scheme/auction/installment business logic
    header.php / footer.php
  sql/schema.sql        # MySQL schema — not web-accessible
  admin/               # staff-only pages (require_admin())
  member/              # member-only pages (require_member())
```
