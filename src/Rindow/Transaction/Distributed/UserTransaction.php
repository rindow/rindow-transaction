<?php
namespace Rindow\Transaction\Distributed;

use Interop\Lenient\Transaction\UserTransaction as UserTransactionInterface;
use Interop\Lenient\Transaction\TransactionManager as TransactionManagerInterface;

class UserTransaction implements UserTransactionInterface
{
    protected $transactionManager;

    public function __construct(/*TransactionManagerInterface*/ $transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    public function begin()
    {
        return $this->transactionManager->begin();
    }

    public function commit()
    {
        return $this->transactionManager->commit();
    }

    public function getStatus()
    {
        return $this->transactionManager->getStatus();
    }

    public function rollback()
    {
        return $this->transactionManager->rollback();
    }

    public function setRollbackOnly()
    {
        return $this->transactionManager->setRollbackOnly();
    }

    public function setTransactionTimeout($seconds)
    {
        return $this->transactionManager->setTransactionTimeout($seconds);
    }
}