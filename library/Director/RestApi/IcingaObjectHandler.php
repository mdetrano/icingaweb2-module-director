<?php

namespace Icinga\Module\Director\RestApi;

use Exception;
use Icinga\Exception\IcingaException;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Director\Core\CoreApi;
use Icinga\Module\Director\Data\Exporter;
use Icinga\Module\Director\DirectorObject\Lookup\ServiceFinder;
use Icinga\Module\Director\Exception\DuplicateKeyException;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Resolver\OverrideHelper;
use InvalidArgumentException;
use RuntimeException;
use Icinga\Module\Director\Util;
use Icinga\Module\Director\Web\Form\IcingaObjectFieldLoader;
use Icinga\Module\Director\Objects\DirectorDatafield;

class IcingaObjectHandler extends RequestHandler
{
    /** @var IcingaObject */
    protected $object;

    /** @var CoreApi */
    protected $api;

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
    protected function loadOptionalObject()
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

    protected function processApiRequest()
    {
        try {
            $this->handleApiRequest();
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

    protected function handleApiRequest()
    {
        $request = $this->request;
        $db = $this->db;

        if ($this->request->getActionName() === 'fields') {
            $this->processFieldsApiRequest();
            return;
        }

        // TODO: I hate doing this:
        if ($this->request->getActionName() === 'ticket') {
            $host = $this->requireObject();

            if ($host->getResolvedProperty('has_agent') !== 'y') {
                throw new NotFoundError('The host "%s" is not an agent', $host->getObjectName());
            }

            $this->sendJson($this->api->getTicket($host->getObjectName()));

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
                $params = $this->request->getUrl()->getParams();
                $allowsOverrides = $params->get('allowOverrides');
                $type = $this->getType();
                if ($object = $this->loadOptionalObject()) {
                    if ($request->getMethod() === 'POST') {
                        $object->setProperties($data);
                    } else {
                        $data = array_merge([
                            'object_type' => $object->get('object_type'),
                            'object_name' => $object->getObjectName()
                        ], $data);
                        $object->replaceWith(IcingaObject::createByType($type, $data, $db));
                    }
                    $this->persistChanges($object);
                    $this->sendJson($object->toPlainObject(false, true));
                } elseif ($allowsOverrides && $type === 'service') {
                    if ($request->getMethod() === 'PUT') {
                        throw new InvalidArgumentException('Overrides are not (yet) available for HTTP PUT');
                    }
                    $this->setServiceProperties($params->getRequired('host'), $params->getRequired('name'), $data);
                } else {
                    $object = IcingaObject::createByType($type, $data, $db);
                    $this->persistChanges($object);
                    $this->sendJson($object->toPlainObject(false, true));
                }
                break;

            case 'GET':
                $object = $this->requireObject();
                $exporter = new Exporter($this->db);
                RestApiParams::applyParamsToExporter($exporter, $this->request, $object->getShortTableName());
                $this->sendJson($exporter->export($object));
                break;

            default:
                $request->getResponse()->setHttpResponseCode(400);
                throw new IcingaException('Unsupported method ' . $request->getMethod());
        }
    }

    protected function persistChanges(IcingaObject $object)
    {
        if ($object->hasBeenModified()) {
            $status = $object->hasBeenLoadedFromDb() ? 200 : 201;
            $object->store();
            $this->response->setHttpResponseCode($status);
        } else {
            $this->response->setHttpResponseCode(304);
        }
    }

    protected function setServiceProperties($hostname, $serviceName, $properties)
    {
        $host = IcingaHost::load($hostname, $this->db);
        $service = ServiceFinder::find($host, $serviceName);
        if ($service === false) {
            throw new NotFoundError('Not found');
        }
        if ($service->requiresOverrides()) {
            unset($properties['host']);
            OverrideHelper::applyOverriddenVars($host, $serviceName, $properties);
            $this->persistChanges($host);
            $this->sendJson($host->toPlainObject(false, true));
        } else {
		throw new RuntimeException('Found a single service, which should have been found (and dealt with) before');
	}
    }

    protected function processFieldsApiRequest() {
        $request = $this->request;
        $response = $this->response;
        $db = $this->db;
        $object = $this->requireObject();

        switch ($request->getMethod()) {
            case 'GET':
                $r=array('objects' => array());
                if (!$this->object->supportsFields()) {
                    $this->sendJson($r);
                    exit; # TODO upstream requires this to avoid not found errors
                }
                $loader = new IcingaObjectFieldLoader($object);
                $fields = $loader->getFields();

                foreach ($fields as $field) {
                    $r['objects'][]=array('object_name' => $field->varname, 'object_type' => 'object', 'is_required' => $field->is_required, $this->getType().'_name' => $this->object->object_name);
                }
                $this->sendJson($r);
                exit; #TODO upstream requires this to avoid not found errors

            case 'PUT':
            case 'POST':
            case 'DELETE':
                if (!$object->supportsFields()) {
                    $this->getResponse()->setHttpResponseCode(400);
                    throw new IcingaException('This object does not support fields');
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

                if ($request->getMethod() !== 'DELETE') {
                    $objectField->store();
                    $response->setHttpResponseCode(200);
                    $this->sendJson(array('object_name' => $related_field->varname, 'object_type' => 'object', 'is_required' => $objectField->is_required));
                    exit; #TODO upstream requires this to avoid not found errors
                } else {
                    $objectField->delete();
                    $response->setHttpResponseCode(200);
                    $this->sendJson(array('message' => 'Object Field Deleted'));
                    exit; #TODO upstream requires this to avoid not found errors
                } 
        }
    }
}
