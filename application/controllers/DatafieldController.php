<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Exception\InvalidPropertyException;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Exception\NotFoundError;
use Icinga\Application\Hook;


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
                return $this->sendJson((object) array('error' => $e->getMessage()));
            } catch (Exception $e) {
                if ($response->getHttpResponseCode() === 200) {
                    $response->setHttpResponseCode(500);
                }

                return $this->sendJson((object) array('error' => $e->getMessage()));
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

        $edit = false;

        if ($id = $this->params->get('id')) {
            $edit = true;
        }

        $form = $this->view->form = $this->loadForm('directorDatafield')
            ->setSuccessUrl('director/data/fields')
            ->setDb($this->db());

        if ($edit) {
            $form->loadObject($id);
            $this->view->title = sprintf(
                $this->translate('Modify %s'),
                $form->getObject()->varname
            );
            $this->singleTab($this->translate('Edit a field'));
        } else {
            $this->view->title = $this->translate('Add a new Data Field');
            $this->singleTab($this->translate('New field'));
        }

        $form->handleRequest();
        $this->render('object/form', null, true);
    }


    protected function loadObject()
    {
        if (!$this->getRequest()->getParam('name')) {
            throw new NotFoundError('Must specify name');
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
        $db = $this->db();

        switch ($request->getMethod()) {
            case 'GET':
                $this->requireObject();
                $props=$this->object->properties;
                foreach(array_keys($props) as $key) {
                    if (is_null($props[$key]) || $key == 'id') {
                        unset($props[$key]);
                    }
                }
                $this->sendJson($props);
                return;
            case 'PUT':
            case 'POST':
            case 'DELETE':
                $this->sendJson(array('TODO' => 'Not yet implemented'));
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

}
