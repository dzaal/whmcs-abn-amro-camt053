<?php
/**
 * ABN AMRO CAMT.053 Import — WHMCS Hooks
 *
 * Registers a CronJob hook that automatically processes any new (unprocessed)
 * CAMT.053 XML files found in the configured inbox folder.
 * Runs every time the WHMCS cron executes (typically every 5 minutes).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/CamtParser.php';
require_once __DIR__ . '/lib/InvoiceMatcher.php';
require_once __DIR__ . '/lib/PaymentProcessor.php';

add_hook('CronJob', 1, function ($vars) {

    // ── Load module settings from DB ─────────────────────────────────────────
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return;
    }

    $c = '\\WHMCS\\Database\\Capsule';

    try {
        $settings = $c::table('tbladdonmodules')
            ->where('module', 'abn_camt_import')
            ->pluck('value', 'setting')
            ->toArray();
    } catch (Exception $e) {
        return;
    }

    $camtFolder  = isset($settings['camt_folder'])
        ? rtrim(trim($settings['camt_folder']), '/') . '/'
        : '';
    $gateway     = $settings['gateway']      ?? 'banktransfer';
    $adminUser   = $settings['admin_user']   ?? '';
    $skipDebtors = [];
    if (!empty($settings['skip_debtors'])) {
        foreach (preg_split('/[\r\n]+/', $settings['skip_debtors']) as $line) {
            $line = trim($line);
            if ($line !== '') $skipDebtors[] = $line;
        }
    }

    // ── Sanity checks ─────────────────────────────────────────────────────────
    if (empty($camtFolder) || $camtFolder[0] !== '/' || !is_dir($camtFolder)) {
        return;
    }

    if (empty($adminUser)) {
        logActivity('ABN CAMT Import [cron]: no admin_user configured — skipping auto-process.');
        return;
    }

    // ── Find unprocessed XML files ────────────────────────────────────────────
    $xmlFiles = array_unique(array_merge(
        (array) glob($camtFolder . '*.xml'),
        (array) glob($camtFolder . '*.XML')
    ));

    if (empty($xmlFiles)) {
        return;
    }

    $processor = new AbnPaymentProcessor($gateway, $adminUser, $skipDebtors);

    $processed = 0;
    $errors    = 0;

    foreach ($xmlFiles as $filePath) {
        $filename = basename($filePath);

        if ($processor->isProcessed($filename, $camtFolder)) {
            continue;
        }

        try {
            $result = $processor->processFile($filePath, $camtFolder);

            if ($result['status'] === 'processed') {
                $s = $result['stats'];
                logActivity(sprintf(
                    'ABN CAMT Import [cron]: processed %s — paid:%d skipped:%d error:%d',
                    $filename, $s['paid'], $s['skipped'], $s['error']
                ));
                $processed++;
            }
        } catch (Exception $e) {
            logActivity('ABN CAMT Import [cron]: error processing ' . $filename . ' — ' . $e->getMessage());
            $errors++;
        }
    }

    if ($processed > 0 || $errors > 0) {
        logActivity(sprintf(
            'ABN CAMT Import [cron]: run complete — %d file(s) processed, %d error(s).',
            $processed, $errors
        ));
    }
});
