<?php
namespace Rindow\Transaction\Local;

class SuspendedTransactions
{
	protected $transactions;

	public function __construct($transactions)
	{
		$this->transactions = $transactions;
	}

	public function getTransactions()
	{
		return $this->transactions;
	}
}