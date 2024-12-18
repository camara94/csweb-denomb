<?php
namespace AppBundle\CSPro\Dictionary;

/**
* List of possible responses for an Item
*
*/
class ValueSet extends DictBase
{
	/** @var Value[] List of responses */
	private $values;
	
	/** @var string|null name of value set that this is linked to if this is a linked value set */	
	private $linkedValueSet;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		parent::__construct($attributes);
		$this->values = $attributes['Value'];
		$this->linkedValueSet = isset($attributes['Link']) ? $attributes['Link'] : null;
	}
	
	public function getValues(){
		return $this->values;
	}

	public function setValues($values){
		$this->values = $values;
	}

	public function getLinkedValueSet(){
		return $this->linkedValueSet;
	}

	public function setLinkedValueSet($linkedValueSet){
		$this->linkedValueSet = $linkedValueSet;
	}
};