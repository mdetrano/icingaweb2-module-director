<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatalist;
use Icinga\Module\Director\Objects\DirectorDatalistEntry;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\IcingaException;

class DatalistController extends ActionController
{
    protected $isApified = true;
    protected $object;

    public function init() {
        parent::init();
        if ($this->getRequest()->isApiRequest()) {
            $response = $this->getResponse();
            try {
                $this->loadObject();
                return $this->handleApiRequest();
            } catch (NotFoundError $e) {
                $response->setHttpResponseCode(404);
                return $this->sendJson((object) array('error' => $e->getMessage()));
            } catch (Exception $e) {
                if ($response->getHttpResponseCode() === 200) {
                    $response->setHttpResponseCode(500);
                }

                return $this->sendJson((object) array('error' => $e->getMessage()));
            }
        }
    }

    public function indexAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }
    }


    protected function loadObject()
    {
        if (!$this->getRequest()->getParam('name')) {
            return;
        }
        $query = $this->db()->getDbAdapter()
            ->select()
            ->from('director_datalist')
            ->where('list_name = ?', $this->getRequest()->getParam('name'));

        $result = DirectorDatalist::loadAll($this->db(), $query);
        if (!count($result)) {
            throw new NotFoundError('Got invalid name "%s"', $this->getRequest()->getParam('name'));
        }
        $this->object=current($result);
        return current($result);
    }

    protected function handleApiRequest() {
        $request = $this->getRequest();
        $response = $this->getResponse();
        $db = $this->db();

        switch ($request->getMethod()) {
            case 'GET':
                $this->requireObject();
                $props=$this->restProps($this->object);
                $this->sendJson($props);
                return;

            case 'PUT':
            case 'POST':
                $data = json_decode($request->getRawBody());

                if ($data === null) {
                    $this->getResponse()->setHttpResponseCode(400);
                    throw new IcingaException(
                        'Invalid JSON: %s' . $request->getRawBody(),
                        $this->getLastJsonError()
                    );
                } else {
                    $data = (array) $data;
                }

                $entries=null;
                $entries_modified=false;
                if (isset($data['entries'])) {
                    $entries=array();
                    foreach($data['entries'] as $e_key => $e_val) {
                        $entries[$e_key]=$e_val;
                    }
                    unset($data['entries']);
                }
                $data['owner']=$this->Auth()->getUser()->getUsername();

                if ($object = $this->object) {
                    if (isset($entries)) {
                        if (count($entries) && !count($this->restProps($this->object)['entries'])) {
                            $entries_modified=true;
                        }
                        if (count(array_diff_assoc($this->restProps($this->object)['entries'], $entries))) {
                            $entries_modified=true;
                        }
                    }
                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge(
                            $object->properties,
                            $data
                        );
                        $object->setProperties($data);
                    }
                } else {
                    if (empty($data['list_name'])) {
                        $response->setHttpResponseCode(400);
                        throw new IcingaException('Must specifiy list_name');
                    }

                    $object = DirectorDatalist::create($data, $db);
                }

                if (isset($entries)) {
                    # entries are erased and repopulated
                    $table = $this->loadTable('datalistEntry')->setConnection($this->db())->setList($object);
                    foreach($table->fetchData() as $entry) {
                        $dummy = DirectorDatalistEntry::load(array('list_id' => $object->id, 'entry_name' => $entry->entry_name), $db);
                        if ($dummy) {
                            $dummy->delete();
                        }
                    }

                    foreach($entries as $e_key => $e_val) {
                        $props=array('entry_name' => $e_key, 'entry_value' => $e_val, 'list_id' => $object->id, 'format' => 'string');
                        $new_entry=DirectorDatalistEntry::create($props, $db);
                        $new_entry->store();
                    }
                }

                if ($object->hasBeenModified() || $entries_modified) {
                    $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
                    $object->store();
                    $response->setHttpResponseCode($status);
                } else {
                    $response->setHttpResponseCode(304);
                }

                return $this->sendJson($this->restProps($object));

 
            case 'DELETE':
                $this->requireObject();
                $this->object->delete();
                $response->setHttpResponseCode(200);
                $this->sendJson(array('message' => 'object deleted.'));
                return;
        }
    }

    protected function requireObject()
    {
        if (! $this->object) {
            $this->getResponse()->setHttpResponseCode(404);
            if (! $this->params->get('name')) {
                throw new NotFoundError('You need to pass a "name" parameter to access a specific object');
            } else {
                throw new NotFoundError('No such object available');
            }
        }
    }

    protected function restProps($obj) {
        $props=$obj->properties;
        foreach (array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key,array('id','owner'))) {
                unset($props[$key]);
            }
        }
        $table = $this->loadTable('datalistEntry')->setConnection($this->db())->setList($obj);
        $entrys=array();
        foreach($table->fetchData() as $entry) {
            $entrys[$entry->entry_name]=$entry->entry_value;
        }
        $props['entries']=$entrys;
        return($props);
    }

}
