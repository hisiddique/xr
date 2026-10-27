<?php

namespace App\Support;

use App\Models\Setting;

class TableSorts
{
    private const KEY_PREFIX = 'table_sort_';

    /**
     * Every list table whose default sort can be configured. "default" is the sort the table shipped with.
     *
     * @return array<string, array{label: string, columns: array<string, string>, default: array{0: string, 1: string}}>
     */
    public static function tables(): array
    {
        $documentColumns = fn (string $numberLabel): array => [
            'doc_number' => $numberLabel,
            'doc_date' => 'Date',
            'status' => 'Status',
            'total_value' => 'Amount',
            'assignee' => 'Sales Person',
            'order_no' => 'Order Ref',
            'created_at' => 'Created',
        ];

        return [
            'delivery-notes' => ['label' => 'Delivery Notes', 'columns' => $documentColumns('Delivery Note No.'), 'default' => ['doc_number', 'desc']],
            'invoices' => ['label' => 'Invoices', 'columns' => $documentColumns('Invoice No.'), 'default' => ['doc_number', 'desc']],
            'credit-notes' => ['label' => 'Credit Notes', 'columns' => $documentColumns('Credit Note No.'), 'default' => ['doc_number', 'desc']],
            'payments' => [
                'label' => 'Payments',
                'columns' => ['reference' => 'Reference', 'payment_date' => 'Date', 'amount' => 'Amount'],
                'default' => ['payment_date', 'desc'],
            ],
            'customers' => [
                'label' => 'Customers',
                'columns' => ['company_name' => 'Company', 'reference' => 'Reference', 'email_1' => 'Email', 'created_at' => 'Created'],
                'default' => ['created_at', 'desc'],
            ],
            'suppliers' => [
                'label' => 'Suppliers',
                'columns' => ['company_name' => 'Company', 'reference' => 'Reference', 'created_at' => 'Created'],
                'default' => ['created_at', 'desc'],
            ],
            'supplier-invoices' => [
                'label' => 'Supplier Invoices',
                'columns' => [
                    'supplier_invoice_no' => 'Invoice No.',
                    'invoice_date' => 'Date',
                    'created_at' => 'Created',
                    'payable_total' => 'Payable',
                    'vat_total' => 'VAT',
                    'net_total' => 'Net',
                    'discount_amount' => 'Discount',
                    'paid_status' => 'Outstanding',
                ],
                'default' => ['supplier_invoice_no', 'desc'],
            ],
            'supplier-debit-notes' => [
                'label' => 'Supplier Debit Notes',
                'columns' => ['reference' => 'Reference', 'doc_date' => 'Date', 'total' => 'Total', 'status' => 'Status'],
                'default' => ['reference', 'desc'],
            ],
            'supplier-payouts' => [
                'label' => 'Supplier Payouts',
                'columns' => ['reference' => 'Reference', 'payout_date' => 'Date', 'amount' => 'Amount'],
                'default' => ['payout_date', 'desc'],
            ],
            'overheads' => [
                'label' => 'Overheads',
                'columns' => [
                    'expense_date' => 'Date',
                    'net_total' => 'Net',
                    'vat_total' => 'VAT',
                    'gross_total' => 'Gross',
                    'payment_method' => 'Payment Method',
                ],
                'default' => ['expense_date', 'desc'],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} column and direction
     */
    public static function for(string $table): array
    {
        $definition = self::tables()[$table] ?? null;

        if ($definition === null) {
            return ['', 'asc'];
        }

        [$column, $direction] = array_pad(explode(':', (string) Setting::get(self::KEY_PREFIX.$table, ''), 2), 2, '');

        if (isset($definition['columns'][$column]) && in_array($direction, ['asc', 'desc'], true)) {
            return [$column, $direction];
        }

        return $definition['default'];
    }

    public static function save(string $table, string $column, string $direction): void
    {
        $definition = self::tables()[$table] ?? null;

        if ($definition === null || ! isset($definition['columns'][$column]) || ! in_array($direction, ['asc', 'desc'], true)) {
            return;
        }

        Setting::set(self::KEY_PREFIX.$table, $column.':'.$direction);
    }
}
