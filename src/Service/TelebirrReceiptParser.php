<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\VerificationException;
use DOMDocument;
use DOMXPath;

class TelebirrReceiptParser
{
    /**
     * @return array{creditParty: ?string, debitParty: ?string, date: ?string, amount: ?string}
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//tr');

        $debitParty = null;
        $creditParty = null;
        $date = null;
        $amount = null;
        $invoiceSection = false;

        if ($rows === false) {
            throw VerificationException::notFound(
                'Telebirr could not find transaction details in the receipt page.',
            );
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);

            if ($cells->length === 2) {
                $label = $this->normalizeText($cells->item(0)?->textContent);
                $value = $this->normalizeText($cells->item(1)?->textContent);

                if ($label === '') {
                    continue;
                }

                if ($this->labelContains($label, 'Payer Name')) {
                    $debitParty = $value !== '' ? $value : $debitParty;
                } elseif ($this->labelContains($label, 'Credited Party name')) {
                    $creditParty = $value !== '' ? $value : $creditParty;
                }

                continue;
            }

            if ($cells->length !== 3) {
                continue;
            }

            $first = $this->normalizeText($cells->item(0)?->textContent);
            $second = $this->normalizeText($cells->item(1)?->textContent);
            $third = $this->normalizeText($cells->item(2)?->textContent);

            if ($this->labelContains($first, 'Invoice No') && $this->labelContains($second, 'Payment date')) {
                $invoiceSection = true;

                continue;
            }

            if (! $invoiceSection) {
                continue;
            }

            if ($date === null && $this->looksLikePaymentDate($second) && $this->looksLikeAmount($third)) {
                $date = $second;
                $amount = $third;
            }
        }

        if ($debitParty === null && $creditParty === null && $date === null && $amount === null) {
            throw VerificationException::notFound(
                'Telebirr could not find transaction details in the receipt page.',
            );
        }

        return [
            'creditParty' => $creditParty,
            'debitParty' => $debitParty,
            'date' => $date,
            'amount' => $amount,
        ];
    }

    private function normalizeText(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function labelContains(string $label, string $needle): bool
    {
        return stripos($label, $needle) !== false;
    }

    private function looksLikePaymentDate(string $value): bool
    {
        return (bool) preg_match('/\d{2}-\d{2}-\d{4}\s+\d{2}:\d{2}:\d{2}/', $value);
    }

    private function looksLikeAmount(string $value): bool
    {
        return $value !== '' && preg_match('/\d/u', $value) === 1;
    }
}
