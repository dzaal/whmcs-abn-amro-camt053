<?php
/**
 * WHMCS invoice matcher for ABN AMRO CAMT.053 import.
 *
 * Looks up detected invoice numbers (format 20YY-N) in tblinvoices.
 * Strategy:
 *   1. Exact match on invoicenum column (e.g. "2026-407")
 *   2. Fall back to matching numeric id (e.g. id = 407)
 *
 * Phase 1: read-only. No payments are processed.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class AbnInvoiceMatcher
{
    /** @var bool */
    private $useCapsule;

    public function __construct()
    {
        $this->useCapsule = class_exists('\\WHMCS\\Database\\Capsule');
    }

    /**
     * Match an array of detected invoice number strings against WHMCS invoices.
     *
     * @param  string[] $invoiceNumbers  e.g. ['2026-407']
     * @param  float    $txAmount
     * @return array[]
     */
    public function matchInvoices(array $invoiceNumbers, $txAmount)
    {
        if (empty($invoiceNumbers)) {
            return [];
        }

        $found    = [];
        $notFound = [];

        foreach ($invoiceNumbers as $num) {
            $invoice = $this->findInvoice($num);
            if ($invoice) {
                $found[] = ['number' => $num, 'invoice' => $invoice];
            } else {
                $notFound[] = $num;
            }
        }

        $results = [];

        foreach ($notFound as $num) {
            $results[] = ['status' => 'not_found', 'number' => $num];
        }

        if (empty($found)) {
            return $results;
        }

        if (count($found) === 1) {
            $results[] = $this->classifySingle($found[0]['invoice'], $txAmount);
            return $results;
        }

        // Multiple invoices — check combined total
        $combinedTotal = array_sum(array_map(function ($fi) {
            return (float) $fi['invoice']['total'];
        }, $found));
        $totalMatches = abs($combinedTotal - $txAmount) < 0.015;

        foreach ($found as $fi) {
            $inv = $fi['invoice'];
            if ($this->isNonPayable($inv['status'])) {
                $results[] = ['status' => 'paid', 'invoice' => $inv];
            } elseif ($totalMatches) {
                $results[] = ['status' => 'multi', 'invoice' => $inv];
            } else {
                $results[] = ['status' => 'wrong_amount', 'invoice' => $inv];
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------

    private function classifySingle(array $inv, $txAmount)
    {
        if ($this->isNonPayable($inv['status'])) {
            return ['status' => $inv['status'] === 'Paid' ? 'paid' : 'cancelled', 'invoice' => $inv];
        }
        if (abs((float) $inv['total'] - $txAmount) < 0.015) {
            return ['status' => 'exact', 'invoice' => $inv];
        }
        return ['status' => 'wrong_amount', 'invoice' => $inv];
    }

    /**
     * Returns true for any invoice status that cannot receive a payment.
     * Cancelled is intentionally excluded: a cancelled invoice with a received
     * payment will be reactivated and marked paid by PaymentProcessor.
     * WHMCS statuses: Unpaid, Paid, Cancelled, Refunded, Collections, Draft
     */
    private function isNonPayable($status)
    {
        return in_array($status, ['Paid', 'Refunded', 'Collections', 'Draft'], true);
    }

    /**
     * Find an invoice by formatted number (e.g. "2026-407").
     *
     * Tries invoicenum column first, then falls back to numeric id.
     *
     * @param  string $invoiceNumber
     * @return array|null
     */
    private function findInvoice($invoiceNumber)
    {
        if ($this->useCapsule) {
            return $this->findViaCapsule($invoiceNumber);
        }
        return $this->findViaLegacy($invoiceNumber);
    }

    private function findViaCapsule($invoiceNumber)
    {
        $capsule = '\\WHMCS\\Database\\Capsule';

        // 1. Exact invoicenum match ("2026-407")
        try {
            $row = $capsule::table('tblinvoices')
                ->select(['id', 'invoicenum', 'total', 'status', 'date'])
                ->where('invoicenum', $invoiceNumber)
                ->first();

            if ($row) {
                return $this->normalise($row);
            }
        } catch (Exception $e) {
            // invoicenum column may not exist in older WHMCS — fall through
        }

        // 2. Fall back: match by numeric id (part after dash, e.g. 407)
        $numericId = $this->extractNumericId($invoiceNumber);
        if ($numericId === null) {
            return null;
        }

        try {
            $row = $capsule::table('tblinvoices')
                ->select(['id', 'invoicenum', 'total', 'status', 'date'])
                ->where('id', $numericId)
                ->first();

            return $row ? $this->normalise($row) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function findViaLegacy($invoiceNumber)
    {
        // 1. Try invoicenum match
        if (function_exists('full_query')) {
            $esc = mysql_real_escape_string($invoiceNumber);
            $res = full_query("SELECT id, invoicenum, total, status, date FROM tblinvoices WHERE invoicenum = '{$esc}' LIMIT 1");
            if ($res && ($row = mysql_fetch_assoc($res))) {
                return $this->normalise($row);
            }
        }

        // 2. Fall back: numeric id
        $numericId = $this->extractNumericId($invoiceNumber);
        if ($numericId === null) {
            return null;
        }

        if (function_exists('full_query')) {
            $res = full_query("SELECT id, invoicenum, total, status, date FROM tblinvoices WHERE id = " . (int) $numericId . " LIMIT 1");
            if ($res && ($row = mysql_fetch_assoc($res))) {
                return $this->normalise($row);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------

    private function extractNumericId($invoiceNumber)
    {
        // "2026-407" → 407
        if (preg_match('/^20[0-9]{2}-([0-9]{1,6})$/', $invoiceNumber, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function normalise($row)
    {
        if (is_object($row)) {
            $row = (array) $row;
        }
        return [
            'id'         => (int)   ($row['id']         ?? 0),
            'invoicenum' =>          $row['invoicenum']  ?? '',
            'total'      => (float) ($row['total']       ?? 0),
            'status'     =>          $row['status']      ?? '',
            'date'       =>          $row['date']        ?? '',
            'currency'   => 'EUR',
        ];
    }
}
