<?php

namespace Icinga\Module\Director\RestApi;

use Exception;
use Icinga\Exception\IcingaException;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Director\Core\CoreApi;
use Icinga\Module\Director\Exception\DuplicateKeyException;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Util;
use Icinga\Module\Director\Web\Table\IcingaObjectDatafieldTable;
use Icinga\Module\Director\Objects\DirectorDatafield;


class IcingaObjectHandler extends RequestHandler
{
    /** @var IcingaObject */
    protected $object;

    /** @var CoreApi */
    protected $api;

    public function dispatch() {
        if ($this->request->getActionName() == 'fields') {
            $this->processFieldsApiRequest();
        } else {
            $this->processApiRequest();
        }
    }
       
    public function setObject(IcingaObject $object)
    {
        $this->object = $object;
        return $this;
    }

    public function setApi(CoreApi $api)
    {
        $this->api = $api;
        return $this;
    }

    /**
     * @return IcingaObject
     * @throws ProgrammingError
     */
    protected function requireObject()
    {
        if ($this->object === null) {
            throw new ProgrammingError('Object is required');
        }

        return $this->object;
    }

    /**
     * @return IcingaObject
     */
    protected function eventuallyLoadObject()
    {
        return $this->object;
    }

    protected function requireJsonBody()
    {
        $data = json_decode($this->request->getRawBody());

        if ($data === null) {
            $this->response->setHttpResponseCode(400);
            throw new IcingaException(
                'Invalid JSON: %s',
                $this->getLastJsonError()
            );
        }

        return $data;
    }

    protected function getType()
    {
        return $this->request->getControllerName();
    }

    protected function handleApiRequest()
    {
        try {
            $this->processApiRequest();
        } catch (NotFoundError $e) {
            $this->sendJsonError($e, 404);
            return;
        } catch (DuplicateKeyException $e) {
            $this->sendJsonError($e, 422);
            return;
        } catch (Exception $e) {
            $this->sendJsonError($e);
        }

        if ($this->request->getActionName() !== 'index') {
            throw new NotFoundError('Not found');
        }
    }

    protected function processApiRequest()
    {
        $request = $this->request;
        $response = $this->response;
        $db = $this->db;

        // TODO: I hate doing this:
        if ($this->request->getActionName() === 'ticket') {
            $host = $this->requireObject();

            if ($host->getResolvedProperty('has_agent') !== 'y') {
                throw new NotFoundError('The host "%s" is not an agent', $host->getObjectName());
            }

            $this->sendJson(
                Util::getIcingaTicket(
                    $host->getObjectName(),
                    $this->api->getTicketSalt()
                )
            );

            // TODO: find a better way to shut down. Currently, this avoids
            //       "not found" errors:
            exit;
        }

        switch ($request->getMethod()) {
            case 'DELETE':
                $object = $this->requireObject();
                $object->delete();
                $this->sendJson($object->toPlainObject(false, true));
                break;

            case 'POST':
            case 'PUT':
                $data = (array) $this->requireJsonBody();
                $type = $this->getType();
                if ($object = $this->eventuallyLoadObject()) {
                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge([
                            'object_type' => $object->get('object_type'),
                            'object_name' => $object->getObjectName()
                        ], $data);
                        $object->replaceWith(
                            IcingaObject::createByType($type, $data, $db)
                        );
                    }
                } else {
                    $object = IcingaObject::createByType($type, $data, $db);
                }

                if ($object->hasBeenModified()) {
                    $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
                    $object->store();
                    $response->setHttpResponseCode($status);
                } else {
                    $response->setHttpResponseCode(304);
                }

                $this->sendJson($object->toPlainObject(false, true));
                break;

            case 'GET':
                $params = $this->request->getUrl()->getParams();
                $this->requireObject();
                $properties = $params->shift('properties');
                if (strlen($properties)) {
                    $properties = preg_split('/\s*,\s*/', $properties, -1, PREG_SPLIT_NO_EMPTY);
                } else {
                    $properties = null;
                }

                $this->sendJson(
                    $this->requireObject()->toPlainObject(
                        $params->shift('resolved'),
                        ! $params->shift('withNull'),
                        $properties
                    )
                );
                break;

            default:
                $request->getResponse()->setHttpResponseCode(400);
                throw new IcingaException('Unsupported method ' . $request->getMethod());
        }
    }

    protected function processFieldsApiRequest() {
        $db = $this->db;
        $request = $this->request;
        $response = $this->response;
 
        $object= $this->requireObject();

        switch ($request->getMethod()) {
            case 'GET':
                $r=array('objects' => array());
                if (!$object->supportsFields()) {
                    $this->sendJson($r);
                    return;
                }
                $table = new IcingaObjectDatafieldTable($object);
                foreach ($table->getQuery()->fetchAll() as $field) {
                    $r['objects'][]=array('object_name' => $field->varname, 'object_type' => 'object', 'is_required' => $field->is_required, $this->getType().'_name' => $this->object->object_name);
                }
                $this->sendJson($r);
                return;

            case 'PUT':
            case 'POST':
            case 'DELETE':
                if (!$object->supportsFields()) {
                    $this->getResponse()->setHttpResponseCode(400);
                    throw new IcingaException('This object does not support fields');
                    return;
                }

                $type = $this->getType();
                $data = json_decode($this->request->getRawBody());

                if ($data === null) {
                    $response->setHttpResponseCode(400);
                    throw new IcingaException(
                        'Invalid JSON: %s' . $this->request->getRawBody(),
                        $this->getLastJsonError()
                    );
                } else {
                    $data = (array) $data;
                }

                $related_field=null;
                if (isset($data['object_name'])) {
                    $query = $db->getDbAdapter()
                        ->select()
                        ->from('director_datafield')
                        ->where('varname = ?', $data['object_name']);

                    $result = DirectorDatafield::loadAll($db, $query);
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
                $data[$type.'_id']=$object->id;

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
}
