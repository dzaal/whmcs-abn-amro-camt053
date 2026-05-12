<?php
/**
 * ABN AMRO CAMT.053 Import — WHMCS Addon Module
 *
 * Phase 2: automatic + manual payment processing.
 *   - Tracks which files have been processed (mod_abn_camt_files table)
 *   - Marks matching unpaid invoices as Paid via WHMCS localAPI
 *   - Sends payment confirmation email to client (noemail=0)
 *   - Auto-processes new files on every WHMCS cron run (hooks.php)
 *   - Manual processing + history view in admin addon page
 *   - Phase 1 preview tab kept for verification
 *
 * @author  DigiZaal
 * @version 2.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/CamtParser.php';
require_once __DIR__ . '/lib/InvoiceMatcher.php';
require_once __DIR__ . '/lib/PaymentProcessor.php';

// =============================================================================
// Module registration
// =============================================================================

function abn_camt_import_config()
{
    return [
        'name'        => 'ABN AMRO CAMT.053 Import',
        'description' => 'Automatically import ABN AMRO CAMT.053 bank statements and match credit transactions to WHMCS invoices.',
        'version'     => '2.0.0',
        'author'      => 'DigiZaal',
        'language'    => 'english',
        'fields'      => [
            'camt_folder' => [
                'FriendlyName' => 'CAMT.053 Inbox Folder',
                'Type'         => 'text',
                'Size'         => '120',
                'Default'      => '/var/www/vhosts/projekt.nl/private/abn-camt/incoming/',
                'Description'  => 'Absolute server path to the folder containing ABN AMRO CAMT.053 XML files.',
            ],
            'gateway' => [
                'FriendlyName' => 'Payment Gateway',
                'Type'         => 'text',
                'Size'         => '40',
                'Default'      => 'banktransfer',
                'Description'  => 'WHMCS gateway module name to record payments under (e.g. banktransfer).',
            ],
            'admin_user' => [
                'FriendlyName' => 'Admin Username',
                'Type'         => 'text',
                'Size'         => '40',
                'Default'      => 'dirk',
                'Description'  => 'WHMCS admin username used for API calls (must be an active admin).',
            ],
            'skip_debtors' => [
                'FriendlyName' => 'Skip Debtors',
                'Type'         => 'textarea',
                'Rows'         => '3',
                'Cols'         => '60',
                'Default'      => 'Mollie',
                'Description'  => 'One debtor name per line. Transactions from these debtors are silently skipped (e.g. payment providers like Mollie).',
            ],
        ],
    ];
}

function abn_camt_import_activate()
{
    try {
        AbnPaymentProcessor::createTables();
        return [
            'status'      => 'success',
            'description' => 'ABN AMRO CAMT.053 Import activated. Tables created. Configure inbox folder, gateway and admin username in module settings.',
        ];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'Could not create tables: ' . $e->getMessage()];
    }
}

function abn_camt_import_deactivate()
{
    // Keep historical data — tables are NOT dropped on deactivate.
    return ['status' => 'success', 'description' => 'ABN AMRO CAMT.053 Import deactivated. Processing history preserved.'];
}

// =============================================================================
// Admin page output
// =============================================================================

function abn_camt_import_output($vars)
{
    AbnPaymentProcessor::ensureSchema();

    $camtFolder   = isset($vars['camt_folder']) ? rtrim(trim($vars['camt_folder']), '/') . '/' : '';
    $gateway      = trim($vars['gateway']      ?? 'banktransfer');
    $adminUser    = trim($vars['admin_user']   ?? '');
    $skipDebtors  = abn_camt_parse_skip_debtors($vars['skip_debtors'] ?? '');

    if (empty($camtFolder) || $camtFolder[0] !== '/') {
        echo '<div class="alert alert-danger">Invalid inbox folder path. Please update <a href="addonmodules.php?module=abn_camt_import">module settings</a>.</div>';
        return;
    }

    $tab        = $_GET['tab'] ?? 'process';
    $actionResult = null;

    // If viewing a file detail, look up its filename so the Preview tab can pre-load it
    $previewFile = '';
    if (!empty($_GET['detail']) && class_exists('\\WHMCS\\Database\\Capsule')) {
        $c   = '\\WHMCS\\Database\\Capsule';
        $row = $c::table('mod_abn_camt_files')->select('filename')->where('id', (int) $_GET['detail'])->first();
        if ($row) {
            $previewFile = $row->filename;
        }
    }

    // ── Handle POST (process + upload actions) ───────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['abn_action'])) {
        $tab = 'process'; // always stay on process tab after any action

        // Upload does not require adminUser — handle it before the adminUser check
        if ($_POST['abn_action'] === 'upload_file') {
            $actionResult = abn_camt_handle_upload($camtFolder);
        } elseif (empty($adminUser)) {
            $actionResult = ['type' => 'error', 'message' => 'Admin username is not configured in module settings.'];
        } else {
            $processor = new AbnPaymentProcessor($gateway, $adminUser, $skipDebtors);

            switch ($_POST['abn_action']) {
                case 'process_file':
                    $file     = basename($_POST['abn_file'] ?? '');
                    $fullPath = $camtFolder . $file;
                    if (!$file || !preg_match('/\.xml$/i', $file) || !is_file($fullPath)) {
                        $actionResult = ['type' => 'error', 'message' => 'Invalid or missing file.'];
                    } else {
                        try {
                            $result       = $processor->processFile($fullPath, $camtFolder);
                            $actionResult = ['type' => 'result', 'data' => $result];
                        } catch (Exception $e) {
                            $actionResult = ['type' => 'error', 'message' => $e->getMessage()];
                        }
                    }
                    break;

                case 'assign_payment':
                    $paymentId  = (int) ($_POST['payment_id']  ?? 0);
                    $invoiceId  = (int) ($_POST['invoice_id']  ?? 0);
                    $invoiceNum = trim($_POST['invoice_num'] ?? '');
                    $actionResult = abn_camt_assign_payment($paymentId, $invoiceId, $invoiceNum, $adminUser, $gateway);
                    break;

                case 'assign_payment_batch':
                    $paymentId  = (int) ($_POST['payment_id'] ?? 0);
                    $invoiceIds = array_map('intval', (array) ($_POST['invoice_ids'] ?? []));
                    $txAmount   = (float) ($_POST['tx_amount'] ?? 0);
                    $actionResult = abn_camt_assign_payment_batch($paymentId, $invoiceIds, $txAmount, $adminUser, $gateway);
                    break;

                case 'ignore_debtor':
                    $paymentId  = (int) ($_POST['payment_id']  ?? 0);
                    $debtorName = trim($_POST['debtor_name'] ?? '');
                    $actionResult = abn_camt_ignore_debtor($paymentId, $debtorName);
                    break;

                case 'process_all':
                    $xmlFiles = abn_camt_list_xml($camtFolder);
                    $results  = [];
                    foreach ($xmlFiles as $file) {
                        if ($processor->isProcessed($file, $camtFolder)) continue;
                        try {
                            $results[] = $processor->processFile($camtFolder . $file, $camtFolder);
                        } catch (Exception $e) {
                            $results[] = ['status' => 'error', 'file' => $file, 'message' => $e->getMessage()];
                        }
                    }
                    $actionResult = ['type' => 'batch', 'data' => $results];
                    break;
            }
        }
    }

    abn_camt_styles();

    // ── Tabs ─────────────────────────────────────────────────────────────────
    $baseUrl = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=abn_camt_import');
    ?>
    <h2 style="margin-bottom:4px">ABN AMRO CAMT.053 Import</h2>
    <p class="text-muted" style="margin-bottom:16px">
        Gateway: <code><?= htmlspecialchars($gateway) ?></code> &nbsp;&bull;&nbsp;
        Admin: <code><?= htmlspecialchars($adminUser ?: '(not set)') ?></code> &nbsp;&bull;&nbsp;
        Inbox: <code><?= htmlspecialchars($camtFolder) ?></code>
    </p>

    <ul class="nav nav-tabs" style="margin-bottom:20px">
        <li class="<?= $tab === 'process' ? 'active' : '' ?>">
            <a href="<?= $baseUrl ?>&tab=process">Process &amp; History</a>
        </li>
        <li class="<?= $tab === 'preview' ? 'active' : '' ?>">
            <a href="<?= $baseUrl ?>&tab=preview<?= $previewFile ? '&file=' . urlencode($previewFile) : '' ?>">Preview</a>
        </li>
    </ul>

    <?php if ($tab === 'process'): ?>
        <?php abn_camt_tab_process($vars, $camtFolder, $gateway, $adminUser, $actionResult, $skipDebtors); ?>
    <?php else: ?>
        <?php abn_camt_tab_preview($vars, $camtFolder); ?>
    <?php endif; ?>
    <?php
}

// =============================================================================
// Tab: Process & History
// =============================================================================

function abn_camt_tab_process($vars, $camtFolder, $gateway, $adminUser, $actionResult, $skipDebtors = [])
{
    $folderOk  = is_dir($camtFolder) && is_readable($camtFolder);
    $processor = new AbnPaymentProcessor($gateway, $adminUser, $skipDebtors);
    $baseUrl   = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=abn_camt_import');

    // Collect file lists
    $allFiles    = $folderOk ? abn_camt_list_xml($camtFolder) : [];
    $unprocessed = array_values(array_filter($allFiles, fn($f) => !$processor->isProcessed($f, $camtFolder)));

    // Detail view?
    if (!empty($_GET['detail'])) {
        abn_camt_render_detail($processor, (int) $_GET['detail'], $vars);
        return;
    }

    // ── Alerts ───────────────────────────────────────────────────────────────
    if ($actionResult) {
        abn_camt_render_action_result($actionResult);
    }
    if (!$folderOk) {
        echo '<div class="alert alert-warning"><strong>Inbox folder not accessible:</strong> <code>' . htmlspecialchars($camtFolder) . '</code></div>';
    }
    if (empty($adminUser)) {
        echo '<div class="alert alert-danger"><strong>Admin username not configured.</strong> Set it in <a href="addonmodules.php?module=abn_camt_import">module settings</a>.</div>';
    }

    // ── Summary stat boxes ───────────────────────────────────────────────────
    $stats   = $processor->getStats();
    $history = $processor->getHistory(100);

    // Sort by the period date encoded in the filename (DDMMYY), newest first
    usort($history, function ($a, $b) {
        return abn_camt_filename_sort_key($b['filename']) <=> abn_camt_filename_sort_key($a['filename']);
    });

    // Amount paid per month for the sparkline-style bar (last 6 months)
    $monthlyPaid = abn_camt_monthly_paid($history);
    ?>

    <div class="abn-stat-row">
        <div class="abn-stat-box abn-stat-blue">
            <div class="abn-stat-num"><?= $stats['files'] ?></div>
            <div class="abn-stat-label">Files processed</div>
        </div>
        <div class="abn-stat-box abn-stat-green">
            <div class="abn-stat-num"><?= $stats['invoices_paid'] ?></div>
            <div class="abn-stat-label">Invoices paid</div>
        </div>
        <div class="abn-stat-box abn-stat-amount">
            <div class="abn-stat-num">&euro;&nbsp;<?= number_format($stats['amount_total'] ?? $stats['amount_paid'], 2, ',', '.') ?></div>
            <div class="abn-stat-label">Total amount processed</div>
        </div>
        <div class="abn-stat-box abn-stat-green-light">
            <div class="abn-stat-num">&euro;&nbsp;<?= number_format($stats['amount_paid'], 2, ',', '.') ?></div>
            <div class="abn-stat-label">Amount paid to invoices</div>
        </div>
        <div class="abn-stat-box abn-stat-grey">
            <div class="abn-stat-num"><?= $stats['invoices_skipped'] ?></div>
            <div class="abn-stat-label">Skipped</div>
        </div>
        <?php if ($stats['errors'] > 0): ?>
        <div class="abn-stat-box abn-stat-red">
            <div class="abn-stat-num"><?= $stats['errors'] ?></div>
            <div class="abn-stat-label">Errors</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($unprocessed)): ?>
        <div class="abn-stat-box abn-stat-orange">
            <div class="abn-stat-num"><?= count($unprocessed) ?></div>
            <div class="abn-stat-label">Waiting to process</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Monthly paid bar chart ─────────────────────────────────────────── -->
    <?php if (!empty($monthlyPaid)): ?>
    <div class="panel panel-default" style="margin-bottom:16px">
        <div class="panel-heading"><h4 class="panel-title">Invoices marked paid per month (by processing date)</h4></div>
        <div class="panel-body" style="padding:14px 18px 6px">
            <div class="abn-bar-chart">
            <?php
            $paidCounts = array_column($monthlyPaid, 'paid');
            $maxPaid    = empty($paidCounts) ? 1 : max(max($paidCounts), 1);
            foreach ($monthlyPaid as $m):
                $pct = round($m['paid'] / $maxPaid * 100);
            ?>
                <div class="abn-bar-col">
                    <div class="abn-bar-val"><?= $m['paid'] ?></div>
                    <div class="abn-bar" style="height:<?= max($pct, 2) ?>px" title="<?= htmlspecialchars($m['month']) ?>: <?= $m['paid'] ?> paid"></div>
                    <div class="abn-bar-lbl"><?= htmlspecialchars($m['month']) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Upload ────────────────────────────────────────────────────────────── -->
    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">Upload CAMT.053 file</h4></div>
        <div class="panel-body">
            <?php if (!$folderOk): ?>
                <p class="text-muted">Inbox folder unavailable — cannot upload.</p>
            <?php else: ?>
            <form method="post" action="<?= $baseUrl ?>&tab=process" enctype="multipart/form-data">
                <input type="hidden" name="abn_action" value="upload_file">
                <div class="form-inline">
                    <div class="form-group">
                        <input type="file" name="camt_upload[]" accept=".xml,.zip" multiple class="form-control"
                               style="display:inline-block;max-width:380px">
                    </div>
                    &nbsp;
                    <button type="submit" class="btn btn-default">
                        &#8679; Upload to inbox
                    </button>
                </div>
                <p class="help-block" style="margin-top:6px;margin-bottom:0">
                    Upload one or more CAMT.053 XML files, or an ABN AMRO ZIP (XML files will be extracted automatically). Hold Ctrl/Cmd to select multiple files &mdash; max <?= ini_get('upload_max_filesize') ?>B per file.
                    After upload the file(s) appear in the inbox below and can be processed immediately.
                </p>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Unprocessed files ──────────────────────────────────────────────── -->
    <div class="panel panel-<?= empty($unprocessed) ? 'default' : 'warning' ?>">
        <div class="panel-heading">
            <h4 class="panel-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>Unprocessed inbox files <span class="badge"><?= count($unprocessed) ?></span></span>
                <?php if (!empty($unprocessed) && !empty($adminUser)): ?>
                <form method="post" action="<?= $baseUrl ?>&tab=process" style="margin:0">
                    <input type="hidden" name="abn_action" value="process_all">
                    <button type="submit" class="btn btn-sm btn-success"
                        onclick="return confirm('Process all <?= count($unprocessed) ?> file(s) now?\nMatched unpaid invoices will be marked as paid and clients will receive a confirmation email.')">
                        &#9654; Process all (<?= count($unprocessed) ?>)
                    </button>
                </form>
                <?php endif; ?>
            </h4>
        </div>
        <div class="panel-body" style="padding:0">
            <?php if (empty($unprocessed)): ?>
                <p style="padding:12px 16px;margin:0;color:#666">&#10003; Inbox is clear — no new files to process.</p>
            <?php else: ?>
                <table class="table table-condensed" style="margin:0">
                    <thead><tr><th>Statement date</th><th>Filename</th><th>Size</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($unprocessed as $file):
                        $path      = $camtFolder . $file;
                        $size      = is_file($path) ? filesize($path) : 0;
                        $stmtDate  = abn_camt_filename_date($file);
                    ?>
                    <tr>
                        <td style="white-space:nowrap;font-weight:600"><?= $stmtDate ? htmlspecialchars($stmtDate) : '<span class="text-muted">—</span>' ?></td>
                        <td><code style="font-size:.82em"><?= htmlspecialchars($file) ?></code></td>
                        <td><?= number_format($size) ?> B</td>
                        <td>
                            <?php if (!empty($adminUser)): ?>
                            <form method="post" action="<?= $baseUrl ?>&tab=process" style="margin:0;display:inline">
                                <input type="hidden" name="abn_action" value="process_file">
                                <input type="hidden" name="abn_file" value="<?= htmlspecialchars($file) ?>">
                                <button type="submit" class="btn btn-xs btn-primary"
                                    onclick="return confirm('Process <?= htmlspecialchars(addslashes($file)) ?>?\nMatched unpaid invoices will be marked paid and clients notified.')">
                                    Process
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Processing history ─────────────────────────────────────────────── -->
    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">Processing history <span class="text-muted" style="font-size:.85em;font-weight:400">(most recent first)</span></h4></div>
        <div class="panel-body" style="padding:0">
            <?php if (empty($history)): ?>
                <p style="padding:12px 16px;margin:0;color:#666">No files processed yet.</p>
            <?php else: ?>
                <table class="table table-condensed table-hover" style="margin:0">
                    <thead>
                        <tr>
                            <th>Processed on</th>
                            <th>Statement date</th>
                            <th>Filename</th>
                            <th class="text-center" title="Total number of credit transactions in this file">Transactions</th>
                            <th class="text-center" title="Invoices automatically matched and marked paid">&#10003;&nbsp;Invoices paid</th>
                            <th class="text-center" title="Transactions skipped (already paid, wrong amount, ignored debtor, etc.)">Skipped</th>
                            <th class="text-center" title="Transactions that could not be matched and need manual attention">Needs attention</th>
                            <th class="text-right" title="Total incoming bank amount in this file">Bank amount</th>
                            <th class="text-right" title="Amount applied to invoices">Applied to invoices</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row):
                        $amountPaid     = abn_camt_file_amount_paid((int) $row['id'], $processor);
                        $amountReceived = (float) ($row['amount_total'] ?? 0);
                        $stmtDate       = abn_camt_filename_date($row['filename']);
                        $txTotal    = (int) $row['tx_total'];
                        $txPaid     = (int) $row['tx_paid'];
                        $txSkipped  = (int) $row['tx_skipped'];
                        $txError    = (int) $row['tx_error'];
                    ?>
                    <tr>
                        <td style="white-space:nowrap;color:#666;font-size:.85em"><?= htmlspecialchars(substr($row['processed_at'], 0, 16)) ?></td>
                        <td style="white-space:nowrap;font-weight:600"><?= $stmtDate ? htmlspecialchars($stmtDate) : '<span class="text-muted">—</span>' ?></td>
                        <td><code style="font-size:.78em;color:#555"><?= htmlspecialchars($row['filename']) ?></code></td>
                        <td class="text-center" title="<?= $txTotal ?> transaction(s) in this file"><?= $txTotal ?></td>
                        <td class="text-center" style="color:#1c7a3c;font-weight:700" title="<?= $txPaid ?> invoice(s) marked paid"><?= $txPaid ?></td>
                        <td class="text-center" style="color:#555" title="<?= $txSkipped ?> transaction(s) skipped"><?= $txSkipped ?></td>
                        <td class="text-center" style="<?= $txError > 0 ? 'color:#b92b2b;font-weight:700' : 'color:#888' ?>"
                            title="<?= $txError > 0 ? $txError . ' transaction(s) need manual attention' : 'No issues' ?>">
                            <?= $txError > 0 ? '&#9888;&nbsp;' . $txError : $txError ?>
                        </td>
                        <td class="text-right" style="color:#555">
                            <?= $amountReceived > 0 ? '&euro;&nbsp;' . number_format($amountReceived, 2, ',', '.') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="text-right" style="color:#1c7a3c;font-weight:600">
                            <?= $amountPaid > 0 ? '&euro;&nbsp;' . number_format($amountPaid, 2, ',', '.') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= abn_camt_status_badge($row['status']) ?></td>
                        <td><a href="<?= $baseUrl ?>&tab=process&detail=<?= (int) $row['id'] ?>" class="btn btn-xs btn-default">Details</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// =============================================================================
// File detail view
// =============================================================================

function abn_camt_render_detail($processor, $fileId, $vars)
{
    $baseUrl  = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=abn_camt_import');
    $payments = $processor->getFilePayments($fileId);

    echo '<p><a href="' . $baseUrl . '&tab=process" class="btn btn-default btn-sm">&larr; Back to overview</a></p>';

    if (empty($payments)) {
        echo '<div class="alert alert-info">No payment records for this file.</div>';
        return;
    }

    // Build invoice_id → userid and userid → client name maps for linking
    $invUserMap  = [];
    $clientNames = [];
    $invNumIdMap = [];
    foreach ($payments as $p) {
        if ($p['invoice_num'] && $p['invoice_id'] > 0) {
            $invNumIdMap[$p['invoice_num']] = (int) $p['invoice_id'];
        }
    }
    $invoiceIds = array_values(array_filter(array_unique(array_map(fn($p) => (int) $p['invoice_id'], $payments))));
    if (!empty($invoiceIds) && class_exists('\\WHMCS\\Database\\Capsule')) {
        $c = '\\WHMCS\\Database\\Capsule';
        foreach ($c::table('tblinvoices')->whereIn('id', $invoiceIds)->select(['id', 'userid'])->get()->all() as $r) {
            $invUserMap[(int) $r->id] = (int) $r->userid;
        }
        $userIds = array_values(array_unique(array_filter(array_values($invUserMap))));
        if (!empty($userIds)) {
            foreach ($c::table('tblclients')->whereIn('id', $userIds)->select(['id', 'firstname', 'lastname'])->get()->all() as $r) {
                $clientNames[(int) $r->id] = trim($r->firstname . ' ' . $r->lastname);
            }
        }
    }

    // Group by bank_reference so one bank transaction = one card
    $grouped = [];
    foreach ($payments as $p) {
        $key = $p['bank_reference'] ?: ('_' . $p['id']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'bank_reference'  => $p['bank_reference'],
                'booking_date'    => $p['booking_date'],
                'debtor_name'     => $p['debtor_name'],
                'debtor_iban'     => $p['debtor_iban'],
                'remittance_info' => $p['remittance_info'],
                'records'         => [],
            ];
        }
        $grouped[$key]['records'][] = $p;
    }

    foreach ($grouped as $group) {
        $records  = $group['records'];
        $recordTxAmounts = array_filter(array_map(fn($r) => (float) ($r['tx_amount'] ?? 0), $records));
        $totalAmt = !empty($recordTxAmounts)
            ? max($recordTxAmounts)
            : array_sum(array_map(fn($r) => (float) $r['amount'], $records));
        $anyPaid  = (bool) array_filter($records, fn($r) => $r['status'] === 'paid');
        $anyError = (bool) array_filter($records, fn($r) => $r['status'] === 'error' && $r['note'] !== 'manually_assigned');
        $panelCss = $anyError ? 'danger' : ($anyPaid ? 'success' : 'default');

        // Resolve client for this group via any matched invoice
        $groupUserId = 0;
        foreach ($records as $p) {
            if ($p['invoice_id'] > 0 && isset($invUserMap[(int) $p['invoice_id']])) {
                $groupUserId = $invUserMap[(int) $p['invoice_id']];
                break;
            }
        }

        echo '<div class="panel panel-' . $panelCss . '" style="margin-bottom:10px">';

        // Transaction header
        echo '<div class="panel-heading" style="padding:8px 14px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center">';
        echo '<div>';
        $debtorText = htmlspecialchars($group['debtor_name'] ?: '(unknown debtor)');
        if ($groupUserId) {
            $clientLabel = isset($clientNames[$groupUserId]) ? ' (' . htmlspecialchars($clientNames[$groupUserId]) . ')' : '';
            echo '<strong><a href="clients.php?action=edit&id=' . $groupUserId . '" target="_blank">' . $debtorText . '</a>' . $clientLabel . '</strong>';
        } else {
            echo '<strong>' . $debtorText . '</strong>';
        }
        if ($group['debtor_iban']) {
            echo ' &mdash; <code>' . htmlspecialchars($group['debtor_iban']) . '</code>';
        }
        if ($group['remittance_info']) {
            $remittanceHtml = preg_replace_callback('/\b(20[0-9]{2}-[0-9]{1,6})\b/', function ($m) use ($invNumIdMap) {
                $num = $m[1];
                return isset($invNumIdMap[$num])
                    ? '<a href="invoices.php?action=edit&id=' . $invNumIdMap[$num] . '" target="_blank">' . htmlspecialchars($num) . '</a>'
                    : htmlspecialchars($num);
            }, htmlspecialchars($group['remittance_info']));
            echo '<br><small>' . $remittanceHtml . '</small>';
        }
        echo '</div>';
        echo '<div style="text-align:right">';
        echo '<strong style="font-size:1.1em">&euro;&nbsp;' . number_format($totalAmt, 2, ',', '.') . '</strong>';
        if ($group['booking_date']) {
            $dateFormatted = date('j M Y', strtotime($group['booking_date']));
            echo '<br><span style="font-size:.95em;color:#555;font-weight:600">' . htmlspecialchars($dateFormatted) . '</span>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>'; // panel-heading

        // Invoice rows
        echo '<table class="table table-condensed" style="margin:0">';
        echo '<thead><tr><th>Invoice</th><th>Amount</th><th>Status</th><th>Remark</th></tr></thead><tbody>';

        $reviewRecord = null;
        foreach ($records as $p) {
            $manuallyAssigned = ($p['status'] === 'paid' && $p['note'] === 'manually_assigned');
            $statusCss  = ['paid' => 'success', 'skipped' => 'warning', 'error' => 'danger'][$p['status']] ?? 'default';
            $statusLabel = $manuallyAssigned ? 'Manually assigned' : $p['status'];
            $invLink   = $p['invoice_id']
                ? '<a href="invoices.php?action=edit&id=' . (int) $p['invoice_id'] . '" target="_blank">'
                    . htmlspecialchars($p['invoice_num'] ?: '#' . $p['invoice_id']) . '</a>'
                : ($p['invoice_num'] ? htmlspecialchars($p['invoice_num']) : '—');
            $amt = (float) $p['amount'];

            echo '<tr>';
            echo '<td>' . $invLink . '</td>';
            echo '<td>' . ($amt > 0 ? '&euro;&nbsp;' . number_format($amt, 2, ',', '.') : '<span class="text-muted">—</span>') . '</td>';
            echo '<td><span class="label label-' . $statusCss . '">' . htmlspecialchars($statusLabel) . '</span></td>';
            echo '<td>' . htmlspecialchars($manuallyAssigned ? '' : abn_camt_format_note($p['note'])) . '</td>';
            echo '</tr>';

            if ($p['status'] === 'error' && $reviewRecord === null) {
                $reviewRecord = $p;
            }
        }

        echo '</tbody></table>';

        // Review panel for unresolved transactions
        if ($reviewRecord) {
            $adminUser = trim($vars['admin_user'] ?? '');
            if ($adminUser) {
                $reviewRecord['tx_amount'] = $totalAmt;
                $suggestions = abn_camt_find_reconciliation_candidates($reviewRecord, $group['debtor_name'], $group['booking_date'] ?? '');
                abn_camt_render_suggestions($reviewRecord, $fileId, $suggestions, $baseUrl, $adminUser);
            }
        }

        echo '</div>'; // panel
    }
}

// =============================================================================
// Action result banner
// =============================================================================

function abn_camt_render_action_result($actionResult)
{
    if ($actionResult['type'] === 'error') {
        echo '<div class="alert alert-danger"><strong>Error:</strong> ' . $actionResult['message'] . '</div>';
        return;
    }

    if ($actionResult['type'] === 'upload_ok') {
        echo '<div class="alert alert-success">&#8679; ' . $actionResult['message'] . '</div>';
        return;
    }

    if ($actionResult['type'] === 'assign_ok') {
        echo '<div class="alert alert-success"><strong>&#10003; Assigned:</strong> ' . $actionResult['message'] . '</div>';
        return;
    }

    if ($actionResult['type'] === 'result') {
        $d = $actionResult['data'];
        if ($d['status'] === 'already_processed') {
            echo '<div class="alert alert-warning">File <code>' . htmlspecialchars($d['file']) . '</code> was already processed.</div>';
            return;
        }
        $s = $d['stats'];
        echo '<div class="alert alert-' . ($s['error'] > 0 ? 'warning' : 'success') . '">';
        echo '<strong>' . htmlspecialchars($d['file']) . '</strong> processed: ';
        echo '<span style="color:#1c7a3c;font-weight:700">' . $s['paid'] . ' paid</span>, ';
        echo $s['skipped'] . ' skipped, ';
        echo '<span style="' . ($s['error'] > 0 ? 'color:#b92b2b;font-weight:700' : '') . '">' . $s['error'] . ' error(s)</span>';
        echo '</div>';
        return;
    }

    if ($actionResult['type'] === 'batch') {
        $results     = $actionResult['data'];
        $totalPaid   = 0;
        $totalErrors = 0;
        $lines       = [];

        foreach ($results as $r) {
            if ($r['status'] === 'processed') {
                $totalPaid   += $r['stats']['paid'];
                $totalErrors += $r['stats']['error'];
                $lines[]      = htmlspecialchars($r['file']) . ': ' . $r['stats']['paid'] . ' paid, ' . $r['stats']['skipped'] . ' skipped, ' . $r['stats']['error'] . ' error(s)';
            } elseif ($r['status'] === 'error') {
                $totalErrors++;
                $lines[] = htmlspecialchars($r['file']) . ': <span style="color:#b92b2b">' . htmlspecialchars($r['message']) . '</span>';
            }
        }

        if (empty($results)) {
            echo '<div class="alert alert-info">No unprocessed files found.</div>';
            return;
        }

        $css = $totalErrors > 0 ? 'warning' : 'success';
        echo '<div class="alert alert-' . $css . '">';
        echo '<strong>Batch complete:</strong> ' . $totalPaid . ' invoice(s) marked paid';
        if ($totalErrors) echo ', ' . $totalErrors . ' error(s)';
        echo '<ul style="margin-top:6px;margin-bottom:0">';
        foreach ($lines as $l) echo '<li>' . $l . '</li>';
        echo '</ul></div>';
    }
}

// =============================================================================
// Tab: Preview (Phase 1 — unchanged)
// =============================================================================

function abn_camt_tab_preview($vars, $camtFolder)
{
    $folderOk = is_dir($camtFolder) && is_readable($camtFolder);
    $files    = [];

    if ($folderOk) {
        $xmlFiles = array_unique(array_merge(
            (array) glob($camtFolder . '*.xml'),
            (array) glob($camtFolder . '*.XML')
        ));
        foreach ($xmlFiles as $path) {
            $files[] = basename($path);
        }
        sort($files);
    }

    $selectedFile = null;
    $transactions = [];
    $parseError   = null;
    $fileStats    = null;

    if (!empty($_GET['file'])) {
        $requested = basename($_GET['file']);
        $fullPath  = $camtFolder . $requested;

        if (!preg_match('/\.xml$/i', $requested)) {
            $parseError = 'Only .xml files are supported.';
        } elseif (!is_file($fullPath) || !is_readable($fullPath)) {
            $parseError = 'File not found: <code>' . htmlspecialchars($requested) . '</code>';
        } else {
            $selectedFile = $requested;
            $fileStats    = ['size' => filesize($fullPath), 'mtime' => filemtime($fullPath)];
            try {
                $parser       = new AbnCamtParser();
                $transactions = $parser->parse($fullPath);
                $matcher      = new AbnInvoiceMatcher();
                foreach ($transactions as &$tx) {
                    $tx['matches'] = $matcher->matchInvoices(
                        $tx['detected_invoice_numbers'],
                        $tx['amount'],
                        $tx['reference_hints'] ?? []
                    );
                }
                unset($tx);
            } catch (Exception $e) {
                $parseError = 'Parse error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    $baseUrl = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=abn_camt_import');

    if (!$folderOk) {
        echo '<div class="alert alert-warning">Inbox folder not accessible.</div>';
    }
    if ($parseError) {
        echo '<div class="alert alert-danger">' . $parseError . '</div>';
    }
    ?>
    <p class="text-muted">Read-only preview. No payments are processed here.</p>

    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">Select a CAMT.053 file</h4></div>
        <div class="panel-body">
            <?php if (!$folderOk): ?>
                <p class="text-muted">Folder unavailable.</p>
            <?php elseif (empty($files)): ?>
                <p class="text-muted">No <code>.xml</code> files found.</p>
            <?php else: ?>
                <form method="get" action="">
                    <?php foreach ($_GET as $k => $v): ?>
                        <?php if ($k !== 'file'): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="form-inline">
                        <select name="file" class="form-control" style="min-width:380px;margin-right:8px">
                            <option value="">— select a file —</option>
                            <?php foreach ($files as $f): ?>
                            <option value="<?= htmlspecialchars($f) ?>"<?= ($selectedFile === $f) ? ' selected' : '' ?>>
                                <?= htmlspecialchars($f) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Load &amp; Preview</button>
                    </div>
                </form>
                <?php if ($fileStats): ?>
                <p class="text-muted" style="margin-top:7px;font-size:.84em">
                    <?= number_format($fileStats['size']) ?> bytes &bull;
                    Modified: <?= date('Y-m-d H:i:s', $fileStats['mtime']) ?>
                </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedFile !== null && !$parseError): ?>
    <h3>Credit transactions &mdash; <small><code><?= htmlspecialchars($selectedFile) ?></code></small>
        &nbsp;<span class="badge"><?= count($transactions) ?></span>
    </h3>
    <?php if (empty($transactions)): ?>
        <div class="alert alert-info">No credit (CRDT) transactions found.</div>
    <?php else: ?>
        <?php
        $statCounts = ['exact' => 0, 'multi' => 0, 'multi_overpay' => 0, 'overpaid' => 0, 'wrong_amount' => 0, 'paid' => 0, 'not_found' => 0, 'no_number' => 0];
        foreach ($transactions as $tx) {
            if (empty($tx['detected_invoice_numbers'])) { $statCounts['no_number']++; }
            elseif (empty($tx['matches']))              { $statCounts['not_found']++; }
            else { foreach ($tx['matches'] as $m) { if (isset($statCounts[$m['status']])) $statCounts[$m['status']]++; } }
        }
        ?>
        <div class="abn-summary-bar">
            <span class="abn-s-exact">&#10003; Exact: <?= $statCounts['exact'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-multi">&#8505; Multi: <?= $statCounts['multi'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-multi">&#10133; Overpay: <?= $statCounts['multi_overpay'] + $statCounts['overpaid'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-wrong">&#9888; Wrong amount: <?= $statCounts['wrong_amount'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-notfound">&#10007; Not found: <?= $statCounts['not_found'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-paid">&#9745; Already paid: <?= $statCounts['paid'] ?></span> &nbsp;|&nbsp;
            <span class="abn-s-none">No invoice #: <?= $statCounts['no_number'] ?></span>
        </div>
        <?php foreach ($transactions as $tx): ?>
        <div class="abn-tx">
            <div class="abn-tx-head">
                <div>
                    <strong><?= htmlspecialchars($tx['debtor_name'] ?: '(unknown debtor)') ?></strong>
                    <?php if ($tx['debtor_iban']): ?>
                    <span class="text-muted"> &mdash; <?= htmlspecialchars($tx['debtor_iban']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:18px;align-items:center">
                    <span class="abn-tx-amount"><?= htmlspecialchars($tx['currency']) ?>&nbsp;<?= number_format($tx['amount'], 2, '.', ',') ?></span>
                    <span class="abn-tx-date"><?= htmlspecialchars($tx['booking_date']) ?></span>
                </div>
            </div>
            <div class="abn-tx-body">
                <table class="table table-condensed">
                    <tr><th>Remittance info</th><td><?= htmlspecialchars($tx['remittance_info'] ?: '—') ?></td></tr>
                    <tr><th>Bank reference</th><td><?= htmlspecialchars($tx['bank_reference'] ?: '—') ?></td></tr>
                    <tr>
                        <th>Detected invoice #</th>
                        <td><?php if (empty($tx['detected_invoice_numbers'])): ?>
                            <span class="abn-s-none">none detected</span>
                        <?php else: ?>
                            <?= implode(', ', array_map(fn($n) => '<code>' . htmlspecialchars($n) . '</code>', $tx['detected_invoice_numbers'])) ?>
                        <?php endif; ?></td>
                    </tr>
                    <tr><th>Match status</th><td><?= abn_camt_render_matches($tx) ?></td></tr>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * Handle a CAMT.053 XML file upload.
 * Validates extension, MIME type, XML well-formedness, and saves to inbox folder.
 *
 * @return array  Action result array for abn_camt_render_action_result()
 */
function abn_camt_handle_upload($camtFolder)
{
    if (empty($_FILES['camt_upload']) || !isset($_FILES['camt_upload']['name'])) {
        return ['type' => 'error', 'message' => 'No file selected.'];
    }

    // Normalise single-file and multi-file into a uniform list
    $raw = $_FILES['camt_upload'];
    if (!is_array($raw['name'])) {
        // Single file (shouldn't happen with multiple attr, but be safe)
        $files = [['name' => $raw['name'], 'tmp_name' => $raw['tmp_name'], 'error' => $raw['error']]];
    } else {
        $files = [];
        foreach ($raw['name'] as $i => $name) {
            $files[] = ['name' => $name, 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i]];
        }
    }

    // Check at least one file was actually selected
    if (count($files) === 1 && $files[0]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['type' => 'error', 'message' => 'No file selected.'];
    }

    // Check inbox folder is writable before doing anything else
    if (!is_dir($camtFolder) || !is_writable($camtFolder)) {
        return ['type' => 'error', 'message' => 'Inbox folder is not writable: <code>' . htmlspecialchars($camtFolder) . '</code>'];
    }

    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE  => 'exceeds form size limit',
        UPLOAD_ERR_PARTIAL    => 'only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'no temporary folder available',
        UPLOAD_ERR_CANT_WRITE => 'failed to write to disk',
    ];

    $allSaved   = [];
    $allSkipped = [];
    $allErrors  = [];

    foreach ($files as $file) {
        $originalName = basename($file['name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $reason = $phpErrors[$file['error']] ?? 'upload error ' . $file['error'];
            $allErrors[] = htmlspecialchars($originalName) . ' (' . $reason . ')';
            continue;
        }

        if (preg_match('/\.zip$/i', $originalName)) {
            $result = abn_camt_handle_zip_upload($file['tmp_name'], $camtFolder);
            // Merge ZIP results into the combined totals
            if (isset($result['saved']))   $allSaved   = array_merge($allSaved,   $result['saved']);
            if (isset($result['skipped'])) $allSkipped = array_merge($allSkipped, $result['skipped']);
            if (isset($result['errors']))  $allErrors  = array_merge($allErrors,  $result['errors']);
        } elseif (preg_match('/\.xml$/i', $originalName)) {
            $result = abn_camt_save_xml_upload($file['tmp_name'], $originalName, $camtFolder);
            if ($result['type'] === 'upload_ok') {
                $allSaved[] = $result['filename'];
            } else {
                $allErrors[] = htmlspecialchars($originalName) . ': ' . strip_tags($result['message']);
            }
        } else {
            $allErrors[] = htmlspecialchars($originalName) . ' (only .xml and .zip accepted)';
        }
    }

    $parts = [];
    if (!empty($allSaved)) {
        $fileLabels = array_map(function($f) {
            $date = abn_camt_filename_date($f);
            return '<code>' . htmlspecialchars($f) . '</code>' . ($date ? ' <span class="text-muted">(' . $date . ')</span>' : '');
        }, $allSaved);
        $parts[] = count($allSaved) . ' file(s) added to inbox:<br>' . implode('<br>', $fileLabels);
    }
    if (!empty($allSkipped)) {
        $fileLabels = array_map(function($f) {
            $date = abn_camt_filename_date($f);
            return '<code>' . htmlspecialchars($f) . '</code>' . ($date ? ' <span class="text-muted">(' . $date . ')</span>' : '');
        }, $allSkipped);
        $parts[] = count($allSkipped) . ' already existed and were skipped:<br>' . implode('<br>', $fileLabels);
    }
    if (!empty($allErrors)) {
        $parts[] = '<span style="color:#b92b2b">' . count($allErrors) . ' error(s): ' . implode(', ', $allErrors) . '</span>';
    }

    if (empty($parts)) {
        return ['type' => 'error', 'message' => 'Nothing was uploaded.'];
    }

    $type = empty($allSaved) ? 'error' : 'upload_ok';
    return ['type' => $type, 'message' => implode('<br>', $parts)];
}

/**
 * Extract all CAMT.053 XML files from a ZIP and save them to the inbox.
 */
function abn_camt_handle_zip_upload($tmpFile, $camtFolder)
{
    $zip = new ZipArchive();
    $res = $zip->open($tmpFile);

    if ($res !== true) {
        return ['type' => 'error', 'message' => 'Could not open ZIP file (error code ' . $res . ').'];
    }

    $saved  = [];
    $skipped = [];
    $errors  = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);

        // Only process .xml files at the root or in subdirectories; skip directories
        if (substr($entryName, -1) === '/' || !preg_match('/\.xml$/i', $entryName)) {
            continue;
        }

        // Skip macOS metadata files (.__MACOSX, ._filename, .DS_Store, etc.)
        if (strpos(basename($entryName), '._') === 0 || strpos($entryName, '__MACOSX/') !== false) {
            continue;
        }

        $baseName = basename($entryName);
        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $baseName);
        if (empty($safeName) || $safeName === '.xml') {
            $safeName = 'camt_' . date('YmdHis') . '_' . $i . '.xml';
        }

        $destination = $camtFolder . $safeName;

        if (file_exists($destination)) {
            $skipped[] = $safeName;
            continue;
        }

        $xmlContent = $zip->getFromIndex($i);
        if ($xmlContent === false) {
            $errors[] = $safeName . ' (could not read from ZIP)';
            continue;
        }

        // Validate XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($xmlContent)) {
            libxml_clear_errors();
            $errors[] = $safeName . ' (invalid XML)';
            continue;
        }
        libxml_clear_errors();

        if (file_put_contents($destination, $xmlContent) === false) {
            $errors[] = $safeName . ' (write failed)';
            continue;
        }

        chmod($destination, 0640);
        $saved[] = $safeName;
    }

    $zip->close();

    if (empty($saved) && empty($errors) && empty($skipped)) {
        return ['type' => 'error', 'message' => 'No XML files found inside the ZIP.', 'saved' => [], 'skipped' => [], 'errors' => []];
    }

    return [
        'type'    => empty($saved) ? 'error' : 'upload_ok',
        'saved'   => $saved,
        'skipped' => $skipped,
        'errors'  => $errors,
        'message' => '',
    ];
}

/**
 * Validate and save a single uploaded XML file to the inbox.
 */
function abn_camt_save_xml_upload($tmpFile, $originalName, $camtFolder)
{
    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
    if (empty($safeName) || $safeName === '.xml') {
        $safeName = 'camt_' . date('YmdHis') . '.xml';
    }

    // Validate XML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->load($tmpFile, LIBXML_NONET)) {
        $err = libxml_get_errors();
        libxml_clear_errors();
        $msg = !empty($err) ? trim($err[0]->message) : 'Not valid XML';
        return ['type' => 'error', 'message' => 'Invalid XML file: ' . htmlspecialchars($msg)];
    }
    libxml_clear_errors();

    $destination = $camtFolder . $safeName;

    if (file_exists($destination)) {
        return ['type' => 'error', 'message' => 'A file named <code>' . htmlspecialchars($safeName) . '</code> already exists in the inbox.'];
    }

    if (!move_uploaded_file($tmpFile, $destination)) {
        return ['type' => 'error', 'message' => 'Could not save file to inbox folder.'];
    }

    chmod($destination, 0640);

    return [
        'type'     => 'upload_ok',
        'filename' => $safeName,
        'message'  => 'Uploaded: <code>' . htmlspecialchars($safeName) . '</code> — ready to process.',
    ];
}

/**
 * Returns paid amount total per month for the last 12 months from history rows.
 * Uses tx_paid count from file records — not the exact EUR amount (that comes
 * from payment rows which would require N queries; we use the count for the bar).
 */
function abn_camt_monthly_paid(array $history)
{
    $months = [];
    foreach ($history as $row) {
        if ((int) $row['tx_paid'] === 0) continue;
        $month = substr($row['processed_at'], 0, 7); // "2026-04"
        if (!isset($months[$month])) $months[$month] = 0;
        $months[$month] += (int) $row['tx_paid'];
    }
    ksort($months);
    // Keep last 12 months
    $months = array_slice($months, -12, 12, true);
    $result = [];
    foreach ($months as $ym => $paid) {
        $result[] = ['month' => date('M y', strtotime($ym . '-01')), 'paid' => $paid];
    }
    return $result;
}

/**
 * Total transaction amount for a single processed file (all statuses, cached via static).
 * Sums all payment record amounts so skipped transactions are included.
 * Historical records that predate the amount fix will have 0 for skipped rows — that's fine.
 */
function abn_camt_file_amount_paid($fileId, AbnPaymentProcessor $processor)
{
    static $cache = [];
    if (!isset($cache[$fileId])) {
        $payments = $processor->getFilePayments($fileId);
        $cache[$fileId] = array_sum(array_map(
            fn($p) => $p['status'] === 'paid' ? (float) $p['amount'] : 0.0,
            $payments
        ));
    }
    return $cache[$fileId];
}

/**
 * Find WHMCS client/invoice suggestions for an unrecognised bank transaction.
 * Searches clients by debtor name words, returns unpaid invoices matching the amount.
 */
function abn_camt_find_suggestions($amount, $debtorName, $bookingDate = '')
{
    if (!class_exists('\\WHMCS\\Database\\Capsule') || empty(trim($debtorName))) {
        return [];
    }

    $c = '\\WHMCS\\Database\\Capsule';

    // Normalise abbreviation dots: "B.V." → "BV", "Ltd." → "LTD", etc.
    $normalised = str_replace('.', '', strtoupper($debtorName));

    // Extract meaningful words (≥3 chars, skip common business suffixes)
    $skip  = ['BV', 'NV', 'VOF', 'LTD', 'BVBA', 'INC', 'GMBH', 'AG', 'SA', 'THE', 'AND', 'VAN', 'DE', 'DEN', 'HET'];
    $words = array_values(array_filter(
        preg_split('/[\s\.\,\-\/&]+/', $normalised),
        fn($w) => strlen($w) >= 3 && !in_array($w, $skip)
    ));

    if (empty($words)) {
        return [];
    }

    // Helper: build a client query requiring ALL words to match (AND logic).
    // Falls back to matching only the longest word if AND yields nothing.
    $buildClientQuery = function(array $searchWords) use ($c) {
        $q = $c::table('tblclients')->select(['id', 'companyname', 'firstname', 'lastname']);
        foreach ($searchWords as $word) {
            $q->where(function ($sub) use ($word) {
                $sub->where('companyname', 'LIKE', '%' . $word . '%')
                    ->orWhere('firstname',  'LIKE', '%' . $word . '%')
                    ->orWhere('lastname',   'LIKE', '%' . $word . '%');
            });
        }
        return $q->limit(15)->get()->all();
    };

    // Try all words first (AND), fall back to longest single word
    $clients = $buildClientQuery($words);
    if (empty($clients) && count($words) > 1) {
        usort($words, fn($a, $b) => strlen($b) - strlen($a));
        $clients = $buildClientQuery([$words[0]]);
    }

    if (empty($clients)) {
        return [];
    }

    $clientIds = array_map(fn($cl) => ((array) $cl)['id'], $clients);
    $clientMap = [];
    foreach ($clients as $cl) {
        $cl = (array) $cl;
        $clientMap[$cl['id']] = trim($cl['companyname'] ?: ($cl['firstname'] . ' ' . $cl['lastname']));
    }

    // All invoices for matched clients with amount within ±10%, ordered newest first.
    // Include all statuses — already-paid invoices can still be manually linked.
    $low  = round($amount * 0.90, 2);
    $high = round($amount * 1.10 + 0.01, 2);

    $q = $c::table('tblinvoices')
        ->whereIn('userid', $clientIds)
        ->whereBetween('total', [$low, $high])
        ->select(['id', 'invoicenum', 'total', 'status', 'date', 'userid'])
        ->orderByRaw('ABS(total - ?) ASC', [$amount])
        ->orderBy('date', 'desc')
        ->limit(15);

    if (!empty($bookingDate)) {
        $q->where('date', '<=', $bookingDate);
    }

    $invoices = $q->get()->all();

    $results = [];
    foreach ($invoices as $inv) {
        $inv = (array) $inv;
        $diff = abs((float) $inv['total'] - $amount);
        $results[] = [
            'invoice_id'   => (int) $inv['id'],
            'invoice_num'  => $inv['invoicenum'],
            'total'        => (float) $inv['total'],
            'status'       => $inv['status'],
            'date'         => $inv['date'],
            'client_id'    => (int) $inv['userid'],
            'client_name'  => $clientMap[$inv['userid']] ?? '(unknown)',
            'exact_amount' => $diff < 0.02,
            'near_amount'  => $diff >= 0.02,
        ];
    }

    return $results;
}

function abn_camt_find_reconciliation_candidates(array $paymentRecord, $debtorName, $bookingDate = '')
{
    $amount = (float) ($paymentRecord['tx_amount'] ?? $paymentRecord['amount'] ?? 0);
    $results = [];
    $seen = [];
    $referenceText = trim(($paymentRecord['remittance_info'] ?? '') . ' ' . ($paymentRecord['bank_reference'] ?? ''));
    $hints = AbnCamtParser::detectInvoiceReferenceHints($referenceText);

    if (class_exists('\\WHMCS\\Database\\Capsule')) {
        $c = '\\WHMCS\\Database\\Capsule';
        $fullNumbers = $hints['full_numbers'] ?? [];
        $clientId = 0;

        if (!empty($fullNumbers)) {
            $rows = $c::table('tblinvoices')
                ->select(['id', 'userid', 'invoicenum', 'total', 'status', 'date'])
                ->whereIn('invoicenum', $fullNumbers)
                ->get()
                ->all();

            $clientIds = array_values(array_unique(array_map(fn($r) => (int) $r->userid, $rows)));
            if (count($clientIds) === 1) {
                $clientId = (int) $clientIds[0];
            }
        }

        if ($clientId > 0) {
            $invoiceNums = $fullNumbers;
            foreach (($hints['shorthand_groups'] ?? []) as $group) {
                $invoiceNums[] = $group['base_year'] . '-' . ltrim((string) $group['base_number'], '0');
                foreach ($group['numbers'] ?? [] as $num) {
                    $invoiceNums[] = $group['base_year'] . '-' . ltrim((string) $num, '0');
                }
            }
            $invoiceNums = array_values(array_unique(array_filter($invoiceNums)));

            if (!empty($invoiceNums)) {
                $rows = $c::table('tblinvoices')
                    ->select(['id', 'userid', 'invoicenum', 'total', 'status', 'date'])
                    ->where('userid', $clientId)
                    ->whereIn('invoicenum', $invoiceNums)
                    ->orderBy('date')
                    ->orderBy('id')
                    ->get()
                    ->all();

                $clientName = '';
                $clientRow = $c::table('tblclients')->select(['companyname', 'firstname', 'lastname'])->where('id', $clientId)->first();
                if ($clientRow) {
                    $clientName = trim($clientRow->companyname ?: ($clientRow->firstname . ' ' . $clientRow->lastname));
                }

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $diff = abs((float) $row['total'] - $amount);
                    $results[] = [
                        'invoice_id'      => (int) $row['id'],
                        'invoice_num'     => $row['invoicenum'],
                        'total'           => (float) $row['total'],
                        'status'          => $row['status'],
                        'date'            => $row['date'],
                        'client_id'       => (int) $row['userid'],
                        'client_name'     => $clientName ?: '(unknown)',
                        'exact_amount'    => $diff < 0.02,
                        'near_amount'     => $diff >= 0.02,
                        'from_reference'  => true,
                    ];
                    $seen[(int) $row['id']] = true;
                }
            }
        }
    }

    foreach (abn_camt_find_suggestions($amount, $debtorName, $bookingDate) as $candidate) {
        if (!isset($seen[$candidate['invoice_id']])) {
            $candidate['from_reference'] = false;
            $results[] = $candidate;
            $seen[$candidate['invoice_id']] = true;
        }
    }

    usort($results, function ($a, $b) {
        return (($a['from_reference'] ?? false) === ($b['from_reference'] ?? false))
            ? strcmp((string) $a['date'], (string) $b['date'])
            : (($a['from_reference'] ?? false) ? -1 : 1);
    });

    return $results;
}

/**
 * Render the suggestion panel below a no_invoice_ref error record.
 */
function abn_camt_render_suggestions(array $paymentRecord, $fileId, array $suggestions, $baseUrl, $adminUser)
{
    $paymentId  = (int) $paymentRecord['id'];
    $amount     = (float) ($paymentRecord['tx_amount'] ?? $paymentRecord['amount']);
    $detailUrl  = $baseUrl . '&tab=process&detail=' . $fileId;
    $formId     = 'abn-batch-' . $paymentId;
    ?>
    <div style="background:#fffbf0;border:1px solid #f0c040;border-top:none;padding:12px 16px">
        <strong style="font-size:.9em">&#128269; Reconcile this payment</strong>
        <div class="text-muted" style="margin-top:4px;font-size:.87em">
            Transaction amount: <strong>&euro;&nbsp;<?= number_format($amount, 2, ',', '.') ?></strong>
        </div>

        <?php if (!empty($suggestions)): ?>
        <form method="post" action="<?= $detailUrl ?>" id="<?= $formId ?>" style="margin:0">
            <input type="hidden" name="abn_action" value="assign_payment_batch">
            <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
            <input type="hidden" name="tx_amount" value="<?= htmlspecialchars(number_format($amount, 2, '.', '')) ?>">
        <table class="table table-condensed" style="margin:8px 0 6px;background:#fff;font-size:.88em">
            <thead>
                <tr>
                    <th></th>
                    <th>Client</th>
                    <th>Invoice</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Fit</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($suggestions as $s):
                $alreadyPaid = in_array($s['status'], ['Paid', 'Refunded'], true);
                $rowBg = !empty($s['from_reference']) ? 'background:#eef9ff' : ($s['exact_amount'] ? 'background:#f0fff4' : ($s['near_amount'] ? 'background:#fffdf0' : ''));
            ?>
            <tr style="<?= $rowBg ?>">
                <td>
                    <?php if (!$alreadyPaid): ?>
                    <input type="checkbox" name="invoice_ids[]" value="<?= $s['invoice_id'] ?>" data-amount="<?= htmlspecialchars(number_format($s['total'], 2, '.', '')) ?>">
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($s['client_name']) ?></td>
                <td>
                    <a href="invoices.php?action=edit&id=<?= $s['invoice_id'] ?>" target="_blank">
                        <?= htmlspecialchars($s['invoice_num'] ?: '#' . $s['invoice_id']) ?>
                    </a>
                    <?php if (!empty($s['from_reference'])): ?>
                        <span class="label label-info" style="font-size:.75em">ref</span>
                    <?php endif; ?>
                    <?php if ($s['exact_amount']): ?>
                        <span class="label label-success" style="font-size:.75em">&#10003; exact</span>
                    <?php elseif ($s['near_amount']): ?>
                        <span class="label label-warning" style="font-size:.75em">~<?= round(abs($s['total'] - $amount) / $amount * 100) ?>% off</span>
                    <?php endif; ?>
                </td>
                <td class="text-right">&euro;&nbsp;<?= number_format($s['total'], 2, ',', '.') ?></td>
                <td><span class="label label-<?= $alreadyPaid ? 'success' : ($s['status'] === 'Cancelled' ? 'warning' : 'primary') ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                <td><?= htmlspecialchars(substr($s['date'], 0, 10)) ?></td>
                <td><?= !empty($s['from_reference']) ? 'Mentioned in payment' : '&mdash;' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="abn-batch-summary" data-total="<?= htmlspecialchars(number_format($amount, 2, '.', '')) ?>" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin:8px 0 6px">
            <span>Selected: <strong class="abn-js-selected">&euro;&nbsp;0,00</strong></span>
            <span>Remaining: <strong class="abn-js-remaining">&euro;&nbsp;<?= number_format($amount, 2, ',', '.') ?></strong></span>
            <span class="text-muted">If remaining is positive, it will be added to the last selected invoice and WHMCS should book it as client credit.</span>
        </div>
        <button type="submit" class="btn btn-sm btn-success"
            onclick="return confirm('Confirm the selected invoices for this payment?\n\nIf there is money left over, it will be added to the last selected invoice so WHMCS can place the excess on the client credit balance.')">
            Confirm selected invoices
        </button>
        </form>
        <?php else: ?>
        <p class="text-muted" style="margin:6px 0 8px;font-size:.87em">No matching clients or invoices found automatically.</p>
        <?php endif; ?>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-top:8px">
            <form method="post" action="<?= $detailUrl ?>" class="form-inline" style="margin:0">
                <input type="hidden" name="abn_action" value="assign_payment">
                <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
                <div class="form-group form-group-sm">
                    <label style="font-size:.85em;font-weight:600;margin-right:6px">Enter invoice number manually:</label>
                    <input type="text" name="invoice_num" class="form-control input-sm"
                           placeholder="e.g. 2025-123" style="width:140px;display:inline-block">
                </div>
                &nbsp;
                <button type="submit" class="btn btn-sm btn-warning"
                    onclick="return confirm('Assign &amp; pay the invoice you entered?\n\nThis marks the invoice as Paid and sends a confirmation email.')">
                    Assign &amp; Pay
                </button>
            </form>

            <?php $debtorLabel = htmlspecialchars($paymentRecord['debtor_name'] ?? ''); if ($debtorLabel): ?>
            <form method="post" action="<?= $detailUrl ?>" style="margin:0">
                <input type="hidden" name="abn_action"   value="ignore_debtor">
                <input type="hidden" name="payment_id"   value="<?= $paymentId ?>">
                <input type="hidden" name="debtor_name"  value="<?= $debtorLabel ?>">
                <button type="submit" class="btn btn-sm btn-default"
                    style="border-color:#aaa"
                    onclick="return confirm('Add &quot;<?= htmlspecialchars(addslashes($paymentRecord['debtor_name'])) ?>&quot; to the ignore list?\n\nThis payment will be marked as skipped and all future transactions from this debtor will be silently ignored.')">
                    &#128683; Ignore debtor &amp; skip
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Extract a sortable date string (YYYYMMDD + HHMMSS) from an ABN AMRO filename.
 * Format: {clientnr}_{accountnr}_{DDMMYY}{HHMMSS}.xml
 * Returns a comparable string like "20250527000000", or "" if not parseable.
 */
function abn_camt_filename_sort_key($filename)
{
    // Match e.g. 59060336_531636917_270525000000.xml
    if (preg_match('/^\d+_\d+_(\d{2})(\d{2})(\d{2})(\d{6})/', basename($filename), $m)) {
        // $m[1]=DD, $m[2]=MM, $m[3]=YY, $m[4]=HHMMSS
        $year = (int) $m[3] + 2000;
        return sprintf('%04d%02d%02d%s', $year, (int) $m[2], (int) $m[1], $m[4]);
    }
    return '';
}

/**
 * Parse the skip_debtors setting (newline-separated) into a trimmed array.
 */
function abn_camt_parse_skip_debtors($raw)
{
    if (empty($raw)) {
        return [];
    }
    $lines = preg_split('/[\r\n]+/', $raw);
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $result[] = $line;
        }
    }
    return $result;
}

/**
 * Mark an unrecognised payment as paid against a specific invoice.
 * Called from the assign_payment POST action.
 */
function abn_camt_assign_payment($paymentRecordId, $invoiceId, $invoiceNum, $adminUser, $gateway)
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return ['type' => 'error', 'message' => 'Database not available.'];
    }

    $c = '\\WHMCS\\Database\\Capsule';

    // Load the payment record
    $pRow = $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->first();
    if (!$pRow) {
        return ['type' => 'error', 'message' => 'Payment record not found.'];
    }
    $p = (array) $pRow;

    // Resolve invoice — by ID (from suggestion button) or by invoice number (manual input)
    if ($invoiceId <= 0 && !empty($invoiceNum)) {
        $invRow = $c::table('tblinvoices')
            ->select(['id', 'invoicenum', 'total', 'status', 'userid'])
            ->where('invoicenum', $invoiceNum)
            ->first();
        if (!$invRow) {
            // Fallback: numeric part only (e.g. "407" → id=407)
            if (preg_match('/^(?:20[0-9]{2}-)?(\d+)$/', $invoiceNum, $m)) {
                $invRow = $c::table('tblinvoices')
                    ->select(['id', 'invoicenum', 'total', 'status', 'userid'])
                    ->where('id', (int) $m[1])
                    ->first();
            }
        }
        if (!$invRow) {
            return ['type' => 'error', 'message' => 'Invoice not found: <code>' . htmlspecialchars($invoiceNum) . '</code>'];
        }
        $inv = (array) $invRow;
    } else {
        $invRow = $c::table('tblinvoices')
            ->select(['id', 'invoicenum', 'total', 'status', 'userid'])
            ->where('id', $invoiceId)
            ->first();
        if (!$invRow) {
            return ['type' => 'error', 'message' => 'Invoice not found.'];
        }
        $inv = (array) $invRow;
    }

    $invNum     = htmlspecialchars($inv['invoicenum'] ?: '#' . $inv['id']);
    $alreadyPaid = in_array($inv['status'], ['Paid', 'Refunded'], true);

    if ($alreadyPaid) {
        // Invoice is already paid — just link the bank payment record to it without
        // calling AddInvoicePayment again (that would create a duplicate payment).
        $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->update([
            'invoice_id'  => (int) $inv['id'],
            'invoice_num' => (string) $inv['invoicenum'],
            'status'      => 'paid',
            'note'        => 'manually_assigned',
        ]);

        // Fix file stats
        $fileId = (int) $p['file_id'];
        $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
            'tx_paid'  => $c::raw('tx_paid + 1'),
            'tx_error' => $c::raw('GREATEST(tx_error - 1, 0)'),
        ]);
        $remaining = $c::table('mod_abn_camt_payments')
            ->where('file_id', $fileId)->where('status', 'error')->count();
        $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
            'status' => $remaining === 0 ? 'processed' : 'partial',
        ]);

        return [
            'type'    => 'assign_ok',
            'message' => 'Bank payment linked to invoice <strong>' . $invNum . '</strong> (already paid — no duplicate payment recorded, no email sent).',
        ];
    }

    // Reactivate if Cancelled
    if ($inv['status'] === 'Cancelled') {
        $r = localAPI('UpdateInvoice', ['invoiceid' => (int) $inv['id'], 'status' => 'Unpaid'], $adminUser);
        if (!isset($r['result']) || $r['result'] !== 'success') {
            return ['type' => 'error', 'message' => 'Could not reactivate cancelled invoice: ' . ($r['message'] ?? 'unknown')];
        }
    }

    // Build unique transaction ID
    $cleanRef = preg_replace('/[^A-Za-z0-9\-]/', '', $p['bank_reference'] ?? '');
    $transId  = substr('ABN-' . $cleanRef . '-' . $inv['id'], 0, 255);

    $result = localAPI('AddInvoicePayment', [
        'invoiceid' => (int) $inv['id'],
        'transid'   => $transId,
        'gateway'   => $gateway,
        'date'      => $p['booking_date'] ?: date('Y-m-d'),
        'amount'    => (float) $p['amount'],
        'noemail'   => 0,
    ], $adminUser);

    if (!isset($result['result']) || $result['result'] !== 'success') {
        return ['type' => 'error', 'message' => 'Payment API error: ' . ($result['message'] ?? 'unknown')];
    }

    // Update payment record to paid
    $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->update([
        'invoice_id'  => (int) $inv['id'],
        'invoice_num' => (string) $inv['invoicenum'],
        'status'      => 'paid',
        'note'        => '',
    ]);

    // Fix file stats: -1 error, +1 paid; set status to processed if errors now 0
    $fileId = (int) $p['file_id'];
    $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
        'tx_paid'  => $c::raw('tx_paid + 1'),
        'tx_error' => $c::raw('GREATEST(tx_error - 1, 0)'),
    ]);
    // Update status based on remaining errors
    $remaining = $c::table('mod_abn_camt_payments')
        ->where('file_id', $fileId)->where('status', 'error')->count();
    $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
        'status' => $remaining === 0 ? 'processed' : 'partial',
    ]);

    return [
        'type'    => 'assign_ok',
        'message' => 'Invoice <strong>' . $invNum . '</strong> marked as paid — confirmation email sent.',
    ];
}

function abn_camt_assign_payment_batch($paymentRecordId, array $invoiceIds, $txAmount, $adminUser, $gateway)
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return ['type' => 'error', 'message' => 'Database not available.'];
    }

    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (empty($invoiceIds)) {
        return ['type' => 'error', 'message' => 'Select at least one invoice.'];
    }

    $c = '\\WHMCS\\Database\\Capsule';
    $pRow = $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->first();
    if (!$pRow) {
        return ['type' => 'error', 'message' => 'Payment record not found.'];
    }
    $p = (array) $pRow;

    $invoices = $c::table('tblinvoices')
        ->select(['id', 'userid', 'invoicenum', 'total', 'status'])
        ->whereIn('id', $invoiceIds)
        ->orderBy('date')
        ->orderBy('id')
        ->get()
        ->all();

    if (count($invoices) !== count($invoiceIds)) {
        return ['type' => 'error', 'message' => 'One or more selected invoices no longer exist.'];
    }

    $clientIds = array_values(array_unique(array_map(fn($r) => (int) $r->userid, $invoices)));
    if (count($clientIds) !== 1) {
        return ['type' => 'error', 'message' => 'Selected invoices must belong to the same client.'];
    }

    $sum = 0.0;
    foreach ($invoices as $invRow) {
        if (in_array($invRow->status, ['Paid', 'Refunded', 'Collections', 'Draft'], true)) {
            return ['type' => 'error', 'message' => 'Selected invoices must be payable. Remove already paid, refunded, collection or draft invoices first.'];
        }
        $sum += (float) $invRow->total;
    }
    $sum = round($sum, 2);
    $txAmount = round((float) $txAmount, 2);
    $remaining = round($txAmount - $sum, 2);

    if ($remaining < -0.014) {
        return ['type' => 'error', 'message' => 'Selected invoices total <strong>&euro;' . number_format($sum, 2, ',', '.') . '</strong>, which is more than the payment amount <strong>&euro;' . number_format($txAmount, 2, ',', '.') . '</strong>.'];
    }

    $cleanRef = preg_replace('/[^A-Za-z0-9\-]/', '', $p['bank_reference'] ?? '');
    $bookingDate = !empty($p['booking_date']) ? $p['booking_date'] : date('Y-m-d');
    $created = 0;

    foreach (array_values($invoices) as $idx => $invRow) {
        $inv = (array) $invRow;
        if ($inv['status'] === 'Cancelled') {
            $r = localAPI('UpdateInvoice', ['invoiceid' => (int) $inv['id'], 'status' => 'Unpaid'], $adminUser);
            if (!isset($r['result']) || $r['result'] !== 'success') {
                return ['type' => 'error', 'message' => 'Could not reactivate cancelled invoice ' . htmlspecialchars($inv['invoicenum']) . '.'];
            }
        }

        $payAmount = round((float) $inv['total'] + (($idx === count($invoices) - 1 && $remaining > 0.014) ? $remaining : 0.0), 2);
        $transId = substr('ABN-' . $cleanRef . '-' . $inv['id'], 0, 255);
        $result = localAPI('AddInvoicePayment', [
            'invoiceid' => (int) $inv['id'],
            'transid'   => $transId,
            'gateway'   => $gateway,
            'date'      => $bookingDate,
            'amount'    => $payAmount,
            'noemail'   => 0,
        ], $adminUser);

        if (!isset($result['result']) || $result['result'] !== 'success') {
            return ['type' => 'error', 'message' => 'Payment API error for invoice <code>' . htmlspecialchars($inv['invoicenum']) . '</code>: ' . htmlspecialchars($result['message'] ?? 'unknown')];
        }

        $rowData = [
            'file_id'         => (int) $p['file_id'],
            'invoice_id'      => (int) $inv['id'],
            'invoice_num'     => (string) $inv['invoicenum'],
            'amount'          => $payAmount,
            'tx_amount'       => $txAmount,
            'currency'        => $p['currency'] ?? 'EUR',
            'booking_date'    => $bookingDate,
            'debtor_name'     => (string) ($p['debtor_name'] ?? ''),
            'debtor_iban'     => (string) ($p['debtor_iban'] ?? ''),
            'bank_reference'  => (string) ($p['bank_reference'] ?? ''),
            'remittance_info' => (string) ($p['remittance_info'] ?? ''),
            'trans_id'        => $transId,
            'status'          => 'paid',
            'note'            => ($idx === count($invoices) - 1 && $remaining > 0.014) ? 'manual_overpay:' . number_format($remaining, 2, '.', '') : 'manually_assigned',
            'processed_at'    => date('Y-m-d H:i:s'),
        ];

        if ($idx === 0) {
            $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->update($rowData);
        } else {
            $c::table('mod_abn_camt_payments')->insert($rowData);
        }
        $created++;
    }

    $fileId = (int) $p['file_id'];
    $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
        'tx_paid'  => $c::raw('tx_paid + ' . (int) $created),
        'tx_error' => $c::raw('GREATEST(tx_error - 1, 0)'),
    ]);

    $remainingErrors = $c::table('mod_abn_camt_payments')
        ->where('file_id', $fileId)
        ->where('status', 'error')
        ->count();
    $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
        'status' => $remainingErrors === 0 ? 'processed' : 'partial',
    ]);

    $message = $remaining > 0.014
        ? 'Invoices confirmed. The remaining <strong>&euro;' . number_format($remaining, 2, ',', '.') . '</strong> was added to the last invoice so WHMCS can place it on client credit.'
        : 'Invoices confirmed and paid.';

    return ['type' => 'assign_ok', 'message' => $message];
}

/**
 * Add a debtor to the skip_debtors module setting and mark the payment record as skipped.
 */
function abn_camt_ignore_debtor($paymentRecordId, $debtorName)
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return ['type' => 'error', 'message' => 'Database not available.'];
    }
    if (empty($debtorName)) {
        return ['type' => 'error', 'message' => 'No debtor name provided.'];
    }

    $c = '\\WHMCS\\Database\\Capsule';

    // Load current skip_debtors setting
    $row = $c::table('tbladdonmodules')
        ->where('module', 'abn_camt_import')
        ->where('setting', 'skip_debtors')
        ->first();

    $existing = $row ? trim((string) $row->value) : '';
    $lines    = array_filter(array_map('trim', preg_split('/[\r\n]+/', $existing)));

    // Add if not already present (case-insensitive)
    $alreadyIn = false;
    foreach ($lines as $line) {
        if (strcasecmp($line, $debtorName) === 0) { $alreadyIn = true; break; }
    }
    if (!$alreadyIn) {
        $lines[] = $debtorName;
        $newValue = implode("\n", $lines);
        if ($row) {
            $c::table('tbladdonmodules')
                ->where('module', 'abn_camt_import')
                ->where('setting', 'skip_debtors')
                ->update(['value' => $newValue]);
        } else {
            $c::table('tbladdonmodules')->insert([
                'module'  => 'abn_camt_import',
                'setting' => 'skip_debtors',
                'value'   => $newValue,
            ]);
        }
    }

    // Mark the payment record as skipped and fix file stats
    $pRow = $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->first();
    if ($pRow) {
        $c::table('mod_abn_camt_payments')->where('id', $paymentRecordId)->update([
            'status' => 'skipped',
            'note'   => 'skipped_debtor:' . $debtorName,
        ]);

        $fileId = (int) $pRow->file_id;
        $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
            'tx_skipped' => $c::raw('tx_skipped + 1'),
            'tx_error'   => $c::raw('GREATEST(tx_error - 1, 0)'),
        ]);
        $remaining = $c::table('mod_abn_camt_payments')
            ->where('file_id', $fileId)->where('status', 'error')->count();
        $c::table('mod_abn_camt_files')->where('id', $fileId)->update([
            'status' => $remaining === 0 ? 'processed' : 'partial',
        ]);
    }

    $msg = $alreadyIn
        ? '<strong>' . htmlspecialchars($debtorName) . '</strong> was already on the ignore list — payment marked as skipped.'
        : '<strong>' . htmlspecialchars($debtorName) . '</strong> added to the ignore list — payment marked as skipped. Future transactions from this debtor will be silently ignored.';

    return ['type' => 'assign_ok', 'message' => $msg];
}

/**
 * Human-readable label for a payment record's note field.
 */
function abn_camt_format_note($note)
{
    static $map = [
        'paid'                => 'Already paid',
        'cancelled'           => 'Was cancelled — reactivated',
        'wrong_amount'        => 'Amount mismatch',
        'not_found'           => 'Invoice not found',
        'invoice_paid'        => 'Already paid',
        'invoice_refunded'    => 'Invoice refunded',
        'invoice_collections' => 'In collections',
        'invoice_draft'       => 'Draft invoice',
        'multi'               => 'Part of multi-invoice payment',
        'multi_overpay'       => 'Overpayment will be booked as client credit',
        'manual_overpay'      => 'Manual overpayment booked as client credit',
        'overpaid'            => 'Overpayment — invoice paid, remainder added as client credit',
        'no_invoice_ref'      => 'No invoice reference — manual reconciliation needed',
    ];
    if (empty($note)) {
        return '—';
    }
    $parts = explode(':', $note, 2);
    $label = $map[$parts[0]] ?? $parts[0];
    return isset($parts[1]) ? $label . ' — ' . $parts[1] : $label;
}

/**
 * Parse the statement date from an ABN AMRO CAMT filename.
 *
 * Format: {clientnr}_{accountnr}_{DDMMYY}{HHMMSS}.xml
 * Example: 59060336_531636917_301225000000.xml → 30 Dec 2025
 *
 * @return string  e.g. "30 Dec 2025", or '' if not parseable.
 */
function abn_camt_filename_date($filename)
{
    // Match the last numeric segment before .xml (12+ digits)
    if (!preg_match('/_(\d{12,})\.xml$/i', $filename, $m)) {
        return '';
    }
    $seg = $m[1];
    $dd  = substr($seg, 0, 2);
    $mm  = substr($seg, 2, 2);
    $yy  = substr($seg, 4, 2);

    // Validate
    if (!checkdate((int)$mm, (int)$dd, (int)$yy)) {
        return '';
    }

    $ts = mktime(0, 0, 0, (int)$mm, (int)$dd, 2000 + (int)$yy);
    return $ts ? date('j M Y', $ts) : '';
}

function abn_camt_list_xml($camtFolder)
{
    $files = [];
    foreach (array_unique(array_merge(
        (array) glob($camtFolder . '*.xml'),
        (array) glob($camtFolder . '*.XML')
    )) as $path) {
        $files[] = basename($path);
    }
    sort($files);
    return $files;
}

function abn_camt_status_badge($status)
{
    $map = [
        'processed'  => 'success',
        'partial'    => 'warning',
        'error'      => 'danger',
        'processing' => 'info',
    ];
    $css = $map[$status] ?? 'default';
    return '<span class="label label-' . $css . '">' . htmlspecialchars($status) . '</span>';
}

function abn_camt_render_matches(array $tx)
{
    if (empty($tx['detected_invoice_numbers'])) {
        return '<span class="abn-s-none">&#8212; No invoice number found</span>';
    }
    if (empty($tx['matches'])) {
        return '<span class="abn-s-notfound">&#10007; Not found: ' . implode(', ', array_map('htmlspecialchars', $tx['detected_invoice_numbers'])) . '</span>';
    }

    $lines = [];
    foreach ($tx['matches'] as $m) {
        switch ($m['status']) {
            case 'exact':     $css = 'abn-s-exact';    $icon = '&#10003;'; $label = 'Exact amount match'; break;
            case 'multi':     $css = 'abn-s-multi';    $icon = '&#8505;';  $label = 'Part of multi-invoice total'; break;
            case 'multi_overpay': $css = 'abn-s-multi'; $icon = '&#10133;'; $label = 'Multi-invoice overpay (remainder to client credit)'; break;
            case 'overpaid':  $css = 'abn-s-multi';    $icon = '&#10133;'; $label = 'Overpaid (invoice paid + remainder to client credit)'; break;
            case 'wrong_amount': $css = 'abn-s-wrong'; $icon = '&#9888;';  $label = 'Wrong amount'; break;
            case 'paid':      $css = 'abn-s-paid';     $icon = '&#9745;';  $label = 'Already paid'; break;
            case 'cancelled': $css = 'abn-s-wrong';    $icon = '&#8856;';  $label = 'Invoice is Cancelled (will be reactivated and paid)'; break;
            case 'not_found': $css = 'abn-s-notfound'; $icon = '&#10007;'; $label = 'Invoice not found'; break;
            default:          $css = '';               $icon = '?';        $label = htmlspecialchars($m['status']);
        }
        $line = '<span class="' . $css . '">' . $icon . ' ' . $label . '</span>';
        if (!empty($m['invoice'])) {
            $inv  = $m['invoice'];
            $num  = htmlspecialchars($inv['invoicenum'] ?: '#' . $inv['id']);
            $link = '<a href="invoices.php?action=edit&id=' . (int)$inv['id'] . '" target="_blank">Invoice ' . $num . '</a>';
            $line .= ' &mdash; ' . $link . ' &mdash; EUR&nbsp;' . number_format((float)$inv['total'], 2) . ' &mdash; ' . htmlspecialchars($inv['status']);
            if (isset($m['pay_amount']) && abs((float) $m['pay_amount'] - (float) $inv['total']) > 0.014) {
                $line .= ' &mdash; pays EUR&nbsp;' . number_format((float) $m['pay_amount'], 2);
            }
        } elseif (isset($m['number'])) {
            $line .= ' &mdash; <code>' . htmlspecialchars($m['number']) . '</code>';
        }
        $lines[] = $line;
    }
    return implode('<br>', $lines);
}

function abn_camt_styles()
{
    echo <<<'CSS'
<style>
/* ── Stat boxes ── */
.abn-stat-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.abn-stat-box{flex:1;min-width:130px;border-radius:6px;padding:14px 18px;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.abn-stat-num{font-size:2em;font-weight:700;line-height:1.1}
.abn-stat-label{font-size:.78em;opacity:.88;margin-top:3px;text-transform:uppercase;letter-spacing:.04em}
.abn-stat-blue      {background:linear-gradient(135deg,#2980b9,#1a6fa8)}
.abn-stat-green     {background:linear-gradient(135deg,#27ae60,#1e8a4a)}
.abn-stat-amount    {background:linear-gradient(135deg,#16a085,#0e7a65)}
.abn-stat-green-light{background:#e8f5e9;color:#1c7a3c!important;border:1px solid #b2dfca}
.abn-stat-green-light .abn-stat-num{color:#1c7a3c}
.abn-stat-green-light .abn-stat-label{color:#2e7d52;opacity:1}
.abn-stat-grey      {background:linear-gradient(135deg,#7f8c8d,#636e72)}
.abn-stat-red       {background:linear-gradient(135deg,#c0392b,#96281b)}
.abn-stat-orange    {background:linear-gradient(135deg,#e67e22,#ca6f1e)}
/* ── Bar chart ── */
.abn-bar-chart{display:flex;align-items:flex-end;gap:6px;height:80px;padding-bottom:0}
.abn-bar-col{display:flex;flex-direction:column;align-items:center;flex:1;min-width:28px}
.abn-bar-val{font-size:.75em;color:#555;margin-bottom:2px;font-weight:600}
.abn-bar{width:100%;background:linear-gradient(180deg,#2ecc71,#27ae60);border-radius:3px 3px 0 0;transition:opacity .2s}
.abn-bar:hover{opacity:.75}
.abn-bar-lbl{font-size:.7em;color:#888;margin-top:4px;white-space:nowrap}
/* ── Transaction preview cards ── */
.abn-summary-bar{background:#f0f4f8;border:1px solid #dce1e7;border-radius:4px;padding:8px 14px;margin-bottom:14px;font-size:.9em}
.abn-tx{border:1px solid #dce1e7;border-radius:5px;margin-bottom:14px;overflow:hidden}
.abn-tx-head{background:#f7f9fb;padding:9px 15px;border-bottom:1px solid #dce1e7;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px}
.abn-tx-amount{font-size:1.3em;font-weight:700;color:#1c7a3c}
.abn-tx-date{color:#666;font-size:.88em}
.abn-tx-body table{margin:0!important}
.abn-tx-body td,.abn-tx-body th{border-top:1px solid #eef0f3!important;padding:6px 13px!important;vertical-align:top!important}
.abn-tx-body tr:first-child td,.abn-tx-body tr:first-child th{border-top:none!important}
.abn-tx-body th{width:175px;background:#fbfcfd;color:#444;font-weight:600}
/* ── Match status colours ── */
.abn-s-exact{color:#1c7a3c;font-weight:700}
.abn-s-multi{color:#1660a8;font-weight:700}
.abn-s-wrong{color:#b85400;font-weight:700}
.abn-s-paid{color:#777;font-weight:700}
.abn-s-none{color:#bbb}
.abn-s-notfound{color:#b92b2b;font-weight:700}
</style>
CSS;
    echo <<<'JS'
<script>
document.addEventListener('change', function (event) {
  if (!event.target.matches('input[name="invoice_ids[]"]')) return;
  var form = event.target.form;
  if (!form) return;
  var summary = form.querySelector('.abn-batch-summary');
  if (!summary) return;
  var total = parseFloat(summary.getAttribute('data-total') || '0');
  var selected = 0;
  form.querySelectorAll('input[name="invoice_ids[]"]:checked').forEach(function (cb) {
    selected += parseFloat(cb.getAttribute('data-amount') || '0');
  });
  var remaining = total - selected;
  var format = function (value) {
    return '&euro;&nbsp;' + value.toFixed(2).replace('.', ',');
  };
  summary.querySelector('.abn-js-selected').innerHTML = format(selected);
  summary.querySelector('.abn-js-remaining').innerHTML = format(remaining);
  summary.querySelector('.abn-js-remaining').style.color = remaining < -0.014 ? '#b92b2b' : '#1c7a3c';
});
</script>
JS;
}
