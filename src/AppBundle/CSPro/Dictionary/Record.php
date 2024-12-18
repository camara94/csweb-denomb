<?php
namespace AppBundle\CSPro\Dictionary;

/**
* Record in a CSPro dictionary.
*
* A record belongs to a Level and represents a group of variables
* that appear on the same line in the data file.
*
*/
class Record extends DictBase
{
	/** @var string Tag used in data file to distinguish this type of record from others */
	private $typeValue;
	
	/** @var bool Whether or not this record is required to have a complete (valid) case */
	private $required;
	
	/** @var int Maximum number of records of this type allowed in a case. If > 1 then the record repeats. */
	private $maxRecords;
	
	/** @var int Total number of columns in data file used by this record */
	private $length;
	
	/** @var string[] List of occurrence labels (repeating records only) */	
	private $occurrenceLabels;
	
	/** @var Item[] List of variables in this record */
	private $items;

        /** @var level of this record */
	private $level; 
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		parent::__construct($attributes);
		$this->typeValue = $attributes['RecordTypeValue'];
		$this->required = $attributes['Required'];
		$this->maxRecords = $attributes['MaxRecords'];
		$this->length = $attributes['RecordLen'];
		$this->occurrenceLabels = $attributes['OccurrenceLabel'];
		$this->items = $attributes['Item'];
	}
	
	public function getTypeValue(){
		return $this->typeValue;
	}

	public function setTypeValue($typeValue){
		$this->typeValue = $typeValue;
	}

	public function getRequired(){
		return $this->required;
	}

	public function setRequired($required){
		$this->required = $required;
	}

	public function getMaxRecords(){
		return $this->maxRecords;
	}

	public function setMaxRecords($maxRecords){
		$this->maxRecords = $maxRecords;
	}

	public function getLength(){
		return $this->length;
	}

	public function setLength($length){
		$this->length = $length;
	}

	public function getOccurrenceLabels(){
		return $this->occurrenceLabels;
	}

	public function setOccurrenceLabels($occurrenceLabels){
		$this->occurrenceLabels = $occurrenceLabels;
	}
	
	public function getItems(){
		return $this->items;
	}

	public function setItems($items){
		$this->items = $items;
	}
        
        public function setLevel($level) {
            $this->level = $level;
        }
        
        public function getLevel() {
            return $this->level;
        }
};