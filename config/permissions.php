<?php

return [
    'sales' => [
        'label' => 'Sales',
        'icon' => 'shopping-cart',
        'functions' => [
            'customer' => [
                'label' => 'Customers',
                'actions' => ['index', 'show', 'create', 'edit', 'delete', 'statement'],
            ],
            'deliverynote' => [
                'label' => 'Delivery Notes',
                'actions' => ['index', 'show', 'create', 'edit', 'delete', 'convert', 'pdf'],
            ],
            'invoice' => [
                'label' => 'Invoices',
                'actions' => ['index', 'show', 'edit', 'delete', 'pdf', 'email'],
            ],
            'creditnote' => [
                'label' => 'Credit Notes',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
            'payment' => [
                'label' => 'Payments',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
            'documentsearch' => [
                'label' => 'Document Search',
                'actions' => ['index'],
            ],
        ],
    ],

    'purchasing' => [
        'label' => 'Purchasing',
        'icon' => 'truck',
        'functions' => [
            'supplier' => [
                'label' => 'Suppliers',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
            'supplierinvoice' => [
                'label' => 'Supplier Invoices',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
            'supplierdebitnote' => [
                'label' => 'Supplier Debit Notes',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
            'supplierpayout' => [
                'label' => 'Supplier Payouts',
                'actions' => ['index', 'show', 'create', 'edit'],
            ],
            'overhead' => [
                'label' => 'Overheads',
                'actions' => ['index', 'show', 'create', 'edit', 'delete'],
            ],
        ],
    ],

    'reporting' => [
        'label' => 'Reporting',
        'icon' => 'chart-bar',
        'functions' => [
            'report' => [
                'label' => 'Reports',
                'actions' => ['overheads', 'supplierPurchasing', 'customerOutstandingPayments', 'customerTurnover'],
            ],
            'export' => [
                'label' => 'Exports',
                'actions' => ['index', 'download'],
            ],
        ],
    ],

    'reference' => [
        'label' => 'Reference Data',
        'icon' => 'book-open',
        'functions' => [
            'referencedata' => [
                'label' => 'Reference Data',
                'actions' => [
                    'titles',
                    'creditTerms',
                    'creditLimits',
                    'units',
                    'paymentMethods',
                    'expenseCategories',
                    'customerCategories',
                    'revenueTypes',
                ],
            ],
        ],
    ],

    'system' => [
        'label' => 'System',
        'icon' => 'cog-6-tooth',
        'functions' => [
            'user' => [
                'label' => 'Users',
                'actions' => ['index', 'create', 'edit', 'delete'],
            ],
            'role' => [
                'label' => 'Roles',
                'actions' => ['index', 'create', 'edit', 'delete'],
            ],
            'settings' => [
                'label' => 'Settings',
                'actions' => ['crm', 'legacyMigration'],
            ],
        ],
    ],
];
