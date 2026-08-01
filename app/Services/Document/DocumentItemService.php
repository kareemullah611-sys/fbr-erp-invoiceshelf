<?php

namespace App\Services\Document;

use App\Support\DocumentTotals;
use Illuminate\Database\Eloquent\Model;

class DocumentItemService
{
    public function createItems(Model $document, array $items): void
    {
        $exchangeRate = $document->exchange_rate;

        foreach ($items as $item) {
            $item['company_id'] = $document->company_id;
            $item['exchange_rate'] = $exchangeRate;
            // Recompute the item total from price/quantity so a tampered item
            // total can't desync from the recomputed document totals (GHSA-8c69).
            $item['total'] = DocumentTotals::itemTotal($item, $document->discount_per_item === 'YES');
            $item['base_price'] = $item['price'] * $exchangeRate;
            $item['base_discount_val'] = $item['discount_val'] * $exchangeRate;
            $item['base_tax'] = $item['tax'] * $exchangeRate;
            $item['base_total'] = $item['total'] * $exchangeRate;

            if (array_key_exists('recurring_invoice_id', $item)) {
                unset($item['recurring_invoice_id']);
            }

            $createdItem = $document->items()->create($item);

            if (array_key_exists('taxes', $item) && $item['taxes']) {
                foreach ($item['taxes'] as $tax) {
                    if ($tax = $this->preparedTaxPayload($tax, $document, $exchangeRate)) {
                        $createdItem->taxes()->create($tax);
                    }
                }
            }

            if (array_key_exists('custom_fields', $item) && $item['custom_fields']) {
                $createdItem->addCustomFields($item['custom_fields']);
            }
        }
    }

    public function createTaxes(Model $document, array $taxes): void
    {
        $exchangeRate = $document->exchange_rate;

        foreach ($taxes as $tax) {
            if ($tax = $this->preparedTaxPayload($tax, $document, $exchangeRate)) {
                $document->taxes()->create($tax);
            }
        }
    }

    private function preparedTaxPayload(array $tax, Model $document, int|float $exchangeRate): ?array
    {
        if (empty($tax['tax_type_id']) || (int) $tax['tax_type_id'] <= 0) {
            return null;
        }

        if (! array_key_exists('amount', $tax) || $tax['amount'] === null) {
            return null;
        }

        if (array_key_exists('recurring_invoice_id', $tax)) {
            unset($tax['recurring_invoice_id']);
        }

        $tax['company_id'] = $document->company_id;
        $tax['exchange_rate'] = $document->exchange_rate;
        $tax['base_amount'] = $tax['amount'] * $exchangeRate;
        $tax['currency_id'] = $document->currency_id;

        return $tax;
    }
}
