<?php
namespace Rindow\Transaction\Support;

use Interop\Lenient\Transaction\TransactionSynchronizationRegistry as TransactionSynchronizationRegistryInterface;
use Rindow\Transaction\Exception;

class TransactionSynchronizationRegistry implements TransactionSynchronizationRegistryInterface
{
    protected $transactionManager;
    protected $logger;
    protected $isDebug = false;

    public function __construct($transactionManager=null)
    {
        if($transactionManager)
            $this->setTransactionManager($transactionManager);
    }

    public function setTransactionManager($transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setDebug($debug=true)
    {
        $this->isDebug = $debug;
    }

    public function getTransactionKey()
    {
        $transaction = $this->getTransactionManager()->getTransaction();
        if($transaction==null)
            return null;
        return spl_object_hash($transaction);
    }

    protected function getTransactionManager()
    {
        if($this->transactionManager==null) {
            if($this->logger)
                $this->logger->error('transaction manager is not specified.');
            throw new Exception\DomainException('transaction manager is not specified.');
        }
        return $this->transactionManager;
    }

    protected function getTransaction()
    {
        $transaction = $this->getTransactionManager()->getTransaction();
        if($transaction==null) {
            if($this->logger)
                $this->logger->error('transaction is not active.');
            throw new Exception\IllegalStateException('transaction is not active.');
        }
        return $transaction;
    }

    public function getTransactionStatus()
    {
        return $this->getTransactionManager()->getStatus();
    }

    public function getRollbackOnly()
    {
        return $this->getTransactionManager()->isRollbackOnly();
    }

    public function setRollbackOnly()
    {
        $this->getTransactionManager()->setRollbackOnly();
    }

    public function getResource($key)
    {
        $transaction = $this->getTransaction();
        if($key==null) {
            if($this->logger)
                $this->logger->error('key is null');
            throw new Exception\DomainException('key is null');
        }
            
        if($this->isDebug && $this->logger)
            $this->logger->debug('getResource()');
        return $transaction->doGetResourceValue($key);
    }

    public function putResource($key, $value)
    {
        $transaction = $this->getTransaction();
        if($key==null) {
            if($this->logger)
                $this->logger->error('key is null');
            throw new Exception\DomainException('key is null');
        }
        if($this->isDebug && $this->logger)
            $this->logger->debug('putResource()');
        return $transaction->doPutResourceValue($key, $value);
    }

    public function registerInterposedSynchronization(/*SynchronizationInterface*/ $sync)
    {
        $transaction = $this->getTransaction();
        if($this->isDebug && $this->logger)
            $this->logger->debug('registerInterposedSynchronization()');
        $transaction->doRegisterInterposedSynchronization($sync);
    }
}