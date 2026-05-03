# WHMCS ABN AMRO CAMT.053 Import

A WHMCS addon module that automatically imports ABN AMRO CAMT.053 bank statements and matches credit transactions to unpaid invoices — marking them as paid and sending confirmation emails to clients.

## Features

- Parses ABN AMRO CAMT.053 XML files (namespace versions `.001.02` and `.001.03`)
- Detects invoice numbers from remittance info (e.g. `Factuur 2025-308`, `F2026-197`)
- Matches transactions to WHMCS invoices by invoice number or numeric ID
- Marks matched unpaid invoices as **Paid** via WHMCS API and sends confirmation emails
- Handles multi-invoice payments (e.g. one bank transfer covering several invoices)
- Reactivates **Cancelled** invoices before processing payment
- Auto-processes new files on every WHMCS cron run
- ZIP upload support — extracts all XML files from an ABN AMRO export ZIP
- Multiple file upload at once (Ctrl/Cmd-click)
- Configurable **skip debtors** list (e.g. payment providers like Mollie)
- Manual reconciliation panel for unrecognised transactions:
  - Smart client/invoice suggestions based on debtor name and amount (±10%)
  - **Assign & Pay** button for unpaid invoices
  - **Mark Resolved** button for already-paid invoices
  - **Ignore debtor & skip** — adds debtor to the skip list and marks payment as skipped
- Full processing history with per-file stats and drill-down detail view
- Overview sorted by statement date (newest first)

## Requirements

- WHMCS 7.0 or higher (tested on 8.x)
- PHP 8.0 or higher
- `php-xml` extension (DOMDocument)
- `php-zip` extension (ZIP upload)
- ABN AMRO business account with CAMT.053 export enabled

## Installation

1. Copy the `abn_camt_import` folder to your WHMCS installation:
   ```
   /path/to/whmcs/modules/addons/abn_camt_import/
   ```

2. Create an inbox folder on your server where ABN AMRO XML files will be stored:
   ```bash
   mkdir -p /path/to/private/abn-camt/incoming
   chmod 750 /path/to/private/abn-camt/incoming
   chown your-webuser:your-webgroup /path/to/private/abn-camt/incoming
   ```
   Keep this folder **outside** the web root so it is not publicly accessible.

3. In WHMCS Admin, go to **Setup > Addon Modules**, find **ABN AMRO CAMT.053 Import** and click **Activate**.

4. Click **Configure** and fill in the settings (see [Configuration](#configuration)).

5. Grant the addon access to the admin roles that should use it.

## Configuration

| Setting | Description |
|---|---|
| **CAMT.053 Inbox Folder** | Absolute server path to the folder containing XML files, e.g. `/var/www/private/abn-camt/incoming/` |
| **Payment Gateway** | WHMCS gateway module name to record payments under, e.g. `banktransfer` |
| **Admin Username** | WHMCS admin username used for API calls (must be an active administrator) |
| **Skip Debtors** | One debtor name per line. Transactions from matching debtors are silently skipped. Useful for payment providers (e.g. `Mollie`) whose transfers should not be matched to invoices. Partial, case-insensitive matching. |

## How It Works

### Invoice number detection

The parser scans the following fields for invoice numbers matching the pattern `20YY-N` (e.g. `2025-308`):

- `RmtInf/Ustrd` — unstructured remittance info
- `RmtInf/Strd/AddtlRmtInf` — structured remittance additional info
- `RmtInf/Strd/CdtrRefInf/Ref` — creditor reference
- `Refs/EndToEndId` — end-to-end ID (if not `NOTPROVIDED`)
- `AddtlNtryInf` — entry-level additional info (ABN AMRO puts SEPA details here)

A single optional letter prefix is stripped automatically, so `F2026-197` is detected as `2026-197`.

### Matching logic

| Scenario | Result |
|---|---|
| Single invoice, amount matches exactly | Marked **Paid**, email sent |
| Multiple invoices, combined total matches | All marked **Paid**, emails sent |
| Invoice already Paid / Refunded | **Skipped** |
| Amount does not match | **Skipped** (wrong_amount) |
| Invoice was Cancelled | Reactivated to Unpaid, then marked Paid |
| No invoice number detected | Logged as **error** — manual reconciliation needed |
| Debtor on skip list | Silently **skipped**, no log record |

### Automatic processing (cron)

The module registers a `CronJob` hook that runs on every WHMCS cron execution (typically every 5 minutes). It scans the inbox folder for new XML files and processes any that have not been processed yet.

### Manual processing

Go to **Addons > ABN AMRO CAMT.053 Import** to:

- Upload XML or ZIP files to the inbox
- Process individual files or all pending files at once
- View processing history with per-file stats
- Drill into file details to review each transaction
- Manually assign unrecognised payments to invoices

## File Format

ABN AMRO exports CAMT.053 files with the following filename format:

```
{clientnr}_{accountnr}_{DDMMYY}{HHMMSS}.xml
```

Example: `59060336_531636917_270525000000.xml` = statement for 27 May 2025.

The module uses this date for sorting and display — it does **not** rely on the processed-at timestamp.

## Database Tables

The module creates two tables on activation:

| Table | Description |
|---|---|
| `mod_abn_camt_files` | One record per processed XML file with aggregate stats |
| `mod_abn_camt_payments` | One record per transaction/invoice match within each file |

Tables are **not** dropped on deactivation — your history is preserved.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT License — see [LICENSE](LICENSE).

## Author

Developed by [Digizaal](https://www.digizaal.nl).
