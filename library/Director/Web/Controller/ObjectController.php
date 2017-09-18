<?php

namespace Icinga\Module\Director\Web\Controller;

use Icinga\Exception\InvalidPropertyException;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Director\Deployment\DeploymentInfo;
use Icinga\Module\Director\Forms\DeploymentLinkForm;
use Icinga\Module\Director\Forms\IcingaCloneObjectForm;
use Icinga\Module\Director\Forms\IcingaObjectFieldForm;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Objects\IcingaObjectGroup;
use Icinga\Module\Director\RestApi\IcingaObjectHandler;
use Icinga\Module\Director\Web\Controller\Extension\ObjectRestrictions;
use Icinga\Module\Director\Web\Form\DirectorObjectForm;
use Icinga\Module\Director\Web\ObjectPreview;
use Icinga\Module\Director\Web\Table\ActivityLogTable;
use Icinga\Module\Director\Web\Table\GroupMemberTable;
use Icinga\Module\Director\Web\Table\IcingaObjectDatafieldTable;
use Icinga\Module\Director\Web\Tabs\ObjectTabs;
use ipl\Html\Link;
use Icinga\Module\Director\Objects\DirectorDatafield;

abstract class ObjectController extends ActionController
{
    use ObjectRestrictions;

    /** @var IcingaObject */
    protected $object;

    /** @var bool This controller handles REST API requests */
    protected $isApified = true;

    /** @var array Allowed object types we are allowed to edit anyways */
    protected $allowedExternals = array(
        'apiuser',
        'endpoint'
    );

    protected $type;

    public function init()
    {
        parent::init();

        if ($this->getRequest()->isApiRequest()) {
            $handler = new IcingaObjectHandler($this->getRequest(), $this->getResponse(), $this->db());
            try {
                $this->eventuallyLoadObject();
            } catch (NotFoundError $e) {
                // Silently ignore the error, the handler will complain
                $handler->sendJsonError($e, 404);
                // TODO: nice shutdown
                exit;
            }

            $handler->setApi($this->api());
            if ($this->object) {
                $handler->setObject($this->object);
            }
            $handler->dispatch();
        } else {
            $this->eventuallyLoadObject();
            if ($this->getRequest()->getActionName() === 'add') {
                $this->addSingleTab(
                    sprintf($this->translate('Add %s'), ucfirst($this->getType())),
                    null,
                    'add'
                );
            } else {
                $this->tabs(new ObjectTabs($this->getType(), $this->getAuth(), $this->object));
            }
        }
    }

    public function indexAction()
    {
        if (! $this->getRequest()->isApiRequest()) {
            $this->redirectToPreviewForExternals()
                ->editAction();
        }
    }

    public function addAction()
    {
        $this->tabs()->activate('add');
        $url = sprintf('director/%ss', $this->getPluralType());

        $imports = $this->params->get('imports');
        $form = $this->loadObjectForm()
            ->presetImports($imports)
            ->setSuccessUrl($url);

        if ($oType = $this->params->get('type', 'object')) {
            $form->setPreferredObjectType($oType);
        }

        if ($oType === 'template') {
            $this->addTemplate();
        } else {
            $this->addObject();
        }

        $form->handleRequest();
        $this->content()->add($form);
    }

    public function editAction()
    {
        $object = $this->requireObject();
        $this->tabs()->activate('modify');
        $this->addObjectTitle()
             ->addObjectForm($object)
             ->addActionClone()
             ->addActionUsage();
    }

    public function renderAction()
    {
        $this->assertPermission('director/showconfig');
        $this->tabs()->activate('render');
        $preview = new ObjectPreview($this->requireObject(), $this->getRequest());
        if ($this->object->isExternal()) {
            $this->addActionClone();
        }
        $preview->renderTo($this);
    }

    public function cloneAction()
    {
        $this->assertPermission('director/' . strtolower($this->getPluralType()));
        $object = $this->requireObject();
        $form = IcingaCloneObjectForm::load()
            ->setObject($object)
            ->handleRequest();

        if ($object->isExternal()) {
            $this->tabs()->activate('render');
        } else {
            $this->tabs()->activate('modify');
        }
        $this->addTitle($this->translate('Clone: %s'), $object->getObjectName())
            ->addBackToObjectLink()
            ->content()->add($form);
    }

    public function fieldsAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }

        $this->assertPermission('director/admin');
        $object = $this->requireObject();
        $type = $this->getType();

        $this->addTitle(
            $this->translate('Custom fields: %s'),
            $object->getObjectName()
        );
        $this->tabs()->activate('fields');

        $form = IcingaObjectFieldForm::load()
            ->setDb($this->db())
            ->setIcingaObject($object);

        if ($id = $this->params->get('field_id')) {
            $form->loadObject([
                "${type}_id"   => $object->id,
                'datafield_id' => $id
            ]);

            $this->actions()->add(Link::create(
                $this->translate('back'),
                $this->url()->without('field_id'),
                null,
                ['class' => 'icon-left-big']
            ));
        }
        $form->handleRequest();
        $this->content()->add($form);
        $table = new IcingaObjectDatafieldTable($object);
        $table->attributes()->set('data-base-target', '_self');
        $table->renderTo($this);
    }

    public function historyAction()
    {
        $this->assertPermission('director/audit')
            ->setAutorefreshInterval(10)
            ->tabs()->activate('history');

        $name = $this->requireObject()->getObjectName();
        $this->addTitle($this->translate('Activity Log: %s'), $name);

        $db = $this->db();
        $type = $this->getType();
        (new ActivityLogTable($db))
            ->setLastDeployedId($db->getLastDeploymentActivityLogId())
            ->filterObject('icinga_' . $type, $name)
            ->renderTo($this);
    }

    public function membershipAction()
    {
        $object = $this->requireObject();
        if (! $object instanceof IcingaObjectGroup) {
            throw new NotFoundError('Not Found');
        }

        $this
            ->addTitle($this->translate('Group membership: %s'), $object->getObjectName())
            ->setAutorefreshInterval(15)
            ->tabs()->activate('membership');

        $type = substr($this->getType(), 0, -5);
        GroupMemberTable::create($type, $this->db())
            ->setGroup($object)
            ->renderTo($this);
    }

    protected function addObjectTitle()
    {
        $object = $this->requireObject();
        $name = $object->getObjectName();
        if ($object->isTemplate()) {
            $this->addTitle($this->translate('Template: %s'), $name);
        } else {
            $this->addTitle($name);
        }

        return $this;
    }

    protected function addActionUsage()
    {
        if ($this->getRequest()->isApiRequest()) {
            return;
        }

        $this->hasPermission('director/admin');
        $object = $this->object;
        $type = $this->getType();
        $object = $this->requireObject();
        if ($object->isTemplate() && ! $type === 'serviceSet') {
            $this->actions()->add([
                Link::create(
                    $this->translate('Usage'),
                    "director/${type}template/usage",
                    ['name'  => $object->getObjectName()],
                    ['class' => 'icon-sitemap']
                )
            ]);
        }

        return $this;
    }

    protected function addActionClone()
    {
        $this->actions()->add(Link::create(
            $this->translate('Clone'),
            'director/' . $this->getType() .'/clone',
            $this->object->getUrlParams(),
            array('class' => 'icon-paste')
        ));

        return $this;
    }

    protected function addTemplate()
    {
        $this->assertPermission('director/admin');
        $this->addTitle(
            $this->translate('Add new Icinga %s template'),
            $this->getTranslatedType()
        );
    }

    protected function addObject()
    {
        $this->assertPermission('director/' . $this->getPluralType());
        $imports = $this->params->get('imports');
        if (is_string($imports) && strlen($imports)) {
            $this->addTitle(
                $this->translate('Add %s: %s'),
                $this->getTranslatedType(),
                $imports
            );
        } else {
            $this->addTitle(
                $this->translate('Add new Icinga %s'),
                $this->getTranslatedType()
            );
        }
    }

    protected function redirectToPreviewForExternals()
    {
        if ($this->object
            && $this->object->isExternal()
            && ! in_array($this->object->getShortTableName(), $this->allowedExternals)
        ) {
            $this->redirectNow(
                $this->getRequest()->getUrl()->setPath(sprintf('director/%s/render', $this->getType()))
            );
        }

        return $this;
    }

    protected function getType()
    {
        if ($this->type === null) {
            // Strip final 's' and upcase an eventual 'group'
            $this->type = preg_replace(
                array('/group$/', '/period$/', '/argument$/', '/apiuser$/', '/set$/'),
                array('Group', 'Period', 'Argument', 'ApiUser', 'Set'),
                $this->getRequest()->getControllerName()
            );
        }

        return $this->type;
    }

    protected function getPluralType()
    {
        return $this->getType() . 's';
    }

    protected function getTranslatedType()
    {
        return $this->translate(ucfirst($this->getType()));
    }

    protected function eventuallyLoadObject()
    {
        if (null !== $this->params->get('name') || $this->params->get('id')) {
            $this->loadObject();
        }
    }

    protected function loadObject()
    {
        if ($this->object === null) {
            if ($id = $this->params->get('id')) {
                $this->object = IcingaObject::loadByType(
                    $this->getType(),
                    (int) $id,
                    $this->db()
                );
            } elseif (null !== ($name = $this->params->get('name'))) {
                $this->object = IcingaObject::loadByType(
                    $this->getType(),
                    $name,
                    $this->db()
                );

                if (! $this->allowsObject($this->object)) {
                    $this->object = null;
                    throw new NotFoundError('No such object available');
                }
            } elseif ($this->getRequest()->isApiRequest()) {
                if ($this->getRequest()->isGet()) {
                    $this->getResponse()->setHttpResponseCode(422);

                    throw new InvalidPropertyException(
                        'Cannot load object, missing parameters'
                    );
                }
            }

            if ($this->object !== null) {
                $this->addDeploymentLink();
            }
        }

        return $this->object;
    }

    protected function addDeploymentLink()
    {
        $info = new DeploymentInfo($this->db());
        $info->setObject($this->object);

        if (! $this->getRequest()->isApiRequest()) {
            $this->actions()->add(
                DeploymentLinkForm::create(
                    $this->db(),
                    $info,
                    $this->Auth(),
                    $this->api()
                )->handleRequest()
            );
        }
    }

    protected function addBackToObjectLink()
    {
        $this->actions()->add(Link::create(
            $this->translate('back'),
            'director/' . strtolower($this->getType()),
            ['name'  => $this->object->getObjectName()],
            ['class' => 'icon-left-big']
        ));

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
        }
        return $this;
    }

    protected function addObjectForm(IcingaObject $object = null)
    {
        $form = $this->loadObjectForm($object);
        $this->content()->add($form);
        $form->handleRequest();
        return $this;
    }

    protected function loadObjectForm(IcingaObject $object = null)
    {
        /** @var DirectorObjectForm $class */
        $class = sprintf(
            'Icinga\\Module\\Director\\Forms\\Icinga%sForm',
            ucfirst($this->getType())
        );

        $form = $class::load()
            ->setDb($this->db())
            ->setAuth($this->Auth());

        if ($object !== null) {
            $form->setObject($object);
        }

        $this->onObjectFormLoaded($form);

        return $form;
    }

    protected function handleFieldsApiRequest() {  //TODO, remove from here
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
                    $r['objects'][]=array('object_name' => $field->varname, 'object_type' => 'object', 'is_required' => $field->is_required, $this->getType().'_name' => $this->object->object_name);
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
                unset($data[$this->getType().'_name']);
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

    protected function onObjectFormLoaded(DirectorObjectForm $form)
    {
    }

    protected function requireObject()
    {
        if (! $this->object) {
            $this->getResponse()->setHttpResponseCode(404);
            if (null === $this->params->get('name')) {
                throw new NotFoundError('You need to pass a "name" parameter to access a specific object');
            } else {
                throw new NotFoundError('No such object available');
            }
        }

        return $this->object;
    }
}
