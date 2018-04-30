<?php

namespace App\Jobs\Client;

use App\Models\InvoiceItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Eloquent;

class GenerateStatementData
{
    public function __construct($client, $options)
    {
        $this->client = $client;
        $this->options = $options;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $client = $this->client;
        $account = $client->account;

        $invoice = $account->createInvoice(ENTITY_INVOICE);
        $invoice->client = $client;
        $invoice->date_format = $account->date_format ? $account->date_format->format_moment : 'MMM D, YYYY';

        $invoice->invoice_items = $this->getInvoices();

        if ($this->options['show_payments']) {
            $invoice->invoice_items = $invoice->invoice_items->merge($this->getPayments());
        }

        return json_encode($invoice);
    }

    private function getInvoices()
    {
        $statusId = intval($this->options['status_id']);

        $invoices = Invoice::scope()
            ->with(['client'])
            ->invoices()
            ->whereClientId($this->client->id)
            ->whereIsPublic(true)
            ->withArchived()
            ->orderBy('invoice_date', 'asc');

        if ($statusId == INVOICE_STATUS_PAID) {
            $invoices->where('invoice_status_id', '=', INVOICE_STATUS_PAID);
        } elseif ($statusId == INVOICE_STATUS_UNPAID) {
            $invoices->where('invoice_status_id', '!=', INVOICE_STATUS_PAID);
        }

        if ($statusId == INVOICE_STATUS_PAID || ! $statusId) {
            $invoices->where('invoice_date', '>=', $this->options['start_date'])
                    ->where('invoice_date', '<=', $this->options['end_date']);
        }

        $invoices = $invoices->get();
        $data = collect();

        for ($i=0; $i<$invoices->count(); $i++) {
            $invoice = $invoices[$i];
            $item = new InvoiceItem();
            $item->product_key = $invoice->invoice_number;
            $item->custom_value1 = $invoice->invoice_date;
            $item->custom_value2 = $invoice->due_date;
            $item->notes = $invoice->amount;
            $item->cost = $invoice->balance;
            $item->qty = 1;
            $item->invoice_item_type_id = 1;
            $data->push($item);
        }

        if ($this->options['show_aging']) {
            $aging = $this->getAging($invoices);
            $data = $data->merge($aging);
        }

        return $data;
    }

    private function getPayments()
    {
        $payments = Payment::scope()
            ->with('invoice', 'payment_type')
            ->withArchived()
            ->whereClientId($this->client->id)
            ->where('payment_date', '>=', $this->options['start_date'])
            ->where('payment_date', '<=', $this->options['end_date']);

        $payments = $payments->get();
        $data = collect();

        for ($i=0; $i<$payments->count(); $i++) {
            $payment = $payments[$i];
            $item = new InvoiceItem();
            $item->product_key = $payment->invoice->invoice_number;
            $item->custom_value1 = $payment->payment_date;
            $item->custom_value2 = $payment->payment_type->name;
            $item->cost = $payment->getCompletedAmount();
            $item->invoice_item_type_id = 3;
            $data->push($item);
        }

        return $data;
    }

    private function getAging($invoices)
    {
        $data = collect();
        $ageGroups = [
            'age_group_0' => 0,
            'age_group_30' => 0,
            'age_group_60' => 0,
            'age_group_90' => 0,
            'age_group_120' => 0,
        ];

        foreach ($invoices as $invoice) {
            $age = $invoice->present()->ageGroup;
            $ageGroups[$age] += $invoice->getRequestedAmount();
        }

        $item = new InvoiceItem();
        $item->product_key = $ageGroups['age_group_0'];
        $item->notes = $ageGroups['age_group_30'];
        $item->custom_value1 = $ageGroups['age_group_60'];
        $item->custom_value1 = $ageGroups['age_group_90'];
        $item->cost = $ageGroups['age_group_120'];
        $item->invoice_item_type_id = 4;
        $data->push($item);

        return $data;
    }
}