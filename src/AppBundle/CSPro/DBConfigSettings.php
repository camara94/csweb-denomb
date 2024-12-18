<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\CSPro;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;

class DBConfigSettings {
    private $serverDeviceId;
    private $logger;
    private $pdo;
    
    public function __construct(PdoHelper $pdo, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
    }
    public function getServerDeviceId(){
        if(empty($this->serverDeviceId)){
            $stm = 'SELECT value  FROM  cspro_config where name=\'server_device_id\'';
            $this->serverDeviceId = (string) $this->pdo->fetchValue($stm);
        }
        return $this->serverDeviceId;
    }
}
