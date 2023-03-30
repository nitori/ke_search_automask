<?php

namespace LFM\KeSearchAutomask\Indexer;

use LFM\KeSearchAutomask\Xclass\Indexer\Types\Page;
use LFM\Lfmcore\Utility\QueryUtility;
use MASK\Mask\Definition\NestedTcaFieldDefinitions;
use MASK\Mask\Definition\TableDefinitionCollection;
use MASK\Mask\Definition\TcaDefinition;
use MASK\Mask\Loader\LoaderRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

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
        $fields .= ',colPos,tx_mask_content_parent';
    }

    /**
     * @param string $bodytext
     * @param array $ttContentRow
     * @param Page $pageIndexer
     * @return void
     */
    public function modifyContentFromContentElement(string &$bodytext, array $ttContentRow, $pageIndexer)
    {
        if (((int)$ttContentRow['colPos']) === 999) {
            if ($this->checkIfAnyParentIsDisabled('tt_content', $ttContentRow)) {
                $bodytext = '';
            }
        }

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

    protected function checkIfAnyParentIsDisabled($table, $row, $root = null): bool
    {
        if ($table === 'tt_content' && $row['colPos'] !== 999) {
            return false;
        }

        if ($table === 'tt_content') {
            $parentId = 0;
            $parentTable = '';
            foreach ($row as $field => $value) {
                if (str_starts_with($field, 'tx_mask_') && str_ends_with($field, '_parent') && $value > 0) {
                    $relField = substr($field, 0, -7);
                    $parentId = $value;
                    $parentTable = $this->collection->getTableByField($relField);
                    break;
                }
            }
        } else {
            $parentId = $row['parentid'] ?? 0;
            $parentTable = $row['parenttable'] ?? '';
        }

        if ($root === null) {
            $root = [];
        }
        $root[] = (int)$row['uid'];

        if ($parentId === 0 || $parentTable === '') {
            // @todo: check
            return true;
        }

        $queryBuilder = $this->getQueryBuilder($parentTable);
        $queryBuilder->from($parentTable)->where(
            $queryBuilder->expr()->eq('uid', $parentId)
        );

        if (str_starts_with($parentTable, 'tx_mask_')) {
            $queryBuilder->select('uid', 'parentid', 'parenttable');
        } else {
            $queryBuilder->select('*');
        }

        $result = $queryBuilder->execute()->fetchAssociative();

        if ($result === false) {
            return true;
        }
        return $this->checkIfAnyParentIsDisabled($parentTable, $result, $root);
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
