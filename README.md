# Wycliffe Motors — Garage Management System

A garage management system for a vehicle repair workshop. Customers book services and follow their car online, while staff run the workshop from job cards through to invoices.

**Live site:** https://andreyan-ui.github.io/garage-repair-system/

Built mobile first for a Ugandan garage: phone numbers as customer identity, UGX amounts and WhatsApp sharing.

## Features

**Customers** — book a service online and get a tracking reference, follow the repair from check-in to collection, request roadside help when broken down, read self-help guides, and keep vehicles and invoices in one account.

**Staff** — a dashboard with breakdown alerts and overdue jobs, job cards, mechanics, parts and stock, estimates, invoices and payments, and reports.

## Built with

PHP 8 with `mysqli` prepared statements, MySQL/MariaDB, and vanilla CSS and JavaScript. No framework and no build step.

Prepared statements throughout, CSRF tokens on every form, hashed passwords, login throttling, a spam honeypot on public forms, and HMAC-signed invoice links.

The published site is a static preview, so booking, tracking and staff login need the PHP application running on a server with MySQL.
