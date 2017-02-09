<?php

namespace Icinga\Module\Director\Web\Controller;

use Exception;
use Icinga\Exception\IcingaException;
use Icinga\Exception\InvalidPropertyException;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Director\Exception\NestingError;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Web\Form\DirectorObjectForm;
use Icinga\Module\Director\Objects\DirectorDatafield;

abstract class ObjectController extends ActionController
{
    protected $object;

    protected $isApified = true;

    protected $allowedExternals = array(
        'apiuser',
        'endpoint'
    );

    public function init()
    {
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

        $type = strtolower($this->getType());

        if ($object = $this->loadObject()) {
            $this->beforeTabs();
            $params = $object->getUrlParams();

            if ($object->isExternal()
                && ! in_array($object->getShortTableName(), $this->allowedExternals)
            ) {
                $tabs = $this->getTabs();
            } else {
                $tabs = $this->getTabs()->add('modify', array(
                    'url'       => sprintf('director/%s', $type),
                    'urlParams' => $params,
                    'label'     => $this->translate(ucfirst($type))
                ));
            }

            if ($this->hasPermission('director/showconfig')) {
                $tabs->add('render', array(
                    'url'       => sprintf('director/%s/render', $type),
                    'urlParams' => $params,
                    'label'     => $this->translate('Preview'),
                ));
            }

            if ($this->hasPermission('director/audit')) {
                $tabs->add('history', array(
                    'url'       => sprintf('director/%s/history', $type),
                    'urlParams' => $params,
                    'label'     => $this->translate('History')
                ));
            }


            if ($this->hasPermission('director/admin') && $this->hasFields()) {
                $tabs->add('fields', array(
                    'url'       => sprintf('director/%s/fields', $type),
                    'urlParams' => $params,
                    'label'     => $this->translate('Fields')
                ));
            }
        } else {
            $this->beforeTabs();
            $this->getTabs()->add('add', array(
                'url'       => sprintf('director/%s/add', $type),
                'label'     => sprintf($this->translate('Add %s'), ucfirst($type)),
            ));
        }
    }

    public function indexAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }

        if ($this->object
            && $this->object->isExternal()
            && ! in_array($this->object->getShortTableName(), $this->allowedExternals)
        ) {
            $this->redirectNow(
                $this->getRequest()->getUrl()->setPath(sprintf('director/%s/render', $this->getType()))
            );
        }

        return $this->editAction();
    }

    public function renderAction()
    {
        $this->assertPermission('director/showconfig');
        $type = $this->getType();
        $this->getTabs()->activate('render');
        $object = $this->object;
        $this->view->isExternal = $object->isExternal();

        if ($this->params->shift('resolved')) {
            $object = $object::fromPlainObject(
                $object->toPlainObject(true),
                $object->getConnection()
            );

            $this->view->actionLinks = $this->view->qlink(
                $this->translate('Show normal'),
                $this->getRequest()->getUrl()->without('resolved'),
                null,
                array('class' => 'icon-resize-small state-warning')
            );
        } else {
            try {
                if ($object->supportsImports() && $object->imports()->count() > 0) {
                    $this->view->actionLinks = $this->view->qlink(
                        $this->translate('Show resolved'),
                        $this->getRequest()->getUrl()->with('resolved', true),
                        null,
                        array('class' => 'icon-resize-full')
                    );
                }
            } catch (NestingError $e) {
                // No resolve link with nesting errors
            }
        }

        $this->view->object = $object;
        $this->view->config = $object->toSingleIcingaConfig();

        $this->view->title = sprintf(
            $this->translate('Config preview: %s'),
            $object->object_name
        );
        $this->setViewScript('object/show');
    }

    public function editAction()
    {
        $object = $this->object;
        $this->getTabs()->activate('modify');
        $ltype = $this->getType();
        $type = ucfirst($ltype);

        $formName = 'icinga' . $type;
        $this->view->form = $form = $this->loadForm($formName)
            ->setDb($this->db())
            ->setApi($this->getApiIfAvailable());
        $form->setObject($object);

        $this->view->title = $object->object_name;
        $this->view->form->handleRequest();

        $this->view->actionLinks = $this->createCloneLink();
        $this->setViewScript('object/form');
    }

    protected function createCloneLink()
    {
        return $this->view->qlink(
            $this->translate('Clone'),
            'director/' . $this->getType() .'/clone',
            $this->object->getUrlParams(),
            array('class' => 'icon-paste')
        );
    }

    public function addAction()
    {
        $this->getTabs()->activate('add');
        $type = $this->getType();
        $ltype = strtolower($type);

        $url = sprintf('director/%ss', $ltype);
        /** @var DirectorObjectForm $form */
        $form = $this->view->form = $this->loadForm('icinga' . ucfirst($type))
            ->setDb($this->db())
            ->presetImports($this->params->shift('imports'))
            ->setApi($this->getApiIfAvailable())
            ->setSuccessUrl($url);

        if ($type = $this->params->shift('type')) {
            $form->setPreferredObjectType($type);
        }

        if ($type === 'template') {
            $this->view->title = sprintf(
                $this->translate('Add new Icinga %s template'),
                ucfirst($ltype)
            );
        } else {
            $this->view->title = sprintf(
                $this->translate('Add new Icinga %s'),
                ucfirst($ltype)
            );
        }

        $this->beforeHandlingAddRequest($form);

        $form->handleRequest();
        $this->setViewScript('object/form');
    }

    protected function beforeHandlingAddRequest($form)
    {
    }

    public function cloneAction()
    {
        $type = $this->getType();
        $ltype = strtolower($type);
        $this->getTabs()->activate('modify');

        $this->view->form = $form = $this->loadForm(
            'icingaCloneObject'
        )->setObject($this->object);

        $this->view->title = sprintf(
            $this->translate('Clone Icinga %s'),
            ucfirst($type)
        );
        $this->view->form->handleRequest();

        $this->view->actionLinks = $this->view->qlink(
            $this->translate('back'),
            'director/' . $ltype,
            array('name'  => $this->object->object_name),
            array('class' => 'icon-left-big')
        );

        $this->setViewScript('object/form');
    }

    public function fieldsAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }

        $this->hasPermission('director/admin');
        $object = $this->object;
        $type = $this->getType();

        $this->getTabs()->activate('fields');

        $this->view->title = sprintf(
            $this->translate('Custom fields: %s'),
            $object->object_name
        );

        $form = $this->view->form = $this
            ->loadForm('icingaObjectField')
            ->setDb($this->db)
            ->setIcingaObject($object);

        if ($id = $this->params->get('field_id')) {
            $form->loadObject(array(
                $type . '_id' => $object->id,
                'datafield_id' => $id
            ));

            $this->view->actionLinks = $this->view->qlink(
                $this->translate('back'),
                $this->getRequest()->getUrl()->without('field_id'),
                null,
                array('class' => 'icon-left-big')
            );
        }

        $form->handleRequest();

        $this->view->table = $this
            ->loadTable('icingaObjectDatafield')
            ->setObject($object);

        $this->setViewScript('object/fields');
    }

    public function historyAction()
    {
        $this->hasPermission('director/audit');
        $this->setAutorefreshInterval(10);
        $db = $this->db();
        $type = $this->getType();
        $this->getTabs()->activate('history');
        $this->view->title = sprintf(
            $this->translate('Activity Log: %s'),
            $this->object->object_name
        );
        $lastDeployedId = $db->getLastDeploymentActivityLogId();
        $this->view->table = $this->applyPaginationLimits(
            $this->loadTable('activityLog')
                ->setConnection($db)
                ->setLastDeployedId($lastDeployedId)
                ->filterObject('icinga_' . $type, $this->object->object_name)
        );
        $this->setViewScript('object/history');
    }

    protected function getType()
    {
        // Strip final 's' and upcase an eventual 'group'
        return preg_replace(
            array('/group$/', '/period$/', '/argument$/', '/apiuser$/', '/set$/'),
            array('Group', 'Period', 'Argument', 'ApiUser', 'Set'),
            $this->getRequest()->getControllerName()
        );
    }

    protected function loadObject()
    {
        if ($this->object === null) {
            if ($name = $this->params->get('name')) {
                $this->object = IcingaObject::loadByType(
                    $this->getType(),
                    $name,
                    $this->db()
                );
            } elseif ($id = $this->params->get('id')) {
                $this->object = IcingaObject::loadByType(
                    $this->getType(),
                    (int) $id,
                    $this->db()
                );
            } elseif ($this->getRequest()->isApiRequest()) {
                if ($this->getRequest()->isGet()) {
                    $this->getResponse()->setHttpResponseCode(422);

                    throw new InvalidPropertyException(
                        'Cannot load object, missing parameters'
                    );
                }
            }

            $this->view->undeployedChanges = $this->countUndeployedChanges();
            $this->view->totalUndeployedChanges = $this->db()
                ->countActivitiesSinceLastDeployedConfig();
        }

        return $this->object;
    }

    protected function hasFields()
    {
        if (! ($object = $this->object)) {
            return false;
        }

        return $object->hasBeenLoadedFromDb()
            && $object->supportsFields()
            && ($object->isTemplate() || $this->getType() === 'command');
    }

    protected function handleApiRequest()
    {
        $request = $this->getRequest();
        $db = $this->db();

        if ($request->getActionName() == 'fields') {
            $this->handleFieldsApiRequest();
            return;
        }
        switch ($request->getMethod()) {
            case 'DELETE':
                $this->requireObject();
                $name = $this->object->object_name;
                $obj = $this->object->toPlainObject(false, true);
                $form = $this->loadForm(
                    'icingaDeleteObject'
                )->setObject($this->object)->setRequest($request)->onSuccess();

                return $this->sendJson($obj);

            case 'POST':
            case 'PUT':
                $type = $this->getType();
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
                if ($object = $this->object) {
                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge(
                            array(
                                'object_type' => $object->object_type,
                                'object_name' => $object->object_name
                            ),
                            $data
                        );
                        $object->replaceWith(
                            IcingaObject::createByType($type, $data, $db)
                        );
                    }
                } else {
                    $object = IcingaObject::createByType($type, $data, $db);
                }

                $response = $this->getResponse();
                if ($object->hasBeenModified()) {
                    $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
                    $object->store();
                    $response->setHttpResponseCode($status);
                } else {
                    $response->setHttpResponseCode(304);
                }

                return $this->sendJson($object->toPlainObject(false, true));

            case 'GET':
                $this->requireObject();
                return $this->sendJson(
                    $this->object->toPlainObject(
                        $this->params->shift('resolved'),
                        ! $this->params->shift('withNull'),
                        $this->params->shift('properties')
                    )
                );

            default:
                $request->getResponse()->setHttpResponseCode(400);
                throw new Exception('Unsupported method ' . $request->getMethod());
        }
    }

    protected function handleFieldsApiRequest() {
        $request = $this->getRequest();
        $db = $this->db();
        $this->requireObject();

        switch ($request->getMethod()) {
            case 'GET':
                $r=array('objects' => array());
                if (!$this->object->supportsFields()) {
                    $this->sendJson($r);
                    return;
                }
                $fields = $this
                    ->loadTable('icingaObjectDatafield')
                    ->setObject($this->object);
                foreach ($fields->fetchData() as $field) {
                    $r['objects'][]=array('object_name' => $field->varname, 'object_type' => 'object', 'is_required' => $field->is_required);
                }
                $this->sendJson($r);
                return;

            case 'PUT':
            case 'POST':
            case 'DELETE':
                if (!$this->hasFields()) {
                    $this->getResponse()->setHttpResponseCode(400);
                    throw new IcingaException('This object does not support fields');
                    return;
                }

                $type = $this->getType();
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

                $related_field=null;
                if (isset($data['object_name'])) {
                    $query = $this->db()->getDbAdapter()
                        ->select()
                        ->from('director_datafield')
                        ->where('varname = ?', $data['object_name']);

                    $result = DirectorDatafield::loadAll($this->db(), $query);
                    if (count($result)) {
                        $related_field=$result[0];
                        $data['datafield_id']=$related_field->id;
                    } else {
                        throw new NotFoundError('Field does not exist: "%s"',$data['object_name']);
                    }
                    unset($data['object_name']);
                } else {
                     $this->getResponse()->setHttpResponseCode(400);
                     throw new IcingaException('Must provide an object_name for the datafield');
                }

                unset($data['object_type']);
                $data[$type.'_id']=$this->object->id;
               
                $objectField = null;
                try {
                    $objectField = IcingaObject::loadByType($type.'Field',$data,$db);
                    $objectField->setProperties($data);
                } catch (Exception $e) {
                    if ($request->getMethod() !== 'DELETE') {
                        $objectField = IcingaObject::createByType($type.'Field',$data,$db);
                    } else {
                        throw $e;
                    }
                }

                $response = $this->getResponse();

                if ($request->getMethod() !== 'DELETE') {
                    $objectField->store();
                    $response->setHttpResponseCode(200);
                    $this->sendJson(array('object_name' => $related_field->varname, 'object_type' => 'object', 'is_required' => $objectField->is_required));
                    return;
                } else {
                    $objectField->delete();
                    $response->setHttpResponseCode(200);
                    $this->sendJson(array('message' => 'Object Field Deleted'));
                    return;
                } 
        }
    }

    protected function countUndeployedChanges()
    {
        if ($this->object === null) {
            return 0;
        }

        return $this->db()->countActivitiesSinceLastDeployedConfig($this->object);
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

    protected function gracefullyActivateTab($name)
    {
        $tabs = $this->getTabs();

        if ($tabs->has($name)) {
            return $tabs->activate($name);
        }

        $req = $this->getRequest();
        $this->redirectNow(
            $req->getUrl()->setPath('director/' . $req->getControllerName())
        );
    }

    protected function beforeTabs()
    {
    }
}
