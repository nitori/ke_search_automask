<?php
defined('TYPO3') or die();

// hook for registereing extra fields of tt_content
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyPageContentFields'][] =
    \LFM\KeSearchAutomask\Indexer\AdditionalContentFields::class;
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyContentFromContentElement'][] =
    \LFM\KeSearchAutomask\Indexer\AdditionalContentFields::class;

// There does not seem to be any other way to automatically
// extend the "contenttypes" field of the page indexer.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\Tpwd\KeSearch\Indexer\Types\Page::class] = [
    'className' => \LFM\KeSearchAutomask\Xclass\Indexer\Types\Page::class,
];
