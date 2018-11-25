<?php
namespace Rindow\Transaction\Distributed;

use Interop\Lenient\Transaction\TransactionManager as TransactionManagerInterface;
use Interop\Lenient\Transaction\Transaction as TransactionInterface;
use Interop\Lenient\Transaction\Status;
use Rindow\Transaction\Exception;

class TransactionManager implements TransactionManagerInterface
{
    protected $currentTransaction;
    protected $transactionTimeout;
    protected $logger;
    protected $isDebug = false;

    protected function newTransaction()
    {
        return new Transaction(new Xid());
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setDebug($debug=true)
    {
        $this->isDebug = $debug;
    }

    protected function debug($message, array $context = array())
    {
        if($this->logger==null)
            return;
        $this->logger->debug($message,$context);
    }

    protected function error($message, array $context = array())
    {
        if($this->logger==null)
            return;
        $this->logger->error($message,$context);
    }

    public function begin($definition=null)
    {
        $tx = $this->getTransaction();
        $this->currentTransaction = null;
        if($tx) {
            switch($tx->getStatus()) {
                case Status::STATUS_MARKED_ROLLBACK:
                    $tx->rollback();
                    break;
                case Status::STATUS_COMMITTED:
                case Status::STATUS_ROLLEDBACK:
                case Status::STATUS_NO_TRANSACTION:
                    break;
                default:
                    throw new Exception\NotSupportedException('Nested transactions not supported.');
            }
        }
        if($this->isDebug && $this->logger)
            $this->debug('begin transaction.');
        $tx = $this->newTransaction();
        if($this->logger)
            $tx->setLogger($this->logger);
        if($this->isDebug)
            $tx->setDebug(true);
        if($this->transactionTimeout!==null)
            $tx->doSetTransactionTimeout($this->transactionTimeout);
        return $this->currentTransaction = $tx;
    }

    public function commit()
    {
        $tx = $this->getTransaction();
        if($tx==null)
            throw new Exception\IllegalStateException('Cannot get Transaction for commit');
        $tx->commit();
        $this->currentTransaction = null;
    }

    public function rollback()
    {
        $tx = $this->getTransaction();
        if($tx==null)
            throw new Exception\IllegalStateException('Cannot get Transaction for rollback');
        $tx->rollback();
        $this->currentTransaction = null;
    }

    public function getStatus()
    {
        $tx = $this->getTransaction();
        if($tx==null)
            return Status::STATUS_NO_TRANSACTION;
        return $tx->getStatus();
    }

    public function setRollbackOnly()
    {
        $tx = $this->getTransaction();
        if($tx==null)
            throw new Exception\IllegalStateException('Cannot get Transaction for setRollbackOnly');
        return $tx->setRollbackOnly();
    }

    public function isRollbackOnly()
    {
        $tx = $this->getTransaction();
        if($tx==null)
            throw new Exception\IllegalStateException('Cannot get Transaction for isRollbackOnly');
        return $tx->isRollbackOnly();
    }

    public function isActive()
    {
        $tx = $this->getTransaction();
        if($tx==null) {
            return false;
        }
        return $tx->isActive();
    }

    public function suspend()
    {
        if($this->isDebug && $this->logger)
            $this->debug('suspend transaction.');
        $tx = $this->getTransaction();
        if($tx==null)
            throw new Exception\IllegalStateException('Cannot get Transaction for suspend');
        $tx->doSuspend();
        $this->currentTransaction = null;
        return $tx;
    }

    public function resume(/*TransactionInterface*/ $tx)
    {
        if($this->isDebug && $this->logger)
            $this->debug('resume transaction.');
        $tx->doResume();
        $this->currentTransaction = $tx;
    }

    public function getTransaction()
    {
        return $this->currentTransaction;
    }

    public function setTransactionTimeout($seconds)
    {
        $this->transactionTimeout = $seconds;
        $tx = $this->getTransaction();
        if($tx)
            $tx->doSetTransactionTimeout($seconds);
    }
}