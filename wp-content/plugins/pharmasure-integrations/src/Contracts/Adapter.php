<?php
namespace PharmaSure\Integrations\Contracts;
interface Adapter{public function key():string;public function type():string;public function deliver(array $config,array $payload):array;}
