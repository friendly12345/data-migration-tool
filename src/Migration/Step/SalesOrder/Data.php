<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\SalesOrder;

use Migration\App\Step\StageInterface;
use Migration\Handler;
use Migration\Reader\MapFactory;
use Migration\Reader\Map;
use Migration\Reader\MapInterface;
use Migration\Resource;
use Migration\Resource\Record;
use Migration\App\ProgressBar;
use Migration\Logger\Manager as LogManager;
use Migration\Logger\Logger;

/**
 * Class Data
 */
class Data implements StageInterface
{
    /**
     * @var Resource\Source
     */
    protected $source;

    /**
     * @var Resource\Destination
     */
    protected $destination;

    /**
     * @var Resource\RecordFactory
     */
    protected $recordFactory;

    /**
     * @var Map
     */
    protected $map;

    /**
     * @var \Migration\RecordTransformerFactory
     */
    protected $recordTransformerFactory;

    /**
     * @var ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param ProgressBar\LogLevelProcessor $progress
     * @param Resource\Source $source
     * @param Resource\Destination $destination
     * @param Resource\RecordFactory $recordFactory
     * @param \Migration\RecordTransformerFactory $recordTransformerFactory
     * @param MapFactory $mapFactory
     * @param Helper $helper
     * @param Logger $logger
     */
    public function __construct(
        ProgressBar\LogLevelProcessor $progress,
        Resource\Source $source,
        Resource\Destination $destination,
        Resource\RecordFactory $recordFactory,
        \Migration\RecordTransformerFactory $recordTransformerFactory,
        MapFactory $mapFactory,
        Helper $helper,
        Logger $logger
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->recordFactory = $recordFactory;
        $this->recordTransformerFactory = $recordTransformerFactory;
        $this->map = $mapFactory->create('sales_order_map_file');
        $this->progress = $progress;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Entry point. Run migration of SalesOrder structure.
     * @return bool
     */
    public function perform()
    {
        $this->progress->start(count($this->helper->getDocumentList()));
        $sourceDocuments = array_keys($this->helper->getDocumentList());
        foreach ($sourceDocuments as $sourceDocName) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($sourceDocName);

            $destinationDocumentName = $this->map->getDocumentMap(
                $sourceDocName,
                MapInterface::TYPE_SOURCE
            );
            if (!$destinationDocumentName) {
                continue;
            }
            $destDocument = $this->destination->getDocument($destinationDocumentName);
            $this->destination->clearDocument($destinationDocumentName);

            $eavDocumentName = $this->helper->getDestEavDocument();
            $eavDocumentResource = $this->destination->getDocument($eavDocumentName);

            /** @var \Migration\RecordTransformer $recordTranformer */
            $recordTransformer = $this->recordTransformerFactory->create(
                [
                    'sourceDocument' => $sourceDocument,
                    'destDocument' => $destDocument,
                    'mapReader' => $this->map
                ]
            );
            $recordTransformer->init();
            $pageNumber = 0;
            $this->logger->debug('migrating', ['table' => $sourceDocName]);
            $this->progress->start($this->source->getRecordsCount($sourceDocName), LogManager::LOG_LEVEL_DEBUG);
            while (!empty($bulk = $this->source->getRecords($sourceDocName, $pageNumber))) {
                $pageNumber++;
                $destinationCollection = $destDocument->getRecords();
                $destEavCollection = $eavDocumentResource->getRecords();
                foreach ($bulk as $recordData) {
                    $this->progress->advance(LogManager::LOG_LEVEL_DEBUG);
                    /** @var Record $sourceRecord */
                    $sourceRecord = $this->recordFactory->create(
                        ['document' => $sourceDocument, 'data' => $recordData]
                    );
                    /** @var Record $destRecord */
                    $destRecord = $this->recordFactory->create(['document' => $destDocument]);
                    $recordTransformer->transform($sourceRecord, $destRecord);
                    $destinationCollection->addRecord($destRecord);

                    $this->migrateAdditionalOrderData($recordData, $sourceDocument, $destEavCollection);
                }
                $this->destination->saveRecords($destinationDocumentName, $destinationCollection);
                $this->destination->saveRecords($eavDocumentName, $destEavCollection);
                $this->progress->finish(LogManager::LOG_LEVEL_DEBUG);
            }
        }
        $this->progress->finish();
        return true;
    }

    /**
     * @param array $data
     * @param Resource\Document $sourceDocument
     * @param Record\Collection $destEavCollection
     * @return void
     */
    public function migrateAdditionalOrderData($data, $sourceDocument, $destEavCollection)
    {
        foreach ($this->helper->getEavAttributes() as $orderEavAttribute) {
            $eavAttributeData = $this->prepareEavEntityData($orderEavAttribute, $data);
            if ($eavAttributeData) {
                $attributeRecord = $this->recordFactory->create(
                    [
                        'document' => $sourceDocument,
                        'data' => $eavAttributeData
                    ]
                );
                $destEavCollection->addRecord($attributeRecord);
            }
        }
    }

    /**
     * @param string $eavAttribute
     * @param array $recordData
     * @return array|null
     */
    protected function prepareEavEntityData($eavAttribute, $recordData)
    {
        $recordEavData = null;
        $value = $this->getAttributeValue($recordData, $eavAttribute);
        if ($value != null) {
            $attributeData = $this->getAttributeData($eavAttribute);
            $recordEavData = [
                'attribute_id' => $attributeData['attribute_id'],
                'entity_type_id' => $attributeData['entity_type_id'],
                'store_id' => $recordData['store_id'],
                'entity_id' => $recordData['entity_id'],
                'value' => $value
            ];
        }
        return $recordEavData;
    }

    /**
     * @param string $eavAttributeCode
     * @return array|null
     */
    protected function getAttributeData($eavAttributeCode)
    {
        $attributeData = null;
        $pageNumber = 0;
        while (!empty($bulk = $this->destination->getRecords('eav_attribute', $pageNumber))) {
            $pageNumber++;
            foreach ($bulk as $eavData) {
                if ($eavData['attribute_code'] == $eavAttributeCode) {
                    $attributeData = $eavData;
                    break;
                }
            }
        }
        return $attributeData;
    }

    /**
     * @param array $recordData
     * @param string $attributeName
     * @return array|null
     */
    protected function getAttributeValue($recordData, $attributeName)
    {
        $attributeValue = null;
        if (isset($recordData[$attributeName])) {
            return $attributeValue = $recordData[$attributeName];
        }
        return $attributeValue;
    }

    /**
     * @return int
     */
    protected function getDestEavDocument()
    {
        return count($this->helper->getDocumentList());
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        throw new \Exception('Rollback is impossible');
    }
}