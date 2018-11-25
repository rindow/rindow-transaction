<?php
namespace Rindow\Transaction\Support;

use Interop\Lenient\Transaction\TransactionManager;
use Interop\Lenient\Transaction\Status;
use Rindow\Transaction\Exception;
use Rindow\Aop\ProceedingJoinPointInterface;

class TransactionBoundary
{
    protected $transactionManager;
    protected $logger;
    protected $isDebug = false;
    protected $withoutTransactionManagement = false;

    public function setTransactionManager(TransactionManager $transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    public function getTransactionManager()
    {
        return $this->transactionManager;
    }

    public function setWithoutTransactionManagement($withoutTransactionManagement)
    {
        $this->withoutTransactionManagement = $withoutTransactionManagement;
    }

    public function getWithoutTransactionManagement()
    {
        return $this->withoutTransactionManagement;
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

    protected function isProceeding($invocation)
    {
        return false;
    }

    protected function getProceed($invocation, array $params=null)
    {
        if($params==null)
            $params = array();
        return array($invocation ,$params);
    }

    protected function getDefinition($invocation,$params,$definition)
    {
        return $definition;
    }

    public function mandatory($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        if(!$this->isActive())
            throw new Exception\IllegalStateException("No existing transaction found for transaction marked with propagation 'mandatory'");

        if($this->isProceeding($invocation))
            return $invocation->proceed();
        list($callback,$args) = $this->getProceed($invocation ,$params);
        return call_user_func_array($callback, $args);
    }

    public function required($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        if($this->isActive()) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }
        $definition = $this->getDefinition($invocation,$params,$definition);
        $this->getTransactionManager()->begin($definition);
        try {
            if($this->isProceeding($invocation)) {
                $result = $invocation->proceed();
            } else {
                list($callback,$args) = $this->getProceed($invocation ,$params);
                $result = call_user_func_array($callback, $args);
            }
        } catch(\Exception $e) {
            $this->processRollback($e,$definition,'required');
            throw $e;
        }
        $this->getTransactionManager()->commit();
        return $result;
    }

    public function requiresNew($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        $tx = null;
        if($this->isActive()) {
            $tx = $this->getTransactionManager()->suspend();
        }
        try {
            $definition = $this->getDefinition($invocation,$params,$definition);
            $this->getTransactionManager()->begin($definition);
            try {
                if($this->isProceeding($invocation)) {
                    $result = $invocation->proceed();
                } else {
                    list($callback,$args) = $this->getProceed($invocation ,$params);
                    $result = call_user_func_array($callback, $args);
                }
            } catch(\Exception $e) {
                $this->processRollback($e,$definition,'requiresNew');
                throw $e;
            }
            $this->getTransactionManager()->commit();
        } catch (\Exception $e) {
            if($this->logger)
                $this->error('catch a exception "'.get_class($e).'('.$e->getMessage().')" in reqiresNew transaction.');
            if($tx)
                $this->getTransactionManager()->resume($tx);
            throw $e;
        }
        if($tx) {
            $this->getTransactionManager()->resume($tx);
        }
        return $result;
    }

    public function nested($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        //if($this->isActive() && !$this->getTransactionManager()->isNestedTransactionAllowed()) {
        //    throw new Exception\IllegalStateException("Transaction manager does not allow nested transactions.");
        //}
        $definition = $this->getDefinition($invocation,$params,$definition);
        $this->getTransactionManager()->begin($definition);
        try {
            if($this->isProceeding($invocation)) {
                $result = $invocation->proceed();
            } else {
                list($callback,$args) = $this->getProceed($invocation ,$params);
                $result = call_user_func_array($callback, $args);
            }
        } catch(\Exception $e) {
            $this->processRollback($e,$definition,'nested');
            throw $e;
        }
        $this->getTransactionManager()->commit();
        return $result;
    }

    public function supports($invocation, array $params=null, $definition=null)
    {
        if($this->isProceeding($invocation))
            return $invocation->proceed();
        list($callback,$args) = $this->getProceed($invocation ,$params);
        return call_user_func_array($callback, $args);
    }

    public function notSupported($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        $tx = $this->getTransactionManager()->getTransaction();
        if($tx) {
            $tx = $this->getTransactionManager()->suspend();
        }
        try {
            if($this->isProceeding($invocation)) {
                $result = $invocation->proceed();
            } else {
                list($callback,$args) = $this->getProceed($invocation ,$params);
                $result = call_user_func_array($callback, $args);
            }
        } catch (\Exception $e) {
            if($this->logger)
                $this->error('catch a exception "'.get_class($e).'('.$e->getMessage().')" in notSupported proceeding.');
            if($tx)
                $this->getTransactionManager()->resume($tx);
            throw $e;
        }
        if($tx) {
            $this->getTransactionManager()->resume($tx);
        }
        return $result;
    }

    public function never($invocation, array $params=null, $definition=null)
    {
        if($this->withoutTransactionManagement) {
            if($this->isProceeding($invocation))
                return $invocation->proceed();
            list($callback,$args) = $this->getProceed($invocation ,$params);
            return call_user_func_array($callback, $args);
        }

        if($this->isActive())
            throw new Exception\IllegalStateException("Existing transaction found for transaction marked with propagation 'never'");
        if($this->isProceeding($invocation))
            return $invocation->proceed();
        list($callback,$args) = $this->getProceed($invocation ,$params);
        return call_user_func_array($callback, $args);
    }

    protected function isActive()
    {
        $tx = $this->getTransactionManager()->getTransaction();
        if($tx==null)
            return false;
        switch($tx->getStatus()) {
            case Status::STATUS_COMMITTED:
            case Status::STATUS_ROLLEDBACK:
                return false;
            default:
                return true;
        }
    }

    protected function processRollback($e,$definition,$propagation)
    {
        if($this->logger)
            $this->logger->error('catch a exception "'.get_class($e).'('.$e->getMessage().')" in '.$propagation.' proceeding.');
        if($definition) {
            $noRollbackFor = $definition->getOption('no_rollback_for');
            if($noRollbackFor) {
                if(!is_array($noRollbackFor))
                    $noRollbackFor = array($noRollbackFor);
                foreach ($noRollbackFor as $exception) {
                    if(is_a($e, $exception)) {
                        if($this->logger) {
                            $this->logger->info('matched no_rollback_for "'.get_class($e).'"');
                        }
                        $this->getTransactionManager()->commit();
                        return;
                    }
                }
            }
        }
        $this->getTransactionManager()->rollback();
    }
}