# WHMCS Marketplace listing — DNA SSL for WHMCS

Everything to paste into https://marketplace.whmcs.com (Domain Name API group → Submit A Listing).

| Field | Value |
|---|---|
| Name | DNA SSL - Domain Name API SSL Certificates Module for WHMCS |
| Category | Provisioning Modules |
| Payment type | Free |
| Download URL | https://github.com/domainreseller/whmcs-ssl-dna/releases/latest/download/DNASSL-WHMCS.zip |
| Icon | `icon-512.png` |
| Version | 1.0.0 |
| Compatibility | WHMCS 8.0.0 – 9.1.0 (tested on 8.6.1) |

## Short description (≤ 150 characters)

Free WHMCS SSL module for Domain Name API resellers: sell DV, wildcard and multi-domain certificates with automated ordering, validation and delivery.

## Long description (Markdown)

```markdown
## Sell SSL Certificates in WHMCS with Domain Name API

**DNA SSL** is the free, official WHMCS module for selling the **SSL certificates of your Domain Name API reseller account**: Sectigo, Comodo, GeoTrust, RapidSSL, Thawte, GlobalSign, AlphaSSL and PrimeSSL. Orders open automatically when the service is activated, clients configure and validate their certificate from the client area, and the issued certificate is delivered right there.

## No Server Setup

There is no server record to create. Enter your **Reseller ID** and **API Key** on the product, tick **Test Mode** to try everything against the Domain Name API test environment, and pick the certificate from a list loaded live from your catalogue with your reseller cost.

## Features

* **Automatic ordering** on activation, renewal as a new order that reuses the client's CSR
* **CSR paste or generation**: clients without a CSR get one generated, with a private key download
* **Domain validation by e-mail, HTTP file or DNS (CNAME)**, with the exact file or DNS record to publish
* **Additional domains (SAN)** up to the package limit on multi-domain certificates
* **Certificate download** (certificate + CA bundle) and **reissue** from the client area
* **Admin tools**: Refresh Status and Revoke Certificate buttons, optional revoke on termination
* **Safe by design**: the CSR's domain is checked before submission, approver e-mails are limited to the domain's own mailboxes, API key and private keys are never written to logs
* **Multilingual**: English and Turkish client area and admin labels, following the client's and the admin's language

## Quick Installation

1. Upload `modules/servers/dnassl` to your WHMCS installation
2. Create a product (type *Other*) and choose **DNA SSL Certificates** on the **Module Settings** tab
3. Enter your Reseller ID and API Key (tick Test Mode for a test key) and save
4. Pick the certificate from the list and save again

No database setup, no cron job and no Composer dependencies.

## Documentation and Support

Installation guide in English and Turkish on [GitHub](https://github.com/domainreseller/whmcs-ssl-dna). Need a reseller account? Visit [domainnameapi.com](https://www.domainnameapi.com/).
```

## Version 1.0.0 changelog (Markdown)

```markdown
* First release of DNA SSL for WHMCS
* Automatic certificate ordering on activation; renewal as a new order with the stored CSR
* Client-area configuration: paste or generate a CSR, e-mail / HTTP file / DNS (CNAME) validation, SANs up to the package limit
* Exact validation file or CNAME record shown, derived from the CSR
* Certificate and private-key download, reissue, status refresh
* Optional revoke on termination; packages that cannot be revoked do not block it
* Reseller ID, API Key and Test Mode on the product, no server record needed
* English and Turkish language files
```

## Requirements

* PHP 7.4 - 8.4 with curl and json (intl recommended for internationalised domains)
* A Domain Name API reseller account with SSL products (Reseller ID and API Key)

## Screenshots (`screenshots/`)

| File | Name | Description |
|---|---|---|
| `1-client-configure.png` | Configure the SSL Certificate in the WHMCS Client Area | Clients paste or generate a CSR, add SANs and choose e-mail, HTTP file or DNS (CNAME) validation for their Domain Name API certificate. |
| `2-client-dns-validation.png` | Domain Validation Instructions (DNS CNAME) | The exact CNAME record to publish, computed from the CSR, with a one-click status check. |
| `3-client-issued.png` | Download and Reissue the Issued Certificate | Download the certificate with its CA bundle and the generated private key, or reissue with a new CSR. |
