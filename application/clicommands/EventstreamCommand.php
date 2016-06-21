<?php

namespace Icinga\Module\Director\Clicommands;

use Exception;
use Icinga\Module\Director\Cli\DdoCommand;
use Icinga\Module\Director\Ddo\StateList;
use Icinga\Module\Director\Redis;

class EventstreamCommand extends DdoCommand
{
    public function streamAction()
    {
        while (true) {
            try {
                $this->api()->onEvent(array($this, 'processEvent'))->stream();
            } catch (Exception $e) {
                echo "ERROR: " . $e->getMessage() . "\n";
            }
            echo "Waiting 5 seconds for reconnect\n";
            sleep(5);
        }
    }

    public function processEvent($event)
    {
        $redis = Redis::instance();
        printf(
            "Stored id %d\n",
            $redis->lpush('icinga2::events', json_encode($event))
        );
    }

    public function processStatesAction()
    {
        $redis = Redis::instance();
        $time = time();
        $cnt = 0;
        $hasTransaction = false;
        $ddo = $this->ddo();
        $db = $ddo->getDbAdapter();
        $list = new StateList($ddo);

        // TODO: 0 is forever, leave loop after a few sec and enter again
        while (true) {

            while ($res = $redis->brpop('icinga2::events', 1)) {
                // res = array(queuename, value)
                $object = $list->processCheckResult(json_decode($res[1]));

                if ($object->hasBeenModified()) {
                    printf("%s has been modified\n", $object->getUniqueName());

                    if (! $hasTransaction) {
                        $db->beginTransaction();
                        $hasTransaction = true;
                    }
                    $cnt++;
                    $object->store();
                } else {
                    // printf("%s has not been modified\n", $object->getUniqueName());
                }

                if (($cnt >= 1000)
                    || ($cnt > 0 && (($newtime = time()) - $time > 1))
                ) {
                    $time = $newtime;
                    echo "Committing $cnt events\n";
                    $cnt = 0;
                    $db->commit();
                    $hasTransaction = false;
                }
            }

            // echo "Got nothing for 1secs\n";

            if ($cnt > 0) {
                $time = time();
                echo "Committing $cnt events\n";
                $cnt = 0;
                $db->commit();
                $hasTransaction = false;
            }
        }
    }

    public function storeEventsAction()
    {
        $redis = Redis::instance();
        $time = time();
        $cnt = 0;
        $hasTransaction = false;
        $db = $this->db()->getDbAdapter();
        // TODO: 0 is forever, leave loop after a few sec and enter again
        while (true) {

            while ($res = $redis->brpop('icinga2::events', 3)) {
                $cnt++;
                if (! $hasTransaction) {
                    $db->beginTransaction();
                    $hasTransaction = true;
                }
                // res = array(queuename, value)
                $this->storeEvent(json_decode($res[1]));

                if (($cnt >= 1000)
                    || (($newtime = time()) - $time > 1)
                ) {
                    $time = $newtime;
                    echo "Committing $cnt events\n";
                    $cnt = 0;
                    $db->commit();
                    $hasTransaction = false;
                }
            }
            echo "Got nothing for 3secs\n";
        }

    }

    public function testAction()
    {
        $db = $this->db()->getDbAdapter();
        $query = $db->select()->from(
            'icinga_host',
            array('object_name', 'id')
        );

        $hostIdx = $db->fetchPairs($query);
        print_r($hostIdx);
    }

    protected function storeEvent($event)
    {
        $db = $this->db()->getDbAdapter();
        if ($event->type !== 'CheckResult' && $event->type !== 'StateChange') {
            printf('Expected a CheckResult, got %s', $event->type);
            print_r($event);
            return;
        }

        $res = $event->check_result;
        $mil = 1000000;

        $entry = array(
            'host'              => $event->host,
            'active'            => $res->active,
            'timestamp'         => (int) ($event->timestamp * $mil),
            'check_source'      => $res->check_source,
            'command'           => json_encode($res->command),
            'execution_start'   => (int) ($res->execution_end * $mil),
            'execution_end'     => (int) ($res->execution_end * $mil),
            'schedule_start'    => (int) ($res->execution_end * $mil),
            'schedule_end'      => (int) ($res->execution_end * $mil),
            'output'            => $res->output,
            'performance_data'  => json_encode($res->performance_data),
            'state'             => $res->state,
            'exit_status'       => min($res->exit_status, 127), /// may be bigger, got 128
            'attempt_after'     => (int) $res->vars_after->attempt,
            // TODO: reachable is bool
            'reachable_after'   => (int) $res->vars_after->reachable,
            'state_after'       => (int) $res->vars_after->state,
            'state_type_after'  => (int) $res->vars_after->state_type,
        );

        if (is_object($res->vars_before)) {
            $entry['attempt_before'] = (int) $res->vars_before->attempt;
            $entry['reachable_before'] = (int) $res->vars_before->reachable;
            $entry['state_before'] = (int) $res->vars_before->state;
            $entry['state_type_before'] = (int) $res->vars_before->state_type;
        }

        if (property_exists($event, 'service')) {
            $entry['service'] = $event->service;
        }

        // TODO: state_change hast also $res->state_type. useless?
        $db->insert('object_checkresult_history', $entry);
        $diff = array_diff((array) $res->vars_before, (array) $res->vars_after);
        if (! empty($diff)) {
            $db->insert('object_state_history', $entry);
        }

    }
}
