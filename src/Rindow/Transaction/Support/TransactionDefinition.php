<?php
namespace Rindow\Transaction\Support;

use Interop\Lenient\Transaction\TransactionDefinition as TransactionDefinitionInterface;

class TransactionDefinition implements TransactionDefinitionInterface
{
    protected $name;
    protected $timeout = -1;
    protected $isolationLevel = TransactionDefinitionInterface::ISOLATION_DEFAULT;
    protected $readOnly = false;
    protected $options = array();

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setTimeOut($timeout)
    {
        $this->timeout = $timeout;
    }

    public function setIsolationLevel($isolationLevel)
    {
        $this->isolationLevel = $isolationLevel;
        return $this;
    }

    public function setReadOnly($readOnly)
    {
        $this->readOnly = $readOnly;
    }

    public function setOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    public function setOption($name,$value)
    {
        $this->options[$name] = $value;
        return $this;
    }

    public function getIsolationLevel()
    {
        return $this->isolationLevel;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function isReadOnly()
    {
        return $this->readOnly;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function getOption($name)
    {
        if(!isset($this->options[$name]))
            return null;
        return $this->options[$name];
    }
}