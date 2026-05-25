<?php
/**
 * Processes a CAMT.053 file: matches credit transactions to WHMCS invoices,
 * marks matched unpaid invoices as paid, sends confirmation emails, and logs
 * everything to mod_abn_camt_files / mod_abn_camt_payments.
 *
 * Uses WHMCS localAPI('AddInvoicePayment') which handles:
 *   - Setting invoice status to Paid
 *   - Recording the payment transaction
 *   - Sending the "Invoice Payment Confirmation" email to the client
 *
 * Phase 2: active payment processing.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/DomainRenewalUpdater.php';

class AbnPaymentProcessor
{
    /** @var string  WHMCS gateway module name, e.g. "banktransfer" */
    private $gateway;

    /** @var string  WHMCS admin username for localAPI calls */
    private $adminUser;

    /** @var string[]  Debtor names to silently skip (e.g. payment providers) */
    private $skipDebtors;

    /** @var bool */
    private $useCapsule;

    public function __construct($gateway, $adminUser, array $skipDebtors = [])
    {
        $this->gateway     = $gateway;
        $this->adminUser   = $adminUser;
        $this->skipDebtors = $skipDebtors;
        $this->useCapsule  = class_exists('\\WHMCS\\Database\\Capsule');
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Returns true if the file has already been (successfully) processed.
     */
    public function isProcessed($filename, $camtFolder)
    {
        $hash = $this->folderHash($camtFolder);

        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';
            return $c::table('mod_abn_camt_files')
                ->where('filename', $filename)
                ->where('folder_hash', $hash)
                ->whereIn('status', ['processed', 'partial'])
                ->exists();
        }

        $esc = addslashes($filename);
        $res = full_query("SELECT id FROM mod_abn_camt_files WHERE filename='{$esc}' AND folder_hash='{$hash}' AND status IN ('processed','partial') LIMIT 1");
        return $res && mysql_num_rows($res) > 0;
    }

    /**
     * Parse, match, pay and log one CAMT file.
     *
     * @return array  Summary with keys: status, file, stats, payments
     */
    public function processFile($filePath, $camtFolder)
    {
        $filename = basename($filePath);

        if ($this->isProcessed($filename, $camtFolder)) {
            return ['status' => 'already_processed', 'file' => $filename];
        }

        $parser       = new AbnCamtParser();
        $transactions = $parser->parse($filePath);
        $matcher      = new AbnInvoiceMatcher();

        $stats = ['total' => count($transactions), 'paid' => 0, 'skipped' => 0, 'error' => 0, 'amount_total' => 0.0];

        // Insert file record with status=processing so cron won't double-start
        $fileId = $this->insertFileRecord($filename, $camtFolder, $stats['total']);

        $paymentResults = [];

        foreach ($transactions as $tx) {
            $stats['amount_total'] += (float) $tx['amount'];

            // Silently skip configured debtors (e.g. Mollie payment provider)
            if ($this->isSkippedDebtor($tx['debtor_name'] ?? '')) {
                $stats['skipped']++;
                continue;
            }

            $matches = $matcher->matchInvoices(
                $tx['detected_invoice_numbers'],
                $tx['amount'],
                $tx['reference_hints'] ?? []
            );

            if (empty($matches)) {
                // No invoice number detected — log as error so it appears in the
                // detail view with full debtor/amount info for manual reconciliation
                $this->logPaymentRow($fileId, [], (float) $tx['amount'], $tx, '', 'error', 'no_invoice_ref');
                $stats['error']++;
                continue;
            }

            $txPaid  = 0;
            $txError = 0;

            foreach ($matches as $match) {
                if (in_array($match['status'], ['exact', 'multi', 'multi_overpay', 'overpaid'], true)) {
                    $result = $this->payInvoice($match, $tx, $fileId);
                } else {
                    // wrong_amount / already paid / not_found — log and skip
                    $result = $this->logSkipped($match, $tx, $fileId);
                }

                $paymentResults[] = $result;

                if ($result['status'] === 'paid')      $txPaid++;
                elseif ($result['status'] === 'error') $txError++;
            }

            // Paid invoices counted individually (multi = several invoices from 1 tx).
            // Skipped/error counted once per transaction so totals never exceed tx_total.
            $stats['paid'] += $txPaid;
            if ($txPaid === 0 && $txError === 0) {
                $stats['skipped']++;
            } elseif ($txError > 0) {
                $stats['error']++;
            }
        }

        $this->finaliseFileRecord($fileId, $stats);

        return [
            'status'   => 'processed',
            'file'     => $filename,
            'file_id'  => $fileId,
            'stats'    => $stats,
            'payments' => $paymentResults,
        ];
    }

    /**
     * Aggregate totals across all processed files for the dashboard.
     *
     * @return array  keys: files, invoices_paid, invoices_skipped, errors, amount_paid
     */
    public function getStats()
    {
        $stats = ['files' => 0, 'invoices_paid' => 0, 'invoices_skipped' => 0, 'errors' => 0, 'amount_paid' => 0.0, 'amount_total' => 0.0];

        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';

            $totals = $c::table('mod_abn_camt_files')
                ->whereIn('status', ['processed', 'partial'])
                ->selectRaw('COUNT(*) as files, SUM(tx_paid) as paid, SUM(tx_skipped) as skipped, SUM(tx_error) as errors')
                ->first();

            if ($totals) {
                $stats['files']            = (int) $totals->files;
                $stats['invoices_paid']    = (int) $totals->paid;
                $stats['invoices_skipped'] = (int) $totals->skipped;
                $stats['errors']           = (int) $totals->errors;
            }

            $stats['amount_paid']  = (float) $c::table('mod_abn_camt_payments')->where('status', 'paid')->sum('amount');
            $stats['amount_total'] = (float) $c::table('mod_abn_camt_files')->whereIn('status', ['processed', 'partial'])->sum('amount_total');
        }

        return $stats;
    }

    /**
     * Fetch recent processed file records for the history view.
     */
    public function getHistory($limit = 30)
    {
        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';
            $rows = $c::table('mod_abn_camt_files')
                ->orderBy('processed_at', 'desc')
                ->limit($limit)
                ->get();
            return array_map(function ($r) { return (array) $r; }, $rows->all());
        }

        $rows = [];
        $res  = full_query("SELECT * FROM mod_abn_camt_files ORDER BY processed_at DESC LIMIT " . (int) $limit);
        while ($res && ($row = mysql_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Fetch payment records for one processed file.
     */
    public function getFilePayments($fileId)
    {
        $fileId = (int) $fileId;

        if ($this->useCapsule) {
            $c    = '\\WHMCS\\Database\\Capsule';
            $rows = $c::table('mod_abn_camt_payments')
                ->where('file_id', $fileId)
                ->orderBy('id')
                ->get();
            return array_map(function ($r) { return (array) $r; }, $rows->all());
        }

        $rows = [];
        $res  = full_query("SELECT * FROM mod_abn_camt_payments WHERE file_id={$fileId} ORDER BY id");
        while ($res && ($row = mysql_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }

    // =========================================================================
    // Payment logic
    // =========================================================================

    private function payInvoice(array $match, array $tx, $fileId)
    {
        $inv = $match['invoice'];

        // Guard: truly non-payable statuses — skip these
        if (in_array($inv['status'], ['Paid', 'Refunded', 'Collections', 'Draft'], true)) {
            return $this->logPaymentRow($fileId, $inv, 0, $tx, '', 'skipped', 'invoice_' . strtolower($inv['status']));
        }

        // Cancelled invoice: reactivate to Unpaid first so AddInvoicePayment can run
        if ($inv['status'] === 'Cancelled') {
            $reactivate = localAPI('UpdateInvoice', [
                'invoiceid' => (int) $inv['id'],
                'status'    => 'Unpaid',
            ], $this->adminUser);

            if (!isset($reactivate['result']) || $reactivate['result'] !== 'success') {
                $errMsg = $reactivate['message'] ?? 'Could not reactivate cancelled invoice';
                return $this->logPaymentRow($fileId, $inv, 0, $tx, '', 'error', $errMsg);
            }
        }

        // For multi-match each invoice is paid at its own total. Overpayment is
        // added to the final selected invoice so WHMCS books the remainder as credit.
        $payAmount = isset($match['pay_amount'])
            ? (float) $match['pay_amount']
            : (in_array($match['status'], ['multi', 'overpaid'], true) ? (float) $inv['total'] : (float) $tx['amount']);

        // Unique transaction ID: bank ref + invoice id
        $cleanRef = preg_replace('/[^A-Za-z0-9\-]/', '', $tx['bank_reference']);
        $transId  = substr('ABN-' . $cleanRef . '-' . $inv['id'], 0, 255);

        $apiParams = [
            'invoiceid' => (int) $inv['id'],
            'transid'   => $transId,
            'gateway'   => $this->gateway,
            'date'      => $tx['booking_date'],
            'amount'    => $payAmount,
            'noemail'   => 0,   // 0 = send payment confirmation email to client
        ];

        try {
            $result = localAPI('AddInvoicePayment', $apiParams, $this->adminUser);

            if (isset($result['result']) && $result['result'] === 'success') {
                $domainSync = AbnDomainRenewalUpdater::syncPaidInvoice((int) $inv['id']);

                if ($match['status'] === 'overpaid' && !empty($match['overpay'])) {
                    $overpay     = round((float) $match['overpay'], 2);
                    $creditResult = localAPI('AddCredit', [
                        'clientid'    => (int) $inv['userid'],
                        'amount'      => $overpay,
                        'description' => 'Overpayment on invoice ' . ($inv['invoicenum'] ?: '#' . $inv['id']),
                    ], $this->adminUser);
                    $creditOk = isset($creditResult['result']) && $creditResult['result'] === 'success';
                    $note = 'overpaid:' . number_format($overpay, 2, '.', '') . ($creditOk ? '' : ':credit_failed');
                } else {
                    $note = !empty($match['overpay']) ? 'multi_overpay:' . number_format((float) $match['overpay'], 2, '.', '') : '';
                }
                if (!empty($domainSync['updated'])) {
                    $note = trim($note . ($note !== '' ? ';' : '') . 'domain_dates_advanced:' . (int) $domainSync['updated'], ';');
                }
                return $this->logPaymentRow($fileId, $inv, $payAmount, $tx, $transId, 'paid', $note);
            }

            $errMsg = $result['message'] ?? 'API returned non-success';
            return $this->logPaymentRow($fileId, $inv, $payAmount, $tx, $transId, 'error', $errMsg);

        } catch (Exception $e) {
            return $this->logPaymentRow($fileId, $inv, $payAmount, $tx, $transId, 'error', $e->getMessage());
        }
    }

    private function logSkipped(array $match, array $tx, $fileId)
    {
        $inv = $match['invoice'] ?? [];
        // Store the invoice's own total, not the bank transaction amount.
        // For multi-invoice transactions this prevents inflating the total by the
        // number of invoices. For not_found (no invoice) we store 0.
        $amount = isset($inv['total']) ? (float) $inv['total'] : 0.0;
        return $this->logPaymentRow($fileId, $inv, $amount, $tx, '', 'skipped', $match['status'] . (isset($match['number']) ? ':' . $match['number'] : ''));
    }

    // =========================================================================
    // DB helpers
    // =========================================================================

    private function insertFileRecord($filename, $camtFolder, $txTotal)
    {
        $hash = $this->folderHash($camtFolder);
        $now  = date('Y-m-d H:i:s');

        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';
            return $c::table('mod_abn_camt_files')->insertGetId([
                'filename'     => $filename,
                'folder_hash'  => $hash,
                'processed_at' => $now,
                'tx_total'     => $txTotal,
                'tx_paid'      => 0,
                'tx_skipped'   => 0,
                'tx_error'     => 0,
                'amount_total' => 0,
                'status'       => 'processing',
            ]);
        }

        $esc = addslashes($filename);
        full_query("INSERT INTO mod_abn_camt_files (filename,folder_hash,processed_at,tx_total,tx_paid,tx_skipped,tx_error,amount_total,status) VALUES ('{$esc}','{$hash}','{$now}',{$txTotal},0,0,0,0,'processing')");
        return mysql_insert_id();
    }

    private function finaliseFileRecord($fileId, array $stats)
    {
        $fileId = (int) $fileId;
        $status = $stats['error'] > 0 ? 'partial' : 'processed';

        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';
            $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
                'tx_paid'      => $stats['paid'],
                'tx_skipped'   => $stats['skipped'],
                'tx_error'     => $stats['error'],
                'amount_total' => round($stats['amount_total'], 2),
                'status'       => $status,
            ]);
            return;
        }

        $amtTotal = round($stats['amount_total'], 2);
        full_query("UPDATE mod_abn_camt_files SET tx_paid={$stats['paid']},tx_skipped={$stats['skipped']},tx_error={$stats['error']},amount_total={$amtTotal},status='{$status}' WHERE id={$fileId}");
    }

    private function logPaymentRow($fileId, array $inv, $amount, array $tx, $transId, $status, $note)
    {
        $fileId = (int) $fileId;
        $invId  = (int) ($inv['id'] ?? 0);
        $now    = date('Y-m-d H:i:s');

        $row = [
            'file_id'         => $fileId,
            'invoice_id'      => $invId,
            'invoice_num'     => substr((string) ($inv['invoicenum'] ?? ''), 0, 50),
            'amount'          => round((float) $amount, 2),
            'tx_amount'       => round((float) ($tx['amount'] ?? 0), 2),
            'currency'        => $tx['currency'] ?? 'EUR',
            'booking_date'    => $tx['booking_date'] ?: null,
            'debtor_name'     => substr($tx['debtor_name'] ?? '', 0, 255),
            'debtor_iban'     => substr($tx['debtor_iban'] ?? '', 0, 34),
            'bank_reference'  => substr($tx['bank_reference'] ?? '', 0, 255),
            'remittance_info' => substr($tx['remittance_info'] ?? '', 0, 500),
            'trans_id'        => substr((string) $transId, 0, 255),
            'status'          => $status,
            'note'            => substr((string) $note, 0, 500),
            'processed_at'    => $now,
        ];

        if ($this->useCapsule) {
            $c = '\\WHMCS\\Database\\Capsule';
            $c::table('mod_abn_camt_payments')->insert($row);
        }
        // Legacy fallback omitted — Capsule is standard in any WHMCS >= 6

        return array_merge(['status' => $status, 'invoice_id' => $invId, 'note' => $note], $row);
    }

    // =========================================================================
    // Table management (called from activate/deactivate)
    // =========================================================================

    public static function createTables()
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            // Raw SQL fallback
            full_query("CREATE TABLE IF NOT EXISTS `mod_abn_camt_files` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `filename` varchar(255) NOT NULL,
                `folder_hash` varchar(64) NOT NULL,
                `processed_at` datetime NOT NULL,
                `tx_total` int(11) NOT NULL DEFAULT 0,
                `tx_paid` int(11) NOT NULL DEFAULT 0,
                `tx_skipped` int(11) NOT NULL DEFAULT 0,
                `tx_error` int(11) NOT NULL DEFAULT 0,
                `amount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
                `status` enum('processing','processed','partial','error') NOT NULL DEFAULT 'processing',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_file_folder` (`filename`(191),`folder_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            full_query("CREATE TABLE IF NOT EXISTS `mod_abn_camt_payments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `file_id` int(11) NOT NULL,
                `invoice_id` int(11) NOT NULL DEFAULT 0,
                `invoice_num` varchar(50) NOT NULL DEFAULT '',
                `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `tx_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) NOT NULL DEFAULT 'EUR',
                `booking_date` date DEFAULT NULL,
                `debtor_name` varchar(255) NOT NULL DEFAULT '',
                `debtor_iban` varchar(34) NOT NULL DEFAULT '',
                `bank_reference` varchar(255) NOT NULL DEFAULT '',
                `remittance_info` varchar(500) NOT NULL DEFAULT '',
                `trans_id` varchar(255) NOT NULL DEFAULT '',
                `status` enum('paid','skipped','error') NOT NULL DEFAULT 'skipped',
                `note` varchar(500) NOT NULL DEFAULT '',
                `processed_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_file_id` (`file_id`),
                KEY `idx_invoice_id` (`invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }

        $c = '\\WHMCS\\Database\\Capsule';

        if (!$c::schema()->hasTable('mod_abn_camt_files')) {
            $c::schema()->create('mod_abn_camt_files', function ($t) {
                $t->increments('id');
                $t->string('filename', 255);
                $t->string('folder_hash', 64);
                $t->dateTime('processed_at');
                $t->integer('tx_total')->default(0);
                $t->integer('tx_paid')->default(0);
                $t->integer('tx_skipped')->default(0);
                $t->integer('tx_error')->default(0);
                $t->decimal('amount_total', 12, 2)->default(0);
                $t->enum('status', ['processing', 'processed', 'partial', 'error'])->default('processing');
                $t->unique(['filename', 'folder_hash'], 'uq_file_folder');
            });
        }

        if (!$c::schema()->hasTable('mod_abn_camt_payments')) {
            $c::schema()->create('mod_abn_camt_payments', function ($t) {
                $t->increments('id');
                $t->integer('file_id');
                $t->integer('invoice_id')->default(0);
                $t->string('invoice_num', 50)->default('');
                $t->decimal('amount', 10, 2)->default(0);
                $t->decimal('tx_amount', 10, 2)->default(0);
                $t->string('currency', 3)->default('EUR');
                $t->date('booking_date')->nullable();
                $t->string('debtor_name', 255)->default('');
                $t->string('debtor_iban', 34)->default('');
                $t->string('bank_reference', 255)->default('');
                $t->string('remittance_info', 500)->default('');
                $t->string('trans_id', 255)->default('');
                $t->enum('status', ['paid', 'skipped', 'error'])->default('skipped');
                $t->string('note', 500)->default('');
                $t->dateTime('processed_at');
                $t->index('file_id', 'idx_file_id');
                $t->index('invoice_id', 'idx_invoice_id');
            });
        }
    }

    public static function dropTables()
    {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $c = '\\WHMCS\\Database\\Capsule';
            $c::schema()->dropIfExists('mod_abn_camt_payments');
            $c::schema()->dropIfExists('mod_abn_camt_files');
        }
    }

    // =========================================================================

    public static function ensureSchema()
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return;
        }

        $c = '\\WHMCS\\Database\\Capsule';
        if (!$c::schema()->hasTable('mod_abn_camt_payments')) {
            return;
        }

        if (!$c::schema()->hasColumn('mod_abn_camt_payments', 'tx_amount')) {
            $c::schema()->table('mod_abn_camt_payments', function ($t) {
                $t->decimal('tx_amount', 10, 2)->default(0)->after('amount');
            });
        }
    }

    private function isSkippedDebtor($debtorName)
    {
        if (empty($this->skipDebtors) || $debtorName === '') {
            return false;
        }
        $lower = strtolower($debtorName);
        foreach ($this->skipDebtors as $skip) {
            if (stripos($lower, strtolower($skip)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function folderHash($camtFolder)
    {
        return md5(rtrim($camtFolder, '/'));
    }
}
