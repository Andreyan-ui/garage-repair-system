# Wycliffe Motors — Garage Management System

A complete garage management system for a vehicle repair workshop: customers book services and follow their car online, while staff run the workshop from job cards through to invoices.

Built for a Ugandan garage, so it is mobile first, uses phone numbers as customer identity (`+256`), prices in whole UGX shillings, and shares over WhatsApp.

## Features

**For customers**
- Book a service online and get a tracking reference
- Follow the repair from check-in to collection, verified by reference plus phone number
- Request roadside help when broken down, including sharing a location
- Self-help guides for problems you can safely fix yourself
- Optional account to keep vehicles, bookings and invoices in one place

**For staff**
- Dashboard with breakdown alerts, overdue jobs and cars ready for collection
- Job cards for walk-ins, bookings and breakdown call-outs
- Mechanics, parts and stock tracking
- Estimates, invoices and payments, all printable
- Reports on work and revenue
- Customer, service and self-help guide management

## Built with

PHP 8 with `mysqli` prepared statements, MySQL/MariaDB, and vanilla CSS and JavaScript. No framework and no build step.

Security: prepared statements throughout, CSRF tokens on every form, hashed passwords, login throttling, a spam honeypot on public forms, and HMAC-signed invoice links.

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `mod_rewrite` (XAMPP works out of the box)

## Setup

**1. Put the code in your web root**

```bash
git clone https://github.com/<your-username>/wycliffe-motors.git
```

For XAMPP on Windows that means `C:\xampp\htdocs\wycliffe-motors`.

**2. Create the database**

```bash
mysql -u root < garage_system.sql
```

This creates the `garage_system` database with its tables and demo data.

**3. Create your configuration**

`includes/config.php` is deliberately not in the repository, because it holds your database credentials and application key. Copy the example and edit it:

```bash
cp includes/config.example.php includes/config.php
```

Then set at least these values:

- `db` — your database host, user, password and name
- `app_key` — a random 64-character hex string, used to sign invoice links. Generate one with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

- `garage` — the garage's name, phone, WhatsApp number, email, address and opening hours

**4. Open the site**

The customer site is at `/`, and staff log in at `/admin/login.php`.

## Default login

The demo data ships with one staff account:

| Username | Password |
| --- | --- |
| `admin` | `admin123` |

**Change this before putting the system in front of anyone.** Log in, open your account page and set a new password.

## Upgrading an existing installation

`garage_system.sql` is a full reset and will drop existing tables, so never run it against live data. Schema and data changes ship as separate upgrade scripts that preserve existing records:

```bash
mysql -u root < garage_upgrade_workshop.sql
mysql -u root < garage_upgrade_local_photos.sql
```

## Rebranding

Everything a different garage needs to change lives in `includes/config.php`: name, contact details, opening hours, currency, booking slots and invoice wording. Logos and favicons are in `images/brand/`.

## Licence

No licence has been chosen yet, so all rights are reserved.
