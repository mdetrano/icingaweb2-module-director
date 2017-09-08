<?php

namespace Icinga\Module\Director\RestApi;

use Exception;
use Icinga\Application\Benchmark;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Director\Db\Cache\PrefetchCache;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Web\Table\ObjectsTable;
use Zend_Db_Select as ZfSelect;

class IcingaObjectsHandler extends RequestHandler
{
    /** @var ObjectsTable */
    protected $table;

    public function processApiRequest()
    {
        try {
            $this->streamJsonResult();
        } catch (Exception $e) {
            // NONO
            $this->sendJsonError($e->getTraceAsString());
        }
    }

    public function setTable(ObjectsTable $table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @return ObjectsTable
     * @throws ProgrammingError
     */
    protected function getTable()
    {
        if ($this->table === null) {
            throw new ProgrammingError('Table is required');
        }

        return $this->table;
    }

    protected function streamJsonResult()
    {
        $connection = $this->db;
        Benchmark::measure('aha');
        $db = $connection->getDbAdapter();
        $table = $this->getTable();
        $query = $table
            ->getQuery()
            ->reset(ZfSelect::COLUMNS)
            ->columns('*')
            ->reset(ZfSelect::LIMIT_COUNT)
            ->reset(ZfSelect::LIMIT_OFFSET);

        echo '{ "objects": [ ';
        $cnt = 0;
        $objects = [];

        $dummy = IcingaObject::createByType($table->getType(), [], $connection);
        $dummy->prefetchAllRelatedTypes();

        Benchmark::measure('Prefetching');
        PrefetchCache::initialize($this->db);
        Benchmark::measure('Ready to query');
        $stmt = $db->query($query);
        $this->response->sendHeaders();
        if (! ob_get_level()) {
            ob_start();
        }
        $params = $this->request->getUrl()->getParams();
        $resolved = (bool) $params->get('resolved', false);
        $withNull = ! $params->shift('withNull');
        $properties = $params->shift('properties');
        if (strlen($properties)) {
            $properties = preg_split('/\s*,\s*/', $properties, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $properties = null;
        }

        $first = true;
        $flushes = 0;
        while ($row = $stmt->fetch()) {
            /** @var IcingaObject $object */
            if ($first) {
                Benchmark::measure('First row');
            }
            $object = $dummy::fromDbRow($row, $connection);
            $objects[] = json_encode($object->toPlainObject(
                $resolved,
                $withNull,
                $properties
            ), JSON_PRETTY_PRINT);
            if ($first) {
                Benchmark::measure('Got first row');
                $first = false;
            }
            $cnt++;
            if ($cnt === 100) {
                if ($flushes > 0) {
                    echo ', ';
                }
                echo implode(', ', $objects);
                $cnt = 0;
                $objects = [];
                $flushes++;
                ob_end_flush();
                ob_start();
            }
        }

        if ($cnt > 0) {
            if ($flushes > 0) {
                echo ', ';
            }
            echo implode(', ', $objects);
        }

        if ($params->get('benchmark')) {
            echo "],\n";
            Benchmark::measure('All done');
            echo '"benchmark_string": ' . json_encode(Benchmark::renderToText());
        } else {
            echo '] ';
        }

        echo "}\n";
        if (ob_get_level()) {
            ob_end_flush();
        }

        // TODO: can we improve this?
        exit;
    }
}
