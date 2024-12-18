<?php
namespace AppBundle\CSPro\Dictionary;

/**
* A value (numeric or alpha) or a numeric range.
*/
class ValuePair
{
    /** @var string|int|float Single value or range start */
	private $from;
	
    /** @var int|float|null Optional range end */
	private $to;
	
	/**
    * Create from array of constructor parameters.
	*
    * @param array $attributes
	*/
	public function __construct($attributes)
	{
		$this->from = $attributes['From'];
		$this->to = isset($attributes['To']) ? $attributes['To'] : null;
	}

	public function getFrom(){
		return $this->from;
	}

	public function setFrom($from){
		$this->from = $from;
	}

	public function getTo(){
		return $this->to;
	}

	public function setTo($to){
		$this->to = $to;
	}
};