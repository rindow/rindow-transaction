<?php
namespace Rindow\Transaction\Distributed;

use Interop\Lenient\Transaction\Transaction as TransactionInterface;
use Interop\Lenient\Transaction\Status;
use Interop\Lenient\Transaction\Xa\XAResource;
use Interop\Lenient\Transaction\Xa\Xid;
use Rindow\Transaction\Exception;

class Transaction implements TransactionInterface
{
    const XA_STATUS_ACTIVE = 1;
    const XA_STATUS_IDLE   = 2;
    const XA_STATUS_OK     = 3;
    const XA_STATUS_RDONLY = 4;
    const XA_STATUS_FAIL   = 5;
    const XA_STATUS_COMMITTED  = 6;
    const XA_STATUS_ROLLEDBACK = 7;

    protected $xid;
    protected $xaResources = array();
    protected $synchronizations = array();
    protected $resourceValues = array();
    protected $status = Status::STATUS_ACTIVE;
    protected $suspended = false;
    protected $transactionTimeout;
    protected $isDebug;
    protected $logger;
    protected $errorException;

    public function __construct(/*Xid*/ $xid,$logger=null)
    {
        $this->xid = $xid;
        if($logger)
            $this->setLogger($logger);
    }

    public function setDebug($debug=true)
    {
        $this->isDebug = $debug;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
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

    public function getStatus()
    {
        if(count($this->xaResources)==0 && count($this->synchronizations)==0)
            return Status::STATUS_NO_TRANSACTION;
        return $this->status;
    }

    public function setRollbackOnly()
    {
        $this->status = Status::STATUS_MARKED_ROLLBACK;
    }

    public function isRollbackOnly()
    {
        if($this->status==Status::STATUS_MARKED_ROLLBACK ||
            $this->status==Status::STATUS_ROLLING_BACK ) {
            return true;
        }
        return false;
    }

    public function enlistResource(/*XAResource*/ $xaRes)
    {
        if(isset($this->xaResources[spl_object_hash($xaRes)]))
            return false;
        $flags = XAResource::TMNOFLAGS;
        if($this->transactionTimeout!==null)
            $xaRes->setTransactionTimeout($this->transactionTimeout);
        $xaRes->start($this->xid, $flags);
        if($flags == XAResource::TMNOFLAGS) {
            $this->xaResources[spl_object_hash($xaRes)] = 
                (object)array('res'=>$xaRes, 'status'=>self::XA_STATUS_ACTIVE);
        }
        return true;
    }

    public function delistResource(/*XAResource*/ $xaRes, $flags)
    {
        if(!isset($this->xaResources[spl_object_hash($xaRes)]))
            throw new Exception\DomainException('xaResource not found.');
        unset($this->xaResources[spl_object_hash($xaRes)]);
        $xaRes->end($this->xid, $flags);
        return true;
    }

    public function registerSynchronization(/*SynchronizationInterface*/ $sync)
    {
        $this->synchronizations[spl_object_hash($sync)] = $sync;
    }

    public function doRegisterInterposedSynchronization(/*SynchronizationInterface*/ $sync)
    {
        $this->registerSynchronization($sync);
    }

    public function doGetResourceValue($key)
    {
        if(is_object($key))
            $key = spl_object_hash($key);
        if(!array_key_exists($key, $this->resourceValues))
            return null;
        return $this->resourceValues[$key];
    }

    public function doPutResourceValue($key, $value)
    {
        if(is_object($key))
            $key = spl_object_hash($key);
        return $this->resourceValues[$key] = $value;
    }

    protected function setErrorException($exception)
    {
        if($this->errorException==null)
            $this->errorException = $exception;
    }

    protected function getErrorException()
    {
        $exception = $this->errorException;
        $this->errorException = null;
        return $exception;
    }

    protected function clearErrorException()
    {
        $this->errorException = null;
    }

    /** 
    *  Complete the transaction represented by this Transaction object.
    *  @return void
    */
    public function commit()
    {
        if($this->isDebug && $this->logger)
            $this->debug('commit transaction.');
        $this->assertNotSuspended();
        if($this->status!==Status::STATUS_ACTIVE &&
           $this->status!==Status::STATUS_MARKED_ROLLBACK ) {
            if($this->logger)
                $this->error('transaction status is not active.: '.$this->xid);
            throw new Exception\IllegalStateException('transaction status is not active.: '.$this->xid,$this->status);
        }

        if($this->status!==Status::STATUS_MARKED_ROLLBACK)
            $this->beforeCompletion();

        if($this->status!==Status::STATUS_MARKED_ROLLBACK)
            $this->endXaResources(XAResource::TMSUCCESS);
        else
            $this->endXaResources(XAResource::TMFAIL);

        if($this->status!==Status::STATUS_MARKED_ROLLBACK) {
            $onePhase = (count($this->xaResources)<=1) ;
            if($onePhase) {
                $this->commitXaResources($onePhase);
            } else {
                $result = $this->prepareXaResources();
                if($this->status===Status::STATUS_PREPARED) {
                    if($result === XAResource::XA_OK) {
                        $this->commitXaResources($onePhase);
                    } else {
                        $this->status = Status::STATUS_COMMITTED;
                    }
                }
            }
        }

        if($this->status===Status::STATUS_MARKED_ROLLBACK) {
            $this->rollbackXaResources();
        }

        $this->afterCompletion();
        if($this->status===Status::STATUS_COMMITTED)
            return;

        if($this->status===Status::STATUS_ROLLEDBACK) {
            if($this->logger)
                $this->error('commit failure: '.$this->xid,array('status'=>$this->status));
            $exception = $this->getErrorException();
            if($exception)
                throw new Exception\TransactionException('commit failure: '.$this->xid,$this->status,$exception);
            else
                throw new Exception\TransactionException('commit failure: '.$this->xid,$this->status);
        }
        $this->clearErrorException();
        if($this->logger)
            $this->error('commit failure with unknown status: '.$this->xid,array('status'=>$this->status));
        throw new Exception\RuntimeException('commit failure with unknown status: '.$this->xid,$this->status);
    }

    /** 
    *  Rollback the transaction represented by this Transaction object.
    *  @return void
    */
    public function rollback()
    {
        if($this->isDebug && $this->logger)
            $this->debug('rollback transaction.');
        $this->assertNotSuspended();
        if($this->status!==Status::STATUS_ACTIVE &&
           $this->status!==Status::STATUS_MARKED_ROLLBACK ) {
            if($this->logger)
                $this->error('transaction status is not active.: '.$this->xid);
            throw new Exception\IllegalStateException('transaction status is not active.: '.$this->xid,$this->status);
        }

        $this->endXaResources(XAResource::TMFAIL);

        $this->rollbackXaResources();

        $this->afterCompletion();

        $this->clearErrorException();

        if($this->status===Status::STATUS_ROLLEDBACK)
            return;

        if($this->logger)
            $this->error('rollback failure with unknown status: '.$this->xid,array('status'=>$this->status));
        throw new Exception\RuntimeException('rollback failure with unknown status.: '.$this->xid,$this->status);
    }

    private function endXaResources($flags)
    {
        foreach($this->xaResources as $xaResource) {
            try {
                $xaResource->res->end($this->xid, $flags);
                $xaResource->status = self::XA_STATUS_IDLE;
            } catch(\Exception $e) {
                $xaResource->status = self::XA_STATUS_FAIL;
                $this->status = Status::STATUS_MARKED_ROLLBACK;
                if($this->logger)
                    $this->error('xaResource::end: '.$e->getMessage().'['.$e->getCode().']',array($e));
                $this->setErrorException($e);
            }
        }
    }

    private function prepareXaResources()
    {
        $result = XAResource::XA_RDONLY;
        $this->status = Status::STATUS_PREPARING;
        foreach($this->xaResources as $xaResource) {
            if($xaResource->status != self::XA_STATUS_IDLE)
                continue;
            try {
                $xaResult = $xaResource->res->prepare($this->xid);
                if($xaResult === XAResource::XA_OK) {
                    $xaResource->status = self::XA_STATUS_OK;
                    $result = XAResource::XA_OK;
                } else if($xaResult === XAResource::XA_RDONLY){
                    $xaResource->status = self::XA_STATUS_RDONLY;
                } else {
                    $xaResource->status = self::XA_STATUS_FAIL;
                    $this->status = Status::STATUS_MARKED_ROLLBACK;
                    if($this->logger)
                        $this->error('xaResource::prepare indicate error['.$xaResult.'].',array('code'=>$xaResult));
                    return $result;
                }
            } catch(\Exception $e) {
                $xaResource->status = self::XA_STATUS_FAIL;
                $this->status = Status::STATUS_MARKED_ROLLBACK;
                if($this->logger)
                    $this->error('xaResource::prepare: '.$e->getMessage().'['.$e->getCode().']',array($e));
                $this->setErrorException($e);
                return $result;
            }
        }
        $this->status = Status::STATUS_PREPARED;
        return $result;
    }

    private function commitXaResources($onePhase)
    {
        $this->status = Status::STATUS_COMMITTING;
        foreach($this->xaResources as $xaResource) {
            if($xaResource->status == self::XA_STATUS_RDONLY) {
                $xaResource->status = self::XA_STATUS_COMMITTED;
                continue;
            }
            try {
                $xaResource->res->commit($this->xid, $onePhase);
                $xaResource->status = self::XA_STATUS_COMMITTED;
            } catch(\Exception $e) {
                $xaResource->status = self::XA_STATUS_FAIL;
                if($onePhase)
                    $this->status = Status::STATUS_MARKED_ROLLBACK;
                else
                    $this->status = Status::STATUS_UNKNOWN;
                if($this->logger)
                    $this->error('xaResource::commit: '.$e->getMessage().'['.$e->getCode().']',array($e));
                $this->setErrorException($e);
            }
        }
        if($this->status==Status::STATUS_COMMITTING)
            $this->status = Status::STATUS_COMMITTED;
    }

    private function rollbackXaResources()
    {
        $this->status = Status::STATUS_ROLLING_BACK;
        foreach($this->xaResources as $xaResource) {
            try {
                $xaResource->res->rollback($this->xid);
                $xaResource->status = self::XA_STATUS_ROLLEDBACK;
            } catch(\Exception $e) {
                $xaResource->status = self::XA_STATUS_FAIL;
                $this->status = Status::STATUS_UNKNOWN;
                if($this->logger)
                    $this->error('xaResource::rollback: '.$e->getMessage().'['.$e->getCode().']',array($e));
            }
        }
        if($this->status==Status::STATUS_ROLLING_BACK)
            $this->status = Status::STATUS_ROLLEDBACK;
    }

    private function beforeCompletion()
    {
        foreach($this->synchronizations as $synchronization) {
            try {
                $synchronization->beforeCompletion();
            } catch(\Exception $e) {
                $this->status = Status::STATUS_MARKED_ROLLBACK;
                if($this->logger)
                    $this->error('synchronization::beforeCompletion: '.$e->getMessage().'['.$e->getCode().']',array($e));
                $this->setErrorException($e);
            }
        }
    }

    private function afterCompletion()
    {
        $status = $this->status;
        foreach($this->synchronizations as $synchronization) {
            try {
                $synchronization->afterCompletion($status);
            } catch(\Exception $e) {
                $this->status = Status::STATUS_UNKNOWN;
                if($this->logger)
                    $this->error('synchronization::afterCompletion: '.$e->getMessage().'['.$e->getCode().']',array($e));
            }
        }
    }

    public function assertNotSuspended()
    {
        if($this->suspended) {
            if($this->logger)
                $this->error('transaction is suspended.: '.$this->xid);
            throw new Exception\IllegalStateException('transaction is suspended.: '.$this->xid);
        }
    }

    public function assertSuspended()
    {
        if(!$this->suspended) {
            if($this->logger)
                $this->error('transaction is not suspended.: '.$this->xid);
            throw new Exception\IllegalStateException('transaction is not suspended.: '.$this->xid);
        }
    }

    public function doSuspend()
    {
        $this->assertNotSuspended();
        foreach($this->xaResources as $xaResource) {
            try {
                $xaResource->res->end($this->xid, XAResource::TMSUSPEND);
            } catch(\Exception $e) {
                if($this->logger)
                    $this->error('xaResource::suspend: '.$e->getMessage().'['.$e->getCode().']',array($e));
                throw new Exception\RuntimeException('suspend failure: '.$this->xid,$this->status,$e);
            }
        }
        $this->suspended = true;
    }

    public function doResume()
    {
        $this->assertSuspended();
        foreach($this->xaResources as $xaResource) {
            try {
                $xaResource->res->start($this->xid, XAResource::TMRESUME);
            } catch(\Exception $e) {
                if($this->logger)
                    $this->error('xaResource::resume: '.$e->getMessage().'['.$e->getCode().']',array($e));
                throw new Exception\RuntimeException('resume failure: '.$this->xid,$this->status,$e);
            }
        }
        $this->suspended = false;
    }

    public function doSetTransactionTimeout($seconds)
    {
        $this->transactionTimeout = $seconds;
        foreach($this->xaResources as $xaResource) {
            try {
                $xaResource->res->setTransactionTimeout($seconds);
            } catch(\Exception $e) {
                if($this->logger)
                    $this->error('xaResource::setTransactionTimeout: '.$e->getMessage().'['.$e->getCode().']',array($e));
                throw new Exception\RuntimeException('setTransactionTimeout failure: '.$this->xid,$this->status,$e);
            }
        }
    }

    public function isActive()
    {
        switch ($this->status) {
            case Status::STATUS_NO_TRANSACTION:
            case Status::STATUS_COMMITTED:
            case Status::STATUS_ROLLEDBACK:
                return false;
            default:
                return true;
        };
    }
}