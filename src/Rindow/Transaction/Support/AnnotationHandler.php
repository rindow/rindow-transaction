<?php
namespace Rindow\Transaction\Support;

use ReflectionClass;
use Rindow\Transaction\Annotation\TransactionAttribute;
use Rindow\Transaction\Annotation\TransactionManagement;
use Rindow\Aop\AopPluginInterface;
use Rindow\Aop\AopManager;
use Rindow\Container\ComponentScanner;
use Rindow\Container\Container;
use Rindow\Transaction\Exception;

class AnnotationHandler implements AopPluginInterface
{
    const ANNOTATION_TRANSACTION_MANAGEMENT = 'Rindow\\Transaction\\Annotation\\TransactionManagement';
    const ANNOTATION_TRANSACTION_ATTRIBUTE  = 'Rindow\\Transaction\\Annotation\\TransactionAttribute';

    protected static $propagations = array(
        'mandatory'     => 'mandatory',
        'nested'        => 'nested',
        'never'         => 'never',
        'not_supported' => 'notSupported',
        'required'      => 'required',
        'requires_new'  => 'requiresNew',
        'supports'      => 'supports',
    );

    protected $aopManager;
    protected $container;
    protected $annotationManager;
    protected $defaultTransactionManager;
    protected $config;

    public function __construct(
        AopManager $aopManager,
        Container $container)
    {
        $this->aopManager = $aopManager;
        $this->container = $container;
        $this->annotationManager = $container->getAnnotationManager();
    }

    public function setConfig($config)
    {
        if(isset($config['transaction']))
            $this->config = $config['transaction'];
    }

    public function attachScanner(ComponentScanner $componentScanner)
    {
        $componentScanner->attachCollect(
            self::ANNOTATION_TRANSACTION_MANAGEMENT,
            array($this,'collectTransactionManagement'));
    }

    public function collectTransactionManagement($annoName,$className,$classAnnotation,ReflectionClass $classRef)
    {
        if($this->annotationManager==null)
            return;
        if(isset($this->config['managers']))
            $txManagers = $this->config['managers'];
        else
            $txManagers = array();

        if($classAnnotation->value==null) {
            if(isset($this->config['defaultTransactionManager'])==null)
                throw new Exception\DomainException('transaction manager is not specified on @TransactionManagement in "'.$className.'"');
            else
                $managerName = $this->config['defaultTransactionManager'];
        } else {
            $managerName = $classAnnotation->value;
        }
        if(!isset($txManagers[$managerName]))
            throw new Exception\DomainException('transaction manager is not found in transaction configuration.:"'.$managerName.'"');
        $manager = $txManagers[$managerName];
        if(!isset($manager['advisorClass']))
            throw new Exception\DomainException('advisor class is not specified in transaction configuration.:"'.$managerName.'"');
        $advisorClassName = $manager['advisorClass'];
        if(!isset($manager['transactionManager']))
            throw new Exception\DomainException('advisor class is not specified in transaction configuration.:"'.$managerName.'"');
        $transactionManager = $manager['transactionManager'];

        $this->aopManager->addInterceptTarget($className);

        foreach($classRef->getMethods() as $methodRef) {
            $annos = $this->annotationManager->getMethodAnnotations($methodRef);
            foreach ($annos as $anno) {
                if($anno instanceof TransactionAttribute) {
                    $location = $className.'::'.$methodRef->getName().'() - '.$methodRef->getFileName().'('.$methodRef->getStartLine().')';
                    $advisorName = 'txAdvisor:'.$className.'::'.$methodRef->getName();
                    $component = $this->getComponent($advisorClassName,$transactionManager);
                    $component = $this->container->getComponentManager()->newComponent($component);
                    $this->container->getComponentManager()->addScannedComponent($advisorName,$component);
                    $advisor = $this->getAdvisor($classAnnotation,$anno,$className,$methodRef->getName(),$location);
                    $this->aopManager->addAdviceByConfig($advisor,$advisorName,$anno->value);
                }
            }
        }
    }

    protected function getComponent($advisorClassName,$transactionManager)
    {
        $component = array();
        $component['class'] = $advisorClassName;
        $component['properties']['transactionManager'] = array('ref'=>$transactionManager);
        $component['proxy'] = array('value'=>'disable');
        return $component;
    }

    protected function getAdvisor($classAnnotation,$methodAnnotation,$className,$method,$location)
    {
        $adviceDefinition = array();
        $adviceDefinition['type'] = 'around';
        $adviceDefinition['pointcut'] = 'execution('.$className.'::'.$method.'())';
        $propagation = $methodAnnotation->value;
        if($propagation==null)
            $propagation = 'required';
        $adviceDefinition['method'] = self::$propagations[$propagation];
        if($methodAnnotation->timeout)
            $adviceDefinition['attributes']['timeout'] = $methodAnnotation->timeout;
        if($methodAnnotation->isolation)
            $adviceDefinition['attributes']['isolation'] = $methodAnnotation->isolation;
        if($methodAnnotation->readOnly)
            $adviceDefinition['attributes']['read_only'] = $methodAnnotation->readOnly;
        if($methodAnnotation->noRollbackFor)
            $adviceDefinition['attributes']['no_rollback_for'] = $methodAnnotation->noRollbackFor;
        if($methodAnnotation->name)
            $adviceDefinition['attributes']['name'] = $methodAnnotation->name;
        return $adviceDefinition;
    }
}