<?php
namespace AppBundle\CSPro\Dictionary;

/**
* Level in a CSPro dictionary.
*
*/
class Level extends DictBase
{
	/** @var Item[] List of id variables for this level */	
	private $idItems;
	
	/** @var Record[] List of records for this level */	
	private $records;
        
        /** @var Record[] List of records for this level */	
	private $levelNumber;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		parent::__construct($attributes);
		$this->idItems = $attributes['IdItems'];
		$this->records = $attributes['Record'];
	}
	
	public function getIdItems(){
		return $this->idItems;
	}

	public function setIdItems($idItems){
		$this->idItems = $idItems;
	}

	public function getRecords(){
		return $this->records;
	}

	public function setRecords($records){
		$this->records = $records;
	}
        
        public function getLevelNumber(){
            return $this->levelNumber;
        }
        public function setLevelNumber($levelNumber){
             $this->levelNumber = $levelNumber;
        }
};
