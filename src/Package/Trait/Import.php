<?php
namespace Package\Raxon\Host\Trait;

use Raxon\Node\Model\Node;

trait Import {

    public function role_system(): void
    {
        $object = $this->object();
        $package = $object->request('package');
        if($package){
            $node = new Node($object);
            $node->role_system_create($package);
        }
    }
}