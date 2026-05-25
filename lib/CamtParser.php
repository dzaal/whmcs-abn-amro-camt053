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

            // Scan entry-level AddtlNtryInf only as a fallback when no remittance
            // text was found in TxDtls — prevents false positives from ABN AMRO
            // internal references (e.g. /TRCD/160/ matching as invoice 2026-160).
            if ($addtlNtryInf && empty($searchTexts)) {
                $searchTexts[] = $addtlNtryInf;
            }

            // Detect invoice references from all collected text.
            $allText        = implode(' ', array_unique($searchTexts));
            $referenceHints = self::detectInvoiceReferenceHints($allText);

            $transactions[] = [
                'booking_date'             => $this->normaliseDate($bookDate),
                'amount'                   => $amount,
                'currency'                 => $currency,
                'debtor_name'              => trim($debtorName),
                'debtor_iban'              => trim($debtorIban),
                'remittance_info'          => trim($remittance),
                'bank_reference'           => trim($bankRef),
                'detected_invoice_numbers' => $referenceHints['full_numbers'],
                'reference_hints'          => $referenceHints,
            ];
        }

        return $transactions;
    }

    // -------------------------------------------------------------------------

    public static function detectInvoiceReferenceHints($text)
    {
        $result = [
            'full_numbers'     => [],
            'shorthand_groups' => [],
        ];

        if (empty($text)) {
            return $result;
        }

        // Optional single-letter prefix (e.g. F2026-197 → 2026-197).
        // Capture group 1 is the normalised 20YY-NNN part without any prefix.
        if (preg_match_all('/\b[A-Z]?(20[0-9]{2}-[0-9]{1,6})\b/i', $text, $m)) {
            $result['full_numbers'] = array_values(array_unique($m[1]));
        }

        // Support compact lists like "2026-69 87 41 42" where the first invoice
        // carries the year prefix and the following items only contain the suffix.
        if (preg_match_all('/\b[A-Z]?(20[0-9]{2})-([0-9]{1,6})\b((?:[\s,;\/+.]+[0-9]{1,6}){1,60})/i', $text, $groups, PREG_SET_ORDER)) {
            foreach ($groups as $group) {
                preg_match_all('/[0-9]{1,6}/', $group[3], $tailMatches);
                $tailNumbers = array_values(array_filter(array_unique($tailMatches[0] ?? []), static function ($n) use ($group) {
                    return $n !== '' && $n !== $group[1];
                }));

                if (empty($tailNumbers)) {
                    continue;
                }

                $result['shorthand_groups'][] = [
                    'base_year'   => $group[1],
                    'base_number' => $group[2],
                    'numbers'     => $tailNumbers,
                ];
            }
        }

        return $result;
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

    private function query($expr, ?DOMNode $ctx = null)
    {
        return $ctx
            ? $this->xpath->query($expr, $ctx)
            : $this->xpath->query($expr);
    }

    private function val($expr, ?DOMNode $ctx = null)
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
