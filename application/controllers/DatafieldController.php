<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Forms\DirectorDatafieldForm;
use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Objects\DirectorDatalist;

class DatafieldController extends ActionController
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


    public function addAction()
    {
        $this->indexAction();
    }

    public function editAction()
    {
        $this->indexAction();
    }

    public function indexAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }

        $form = DirectorDatafieldForm::load()
            ->setDb($this->db());

        if ($id = $this->params->get('id')) {
            $form->loadObject((int) $id);
            $this->addTitle(
                $this->translate('Modify %s'),
                $form->getObject()->varname
            );
            $this->addSingleTab($this->translate('Edit a Field'));
        } else {
            $this->addTitle($this->translate('Add a new Data Field'));
            $this->addSingleTab($this->translate('New Field'));
        }

        $form->handleRequest();
        $this->content()->add($form);
    }


    protected function loadObject()
    {
        #Note: assuming varname is unique for API purposes, but this does seem to be enforced by the database
        if (!$this->getRequest()->getParam('name')) {
            return;
        }
        $query = $this->db()->getDbAdapter()
            ->select()
            ->from('director_datafield')
            ->where('varname = ?', $this->getRequest()->getParam('name'));

        $result = DirectorDatafield::loadAll($this->db(), $query);
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
                $this->sendJson($response,$props);
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
                if (!empty($data['object_name'])) {
                    $data['varname'] = $data['object_name'];
                    unset($data['object_name']);
                }

                if (!empty($data['datalist_name'])) {
                    $query = $this->db()->getDbAdapter()
                        ->select()
                        ->from('director_datalist')
                       ->where('list_name = ?', $data['datalist_name']);

                    $result = DirectorDatalist::loadAll($this->db(), $query);
                    if (!count($result)) {
                        $response->setHttpResponseCode(400);
                        throw new IcingaException('Got invalid datalist_name: '.$data['datalist_name']);
                    } else {
                        $datalist = current($result);
                    }
                }

                $target_data_type = false;
                if (array_key_exists( 'target_data_type', $data )) {
                    $target_data_type=$data['target_data_type'];
                }

                unset($data['object_type']);

                $modified=false;

                if ($object = $this->object) {
                    $old_props = $this->restProps($object);

                    if (isset($datalist)) {
                        if (isset($old_props['datalist_name'])){
                            $modified = $datalist->list_name != $old_props['datalist_name'];
                        } else {
                            $modified = true;
                        }
                    }
                    foreach(array('target_data_type', 'icinga_object_type','query','resource') as $check_setting) {
                        if (!empty($data[$check_setting])) {
                            if (isset($old_props[$data[$check_setting]])){
                                $modified = $data[$check_setting] != $old_props[$check_setting];
                            } else {
                                $modified = true;
                            }
                        }
                    }

                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge(array('varname' => $object->get('varname'), 'uuid' => $object->get('uuid')),$data);
                        $tmp = DirectorDatafield::create($data, $db);
                        $tmp->setProperties($data);
			$replacement = array_merge($tmp->getProperties(),$data);
                        unset($replacement['id']);
                        $object->setProperties($replacement);
                    }
                } else {
                    if (empty($data['varname'])) {
                        $response->setHttpResponseCode(400);
                        throw new IcingaException('Must specifiy object_name');
                    }

                    //The API will not allow duplicate varnames
                    $newname=$data['varname'];
                    $query = $this->db()->getDbAdapter()
                        ->select()
                        ->from('director_datafield')
                        ->where('varname = ?', $newname);

                    $result = DirectorDatafield::loadAll($this->db(), $query);
                    if (count($result)) {
                        throw new IcingaException('Trying to recreate "%s"',$newname);
                    }

                    $object = DirectorDatafield::create($data, $db);
                }
                if (isset($datalist)) {
                    if (!preg_match('/DataTypeDatalist$/',$object->get('datatype'))) {
                        $object->set('datalist_id','');
                    } else {
                        $object->set('datalist_id', $datalist->id);
                    }
                }

                if ( $target_data_type !== false) {
                    $object->set('data_type', $target_data_type);
                } else {
                    $object->set('data_type','');
                }

                if ($object->hasBeenModified() || $modified) {
                    $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
                    $object->store();
                    $response->setHttpResponseCode($status);
                } else {
                    $response->setHttpResponseCode(304);
                }

                return $this->sendJson($response,$this->restProps($object));


            case 'DELETE':
                $this->requireObject();
                $this->object->delete();
                $response->setHttpResponseCode(200);
                $this->sendJson($response,array('message' => 'object deleted.'));
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
        $props=$obj->getProperties();
        $props['object_name']=$props['varname'];
        $props['object_type']='template';
        foreach(array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key, array('id','varname'))) {
                unset($props[$key]);
            }
        }
        if ($obj->getSetting('datalist_id')) {
            $datalist = DirectorDatalist::loadWithAutoIncId($obj->getSetting('datalist_id'),$this->db());
            $props['datalist_name']=$datalist->list_name;
        }
        if ($obj->getSetting('data_type')) {
            $props['target_data_type']=$obj->getSetting('data_type');
        }
        foreach (array('icinga_object_type','query','resource') as $standard_prop) {
            if ($obj->getSetting($standard_prop)) {
                $props[$standard_prop]=$obj->getSetting($standard_prop);
            }
        }

        return($props);
    }

}
