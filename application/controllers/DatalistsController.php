<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatalist;
use Icinga\Module\Director\Web\Table\DatalistEntryTable;

use Ramsey\Uuid\Uuid;


class DatalistsController extends ActionController
{
    var $isApified = true;

    public function indexAction() {
        if (! $this->getRequest()->isApiRequest()) {
            $this->redirectNow('director/data/lists');
            return;
        }
        $dummy = DirectorDatalist::create(array());
        $objects = array();
        foreach ($dummy::loadAll($this->db()) as $object) {
            $objects[] = $this->restProps($object);
        }
        return $this->sendJson($this->getResponse(), (object) array('objects' => $objects));

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
        foreach($table->fetch() as $entry) {
            $entrys[$entry->entry_name]=$entry->entry_value;
        }
        $props['entries']=$entrys;
        return($props);
    }
}
