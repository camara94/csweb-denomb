<?php
namespace AppBundle\CSPro\Dictionary;

/**
* One value in a ValueSet.
*/
class Value
{
    /** @var string[] Labels for this value (one per language) */
	private $labels;
	
    /** @var ValuePair[] Numeric/alpha values/ranges associated with this value */
	private $valuePairs;
	
    /** @var string|null Special value associated with this value (MISSING, NOTAPPL, DEFAULT) or null if not special */	
	private $special;
	
    /** @var string|null User notes */
	private $note;
	
    /** @var string|null Path to image for this file if it has an image, null otherwise */
	private $image;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		$this->labels = $attributes['Label'];
		$this->valuePairs = $attributes['VPairs'];
		$this->special = isset($attributes['Special']) ? $attributes['Special'] : null;
		$this->note = isset($attributes['Note']) ? $attributes['Note'] : null;
		$this->image = isset($attributes['Image']) ? $attributes['Image'] : null;
	}
	
	public function getLabel($languageIndex = 0){
		return $this->labels[$languageIndex];
	}

	public function setLabel($label, $languageIndex = 0){
		$this->labels[$languageIndex] = $label;
	}

	public function getValuePairs(){
		return $this->valuePairs;
	}

	public function setValuePairs($valuePairs){
		$this->valuePairs = $valuePairs;
	}

	public function getSpecial(){
		return $this->special;
	}

	public function setSpecial($special){
		$this->special = $special;
	}

	public function getNote(){
		return $this->note;
	}

	public function setNote($note){
		$this->note = $note;
	}

	public function getImage(){
		return $this->image;
	}

	public function setImage($image){
		$this->image = $image;
	}	
};
