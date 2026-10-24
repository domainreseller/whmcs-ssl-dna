# Changelog

## 1.0.0 — 2026-10-24

First release.

- WHMCS server module `dnassl` for Domain Name API SSL certificates.
- Certificate dropdown filled from the live catalogue, priced from the reseller's price
  group; duplicate product codes collapsed.
- Order opened on activation; client-area configuration: paste or generate a CSR, e-mail /
  HTTP file / DNS (CNAME) validation, additional domains up to the package limit.
- Exact validation file or CNAME record shown, derived from the CSR.
- Certificate and private-key download, reissue, renewal as a new order with the stored
  CSR, optional revoke on termination (packages that cannot be revoked do not block it).
- CSR common name checked against the ordered domain before it is submitted; approver
  e-mail limited to the gateway's list or a mailbox on the certificate domain.
- API key and private keys masked in every log line; TLS verified on every request.
