<?php
namespace AppBundle\CSPro\Dictionary;

/**
* Relation between two records in a CSPro dictionary.
*
*/
class Relation
{
	/** @var string Name of relation */
	private $name;
	
	/** @var string Name of primary record */
	private $primary;
		
	/** @var string Name of item on primary record used to link or null if linked on occurrence */
	private $primaryLink;
	
	/** @var string Name of secondary record */
	private $secondary;

	/** @var string Name of item on secondary record used to link or null if linked on occurrence */
	private $secondaryLink;

	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		$this->name = $attributes['Name'];
		$this->primary = $attributes['Primary'];
		$this->primaryLink = isset($attributes['PrimaryLink']) ? $attributes['PrimaryLink'] : null;
		$this->secondary = $attributes['Secondary'];
		$this->secondaryLink = isset($attributes['SecondaryLink']) ? $attributes['SecondaryLink'] : null;
	}
	
	public function getName(){
		return $this->name;
	}

	public function setName($name){
		$this->name = $name;
	}

	public function getPrimary(){
		return $this->primary;
	}

	public function setPrimary($primary){
		$this->primary = $primary;
	}

	public function getPrimaryLink(){
		return $this->primaryLink;
	}

	public function setPrimaryLink($primaryLink){
		$this->primaryLink = $primaryLink;
	}

	public function getSecondary(){
		return $this->secondary;
	}

	public function setSecondary($secondary){
		$this->secondary = $secondary;
	}

	public function getSecondaryLink(){
		return $this->secondaryLink;
	}

	public function setSecondaryLink($secondaryLink){
		$this->secondaryLink = $secondaryLink;
	}
};