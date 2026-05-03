# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2025-05-03

### Added
- Active payment processing via WHMCS `AddInvoicePayment` API
- Confirmation email sent to client on payment
- Cancelled invoice reactivation before payment
- Multi-invoice payment support (one bank transfer, multiple invoices)
- Processing history with per-file stats and detail drill-down
- Monthly bar chart on dashboard
- ZIP upload support — extracts all XML files from an ABN AMRO export ZIP
- Multiple file upload at once
- Configurable skip debtors list (e.g. Mollie)
- Manual reconciliation panel for unrecognised transactions
  - Smart client/invoice suggestions (debtor name + amount ±10%)
  - Assign & Pay button for unpaid/cancelled invoices
  - Mark Resolved button for already-paid invoices
  - Ignore debtor & skip button — adds to skip list, marks payment skipped
- `amount_total` column on files table (total incoming bank amount per file)
- Statement date parsed from filename and shown in overview + detail
- Overview sorted by statement date (newest first)
- Single-letter invoice prefix stripping (F2026-197 → 2026-197)
- macOS metadata file filtering in ZIP uploads (`._` files, `__MACOSX`)

### Changed
- Stats counting fixed: skipped/error counted once per transaction, not per match result
- Skipped payment records store each invoice's own total, not the full bank transaction amount

## [1.0.0] - 2025-04-01

### Added
- Initial release — preview/parse only (Phase 1)
- CAMT.053 XML parser supporting namespace versions `.001.02` and `.001.03`
- Credit transaction extraction (`CdtDbtInd = CRDT`)
- Invoice number detection from remittance fields
- Invoice lookup by `invoicenum` column with fallback to numeric `id`
- Preview tab showing parsed transactions with match status
- WHMCS Capsule ORM support with `full_query()` fallback
