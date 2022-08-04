<?php

namespace LFM\KeSearchAutomask\Indexer;

use LFM\KeSearchAutomask\Xclass\Indexer\Types\Page;
use MASK\Mask\Definition\NestedTcaFieldDefinitions;
use MASK\Mask\Definition\TableDefinitionCollection;
use MASK\Mask\Definition\TcaDefinition;
use MASK\Mask\Loader\LoaderRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AdditionalContentFields
{
    protected ?TableDefinitionCollection $collection = null;

    protected ?Page $pageIndexer = null;

    protected $aliasCounter = 1;

    public function __construct()
    {
        $loaderRegistry = GeneralUtility::getContainer()->get(LoaderRegistry::class);
        $this->collection = $loaderRegistry->getActivateLoader()->load();
    }

    public function modifyPageContentFields(&$fields, $pageIndexer)
    {
        // add all tt_content non-core searchable fields
        foreach ($this->collection->getTable('tt_content')->tca ?? [] as $field) {
            if (!$field->isCoreField && $field->getFieldType($field->fullKey)->isSearchable()) {
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

        [$textFields, $hasNesting] = $this->getTextFieldsNested($element->tcaDefinition);
        if (empty($textFields)) {
            return;
        }

        // add fields directly on tt_content
        foreach ($textFields as $textField) {
            $fieldName = $textField['key'];
            if (isset($ttContentRow[$fieldName]) && is_string($ttContentRow[$fieldName])) {
                $bodytext .= ' ' . $ttContentRow[$fieldName];
            }
        }

        if (!$hasNesting) {
            // simple form, no further querying required
            return;
        }

        $queryBuilder = $this->getQueryBuilder('tt_content');
        $queryBuilder->from('tt_content', 'c')
            ->where($queryBuilder->expr()->eq('c.uid', (int)$ttContentRow['uid']));

        $this->buildRecursiveJoinQueries($queryBuilder, 'c', 'tt_content', $textFields);

        $result = $queryBuilder->execute();
        $pageIndexer->addQueryCount(1);
        $pageIndexer->addRowCount($result->rowCount());

        // collect rows into mappings of [$tableAlias => [$uid => $row]]
        // main purpose is to avoid duplicate rows due to joins.
        $collections = [];
        foreach ($result as $row) {
            $uidMap = [];
            foreach ($row as $columnName => $columnValue) {
                [$alias, $column] = explode('__', $columnName, 2);
                if ($column === 'uid') {
                    $uidMap[$alias] = $columnValue;
                }
            }

            foreach ($row as $columnName => $columnValue) {
                [$alias, $column] = explode('__', $columnName, 2);
                if ($column === 'uid') {
                    continue; // no need to add uid
                }
                $uid = $uidMap[$alias];
                $collections[$alias] = $collections[$alias] ?? [];
                $collections[$alias][$uid] = $collections[$alias][$uid] ?? [];
                $collections[$alias][$uid][$column] = $columnValue;
            }
        }

        $values = [];
        foreach ($collections as $collection) {
            foreach ($collection as $row) {
                $value = implode("\n", array_filter($row, 'trim'));
                $value = strip_tags($value);

                $values[] = $value;
            }
        }

        $bodytext .= implode(" ", $values);
    }

    protected function buildRecursiveJoinQueries(QueryBuilder $queryBuilder, $fromAlias, $fromTable, $textFields)
    {
        if ($fromAlias !== 'c') {
            $queryBuilder->addSelect($fromAlias . '.uid AS ' . $fromAlias . '__uid');
        }
        foreach ($textFields as $textField) {
            if ($textField['type'] === 'inline') {
                $nextAlias = $this->getUniqueAlias($textField['key']);
                $condition = $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq($nextAlias . '.parentid', $fromAlias . '.uid'),
                    $queryBuilder->expr()->eq($nextAlias . '.parenttable', $queryBuilder->createNamedParameter($fromTable))
                );
                $queryBuilder->leftJoin($fromAlias, $textField['key'], $nextAlias, $condition);
                $this->buildRecursiveJoinQueries($queryBuilder, $nextAlias, $textField['key'], $textField['fields']);
            }
            if ($fromAlias === 'c') {
                // fields in initial tt_content are already covered.
                continue;
            }
            $queryBuilder->addSelect(
                $fromAlias . '.' . $textField['key'] . ' AS ' . $fromAlias . '__' . $textField['key']
            );
        }
    }

    protected function getUniqueAlias($tableName)
    {
        if (str_starts_with($tableName, 'tx_mask_')) {
            $tableName = substr($tableName, 8);
        }
        $alias = substr($tableName, 0, 3) . $this->aliasCounter;
        $this->aliasCounter++;
        return $alias;
    }

    /**
     * @param NestedTcaFieldDefinitions|TcaDefinition $tca
     * @return array
     */
    protected function getTextFieldsNested(\IteratorAggregate $tca)
    {
        $textFields = [];
        $hasNesting = false;
        foreach ($tca as $field) {
            if ($field->isCoreField) {
                continue;
            }
            $type = $field->getFieldType($field->fullKey);
            if ($type->isSearchable()) {
                $textFields[] = [
                    'key' => $field->fullKey,
                    'type' => 'string',
                ];
            }

            $isParent = $type->isParentField();
            $isGrouping = $type->isGroupingField();
            $isInline = $isParent && !$isGrouping;
            $isPalette = $isParent && $isGrouping;

            if ($isPalette) {
                $nestedTca = $this->collection->loadInlineFields($field->fullKey, $field->fullKey);
                [$nestedTextFields, $hasNestedNesting] = $this->getTextFieldsNested($nestedTca);
                $hasNesting = $hasNesting || $hasNestedNesting;
                foreach ($nestedTextFields as $nestedTextField) {
                    $textFields[] = $nestedTextField;
                }
            }

            if ($isInline) {
                $nestedTca = $this->collection->loadInlineFields($field->fullKey, $field->fullKey);
                [$nestedTextFields, $hasNestedNesting] = $this->getTextFieldsNested($nestedTca);
                if (!empty($nestedTextFields)) {
                    $hasNesting = true;
                    $textFields[] = [
                        'key' => $field->fullKey,
                        'type' => 'inline',
                        'nesting' => $hasNestedNesting,
                        'fields' => $nestedTextFields,
                    ];
                }
            }
        }
        return [$textFields, $hasNesting];
    }

    /**
     * Makes a query for each relationship
     *
     * @param string $tableName
     * @return QueryBuilder
     */
    protected function getQueryBuilder($tableName): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);
    }
}
