<?php

namespace app\components;

use Hashids\Hashids;

class Hash
{
    public $hashids;

    public function __construct(array $params)
    {
        $this->hashids = new Hashids(
            $params['salt'],
            $params['length'],
            $params['alphabet']
        );
    }

    public function encode($id)
    {
        return $this->hashids->encode($id);
    }

    public function decode($hash)
    {
        $id = $this->hashids->decode($hash);

        return $id ? $id[0] : false;
    }
}
