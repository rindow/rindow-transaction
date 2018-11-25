<?php
namespace Rindow\Transaction\Distributed;

use Interop\Lenient\Transaction\Xa\Xid as XidInterface;

class Xid implements XidInterface
{
	protected $globalTransactionId;
	protected $branchQualifier;
	protected $formatId;

	public function __construct($globalTransactionId=null,$branchQualifier=null,$formatId=null)
	{
		if($globalTransactionId)
			$this->globalTransactionId = $globalTransactionId;
		else {
			$hash = spl_object_hash($this);
			$this->globalTransactionId = substr($hash,8,8).substr($hash,24,8).'-'.time().'-'.mt_rand();
		}
		$this->branchQualifier = $branchQualifier;
		$this->formatId = $formatId;
	}

	public function getGlobalTransactionId()
	{
		return $this->globalTransactionId;
	}

	public function getBranchQualifier()
	{
		return $this->branchQualifier;
	}

	public function getFormatId()
	{
		return $this->formatId;
	}

	public function __toString()
	{
		$string = $this->globalTransactionId;
		if($this->branchQualifier)
			$string .= ':' . $this->branchQualifier;
		if($this->formatId)
			$string .= ':' . $this->formatId;
		return $string;
	}
}