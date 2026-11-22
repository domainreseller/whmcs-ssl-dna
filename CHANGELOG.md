# Changelog

## 1.1.0 — 2026-11-22

Required update. Domain Name API changed its SSL API on 2026-11-20: a certificate is now
addressed by its certificate ID instead of its domain name. Version 1.0.0 still sends the
domain name, so it can no longer configure, refresh, download, reissue or revoke a
certificate.

- Every call for an existing certificate sends its Domain Name API certificate ID. The ID
  has been stored with each order since 1.0.0, so existing services keep working without
  any migration. A service that has no ID is matched once to the newest current
  certificate on its domain.
- Renewal uses the gateway's new renew call: a renewal order linked to the current
  certificate, charged at the renewal price, instead of a second purchase. The stored CSR
  is still submitted automatically.
  - A certificate that is cancelled or no longer exists at Domain Name API gets a new
    order, as before.
  - A renewal already opened for the certificate (for example from the reseller panel) is
    picked up instead of being charged again.
  - Domain Name API opens renewal 30 days before the subscription ends. Earlier than that
    nothing is bought: the gateway's message is shown and the renewal can be run again
    from the module queue.
- Persian (Farsi) language file for the client area and the admin labels, contributed by
  @inasrabadi (#1). It is used when the WHMCS language of the client or the admin is `farsi`.

## 1.0.0 — 2026-10-24

First release.

- WHMCS server module `dnassl` for Domain Name API SSL certificates.
- Certificate dropdown filled from the live catalogue, priced from the reseller's price
  group; duplicate product codes collapsed.
- No server record: Reseller ID, API Key and Test Mode are product module settings; a key
  used with the wrong Test Mode setting is reported as such.
- Order opened on activation; client-area configuration: paste or generate a CSR, e-mail /
  HTTP file / DNS (CNAME) validation, additional domains up to the package limit.
- Exact validation file or CNAME record shown, derived from the CSR.
- Certificate and private-key download, reissue, renewal as a new order with the stored
  CSR, optional revoke on termination (packages that cannot be revoked do not block it).
- CSR common name checked against the ordered domain before it is submitted; approver
  e-mail limited to the gateway's list or a mailbox on the certificate domain.
- API key and private keys masked in every log line; TLS verified on every request.
- Requests Cloudflare could not deliver to the gateway (521/522/523/525/526) are retried.
- Every admin and client label comes from the language files (English and Turkish); admin
  pages follow the admin's language, the client area the client's.
- Key size and signature algorithm read from the certificate or CSR.
