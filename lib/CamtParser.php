<?php
/**
 * ABN AMRO CAMT.053 XML parser.
 *
 * Handles namespace versions camt.053.001.02 and .03.
 * Returns only credit (CRDT) entries.
 *
 * Verified against real ABN AMRO export format (AcctSvcrRef as bank ref,
 * EndToEndId always NOTPROVIDED, amounts zero-padded).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class AbnCamtParser
{
    /** @var DOMXPath */
    private $xpath;

    /** @var string  XPath element prefix, e.g. "camt:" or "" */
    private $p;

    /**
     * Parse a CAMT.053 XML file and return all credit transactions.
     *
     * @param  string $filePath Absolute path to the XML file.
     * @return array[]
     * @throws RuntimeException
     */
    public function parse($filePath)
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!$dom->load($filePath, LIBXML_NONET)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = !empty($errors) ? trim($errors[0]->message) : 'Unknown XML error';
            throw new RuntimeException('Cannot load XML: ' . $msg);
        }

        libxml_clear_errors();

        $this->xpath = new DOMXPath($dom);
        $ns          = $dom->documentElement->namespaceURI;

        if ($ns) {
            $this->xpath->registerNamespace('camt', $ns);
            $this->p = 'camt:';
        } else {
            $this->p = '';
        }

        return $this->extractTransactions();
    }

    // -------------------------------------------------------------------------

    private function extractTransactions()
    {
        $p       = $this->p;
        $entries = $this->query("//{$p}BkToCstmrStmt/{$p}Stmt/{$p}Ntry");

        if (!$entries || $entries->length === 0) {
            return [];
        }

        $transactions = [];

        foreach ($entries as $entry) {
            // Only process credit entries
            $cdtDbtInd = $this->val("{$p}CdtDbtInd", $entry);
            if (strtoupper($cdtDbtInd) !== 'CRDT') {
                continue;
            }

            $amount   = (float) $this->val("{$p}Amt", $entry);
            $currency = $this->attr("{$p}Amt", $entry, 'Ccy') ?: 'EUR';
            $bookDate = $this->val("{$p}BookgDt/{$p}Dt", $entry)
                     ?: $this->val("{$p}BookgDt/{$p}DtTm", $entry);

            // ABN AMRO uses AcctSvcrRef as the primary bank reference at entry level
            $bankRef  = $this->val("{$p}AcctSvcrRef", $entry)
                     ?: $this->val("{$p}NtryRef", $entry);

            // Entry-level additional info (ABN AMRO puts full SEPA details here)
            $addtlNtryInf = $this->val("{$p}AddtlNtryInf", $entry);

            // Transaction detail nodes (can be multiple for batches)
            $txNodes = $this->query("{$p}NtryDtls/{$p}TxDtls", $entry);

            $debtorName  = '';
            $debtorIban  = '';
            $remittance  = '';
            $searchTexts = [];

            if ($txNodes && $txNodes->length > 0) {
                foreach ($txNodes as $tx) {
                    if (!$debtorName) {
                        $debtorName = $this->val("{$p}RltdPties/{$p}Dbtr/{$p}Nm", $tx)
                                   ?: $this->val("{$p}RltdPties/{$p}UltmtDbtr/{$p}Nm", $tx);
                    }

                    if (!$debtorIban) {
                        $debtorIban = $this->val("{$p}RltdPties/{$p}DbtrAcct/{$p}Id/{$p}IBAN", $tx);
                    }

                    // Unstructured remittance (e.g. "Factuur 2025-308")
                    $ustrd = $this->val("{$p}RmtInf/{$p}Ustrd", $tx);
                    if ($ustrd) {
                        $remittance    = $remittance ? $remittance . ' | ' . $ustrd : $ustrd;
                        $searchTexts[] = $ustrd;
                    }

                    // Structured remittance additional info
                    $strdInfo = $this->val("{$p}RmtInf/{$p}Strd/{$p}AddtlRmtInf", $tx);
                    if ($strdInfo) {
                        $searchTexts[] = $strdInfo;
                    }

                    // Creditor reference in structured remittance
                    $credRef = $this->val("{$p}RmtInf/{$p}Strd/{$p}CdtrRefInf/{$p}Ref", $tx);
                    if ($credRef) {
                        $searchTexts[] = $credRef;
                    }

                    // End-to-end ID (skip NOTPROVIDED)
                    $e2e = $this->val("{$p}Refs/{$p}EndToEndId", $tx);
                    if ($e2e && $e2e !== 'NOTPROVIDED') {
                        $searchTexts[] = $e2e;
                    }
                }
            }

            // Also scan entry-level AddtlNtryInf — ABN AMRO puts /REMI/... here
            if ($addtlNtryInf) {
                $searchTexts[] = $addtlNtryInf;
            }

            // Detect invoice numbers from all collected text
            $allText         = implode(' ', array_unique($searchTexts));
            $detectedNumbers = $this->detectInvoiceNumbers($allText);

            $transactions[] = [
                'booking_date'             => $this->normaliseDate($bookDate),
                'amount'                   => $amount,
                'currency'                 => $currency,
                'debtor_name'              => trim($debtorName),
                'debtor_iban'              => trim($debtorIban),
                'remittance_info'          => trim($remittance),
                'bank_reference'           => trim($bankRef),
                'detected_invoice_numbers' => $detectedNumbers,
            ];
        }

        return $transactions;
    }

    // -------------------------------------------------------------------------

    private function detectInvoiceNumbers($text)
    {
        if (empty($text)) {
            return [];
        }
        // Optional single-letter prefix (e.g. F2026-197 → 2026-197).
        // Capture group 1 is the normalised 20YY-NNN part without any prefix.
        if (!preg_match_all('/\b[A-Z]?(20[0-9]{2}-[0-9]{1,6})\b/i', $text, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }

    private function normaliseDate($raw)
    {
        if (!$raw) {
            return '';
        }
        // DtTm format: 2024-01-15T00:00:00 — take only date part
        return substr($raw, 0, 10);
    }

    // -------------------------------------------------------------------------
    // DOM helpers
    // -------------------------------------------------------------------------

    private function query($expr, DOMNode $ctx = null)
    {
        return $ctx
            ? $this->xpath->query($expr, $ctx)
            : $this->xpath->query($expr);
    }

    private function val($expr, DOMNode $ctx = null)
    {
        $nodes = $this->query($expr, $ctx);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }

    private function attr($expr, DOMNode $ctx, $attribute)
    {
        $nodes = $this->query($expr, $ctx);
        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->getAttribute($attribute);
        }
        return '';
    }
}
