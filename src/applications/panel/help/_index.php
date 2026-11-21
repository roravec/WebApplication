<?php
/**
 * Pomocník — which guide belongs to which page and how the list of guides is grouped (Modules/HelpModule.php).
 * pages: template of the page (PanelContext::page) => guide; sections: menu section => guide (when the page has none).
 * A new page or feature = add its guide here (docs/panel-architektura.md, chapter 7).
 */
return [
    'groups' => [
        'Začíname' => ['prve-kroky', 'prve-kroky-spravca', 'slovnik'],
        'Ako na to' => ['ako-faktura-z-objednavky', 'ako-zlava-na-fakture', 'ako-vratit-fakturu-do-konceptu', 'ako-sparovat-platby',
            'ako-poslat-podklady-uctovnicke', 'ako-napojit-eshop', 'ako-email-pri-zmene-stavu'],
        'Predaj' => ['prehlad', 'objednavky', 'objednavka', 'expedicia', 'vratenie-tovaru', 'upravit-polozky-objednavky', 'eshopy',
            'eshopy-produkt', 'odberatelia'],
        'Financie' => ['faktury', 'faktura-formular', 'faktura-detail', 'uhrady', 'pre-uctovnika', 'export-omega', 'naklady', 'vysledky'],
        'Sklad' => ['sklad'],
        'Nastavenia' => ['nastavenia', 'nastavenia-firmy', 'ciselne-rady', 'nastavenia-eshopy', 'stavy-objednavok', 'naklady-objednavok',
            'legislativa', 'zamestnanci', 'roly-a-prava', 'moj-ucet'],
    ],
    'pages' => [
        'dashboard' => 'prehlad',
        'orders/list' => 'objednavky', 'orders/unavailable' => 'objednavky', 'orders/detail' => 'objednavka', 'orders/address' => 'objednavka',
        'orders/message' => 'objednavka', 'orders/shipping' => 'expedicia', 'orders/return' => 'vratenie-tovaru',
        'orders/lines' => 'upravit-polozky-objednavky', 'orders/states' => 'stavy-objednavok', 'orders/state-form' => 'stavy-objednavok',
        'stock/shops' => 'eshopy', 'stock/shop-stock' => 'eshopy', 'stock/shop-product' => 'eshopy-produkt',
        'stock/shop-connections' => 'nastavenia-eshopy', 'stock/shop-form' => 'nastavenia-eshopy',
        'customers/list' => 'odberatelia', 'customers/form' => 'odberatelia',
        'invoices/list' => 'faktury', 'invoices/form' => 'faktura-formular', 'invoices/show' => 'faktura-detail', 'invoices/send' => 'faktura-detail',
        'invoices/no-company' => 'prve-kroky-spravca', 'invoices/bank-import' => 'uhrady', 'invoices/bank-review' => 'uhrady',
        'invoices/accounting' => 'pre-uctovnika', 'invoices/export' => 'export-omega',
        'expenses/list' => 'naklady', 'expenses/form' => 'naklady', 'expenses/show' => 'naklady', 'expenses/recurring' => 'naklady',
        'expenses/recurring-form' => 'naklady', 'expenses/categories' => 'naklady',
        'reports/result' => 'vysledky', 'reports/money' => 'vysledky', 'reports/sales' => 'vysledky', 'reports/purchase-prices' => 'vysledky',
        'reports/unavailable' => 'vysledky', 'reports/order-costs' => 'naklady-objednavok',
        'stock/index' => 'sklad', 'stock/item' => 'sklad', 'stock/item-form' => 'sklad', 'stock/docs' => 'sklad', 'stock/doc' => 'sklad',
        'stock/doc-form' => 'sklad', 'stock/warehouses' => 'sklad', 'stock/warehouse-form' => 'sklad',
        'companies/list' => 'nastavenia-firmy', 'companies/form' => 'nastavenia-firmy', 'companies/series' => 'ciselne-rady',
        'settings/legislation' => 'legislativa',
        'employees/list' => 'zamestnanci', 'employees/form' => 'zamestnanci', 'employees/account' => 'moj-ucet',
        'employees/roles' => 'roly-a-prava', 'employees/role-form' => 'roly-a-prava', 'employees/overview' => 'roly-a-prava',
    ],
    'sections' => [
        'dashboard' => 'prehlad', 'orders' => 'objednavky', 'eshops' => 'eshopy', 'customers' => 'odberatelia', 'invoices' => 'faktury',
        'expenses' => 'naklady', 'reports' => 'vysledky', 'stock' => 'sklad', 'settings' => 'nastavenia', 'account' => 'moj-ucet',
    ],
];
