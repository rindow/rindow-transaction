<?php
namespace Rindow\Transaction\Local;

use Interop\Lenient\Transaction\TransactionManager as TransactionManagerInterface;
use Interop\Lenient\Transaction\Status;
use Rindow\Transaction\Exception;
use Rindow\Transaction\Support\TransactionDefinition;

class TransactionManager implements TransactionManagerInterface
{
    protected $topTransaction;
    protected $currentTransaction;
    protected $transactionTimeout;
    protected $throwRollbackException = false;
    protected $logger;
    protected $debug = false;

    public function getTransaction()
    {
        return $this->currentTransaction;
    }

    //public function setConnected($connected = true)
    //{
    //    $this->connected = $connected;
    //}

    //public function setResourceManager($resourceManager)
    //{
    //    $this->resourceManager = $resourceManager;
    //    $this->resourceManager->setConnectedEventListener(array($this,'onConnected'));
    //    if($this->resourceManager->isConnected())
    //        $this->onConnected();
    //}

    //public function setUseSavepointForNestedTransaction($useSavepointForNestedTransaction)
    //{
    //    return $this->useSavepointForNestedTransaction = $useSavepointForNestedTransaction;
    //}

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    //public function getUseSavepointForNestedTransaction()
    //{
    //    return $this->useSavepointForNestedTransaction;
    //}

    //public function isNestedTransactionAllowed()
    //{
        //if($this->useSavepointForNestedTransaction)
        //    return true;
        //if($this->currentTransaction==null) {
        //    throw new Exception\DomainException("no transaction");
        //}
        //return $this->currentTransaction->isNestedTransactionAllowed();
    //}

    //public function isConnected()
    //{
    //    return $this->connected;
    //}

    public function setThrowRollbackException($throwRollbackException)
    {
        $this->throwRollbackException = $throwRollbackException;
    }

    //public function delete($transaction)
    //{
    //    if(spl_object_hash($this->currentTransaction) != spl_object_hash($transaction))
    //        throw new Exception\DomainException('Wrong order to turn off a transaction.');
    //    $this->currentTransaction = array_pop($this->transactions);
    //}

    //public function onConnected()
    //{
    //    if($this->connected)
    //        return;
    //    $this->logDebug('catch connected.');
    //    $this->connected = true;
    //    if($this->currentTransaction==null ||
    //        !$this->currentTransaction->isActive())
    //        return;
    //    foreach ($this->transactions as $transaction) {
    //        if($transaction==null)
    //            continue;
    //        $transaction->processBeginTransaction();
    //    }
    //    if($this->currentTransaction) {
    //        $this->currentTransaction->processBeginTransaction();
    //    }
    //}

    public function begin($definition=null)
    {
        $this->logDebug('begin transaction.');
        if($definition==null)
            $definition= new TransactionDefinition();

        if($this->transactionTimeout!==null)
            $definition->setTimeout($this->transactionTimeout);
        $transaction = new Transaction(
            $definition,
            $this->currentTransaction,
            $this->throwRollbackException);
        $transaction->setLogger($this->logger);
        $transaction->setDebug($this->debug);
        $this->currentTransaction = $transaction;
        return $transaction;
    }

    public function commit()
    {
        if($this->currentTransaction==null) {
            throw new Exception\DomainException("no transaction");
        }
        $transaction = $this->currentTransaction;
        if($transaction->isActive()) {
            try {
                $transaction->commit();
            } catch(\Exception $e) {
                $this->currentTransaction = $transaction->getParent();
                throw $e;
            }
        }
        $this->currentTransaction = $transaction->getParent();
    }

    public function rollback()
    {
        if($this->currentTransaction==null) {
            throw new Exception\DomainException("no transaction");
        }
        $transaction = $this->currentTransaction;
        if($transaction->isActive()) {
            try {
                $transaction->rollback();
            } catch(\Exception $e) {
                $this->currentTransaction = $transaction->getParent();
                throw $e;
            }
        }
        $this->currentTransaction = $transaction->getParent();
    }

    public function getStatus()
    {
        if($this->currentTransaction==null) {
            return Status::STATUS_NO_TRANSACTION;
        }
        $this->currentTransaction->getStatus();
    }

    public function isActive()
    {
        if($this->currentTransaction==null) {
            return false;
        }
        return $this->currentTransaction->isActive();
    }

    public function setRollbackOnly()
    {
        if($this->currentTransaction==null) {
            throw new Exception\DomainException("no transaction");
        }
        $this->currentTransaction->setRollbackOnly();
    }

    public function setTransactionTimeout($seconds)
    {
        $this->transactionTimeout = $seconds;
    }

    public function suspend()
    {
        $this->logDebug('suspend transaction.');
        if($this->currentTransaction===null) {
            throw new Exception\DomainException("no transaction.");
        }
        $this->currentTransaction->suspend();
        $suspended = new SuspendedTransactions($this->currentTransaction);
        $this->currentTransaction = null;
        return $suspended;
    }

    public function resume(/*SuspendedTransactions*/ $suspended)
    {
        $this->logDebug('resume transaction.');
        if($this->currentTransaction!==null) {
            throw new Exception\DomainException("active transaction exists.");
        }
        $transaction = $suspended->getTransactions();
        $transaction->resume();

        $this->currentTransaction = $transaction;
    }

    protected function logDebug($message, array $context = array())
    {
        if(!$this->debug || $this->logger==null)
            return;
        if(empty($context))
            $context = array('class'=>get_class($this));
        $this->logger->debug($message,$context);
    }
}