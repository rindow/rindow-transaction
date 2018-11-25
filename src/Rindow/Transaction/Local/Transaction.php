<?php
namespace Rindow\Transaction\Local;

use Interop\Lenient\Transaction\Status;
use Interop\Lenient\Transaction\TransactionDefinition;
use Interop\Lenient\Transaction\Transaction as TransactionInterface;
use Rindow\Transaction\Exception;
use Rindow\Stdlib\PriorityQueue;
use Rindow\Event\EventManager;

class Transaction implements TransactionInterface
{
    protected $definition;
    protected $parent;
    protected $resources = array();
    protected $childCounter;
    protected $rollbackOnly = false;
    protected $resourceValues = array();
    protected $status = Status::STATUS_ACTIVE;
    protected $debug;
    protected $logger;
    protected $suspended = false;
    protected $suspendResourceTransactions = array();
    protected $isActiveOnResource = false;
    protected $synchronizations = array();
    protected $closed = false;
    protected $errorAfterCommitEvents;
    protected $throwRollbackException = false;

    public function __construct(
        TransactionDefinition $definition,
        Transaction $parent=null,
        $throwRollbackException=null)
    {
        $this->definition = $definition;
        $this->parent = $parent;
        if($this->parent)
            $this->parent->incrementChildCounter();
        if($this->throwRollbackException)
            $this->throwRollbackException = $throwRollbackException;
    }

    public function setDebug($debug=true)
    {
        $this->debug = $debug;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function getDefinition()
    {
        return $this->definition;
    }

    public function getParent()
    {
        return $this->parent;
    }

    public function incrementChildCounter()
    {
        $this->childCounter++;
    }

    public function decrementChildCounter()
    {
        if($this->childCounter<=0)
            throw new Exception\DomainException('Invalid childCounter');
        $this->childCounter--;
    }

    public function getChildCounter()
    {
        return $this->childCounter;
    }

    public function getErrorAfterCommitEvents()
    {
        if($this->errorAfterCommitEvents)
            return $this->errorAfterCommitEvents;
        $this->errorAfterCommitEvents = new EventManager();
        return $this->errorAfterCommitEvents;
    }

    //public function isNested()
    //{
    //    return $this->nested;
    //}

    public function setRollbackOnly()
    {
        $this->rollbackOnly = true;
        return $this;
    }

    public function isRollbackOnly()
    {
        return $this->rollbackOnly;
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function enlistResource($resource)
    {
        $this->logDebug('enlist resource to transaction.');
        $oid = spl_object_hash($resource);
        if(isset($this->resources[$oid]))
            return;
        try {
            if($this->parent)
                $this->parent->enlistResource($resource);
            $this->resources[$oid] = $resource;
            $this->processBeginTransaction($resource);
        } catch(\Exception $e) {
            unset($this->resources[$oid]);
            throw $e;
        }
    }

    public function delistResource($resource, $flag)
    {
        $this->logDebug('"delist" is resource from transaction.');
        if(isset($this->resources[$oid])) {
            $resource = $this->resources[$oid];
            unset($this->resources[$oid]);
            if($this->isActive())
                $resource->rollback();
        }
        if($this->parent)
            $this->parent->delistResource($resource, $flag);
    }

    public function getResourceQueue()
    {
        $priorities = $this->getDefinition()->getOption('priorities');
        if($priorities==null)
            return $this->resources;
        $queue = new PriorityQueue();
        foreach ($this->resources as $resource) {
            $name = $resource->getName();
            if($name && isset($priorities[$name]))
                $priority = $priorities[$name];
            else
                $priority = 0;
            $queue->insert($resource,$priority);
        }
        return $queue;
    }

    public function registerSynchronization($synchronization)
    {
        $this->doRegisterSynchronization($synchronization);
    }

    public function doRegisterInterposedSynchronization(/*SynchronizationInterface*/ $synchronization)
    {
        $this->doRegisterSynchronization($synchronization);
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

    protected function close()
    {
        if($this->closed)
            return;
        $this->closed = true;
        if($this->parent)
            $this->parent->decrementChildCounter();
        $this->errorAfterCommitEvents = null;
    }

    public function commit()
    {
        $this->logDebug('commiting transaction.');
        $this->assertNotSuspended();
        $this->assertDontHaveChildren();
        $success = false;
        $commiting = false;
        if($this->isRollbackOnly()) {
            $this->logDebug('the transaction is rollback only.');
            $this->rollback();
            if($this->throwRollbackException) {
                $this->logError('a transaction has been marked for rollback only.:'.$this->definition->getName());
                throw new Exception\RollbackException("a transaction has been marked for rollback only.:".$this->definition->getName());
            }
            return;
        }

        try {
            $this->status = Status::STATUS_COMMITTING;
            try {
                $this->beforeCompletion(true);
            } catch(\Exception $e) {
                if($this->rollbackOnCommitException())
                    $this->status = Status::STATUS_ROLLEDBACK;
                else
                    $this->status = Status::STATUS_UNKNOWN;
                $this->afterCompletion(false);
                throw $e;
            }
            try {
                try {
                    $this->processCommit();
                } catch(\Exception $e) {
                    $this->status = Status::STATUS_UNKNOWN;
                    $this->afterCompletion(false);
                    throw $e;
                }
                $this->afterCompletion(true);
            } catch(\Exception $e) {
                $this->status = Status::STATUS_UNKNOWN;
                throw $e;
            }
            $this->status = Status::STATUS_COMMITTED;
        } catch(\Exception $e) {
            $this->close();
            throw $e;
        }
        $this->logDebug('success to commit transaction.');
        $this->close();
    }

    public function rollback()
    {
        $this->logDebug('rollback transaction.');
        $this->assertNotSuspended();
        $this->assertDontHaveChildren();
        $this->status = Status::STATUS_ROLLING_BACK;
        try {
            try {
                // ** CAUTION ** 
                // Never call beforeCompletion() in rollback process.
                // If there is resource you want to clear, do it in afterCompletion().
                $this->processRollback();
            } catch(\Exception $e) {
                $this->afterCompletion(false);
                throw $e;
            }
            $this->afterCompletion(false);
        } catch(\Exception $e) {
            $this->status = Status::STATUS_UNKNOWN;
            $this->close();
            throw $e;
        }
        $this->status = Status::STATUS_ROLLEDBACK;
        $this->close();
    }

    public function processBeginTransaction($resource)
    {
        $definition = $this->getDefinition();
        if($this->parent && !$resource->isNestedTransactionAllowed()) {
            $this->logError('Nested transaction is not allowed by a resource manager.');
            throw new Exception\NotSupportedException('Nested transaction is not allowed by a resource manager.');
        }
        $resource->beginTransaction($definition);
        //$this->isActiveOnResource = true;
    }

    protected function processCommit()
    {
        $queue = $this->getResourceQueue();
        $errorException = null;
        $commited = array();
        foreach($queue as $resource) {
            try {
                if($errorException==null) {
                    $resource->commit();
                    $commited[] = $resource;
                } else {
                    $resource->rollback();
                }
            } catch(\Exception $e) {
                if($errorException==null) {
                    $this->logError('Failed to commit the transaction.:'.$this->definition->getName());
                    $errorException = $e;
                } else {
                    $this->logError('Failed to rollback the transaction.:'.$this->definition->getName().':'.$e->getMessage());
                }
            }
        }
        if($errorException==null)
            return;
        foreach ($commited as $resource) {
            $name = $resource->getName();
            if($name==null)
                continue;
            $args = array();
            try {
                $this->getErrorAfterCommitEvents()
                    ->notify($name,$args,$resource);
            } catch(\Exception $e) {
                $this->logError('Failed recover for commit after errors.:'.$this->definition->getName().':'.$e->getMessage());
            }
        }
        throw $errorException;
    }

    protected function processRollback()
    {
        $errorException = null;
        $queue = $this->getResourceQueue();
        foreach ($queue as $resource) {
            try {
                $resource->rollback();
            } catch(\Exception $e) {
                $this->logError('It failed to rollback the transaction.:'.$this->definition->getName().':'.$e->getMessage());
                if($errorException == null)
                    $errorException = $e;
            }
        }
        if($errorException)
            throw $errorException;
    }

    protected function rollbackOnCommitException()
    {
        try {
            $this->processRollback();
            return true;
        } catch(\Exception $e) {
            return false;
        }
    }

    public function doRegisterSynchronization($synchronization)
    {
        if($synchronization==null)
            throw new Exception\DomainException('synchronization must be not null.');
        $idx = spl_object_hash($synchronization);
        $this->synchronizations[$idx] = $synchronization;
    }

    public function doGetSynchronizations()
    {
        return $this->synchronizations;
    }

    protected function beforeCompletion($success)
    {
        $this->logDebug('execute the before completion.');
        $synchronizations = $this->doGetSynchronizations();
        foreach ($synchronizations as $idx => $synchronization) {
            $synchronization->beforeCompletion();
        }
    }

    protected function afterCompletion($success)
    {
        $this->logDebug('execute the after completion.');
        $synchronizations = $this->doGetSynchronizations();
        foreach ($synchronizations as $idx => $synchronization) {
            $synchronization->afterCompletion($success);
        }
    }

    public function getStatus()
    {
        return $this->status;
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

    public function assertNotSuspended()
    {
        if($this->suspended) {
            $this->logError('transaction is suspended.');
            throw new Exception\IllegalStateException('transaction is suspended.: ');
        }
    }

    public function assertSuspended()
    {
        if(!$this->suspended) {
            $this->logError('transaction is not suspended.');
            throw new Exception\IllegalStateException('transaction is not suspended.: ');
        }
    }

    public function assertDontHaveChildren()
    {
        if($this->childCounter!=0) {
            $this->logError('transaction has nested level active transaction.');
            throw new Exception\IllegalStateException('transaction has nested level active transaction.');
        }
    }

    public function suspend()
    {
        $this->assertNotSuspended();
        $this->suspendResourceTransactions = array();
        $queue = $this->getResourceQueue();
        foreach ($queue as $resource) {
            $oid = spl_object_hash($resource);
            try {
                $this->suspendResourceTransactions[$oid] = $resource->suspend();
            } catch(\Exception $e) {
                throw new Exception\TransactionException('suspend failure',0,$e);
            }
        }
        $this->suspended = true;
    }

    public function resume()
    {
        $this->assertSuspended();
        $queue = $this->getResourceQueue();
        foreach ($queue as $resource) {
            $oid = spl_object_hash($resource);
            try {
                $resource->resume($this->suspendResourceTransactions[$oid]);
            } catch(\Exception $e) {
                throw new Exception\TransactionException('resume failure',0,$e);
            }
        }
        $this->suspendResourceTransactions = array();
        $this->suspended = false;
    }

    protected function logDebug($message, array $context = array())
    {
        if(!$this->debug || $this->logger==null)
            return;
        if(empty($context))
            $context = array('class'=>__CLASS__);
        $this->logger->debug($message,$context);
    }

    protected function logError($message, array $context = array())
    {
        if($this->logger==null)
            return;
        if(empty($context))
            $context = array('class'=>__CLASS__);
        $this->logger->error($message,$context);
    }
}