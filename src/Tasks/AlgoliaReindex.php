<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Map;
use SilverStripe\Versioned\Versioned;
use Throwable;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

/**
 * Bulk reindex all objects. Note that this should be run via cli, if you can,
 * use the queuedjobs version `AlgoliaReindexAllJob`
 */
class AlgoliaReindex extends BuildTask
{
    protected $title = 'Algolia Reindex';

    protected $description = 'Algolia Reindex';

    private static $segment = 'AlgoliaReindex';

    private static $batch_size = 20;

    /**
     * An optional array of default filters to apply when doing the reindex
     * i.e for indexing Page subclasses you may wish to exclude expired pages.
     *
     * @config
     */
    private static $reindexing_default_filters = [];

    protected $errors = [];

    public function run($request)
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        $targetClass = '';
        $filter = '';
        $defaultFilters = $this->config()->get('reindexing_default_filters');

        if ($request->getVar('onlyClass')) {
            $targetClass = $request->getVar('onlyClass');

            if ($defaultFilters && isset($defaultFilters[$targetClass])) {
                $filter = $defaultFilters[$targetClass];
            }
        }

        if ($request->getVar('filter')) {
            $filter = $request->getVar('filter');
        }

        if (!$request->getVar('forceAll') && !$filter) {
            $filter = 'AlgoliaIndexed IS NULL';
        }

        /** @var AlgoliaService */
        $algoliaService = Injector::inst()->create(AlgoliaService::class);

        if ($request->getVar('clearAll')) {
            $indexes = $algoliaService->initIndexes();

            foreach ($indexes as $indexName => $index) {
                $index->clearObjects();
            }
        }

        // find all classes we have to index and do so
        foreach ($algoliaService->indexes as $indexName => $index) {
            echo 'Updating index ' . $indexName . PHP_EOL;

            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;
            $indexFilters = (isset($index['includeFilter'])) ? $index['includeFilter'] : [];

            if ($classes) {
                foreach ($classes as $candidate) {
                    if ($targetClass && $targetClass !== $candidate) {
                        continue;
                    }


                    $items = $this->getItems($candidate, $filter, $indexFilters);
                    echo sprintf(
                        '| Found %s %s remaining to index which match filter (%s)%s',
                        $items->count(),
                        $targetClass,
                        implode(',', array_merge([$filter], [$indexFilters[$candidate] ?? ''])),
                        PHP_EOL
                    );

                    if ($items->exists()) {
                        $this->indexItems($indexName, $items);
                    }
                }
            }
        }


        echo 'Done';
    }


    /**
     * @param string $targetClass
     * @param string $filter
     * @param string[] $indexFilters
     *
     * @return \SilverStripe\ORM\DataList
     */
    public function getItems($targetClass, $filter = '', $indexFilters = [])
    {
        $inst = $targetClass::create();

        if ($inst->hasExtension(Versioned::class)) {
            $items = Versioned::get_by_stage($targetClass, 'Live', $filter);
        } else {
            $items = $inst::get();

            if ($filter) {
                $items = $items->where($filter);
            }
        }

        if (isset($indexFilters[$targetClass])) {
            $items = $items->where($indexFilters[$targetClass]);
        }

        $items = $items->setDataQueryParam('Subsite.filter', false);

        return $items;
    }


    /**
     * @param DataObject $obj
     *
     * @return bool
     */
    public function indexItem($obj = null): bool
    {
        if (!$obj) {
            return false;
        } elseif (min($obj->invokeWithExtensions('canIndexInAlgolia')) === false) {
            return false;
        } else {
            if (!$obj->AlgoliaUUID) {
                $obj->assignAlgoliaUUID();
            }

            if ($obj->doImmediateIndexInAlgolia()) {
                return true;
            } else {
                return false;
            }
        }
    }


    /**
     * @param string $indexName
     * @param DataList? $items
     * @param bool $output;
     *
     * @return bool|string
     */
    public function indexItems($indexName, $items = null, $output = true)
    {
        $algoliaService = Injector::inst()->get(AlgoliaService::class);
        $count = 0;
        $skipped = 0;
        $total = ($items) ? $items->count() : 0;
        $batchSize = $this->config()->get('batch_size') ?? 25;
        $batchesTotal = ($total > 0) ? (ceil($total / $batchSize)) : 0;
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $pos = 0;

        if ($total < 1) {
            return false;
        }

        $currentBatches = [];

        for ($i = 0; $i < $batchesTotal; $i++) {
            $limitedSize = $items->sort('ID', 'DESC')->limit($batchSize, $i * $batchSize);

            foreach ($limitedSize as $item) {
                $pos++;

                if ($output) {
                    echo '.';

                    if ($pos % 50 == 0) {
                        echo sprintf(' [%s/%s]%s', $pos, $total, PHP_EOL);
                    }
                }

                // fetch the actual instance
                $instance = DataObject::get_by_id($item->ClassName, $item->ID);

                if (!$instance || min($instance->invokeWithExtensions('canIndexInAlgolia')) == false) {
                    $skipped++;

                    continue;
                }

                // Set AlgoliaUUID, in case it wasn't previously set
                if (!$item->AlgoliaUUID) {
                    $item->assignAlgoliaUUID();
                }

                $batchKey = get_class($item);

                if (!isset($currentBatches[$batchKey])) {
                    $currentBatches[$batchKey] = [];
                }

                try {
                    $data = $indexer->exportAttributesFromObject($item);

                    if ($data instanceof Map) {
                        $data = $data->toArray();
                    }

                    $currentBatches[$batchKey][] = $data;
                    $item->touchAlgoliaIndexedDate();
                    $count++;
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->warning($e->getMessage());
                }

                if (count($currentBatches[$batchKey]) >= $batchSize) {
                    $this->indexBatch($indexName, $currentBatches[$batchKey]);

                    unset($currentBatches[$batchKey]);
                }

                if ($output) {
                    sleep(1);
                }
            }
        }

        foreach ($currentBatches as $class => $records) {
            if (count($currentBatches[$class]) > 0) {
                $this->indexBatch($indexName, $currentBatches[$class]);

                if ($output) {
                    sleep(1);
                }
            }
        }

        $summary = sprintf(
            "%s| Number of objects indexed in %s: %s, Skipped %s",
            PHP_EOL,
            $indexName,
            $count,
            $skipped
        );

        if ($output) {
            echo $summary;

            echo sprintf(
                "%s| See index at <a href='https://www.algolia.com/apps/%s/explorer/indices' target='_blank'>" .
                    "algolia.com/apps/%s/explorer/indices</a>%s---%s",
                PHP_EOL,
                $algoliaService->applicationId,
                $algoliaService->applicationId,
                PHP_EOL,
                PHP_EOL
            );
        }

        return $summary;
    }

    /**
     * Index a batch of changes
     *
     * @param array $items
     *
     * @return bool
     */
    public function indexBatch($indexName, $items): bool
    {
        $service = Injector::inst()->create(AlgoliaService::class);
        $index = $service->getIndexByName($indexName);

        try {
            $result = $index->saveObjects($items, [
                'autoGenerateObjectIDIfNotExist' => true
            ]);

            if (!$result->valid()) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);

            if (Director::isDev()) {
                Debug::message($e->getMessage());
            }

            $this->errors[] = $e->getMessage();

            return false;
        }
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return $this
     */
    public function clearErrors()
    {
        $this->errors = [];

        return $this;
    }
}
