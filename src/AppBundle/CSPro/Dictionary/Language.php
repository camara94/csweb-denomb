<?php
namespace AppBundle\CSPro\Dictionary;

/**
* Language in a CSPro dictionary.
*
*/
class Language
{
	/** @var string Language name (used in logic) */
	private $name;
	
	/** @var string Language label (shown to user) */
	private $label;
		
	/**
    * Create from name and label.
	*
    * @param string $name
    * @param string $label
	*/
	public function __construct($name, $label)
	{
		$this->name = $name;
		$this->label = $label;
	}
	
	public function getName(){
		return $this->name;
	}

	public function setName($name){
		$this->name = $name;
	}

	public function getLabel(){
		return $this->label;
	}

	public function setLabel($label){
		$this->label = $label;
	}
};