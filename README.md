<div align="center">
  <a href="README-TR.md">TR <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/TR.png" alt="TR" height="20" /></a>
  <a href="README.md"> | EN <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/US.png" alt="EN" height="20" /></a>
</div>

<div align="center">

# DNA SSL for WHMCS

**Sell Domain Name API SSL certificates from WHMCS.**

DV, OV, EV, wildcard and multi-domain certificates from your Domain Name API reseller
account: ordered on activation, validated and delivered from the client area.

![WHMCS](https://img.shields.io/badge/WHMCS-8.0%20%E2%80%93%209.x-4A90D9?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.4-777BB4?style=flat-square&logo=php&logoColor=white)

</div>

> Using WISECP 5? See [wisecp-ssl-dna](https://github.com/domainreseller/wisecp-ssl-dna).

---

## 📦 Download

Get `DNASSL-WHMCS.zip` from the [latest release](https://github.com/domainreseller/whmcs-ssl-dna/releases/latest)
and unpack it into the WHMCS root; it creates `modules/servers/dnassl`.

## ✨ What it does

- Opens the certificate order at Domain Name API when the service is activated
- No server record: Reseller ID and API Key go on the product, live or test detected from the key
- Fills the product's **Certificate** list live from your Domain Name API catalogue, with your reseller cost
- Client area: paste a CSR or let the module generate one (private key downloadable afterwards)
- E-mail, HTTP file or DNS (CNAME) domain validation, with the exact file / record to publish
- Additional domains (SAN) up to the package limit
- Certificate download (certificate + CA bundle) and reissue from the client area
- Renewal as a new order, reusing the stored CSR so the client has nothing to do
- Optional revoke on termination; *Refresh Status* and *Revoke Certificate* buttons for admins

## 📋 Requirements

- A **Domain Name API reseller account** with SSL products, and its **Reseller ID** and **API Key**
- WHMCS 8.0 or newer
- PHP 7.4 – 8.4 with `curl` and `json` (`intl` recommended for internationalised domains)

## 🚀 Installation

1. Unpack `DNASSL-WHMCS.zip` into the WHMCS root.
2. Create a product (type *Other*) and open **Module Settings**:

   | Setting | Value |
   |---|---|
   | Module Name | `DNA SSL Certificates` |
   | Reseller ID | your **Reseller ID** |
   | API Key | your **API Key** — live or test, the module detects which |

   **Save Changes**: the **Certificate** list then loads from your Domain Name API
   catalogue with your reseller cost. Pick the certificate, optionally tick **Revoke on
   Termination**, and save again. No server or server group is needed.
3. Choose when the order is opened (for example *as soon as the first payment is received*).
4. Make sure the order form asks for a **domain**: the certificate is issued for it.

After payment the order is opened at Domain Name API. The client opens the service in the
client area, pastes a CSR or lets the module generate one, picks the validation method and
follows the instructions shown there until the certificate is issued.

## 🧩 Things worth knowing

- **A certificate is identified by its domain.** Domain Name API addresses certificates by
  domain, not by order number, so the module stores the domain the order was opened for.
- **Additional domains are taken with the order.** The API cannot add a SAN to an existing
  certificate.
- **Validation e-mails cannot be re-sent on request,** and the method cannot be changed on
  an open order. A reissue restarts validation.
- **Validation methods** are e-mail, an HTTP file or a DNS CNAME record; the API has no
  HTTPS-file or TXT validation.
- **Web server type** is *Apache* (also covers nginx, LiteSpeed, cPanel, Plesk) or
  *Microsoft IIS*: the two values the API accepts.
- **The contact phone country code can have at most two digits** (API limit). Clients with
  a three-digit code (e.g. +994, +380) get the API's validation message.
- **Approver e-mails** are limited to the addresses Domain Name API lists for the domain or
  a mailbox on the domain itself.
- **Prices** come from the price group of your reseller account (Reseller, Premium, Platinum, VIP).
- **Some packages cannot be revoked** (the API answers "Package is not supported"); termination
  still completes and the attempt is written to the Activity Log.
- **Private keys** are only stored when the module generated the CSR, encrypted with the
  WHMCS system key. They are never written to a log.

## 🔍 Logs

- *Utilities → Logs → Module Log* (with Module Debug Mode on): every request and response.
- *Utilities → Logs → Activity Log*: a `dnassl:` line for every failed operation.

The API key and private keys are replaced by `***` before anything is logged.

## 🛠 Development

```
modules/servers/dnassl/          the module
modules/servers/dnassl/lib/DnaSsl/  Domain Name API SSL client (PHP 7.4+)
tools/build-release-zip.sh [ref|WORKTREE] [out-dir]
tests/                            smoke tests with a fake gateway
```

```bash
php tests/smoke-shared.php
tools/build-release-zip.sh WORKTREE /tmp/dnassl && php tests/smoke-whmcs.php /tmp/dnassl/DNASSL-WHMCS.zip
```

`lib/DnaSsl` is the same client as in [wisecp-ssl-dna](https://github.com/domainreseller/wisecp-ssl-dna);
keep the two copies identical.

## 📄 Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

<div align="center">

**DNA SSL for WHMCS** · Domain Name API SSL certificates

[domainnameapi.com](https://www.domainnameapi.com) · [Reseller panel](https://dm.domainnameapi.com)

</div>
