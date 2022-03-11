<?php
defined('TYPO3') or die();

if (!empty($GLOBALS['TCA']['tx_kesearch_indexerconfig']) && is_array($GLOBALS['TCA']['tx_kesearch_indexerconfig'])) {

    $maskTypes = array_filter(
        array_keys($GLOBALS['TCA']['tt_content']['types']),
        fn($type) => str_starts_with($type, 'mask_')
    );

    $GLOBALS['TCA']['tx_kesearch_indexerconfig']['columns']['contenttypes']['config']['default'] .=
        ',' . implode(',', $maskTypes);

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
        'tx_kesearch_indexerconfig',
        [
            'autoadd_mask_contenttypes' => [
                'label' => 'Auto-Add Mask Content Types',
                'displayCond' => 'FIELD:type:IN:page',
                'exclude' => 0,
                'config' => [
                    'type' => 'check',
                    'default' => 1,
                ],
            ],
        ]
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'tx_kesearch_indexerconfig',
        'autoadd_mask_contenttypes',
        '',
        'before:contenttypes'
    );

}
