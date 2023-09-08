<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatalist;
use Icinga\Module\Director\Objects\DirectorDatalistEntry;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Web\Table\DatalistEntryTable;

use Ramsey\Uuid\Uuid;

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
                return $this->sendJson($response, (object) array('error' => $e->getMessage()));
            } catch (Exception $e) {
                if ($response->getHttpResponseCode() === 200) {
                    $response->setHttpResponseCode(500);
                }

                return $this->sendJson($response, (object) array('error' => $e->getMessage()));
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
                $this->sendJson($response, $props);
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
                $modified=false;
                if (isset($data['entries'])) {
                    $entries=array();
                    foreach($data['entries'] as $e_key => $e_val) {
                        $entries[$e_key]=$e_val;
                    }
                    unset($data['entries']);
                }
                $data['owner']=$this->Auth()->getUser()->getUsername();
                if (isset($data['object_name'])) {
                    $data['list_name']=$data['object_name'];
                    unset($data['object_name']);
                }
                unset($data['object_type']);

                if ($object = $this->object) {
                    $old_props = $this->restProps($object);
                    if (isset($entries)) {
                        if (count($entries) != count($old_props['entries'])) {
                            $modified=true;
                        }
                        if (count(array_diff_assoc($old_props['entries'], $entries))) {
                            $modified=true;
                        }
                    }
                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge(array('list_name' => $object->get('list_name'), 'uuid' => $object->get('uuid')),$data);
                        $tmp = DirectorDatalist::create($data, $db);
                        $replacement = $tmp->getProperties();
                        unset($replacement['id']);
                        $object->setProperties($replacement);  
                        # for a PUT, remove all entries
                        $table = new DatalistEntryTable($this->db());
                        $table->setList($object);
                        //$table = $this->loadTable('datalistEntry')->setConnection($this->db())->setList($object);
                        foreach($table->fetch() as $entry) {
                            if ($dummy = DirectorDatalistEntry::load(array('list_id' => $object->id, 'entry_name' => $entry->entry_name), $db)) {
                                $dummy->delete();
                            }
                        }
                    }
                } else {
                    if (empty($data['list_name'])) {
                        $response->setHttpResponseCode(400);
                        throw new IcingaException('Must specifiy object_name');
                    }
                    $object = DirectorDatalist::create($data, $db);
                }
                if ($object->hasBeenModified() || $modified) {
                    $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
                    $object->store();
                    $response->setHttpResponseCode($status);
                } else {
                    $response->setHttpResponseCode(304);
                }

                if (isset($entries)) {
                    foreach($entries as $e_key => $e_val) {
                        $props=array('entry_name' => $e_key, 'list_id' => $object->id, 'entry_value' => $e_val, 'format' => 'string');
                        try {
                            $dummy = DirectorDatalistEntry::load(array('list_id' => $object->id, 'entry_name' => $e_key),$db);
                            $dummy->setProperties($props);
                            $dummy->store();
                        } catch (NotFoundError $e) {
                            $new_entry=DirectorDatalistEntry::create($props, $db);
                            $new_entry->store();
                        }
                    }
                }

                return $this->sendJson($response, $this->restProps($object));

 
            case 'DELETE':
                $this->requireObject();
                $this->object->delete();
                $response->setHttpResponseCode(200);
                $this->sendJson($response, array('message' => 'object deleted.'));
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
        $props['object_name']=$props['list_name'];
        $props['object_type']='template';
        foreach (array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key,array('id','owner','list_name'))) {
                unset($props[$key]);
	    }
	    if ($key == 'uuid') {
		    $props[$key] = Uuid::fromBytes($props[$key])->toString();
            }
        }
        $table = new DatalistEntryTable($this->db());
        $table->setList($obj);
        $entrys=array();
        foreach ($table->fetch() as $row) {
            $entrys[$row->entry_name]=$row->entry_value;
        }
        $props['entries']=$entrys;
        return($props);
    }

}
