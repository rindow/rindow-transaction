<?php
namespace Rindow\Transaction\Support;

use Interop\Lenient\Transaction\TransactionDefinition as TransactionDefinitionInterface;

class TransactionAdvisor extends TransactionBoundary
{
    const ISOLATION_DEFAULT          = 'default';
    const ISOLATION_READ_UNCOMMITTED = 'read_uncommitted';
    const ISOLATION_READ_COMMITTED   = 'read_committed';
    const ISOLATION_REPEATABLE_READ  = 'repeatable_read';
    const ISOLATION_SERIALIZABLE     = 'serializable';
    protected static $isolation = array(
        self::ISOLATION_DEFAULT          => TransactionDefinitionInterface::ISOLATION_DEFAULT,
        self::ISOLATION_READ_UNCOMMITTED => TransactionDefinitionInterface::ISOLATION_READ_UNCOMMITTED,
        self::ISOLATION_READ_COMMITTED   => TransactionDefinitionInterface::ISOLATION_READ_COMMITTED,
        self::ISOLATION_REPEATABLE_READ  => TransactionDefinitionInterface::ISOLATION_REPEATABLE_READ,
        self::ISOLATION_SERIALIZABLE     => TransactionDefinitionInterface::ISOLATION_SERIALIZABLE,
    );

    protected function isProceeding($invocation)
    {
        return true;
    }

    protected function getProceed($invocation, array $params=null)
    {
        return array(array($invocation,'proceed'),array());
    }

    protected function getDefinition($invocation,$params,$definition)
    {
        $definition = new TransactionDefinition();
        $attributes = $invocation->getAdvice()->getAttributes();
        if($attributes) {
            if(isset($attributes['name'])) {
                $definition->setName($attributes['name']);
                unset($attributes['name']);
            }
            if(isset($attributes['timeout'])) {
                $definition->setTimeOut($attributes['timeout']);
                unset($attributes['timeout']);
            }
            if(isset($attributes['isolation'])) {
                $isolation = $attributes['isolation'];
                if(!isset(self::$isolation[$isolation]))
                    throw new Exception\DomainException('unknown isolation type:'.$isolation);
                $definition->setIsolationLevel(self::$isolation[$isolation]);
                unset($attributes['isolation']);
            }
            if(isset($attributes['read_only'])) {
                $definition->setReadOnly($attributes['read_only']);
                unset($attributes['read_only']);
            }
            if(count($attributes)) {
                $definition->setOptions($attributes);
            }
        }
        return $definition;
    }
}