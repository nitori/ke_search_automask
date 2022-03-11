<?php

namespace LFM\MaskAutoKesearch\Xclass\Indexer\Types;

use MASK\Mask\Loader\LoaderRegistry;
use Tpwd\KeSearch\Indexer\IndexerRunner;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Page extends \Tpwd\KeSearch\Indexer\Types\Page
{
    protected int $queryCount = 0;
    protected int $rowCount = 0;

    public function addQueryCount(int $i)
    {
        $this->queryCount += $i;
    }

    public function addRowCount(int $i)
    {
        $this->rowCount += $i;
    }

    public function startIndexing()
    {
        $this->queryCount = 0;
        $this->rowCount = 0;
        $messages = parent::startIndexing();
        $messages = rtrim($messages) . LF . LF;
        $messages .= 'Total extra Mask queries performed: ' . $this->queryCount . LF;
        $messages .= 'Total extra Mask rows processed: ' . $this->rowCount . LF;
        return $messages;
    }

    /**
     * tx_kesearch_indexer_types_page constructor.
     * @param IndexerRunner $pObj
     */
    public function __construct($pObj)
    {
        if ((int)$pObj->indexerConfig['autoadd_mask_contenttypes']) {
            $contentTypes = GeneralUtility::trimExplode(',', $pObj->indexerConfig['contenttypes'], true);

            $loaderRegistry = GeneralUtility::getContainer()->get(LoaderRegistry::class);
            $collection = $loaderRegistry->getActivateLoader()->load();
            $table = $collection->getTable('tt_content');
            foreach ($table->elements as $element) {
                $contentTypes[] = 'mask_' . $element->key;
            }
            $contentTypes = array_unique($contentTypes);

            $pObj->indexerConfig['contenttypes'] = implode(',', $contentTypes);
        }
        parent::__construct($pObj);
    }
}
