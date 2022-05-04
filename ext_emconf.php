<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Mask ke_search auto integration',
    'description' => 'Automatically adjusts the page indexer of ke_search to index your custom Mask element fields and related records.',
    'category' => 'plugin',
    'author' => 'Lichtflut.Medien Entwicklung',
    'author_company' => 'Lichtflut.Medien GmbH & Co. KG',
    'author_email' => 'entwicklung@lichtflut-medien.de',
    'clearCacheOnLoad' => 1,
    'state' => 'beta',
    'version' => '11.0.0',
    'constraints' => [
        'depends' => [
            'php' => '7.4.0-7.4.99',
            'typo3' => '10.4.0-11.5.99',
            'mask' => '7.1.19-7.1.99',
            'ke_search' => '4.3.1-4.3.99',
        ],
        'suggests' => [],
    ],
];
