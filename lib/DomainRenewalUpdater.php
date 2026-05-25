<?php
/**
 * Keeps WHMCS domain renewal dates in step when the CAMT importer marks a
 * domain renewal invoice as paid.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class AbnDomainRenewalUpdater
{
    public static function syncPaidInvoice($invoiceId, $source = 'ABN CAMT Import')
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return ['updated' => 0, 'domains' => []];
        }

        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return ['updated' => 0, 'domains' => []];
        }

        $c = '\\WHMCS\\Database\\Capsule';
        $invoice = self::invoiceInfo($invoiceId);
        $items = $c::table('tblinvoiceitems as ii')
            ->join('tbldomains as d', 'd.id', '=', 'ii.relid')
            ->where('ii.invoiceid', $invoiceId)
            ->where('ii.type', 'Domain')
            ->where('ii.relid', '>', 0)
            ->select([
                'ii.id as item_id',
                'ii.description',
                'ii.duedate as item_duedate',
                'd.id as domain_id',
                'd.domain',
                'd.registrationperiod',
                'd.expirydate',
                'd.nextduedate',
                'd.nextinvoicedate',
                'd.status',
                'd.additionalnotes',
            ])
            ->get();

        $updated = [];
        foreach ($items as $row) {
            $domain = (array) $row;
            $targets = self::targetDates($domain);
            if (!$targets) {
                continue;
            }

            $changes = [];
            foreach ($targets as $field => $targetDate) {
                $current = (string) ($domain[$field] ?? '');
                if (self::isValidDate($targetDate) && (!self::isValidDate($current) || $current < $targetDate)) {
                    $changes[$field] = $targetDate;
                }
            }

            if (!$changes) {
                continue;
            }

            $changes['updated_at'] = date('Y-m-d H:i:s');
            if (isset($domain['status']) && $domain['status'] === 'Expired') {
                $changes['status'] = 'Active';
            }
            $changes['additionalnotes'] = self::appendAdminNote(
                $domain['additionalnotes'] ?? '',
                $invoice['label'],
                $changes['expirydate'] ?? ($domain['expirydate'] ?? '')
            );

            $c::table('tbldomains')->where('id', (int) $domain['domain_id'])->update($changes);

            $updated[] = [
                'domain_id' => (int) $domain['domain_id'],
                'domain'    => $domain['domain'],
                'changes'   => $changes,
            ];

            self::log($source . ': advanced domain renewal dates after paid invoice - Invoice ID: ' . $invoiceId . ' - Domain ID: ' . (int) $domain['domain_id'] . ' - Domain: ' . $domain['domain'] . ' - ' . self::formatChanges($changes));
        }

        return ['updated' => count($updated), 'domains' => $updated];
    }

    private static function targetDates(array $domain)
    {
        $periodYears = self::periodYears($domain);

        $fromDescription = self::datesFromDescription($domain['description'] ?? '');
        if ($fromDescription) {
            $nextDue = self::addDays($fromDescription['expirydate'], -15);
            return [
                'expirydate'      => $fromDescription['expirydate'],
                'nextduedate'     => $nextDue,
                'nextinvoicedate' => $nextDue,
            ];
        }

        $baseDue = self::isValidDate($domain['item_duedate'] ?? '') ? $domain['item_duedate'] : ($domain['nextduedate'] ?? '');
        return [
            'expirydate'      => self::addYears($domain['expirydate'] ?? '', $periodYears),
            'nextduedate'     => self::addYears($baseDue, $periodYears),
            'nextinvoicedate' => self::addYears($baseDue, $periodYears),
        ];
    }

    private static function invoiceInfo($invoiceId)
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return ['label' => '#' . (int) $invoiceId];
        }

        $c = '\\WHMCS\\Database\\Capsule';
        $row = $c::table('tblinvoices')
            ->where('id', (int) $invoiceId)
            ->select(['id', 'invoicenum'])
            ->first();

        if (!$row) {
            return ['label' => '#' . (int) $invoiceId];
        }

        return ['label' => $row->invoicenum ?: '#' . (int) $row->id];
    }

    private static function appendAdminNote($currentNotes, $invoiceLabel, $expiryDate)
    {
        $line = date('Y-m-d') . ' > exp. date updated to ' . $expiryDate . ' via invoice ' . $invoiceLabel;
        $currentNotes = trim((string) $currentNotes);

        if (strpos($currentNotes, 'exp. date updated to ' . $expiryDate . ' via invoice ' . $invoiceLabel) !== false) {
            return $currentNotes;
        }

        return $currentNotes === '' ? $line : $currentNotes . "\n" . $line;
    }

    private static function periodYears(array $domain)
    {
        $description = (string) ($domain['description'] ?? '');
        if (preg_match('/-\s*([0-9]+)\s*(?:Jaar|Year)/i', $description, $m)) {
            return max(1, min(10, (int) $m[1]));
        }

        return max(1, min(10, (int) ($domain['registrationperiod'] ?? 1)));
    }

    private static function datesFromDescription($description)
    {
        if (!preg_match('/\(([0-9]{2})\/([0-9]{2})\/([0-9]{4})\s*-\s*([0-9]{2})\/([0-9]{2})\/([0-9]{4})\)/', (string) $description, $m)) {
            return null;
        }

        $end = sprintf('%04d-%02d-%02d', (int) $m[6], (int) $m[5], (int) $m[4]);
        if (!self::isValidDate($end)) {
            return null;
        }

        return ['expirydate' => self::addDays($end, 1)];
    }

    private static function addYears($date, $years)
    {
        if (!self::isValidDate($date)) {
            return null;
        }
        return (new DateTimeImmutable($date))->modify('+' . (int) $years . ' year')->format('Y-m-d');
    }

    private static function addDays($date, $days)
    {
        if (!self::isValidDate($date)) {
            return null;
        }
        return (new DateTimeImmutable($date))->modify('+' . (int) $days . ' day')->format('Y-m-d');
    }

    private static function isValidDate($date)
    {
        if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    private static function formatChanges(array $changes)
    {
        unset($changes['updated_at']);
        $parts = [];
        foreach ($changes as $field => $value) {
            $parts[] = $field . '=' . $value;
        }
        return implode(', ', $parts);
    }

    private static function log($message)
    {
        if (function_exists('logActivity')) {
            logActivity($message);
        }
    }
}
