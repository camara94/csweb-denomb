<?php
namespace AppBundle\CSPro\Dictionary;

/**
 * Basic attributes shared by multiple object types in dictionary.
 */
class DictBase
{
    /** @var string Element name */
	private $name;
	
    /** @var string[] Labels, one per language */
	private $labels;
	
    /** @var string|null User notes */
	private $note;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		$this->name = $attributes['Name'];
		$this->labels = $attributes['Label'];
		if (isset($attributes['Note']))
			$this->note = $attributes['Note'];
	}
	
	public function getName(){
		return $this->name;
	}

	public function setName($name){
		$this->name = $name;
	}

	public function getLabel($languageIndex = 0){
		return $this->labels[$languageIndex];
	}

	public function setLabel($label, $languageIndex = 0){
		$this->labels[$languageIndex] = $label;
	}

	public function getNote(){
		return $this->note;
	}

	public function setNote($note){
		$this->note = $note;
	}	
};