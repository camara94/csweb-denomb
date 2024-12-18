<?php
namespace AppBundle\CSPro\Dictionary;

/**
* CSPro data dictionary.
*
*/
class Dictionary extends DictBase
{
	/** @var string Version of CSPro used to create dictionary */	
	private $version;
	
	/** @var int Start column that record type is stored in the data file */	
	private $recordTypeStart;
	
	/** @var int Number of columns that record type occupies in the data file */	
	private $recordTypeLength;
	
	/** @var string "Relative" or "Absolute" determines how new items are positioned in dictionary editors */	
	private $positioning;
	
	/** @var bool Default value for zero fill setting on new items in dictionary editor */	
	private $zeroFill;
	
	/** @var bool Default value for decimal char setting on new items in dictionary editor */	
	private $decimalChar;
	
	/** @var Level[] List of levels starting with level 1 at index 0, level 2 at index 1... */
	private $levels;

	/** @var Language[] List of languages used in labels in dictionary ... */
	private $languages;
	
	/** @var Relation[] List of relations between dictionary records ... */
	private $relations;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		parent::__construct($attributes);
		$this->version = $attributes['Version'];
		$this->recordTypeStart = $attributes['RecordTypeStart'];
		$this->recordTypeLength = $attributes['RecordTypeLen'];
		$this->positioning = $attributes['Positions'];
		$this->zeroFill = $attributes['ZeroFill'];
		$this->decimalChar = $attributes['DecimalChar'];
		$this->levels = $attributes['Level'];
		$this->languages = $attributes['Languages'];
		$this->relations = $attributes['Relation'];
	}
	
	/**
	* Get dictionary version. 
	*
	* @return string Version of CSPro used to create dictionary 
	*/	
	public function getVersion(){
		return $this->version;
	}

	/**
	* Set dictionary version. 
	*
	* @param string $version Version of CSPro used to create dictionary 
	*/	
	public function setVersion($version){
		$this->version = $version;
	}

	public function getRecordTypeStart(){
		return $this->recordTypeStart;
	}

	public function setRecordTypeStart($recordTypeStart){
		$this->recordTypeStart = $recordTypeStart;
	}

	public function getRecordTypeLength(){
		return $this->recordTypeLength;
	}

	public function setRecordTypeLength($recordTypeLength){
		$this->recordTypeLength = $recordTypeLength;
	}

	public function getPositioning(){
		return $this->positioning;
	}

	public function setPositioning($positioning){
		$this->positioning = $positioning;
	}

	public function getZeroFill(){
		return $this->zeroFill;
	}

	public function setZeroFill($zeroFill){
		$this->zeroFill = $zeroFill;
	}

	public function getDecimalChar(){
		return $this->decimalChar;
	}

	public function setDecimalChar($decimalChar){
		$this->decimalChar = $decimalChar;
	}
	
	public function getLevels(){
		return $this->levels;
	}

	public function setLevels($levels){
		$this->levels = $levels;
	}

	/**
	* Find item in dictionary by name.
	*
	* @param string $itemName Name of item to search for
	* @return array Triple (item, record, level) or false if item not found
	*/
	public function findItem($itemName)
	{
		$matchItem = function ($item) use ($itemName) {return $item->getName() == $itemName; };
		
		foreach ($this->levels as $level) {
			
			$item = current(array_filter($level->getIdItems(), $matchItem));
			if ($item)
				return array($item, null, $level);
					
			foreach ($level->getRecords() as $record) {
				$item = current(array_filter($record->getItems(), $matchItem));
				if ($item)
					return array($item, $record, $level);				
			}
		}
		
		return false;
	}
	
	/**
	* Find record in dictionary by name
	*
	* @param string $recordName Name of record to search for
	* @return Record Record or false if record not found
	*/
	public function findRecord($recordName)
	{
		$matchRecord = function ($record) use ($recordName) {return $record->getName() == $recordName; };
		
		foreach ($this->levels as $level) {
			$record = current(array_filter($level->getRecords(), $matchRecord));
			if ($record)
				return $record;
		}
		
		return false;
	}
	
	/**
	* Find item that contains a given subitem
	*
	* @param Item $subitemName Name of subitem
	* @return Item Parent item of subitem or false if not found
	*/
	public function findSubitemParent($subitemName)
	{
		list($subitem, $record, $level) = $this->findItem($subitemName);
		if ($subitem == false)
			return false;
		
		$matchSubitemParent = function ($item) use ($subitem) {
			return $item != $subitem && 
				   $item->getItemType() == 'Item' && 
				   $item->getStart() <= $subitem->getStart() &&  
				   $item->getStart() + $item->getLength() >= $subitem->getStart() + $subitem->getLength();
		};
		
		return current(array_filter($record->getItems(), $matchSubitemParent));
	}

};	