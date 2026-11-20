"""Writes the production config.php of WebApplication into the deploy package (secrets generated here, never printed)."""
import secrets
import sys

path = sys.argv[1]
jwt = secrets.token_hex(32)
cron = secrets.token_hex(20)
config = """<?php
/**
 * ERMA hosting - production configuration of WebApplication (deploy package of %(date)s).
 * NOT in git. Fill in the EMPTY values marked TODO, then upload as config/config.php.
 * jwtSecret: generated once - never change it (it encrypts the Fio bank token and signs logins).
 * Databases (docs/nasadenie-a-udrzba.md): first `molesk` (test), later flipped over to `ermaskretail` (phase A2).
 */
return [
    'debug' => false,
    'timezone' => 'Europe/Bratislava',

    // TODO: host / user / password = the same values as in today's config/DatabaseConfig.php on the hosting
    'database' => [
        'host' => '',
        'port' => 3306,
        'name' => 'molesk',
        'user' => '',
        'password' => '',
    ],

    'jwtSecret' => '%(jwt)s',

    // which app answers which domain (and path); unknown hosts get a 404 page
    'routes' => [
        'erma.sk' => 'erma',
        'www.erma.sk' => 'erma',
        'erma.sk/panel' => 'panel',
        'www.erma.sk/panel' => 'panel',
        'hbkpravotice.sk' => 'hbkpravotice',
        'www.hbkpravotice.sk' => 'hbkpravotice',
        'bnhl.sk' => 'bnhl',
        'www.bnhl.sk' => 'bnhl',
    ],
    'devPathSelect' => false,
    'logApiAuth' => false,

    'apps' => [
        'erma' => ['folder' => 'erma', 'file' => 'App.php', 'class' => 'ErmaApp', 'dbPrefix' => 'ermaweb_', 'auth' => 'none',
                   'panelUrl' => '/panel'],
        'panel' => [
            'folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
            // the shop tables in the same database (molesk: ps_*), read only; writes go through the Webservice
            'shop' => ['prefix' => 'ps_', 'lang' => 'sk'],
            // TODO: SMTP mailbox for invoices (e.g. faktury@erma.sk) - host, port, user, password from the hosting
            'mail' => ['transport' => 'smtp', 'host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'password' => ''],
            // monthly package for the accountant without opening the panel: /panel/cron/accounting?token=<this>
            'cron_token' => '%(cron)s',
        ],
        'hbkpravotice' => ['folder' => 'hbkpravotice', 'file' => 'App.php', 'class' => 'HbkpravoticeApp',
                           'dbPrefix' => 'hbkpravotice_', 'auth' => 'none'],
        'bnhl' => ['folder' => 'bnhl', 'file' => 'App.php', 'class' => 'BnhlApp', 'dbPrefix' => 'hbkpravotice_', 'auth' => 'none',
                   'stadiumControlApi' => 'https://hbkpravotice.sk/StadiumControl'],
    ],
];
""" % {'jwt': jwt, 'cron': cron, 'date': sys.argv[2]}
with open(path, 'w', encoding='utf-8', newline='\n') as f:
    f.write(config)
print('written (secrets not shown)')
