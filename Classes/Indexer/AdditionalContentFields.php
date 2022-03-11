<?php

namespace LFM\MaskAutoKesearch\Indexer;

use LFM\Lfmcore\Utility\DebuggerUtility;
use LFM\MaskAutoKesearch\Xclass\Indexer\Types\Page;
use MASK\Mask\Definition\TableDefinitionCollection;
use MASK\Mask\Definition\TcaFieldDefinition;
use MASK\Mask\Loader\LoaderRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AdditionalContentFields
{
    protected ?TableDefinitionCollection $collection = null;

    protected ?Page $pageIndexer = null;

    protected static array $tableCache = [];

    public function __construct()
    {
        $loaderRegistry = GeneralUtility::getContainer()->get(LoaderRegistry::class);
        $this->collection = $loaderRegistry->getActivateLoader()->load();
    }

    public function modifyPageContentFields(&$fields, $pageIndexer)
    {
        // add all tt_content non-core searchable fields
        foreach ($this->collection->getTable('tt_content')->tca ?? [] as $field) {
            if (!$field->isCoreField && $field->type->isSearchable()) {
                $fields .= "," . $field->fullKey;
            }
        }
    }

    /**
     * @param string $bodytext
     * @param array $ttContentRow
     * @param Page $pageIndexer
     * @return void
     */
    public function modifyContentFromContentElement(string &$bodytext, array $ttContentRow, $pageIndexer)
    {
        $this->pageIndexer = $pageIndexer;
        $ctype = $ttContentRow['CType'];
        if (!str_starts_with($ctype, 'mask_')) {
            return;
        }
        $elementKey = substr($ctype, 5);
        $element = $this->collection->loadElement('tt_content', $elementKey);

        if (!$element) {
            return;
        }

        foreach ($element->tcaDefinition as $field) {
            $generator = $this->getContentRecursive($field, 'tt_content', $ttContentRow);
            $content = iterator_to_array($generator, false);
            $content = implode(' ', array_filter($content, 'trim'));
            $bodytext .= empty($content) ? '' : (' ' . $content);
        }
    }

    protected function getContentRecursive(TcaFieldDefinition $field, string $tableName, array $row)
    {
        if ($field->isCoreField) {
            return;
        }

        if ($field->type->isSearchable()) {
            yield strip_tags($row[$field->fullKey] ?? '');
        }

        if ($field->type->isParentField()) {
            $childTable = $field->fullKey;
            $nestedTca = $this->collection->loadInlineFields($field->fullKey, $field->fullKey);
            $rows = $this->getTableData($childTable, $tableName, $row['uid']);
            $this->pageIndexer->addRowCount(count($rows));

            // @todo: Improve this, as it potentially causes A LOT of database queries.
            foreach ($nestedTca as $childField) {
                foreach ($rows as $childRow) {
                    yield from $this->getContentRecursive($childField, $childTable, $childRow);
                }
            }
        }
    }

    protected function getTableData($tableName, $parenttable, $parentid): array
    {
        $cacheKey = $parenttable . '-' . $parentid;
        if (!isset(self::$tableCache[$tableName])) {
            self::$tableCache[$tableName] = [];
        }

        if (!isset(self::$tableCache[$tableName][$cacheKey])) {
            $this->pageIndexer->addQueryCount(1);
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($tableName);
            $result = $queryBuilder->select('*')
                ->from($tableName)
                ->execute();
            foreach ($result as $row) {
                $rowCacheKey = $row['parenttable'] . '-' . $row['parentid'];
                if (!isset(self::$tableCache[$tableName][$rowCacheKey])) {
                    self::$tableCache[$tableName][$rowCacheKey] = [];
                }
                self::$tableCache[$tableName][$rowCacheKey][] = $row;
            }
        }
        return self::$tableCache[$tableName][$cacheKey] ?? [];
    }
}
