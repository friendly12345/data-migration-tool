<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\Eav;

use Migration\App\Step\StageInterface;
use Migration\App\Step\RollbackInterface;
use Migration\Reader\MapInterface;
use Migration\Reader\GroupsFactory;
use Migration\Reader\MapFactory;
use Migration\Reader\Map;
use Migration\App\ProgressBar;
use Migration\ResourceModel\Destination;
use Migration\ResourceModel\Record;
use Migration\ResourceModel\Document;
use Migration\ResourceModel\RecordFactory;
use Migration\ResourceModel\Source;

/**
 * Class Data
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @codeCoverageIgnoreStart
 */
class Data implements StageInterface, RollbackInterface
{
    const ENTITY_TYPE_ID_CATALOG_PRODUCT = 4;

    /**
     * @var array;
     */
    protected $newAttributes;

    /**
     * @var array;
     */
    protected $newAttributeSets;

    /**
     * @var array;
     */
    protected $newAttributeGroups;

    /**
     * @var array;
     */
    protected $destAttributeOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeSetsOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeGroupsOldNewMap;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var Map
     */
    protected $map;

    /**
     * @var RecordFactory
     */
    protected $factory;

    /**
     * @var InitialData
     */
    protected $initialData;

    /**
     * @var ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * @var \Migration\Reader\Groups
     */
    protected $readerGroups;

    /**
     * @var \Migration\Reader\Groups
     */
    protected $readerAttributes;

    /**
     * @var array
     */
    protected $groupsDataToAdd = [
        [
            'attribute_group_name' => 'Schedule Design Update',
            'attribute_group_code' => 'schedule-design-update',
            'sort_order' => '55',
        ], [
            'attribute_group_name' => 'Bundle Items',
            'attribute_group_code' => 'bundle-items',
            'sort_order' => '16',
        ]
    ];

    /**
     * @param Source $source
     * @param Destination $destination
     * @param MapFactory $mapFactory
     * @param GroupsFactory $groupsFactory
     * @param Helper $helper
     * @param RecordFactory $factory
     * @param InitialData $initialData
     * @param ProgressBar\LogLevelProcessor $progress
     */
    public function __construct(
        Source $source,
        Destination $destination,
        MapFactory $mapFactory,
        GroupsFactory $groupsFactory,
        Helper $helper,
        RecordFactory $factory,
        InitialData $initialData,
        ProgressBar\LogLevelProcessor $progress
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->map = $mapFactory->create('eav_map_file');
        $this->readerGroups = $groupsFactory->create('eav_document_groups_file');
        $this->readerAttributes = $groupsFactory->create('eav_attribute_groups_file');
        $this->helper = $helper;
        $this->factory = $factory;
        $this->initialData = $initialData;
        $this->progress = $progress;
    }

    /**
     * Entry point. Run migration of EAV structure.
     * @return bool
     */
    public function perform()
    {
        $this->progress->start($this->getIterationsCount());
        $this->initialData->init();
        $this->migrateAttributeSetsAndGroups();
        $this->migrateAttributes();
        $this->migrateEntityAttributes();
        $this->migrateMappedTables();
        $this->progress->finish();
        return true;
    }

    /**
     * Migrate eav_attribute_set and eav_attribute_group
     * @return void
     */
    protected function migrateAttributeSetsAndGroups()
    {
        foreach (['eav_attribute_set', 'eav_attribute_group'] as $documentName) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapInterface::TYPE_SOURCE)
            );

            $this->destination->backupDocument($destinationDocument->getName());

            $sourceRecords = $this->source->getRecords($documentName, 0, $this->source->getRecordsCount($documentName));
            $recordsToSave = $destinationDocument->getRecords();
            $recordTransformer = $this->helper->getRecordTransformer($sourceDocument, $destinationDocument);
            foreach ($sourceRecords as $recordData) {
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
                $recordTransformer->transform($sourceRecord, $destinationRecord);
                $recordsToSave->addRecord($destinationRecord);
            }

            if ($documentName == 'eav_attribute_set') {
                foreach ($this->initialData->getAttributeSets('dest') as $record) {
                    $record['attribute_set_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            if ($documentName == 'eav_attribute_group') {
                foreach ($this->initialData->getAttributeGroups('dest') as $record) {
                    $oldAttributeSet = $this->initialData->getAttributeSets('dest')[$record['attribute_set_id']];
                    $newAttributeSet = $this->newAttributeSets[
                        $oldAttributeSet['entity_type_id'] . '-' . $oldAttributeSet['attribute_set_name']
                    ];
                    $record['attribute_set_id'] = $newAttributeSet['attribute_set_id'];

                    $record['attribute_group_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
                $recordsToSave = $this->addAttributeGroups($recordsToSave, $documentName, $this->groupsDataToAdd);
            }

            $this->destination->clearDocument($destinationDocument->getName());
            $this->saveRecords($destinationDocument, $recordsToSave);
            if ($documentName == 'eav_attribute_set') {
                $this->loadNewAttributeSets();
            }
            if ($documentName == 'eav_attribute_group') {
                $this->loadNewAttributeGroups();
            }
        }
    }

    /**
     * Add attribute groups to Magento 1 which are needed for Magento 2
     *
     * @param Record\Collection $recordsToSave
     * @param string $documentName
     * @param array $groupsData
     * @return Record\Collection
     */
    protected function addAttributeGroups($recordsToSave, $documentName, array $groupsData)
    {
        /** @var \Magento\Framework\DB\Select $select */
        $select = $this->source->getAdapter()->getSelect();
        $select->from(
            ['eas' => $this->source->addDocumentPrefix('eav_attribute_set')],
            ['attribute_set_id']
        )->where(
            'entity_type_id = ?',
            self::ENTITY_TYPE_ID_CATALOG_PRODUCT
        );
        $catalogProductSetIds = $select->getAdapter()->fetchCol($select);
        $addedGroups = [];
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($documentName, MapInterface::TYPE_SOURCE)
        );
        foreach ($groupsData as $group) {
            foreach ($catalogProductSetIds as $id) {
                $destinationRecord = $this->factory->create(
                    [
                        'document' => $destinationDocument,
                        'data' => [
                            'attribute_group_id' => null,
                            'attribute_set_id' => $id,
                            'attribute_group_name' => $group['attribute_group_name'],
                            'sort_order' => $group['sort_order'],
                            'default_id' => '0',
                            'attribute_group_code' => $group['attribute_group_code'],
                            'tab_group_code' => 'advanced',
                        ]
                    ]
                );
                $addedGroups[] = $destinationRecord;
                $recordsToSave->addRecord($destinationRecord);
            }
        }
        $this->helper->setAddedGroups($addedGroups);

        return $recordsToSave;
    }

    /**
     * Migrate eav_attribute
     * @return void
     */
    protected function migrateAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapInterface::TYPE_SOURCE)
        );
        $this->destination->backupDocument($destinationDocument->getName());
        $sourceRecords = $this->source->getRecords($sourceDocName, 0, $this->source->getRecordsCount($sourceDocName));
        foreach (array_keys($this->readerAttributes->getGroup('ignore')) as $attributeToClear) {
            $sourceRecords = $this->clearIgnoredAttributes($sourceRecords, $attributeToClear);
        }
        $destinationRecords = $this->initialData->getAttributes('dest');

        $recordsToSave = $destinationDocument->getRecords();
        foreach ($sourceRecords as $sourceRecordData) {
            /** @var Record $sourceRecord */
            $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $sourceRecordData]);
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

            $entityTypeMappedId = $this->getEntityTypeIdMappedByCode($sourceRecord->getValue('entity_type_id'), true);
            $mappingValue = $entityTypeMappedId . '-' . $sourceRecord->getValue('attribute_code');
            if (isset($destinationRecords[$mappingValue])) {
                $destinationRecordData = $destinationRecords[$mappingValue];
                unset($destinationRecords[$mappingValue]);
            } else {
                $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
            }
            $destinationRecord->setData($destinationRecordData);

            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($destinationRecords as $record) {
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $destinationRecord->setValue('attribute_id', null);
            $destinationRecord->setValue(
                'entity_type_id',
                $this->getEntityTypeIdMappedByCode($destinationRecord->getValue('entity_type_id'), false)
            );
            $recordsToSave->addRecord($destinationRecord);
        }
        $this->destination->clearDocument($destinationDocument->getName());
        $this->saveRecords($destinationDocument, $recordsToSave);
        $this->loadNewAttributes();
    }

    /**
     * Migrate eav_entity_attributes
     *
     * @return void
     */
    protected function migrateEntityAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_entity_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapInterface::TYPE_SOURCE)
        );
        $this->destination->backupDocument($destinationDocument->getName());
        $recordsToSave = $destinationDocument->getRecords();
        foreach ($this->helper->getSourceRecords($sourceDocName) as $sourceRecordData) {
            $sourceRecord = $this->factory->create([
                'document' => $sourceDocument,
                'data' => $sourceRecordData
            ]);
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($this->helper->getDestinationRecords('eav_entity_attribute') as $record) {
            if (!isset($this->destAttributeOldNewMap[$record['attribute_id']])
                || !isset($this->destAttributeSetsOldNewMap[$record['attribute_set_id']])
                || !isset($this->destAttributeGroupsOldNewMap[$record['attribute_group_id']])
            ) {
                continue;
            }
            $record['attribute_id'] = $this->destAttributeOldNewMap[$record['attribute_id']];
            $record['attribute_set_id'] = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']];
            $record['attribute_group_id'] = $this->destAttributeGroupsOldNewMap[$record['attribute_group_id']];

            $record['entity_attribute_id'] = null;
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $recordsToSave->addRecord($destinationRecord);
        }

        $recordsToSave = $this->processDesignEntityAttributes($recordsToSave);
        $recordsToSave = $this->moveAttributes($recordsToSave);

        $this->destination->clearDocument($destinationDocument->getName());
        $this->saveRecords($destinationDocument, $recordsToSave);
    }

    /**
     * Move some fields to other attribute groups
     *
     * @param Record\Collection $recordsToSave
     * @return Record\Collection
     */
    private function moveAttributes($recordsToSave)
    {
        $this->moveAttributeToGroup($recordsToSave, 'price', 'product-details');
        $this->moveAttributeToGroup($recordsToSave, 'shipment_type', 'bundle-items');
        $this->addAttributeToGroup($recordsToSave, 'quantity_and_stock_status', 'product-details');
        return $recordsToSave;
    }

    /**
     * Move attribute to other attribute group
     *
     * @param Record\Collection $recordsToSave
     * @param string $attributeCode
     * @param string $attributeGroupCode
     * @return Record\Collection
     */
    private function moveAttributeToGroup($recordsToSave, $attributeCode, $attributeGroupCode)
    {
        $attributes = $this->helper->getDestinationRecords('eav_attribute', ['attribute_id']);
        $attributeGroups = $this->helper->getDestinationRecords('eav_attribute_group', ['attribute_group_id']);
        $attributeSetGroups = [];
        foreach ($attributeGroups as $attributeGroup) {
            if ($attributeGroup['attribute_group_code'] == $attributeGroupCode) {
                $attributeSetGroups[$attributeGroup['attribute_set_id']][$attributeGroupCode] =
                    $attributeGroup['attribute_group_id'];
            }
        }
        foreach ($recordsToSave as $record) {
            $attributeId = $record->getValue('attribute_id');
            if (!isset($attributes[$attributeId])) {
                continue;
            }
            if ($attributes[$attributeId]['attribute_code'] == $attributeCode) {
                $record->setValue(
                    'attribute_group_id',
                    $attributeSetGroups[$record->getValue('attribute_set_id')][$attributeGroupCode]
                );
            }
        }
        return $recordsToSave;
    }

    /**
     * Add attribute to attribute group
     *
     * @param Record\Collection $recordsToSave
     * @param string $attributeCode
     * @param string $attributeGroupCode
     * @return Record\Collection
     */
    private function addAttributeToGroup($recordsToSave, $attributeCode, $attributeGroupCode)
    {
        $attributes = $this->helper->getDestinationRecords('eav_attribute', ['attribute_id']);
        $attributeGroups = $this->helper->getDestinationRecords('eav_attribute_group', ['attribute_group_id']);
        $attributeSetGroups = [];
        foreach ($attributeGroups as $attributeGroup) {
            if ($attributeGroup['attribute_group_code'] == $attributeGroupCode) {
                $attributeSetGroups[$attributeGroup['attribute_set_id']][$attributeGroupCode] =
                    $attributeGroup['attribute_group_id'];
            }
        }
        $attribute = null;
        foreach ($recordsToSave as $record) {
            $attributeId = $record->getValue('attribute_id');
            if (!isset($attributes[$attributeId])) {
                continue;
            }
            if ($attributes[$attributeId]['attribute_code'] == $attributeCode) {
                $attributeSetGroups[$record->getValue('attribute_set_id')][$attributeCode] =
                    $record->getValue('attribute_group_id');
                $attribute = $record->getData();
            }
        }
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap('eav_entity_attribute', MapInterface::TYPE_SOURCE)
        );
        foreach ($attributeSetGroups as $attributeSetId => $attributeSetGroup) {
            if (!isset($attributeSetGroup[$attributeCode])) {
                $attribute['attribute_set_id'] = $attributeSetId;
                $attribute['attribute_group_id'] = $attributeSetGroup[$attributeGroupCode];
                $attribute['entity_attribute_id'] = null;
                $destinationRecord = $this->factory->create(
                    [
                        'document' => $destinationDocument,
                        'data' => $attribute
                    ]
                );
                $recordsToSave->addRecord($destinationRecord);
            }
        }

        return $recordsToSave;
    }

    /**
     * Move design attributes to schedule-design-update attribute groups
     *
     * @param Record\Collection $recordsToSave
     * @return Record\Collection
     * @throws \Migration\Exception
     */
    private function processDesignEntityAttributes($recordsToSave)
    {
        $entityTypeIdCatalogProduct = 0;
        foreach ($this->helper->getDestinationRecords('eav_entity_type') as $record) {
            if ('catalog_product' == $record['entity_type_code']) {
                $entityTypeIdCatalogProduct = $record['entity_type_id'];
                break;
            }
        }
        $entityTypeIdCatalogProductMapped = $this->getEntityTypeIdMappedByCode($entityTypeIdCatalogProduct, false);
        $data = $this->helper->getDesignAttributeAndGroupsData(
            $entityTypeIdCatalogProduct,
            $entityTypeIdCatalogProductMapped
        );

        $entityAttributeDocument = $this->destination->getDocument(
            $this->map->getDocumentMap('eav_entity_attribute', MapInterface::TYPE_SOURCE)
        );
        $recordsToSaveFiltered = $entityAttributeDocument->getRecords();
        foreach ($recordsToSave as $record) {
            /** @var Record $record */
            if (in_array($record->getValue('attribute_set_id'), $data['catalogProductSetIdsMigrated']) &&
                $record->getValue('attribute_id') == $data['customDesignAttributeId']
            ) {
                continue;
            }
            $recordsToSaveFiltered->addRecord($record);
        }
        $recordsToSave = $recordsToSaveFiltered;

        foreach ($data['scheduleGroupsMigrated'] as $group) {
            if (isset($data['customDesignAttributeId']) && $data['customDesignAttributeId']) {
                $dataRecord = [
                    'entity_attribute_id' => null,
                    'entity_type_id' => $entityTypeIdCatalogProductMapped,
                    'attribute_set_id' => $group['attribute_set_id'],
                    'attribute_group_id' => $group['attribute_group_id'],
                    'attribute_id' => $data['customDesignAttributeId'],
                    'sort_order' => 40,
                ];
                $destinationRecord = $this->factory->create([
                    'document' => $entityAttributeDocument,
                    'data' => $dataRecord
                ]);
                /** Adding custom_design */
                $recordsToSave->addRecord($destinationRecord);
            }

            if (isset($data['customLayoutAttributeId']) && $data['customLayoutAttributeId']) {
                $dataRecord = [
                    'entity_attribute_id' => null,
                    'entity_type_id' => $entityTypeIdCatalogProductMapped,
                    'attribute_set_id' => $group['attribute_set_id'],
                    'attribute_group_id' => $group['attribute_group_id'],
                    'attribute_id' => $data['customLayoutAttributeId'],
                    'sort_order' => 50,
                ];
                $destinationRecord = $this->factory->create([
                    'document' => $entityAttributeDocument,
                    'data' => $dataRecord
                ]);
                /** Adding custom_layout */
                $recordsToSave->addRecord($destinationRecord);
            }
        }

        return $recordsToSave;
    }

    /**
     * Migrate EAV tables which in result must have all unique records from both source and destination documents
     * @return void
     */
    protected function migrateMappedTables()
    {
        $documents = $this->readerGroups->getGroup('mapped_documents');

        foreach ($documents as $documentName => $mappingFields) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapInterface::TYPE_SOURCE)
            );
            $this->destination->backupDocument($destinationDocument->getName());
            $mappingFields = explode(',', $mappingFields);
            $destinationRecords = $this->helper->getDestinationRecords($documentName, $mappingFields);
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($this->helper->getSourceRecords($documentName) as $recordData) {
                /** @var Record $sourceRecord */
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                /** @var Record $destinationRecord */
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

                $mappingValue = $this->getMappingValue($sourceRecord, $mappingFields);
                if (isset($destinationRecords[$mappingValue])) {
                    $destinationRecordData = $destinationRecords[$mappingValue];
                    unset($destinationRecords[$mappingValue]);
                } else {
                    $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
                }
                $destinationRecord->setData($destinationRecordData);

                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                    ->transform($sourceRecord, $destinationRecord);

                if ($documentName == 'eav_entity_type') {
                    $oldAttributeSetValue = $destinationRecord->getValue('default_attribute_set_id');
                    if (isset($this->destAttributeSetsOldNewMap[$oldAttributeSetValue])) {
                        $destinationRecord->setValue(
                            'default_attribute_set_id',
                            $this->destAttributeSetsOldNewMap[$oldAttributeSetValue]
                        );
                    }
                }

                $recordsToSave->addRecord($destinationRecord);
            }
            $this->destination->clearDocument($destinationDocument->getName());
            $this->saveRecords($destinationDocument, $recordsToSave);

            $recordsToSave = $destinationDocument->getRecords();
            if ($mappingFields) {
                foreach ($destinationRecords as $record) {
                    $destinationRecord = $this->factory->create([
                        'document' => $destinationDocument,
                        'data' => $record
                    ]);
                    if (isset($record['attribute_id'])
                        && isset($this->destAttributeOldNewMap[$record['attribute_id']])
                    ) {
                        $destinationRecord->setValue(
                            'attribute_id',
                            $this->destAttributeOldNewMap[$record['attribute_id']]
                        );
                    }
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            $this->saveRecords($destinationDocument, $recordsToSave);
        }
    }

    /**
     * @param Document $document
     * @param Record\Collection $recordsToSave
     * @return void
     */
    protected function saveRecords(Document $document, Record\Collection $recordsToSave)
    {
        $this->destination->saveRecords($document->getName(), $recordsToSave);
    }

    /**
     * @param Record $sourceRecord
     * @param array $keyFields
     * @return string
     */
    protected function getMappingValue(Record $sourceRecord, $keyFields)
    {
        $value = [];
        foreach ($keyFields as $field) {
            switch ($field) {
                case 'attribute_id':
                    $value[] =  $this->getDestinationAttributeId($sourceRecord->getValue($field));
                    break;
                default:
                    $value[] = $sourceRecord->getValue($field);
                    break;
            }
        }
        return implode('-', $value);
    }

    /**
     * Load migrated attribute sets data
     * @return void
     */
    protected function loadNewAttributeSets()
    {
        $this->newAttributeSets = $this->helper->getDestinationRecords(
            'eav_attribute_set',
            ['entity_type_id', 'attribute_set_name']
        );
        foreach ($this->initialData->getAttributeSets('dest') as $attributeSetId => $record) {
            $newAttributeSet = $this->newAttributeSets[$record['entity_type_id'] . '-' . $record['attribute_set_name']];
            $this->destAttributeSetsOldNewMap[$attributeSetId] = $newAttributeSet['attribute_set_id'];
        }
    }

    /**
     * Load migrated attribute groups data
     * @return void
     */
    protected function loadNewAttributeGroups()
    {
        $this->newAttributeGroups = $this->helper->getDestinationRecords(
            'eav_attribute_group',
            ['attribute_set_id', 'attribute_group_name']
        );
        foreach ($this->initialData->getAttributeGroups('dest') as $record) {
            $newKey = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']] . '-'
                . $record['attribute_group_name'];
            $newAttributeGroup = $this->newAttributeGroups[$newKey];
            $this->destAttributeGroupsOldNewMap[$record['attribute_group_id']] =
                $newAttributeGroup['attribute_group_id'];
        }
    }

    /**
     * Load migrated attributes data
     * @return array
     */
    protected function loadNewAttributes()
    {
        $this->newAttributes = $this->helper->getDestinationRecords(
            'eav_attribute',
            ['entity_type_id', 'attribute_code']
        );
        foreach ($this->initialData->getAttributes('dest') as $key => $attributeData) {
            list($entityTypeId, $attributeCode) = explode('-', $key);
            $key = $this->getEntityTypeIdMappedByCode($entityTypeId, false) . '-' . $attributeCode;
            $this->destAttributeOldNewMap[$attributeData['attribute_id']] = $this->newAttributes[$key]['attribute_id'];
        }

        return $this->newAttributes;
    }

    /**
     * Returns destination entity type id for correspondent source entity type id and vise versa
     * Linking between two values is performed using entity_type_code
     * @param int|string $initialEntityTypeId
     * @param bool $returnDestId
     * @return mixed
     */
    protected function getEntityTypeIdMappedByCode($initialEntityTypeId, $returnDestId = true)
    {
        $id = $initialEntityTypeId;
        $entityTypeIdsFrom =
            $this->initialData->getEntityTypesWithKeyField($returnDestId ? 'source' : 'dest', 'entity_type_id');
        $entityTypeCodesTo =
            $this->initialData->getEntityTypesWithKeyField($returnDestId ? 'dest' : 'source', 'entity_type_code');

        if (isset($entityTypeIdsFrom[$initialEntityTypeId])) {
            $entityTypeCode = $entityTypeIdsFrom[$initialEntityTypeId]['entity_type_code'];
            if (isset($entityTypeCodesTo[$entityTypeCode])) {
                $id = $entityTypeCodesTo[$entityTypeCode]['entity_type_id'];
            }
        }

        return $id;
    }

    /**
     * @param int $sourceAttributeId
     * @return mixed
     */
    protected function getDestinationAttributeId($sourceAttributeId)
    {
        $id = null;
        $key = null;
        if (isset($this->initialData->getAttributes('source')[$sourceAttributeId])) {
            $entityTypeId = $this->getEntityTypeIdMappedByCode(
                $this->initialData->getAttributes('source')[$sourceAttributeId]['entity_type_id'],
                true
            );
            $key = $entityTypeId . '-'
                . $this->initialData->getAttributes('source')[$sourceAttributeId]['attribute_code'];
        }

        if ($key && isset($this->initialData->getAttributes('dest')[$key])) {
            $id = $this->initialData->getAttributes('dest')[$key]['attribute_id'];
        }

        return $id;
    }

    /**
     * @return int
     */
    public function getIterationsCount()
    {
        return count($this->readerGroups->getGroup('documents'));
    }

    /**
     * Rollback backed up documents
     * @return void
     */
    public function rollback()
    {
        foreach (array_keys($this->readerGroups->getGroup('documents')) as $documentName) {
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapInterface::TYPE_SOURCE)
            );
            if ($destinationDocument !== false) {
                $this->destination->rollbackDocument($destinationDocument->getName());
            }
        }
    }

    /**
     * Remove ignored attributes from source records
     *
     * @param array $sourceRecords
     * @param array $attributeToClear
     * @return array
     */
    protected function clearIgnoredAttributes($sourceRecords, $attributeToClear)
    {
        foreach ($sourceRecords as $attrNum => $sourceAttribute) {
            if ($sourceAttribute['attribute_code'] == $attributeToClear) {
                unset($sourceRecords[$attrNum]);
            }
        }
        return $sourceRecords;
    }
    // @codeCoverageIgnoreEnd
}
