<?php
namespace RindowTest\Transaction\Local\AnnotationHandlerTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\ResourceManager;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\TransactionDefinition as TransactionDefinitionInterface;
use Interop\Lenient\Transaction\Annotation\TransactionAttribute;
use Interop\Lenient\Transaction\Annotation\TransactionManagement;

use Rindow\Container\ModuleManager;

class TestException extends \Exception
{}

class TestLogger
{
    public $logdata = array();
    public function log($message)
    {
        $this->logdata[] = $message;
    }
    public function debug($message)
    {
        $this->log($message);
    }
    public function error($message)
    {
        $this->log($message);
    }
}

class TestResourceManager implements ResourceManager
{
    //public $listener;
    public $isolationLevel = TransactionDefinitionInterface::ISOLATION_DEFAULT;
    public $timeout;
    public $nestedTransactionAllowed = true;
    public $logger;
    public $commitError;
    public $rollbackError;
    public $suspendSupported;
    public $depth = 0;
    public $name;
    public $maxDepth;
    public $readOnly;

    public function getName()
    {
        return $this->name;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    //public function setNestedTransactionAllowed()
    //{
    //    $this->nestedTransactionAllowed = true;
    //}
    //public function clearUseSavepointForNestedTransaction()
    //{
    //    $this->allowSavepointForNestedTransaction = false;
    //}
    //public function setConnectedEventListener($listener)
    //{
    //    $this->logger->log('setConnectedEventListener');
    //    $this->listener = $listener;
    //}
    //public function connect()
    //{
    //    if($this->connected)
    //        return;
    //    $this->logger->log('connect');
    //    $this->connected = true;
    //    if($this->listener) {
    //        call_user_func($this->listener);
    //    }
    //}
    //public function isConnected()
    //{
    //    return $this->connected;
    //}
    public function access()
    {
        //$this->connect();
        $this->logger->log('access'.($this->name ? '['.$this->name.']':''));
    }
    public function setIsolationLevel($isolationLevel)
    {
        $this->logger->log('setIsolationLevel:'.$isolationLevel);
        $this->isolationLevel = $isolationLevel;
    }
    public function getIsolationLevel()
    {
        return $this->isolationLevel;
    }
    public function setReadOnly($readOnly)
    {
        $this->logger->log('setReadOnly:'.($readOnly ? 'true':'false'));
        $this->readOnly = $readOnly;
    }

    public function setTimeout($seconds)
    {
        $this->logger->log('setTimeout:'.$seconds);
        $this->timeout = $seconds;
    }
    public function isNestedTransactionAllowed()
    {
        return $this->nestedTransactionAllowed;
    }
    public function beginTransaction($definition=null)
    {
        if(!$this->nestedTransactionAllowed && $this->depth>0)
            throw new \Exception('already transaction started.');
        $this->depth++;
        $this->logger->log('beginTransaction('.$this->depth.')'.($this->name ? '['.$this->name.']':''));
        if($this->maxDepth && ($this->depth > $this->maxDepth)) {
            $this->logger->log('BEGIN TRANSACTION ERROR('.$this->depth.')'.($this->name ? '['.$this->name.']':''));
            $this->depth--;
            throw new TestException('BEGIN TRANSACTION ERROR('.($this->depth+1).')'.($this->name ? '['.$this->name.']':''));
        }
        if($definition) {
            if(($isolation=$definition->getIsolationLevel())>0) {
                $this->setIsolationLevel($isolation);
            }
            if($definition->isReadOnly())
                $this->setReadOnly(true);
            if(($timeout=$definition->getTimeout())>0)
                $this->setTimeout($timeout);
        }
    }
    public function commit()
    {
        $this->logger->log('commit('.$this->depth.')'.($this->name ? '['.$this->name.']':''));
        if($this->depth<=0)
            throw new \Exception('no transaction.');
        $this->depth--;
        if($this->commitError) {
            $this->logger->log('COMMIT ERROR');
            throw new TestException('COMMIT ERROR');
        }
    }
    public function rollback()
    {
        $this->logger->log('rollback('.$this->depth.')'.($this->name ? '['.$this->name.']':''));
        if($this->depth<=0)
            throw new \Exception('no transaction.');
        $this->depth--;
        if($this->rollbackError) {
            $this->logger->log('ROLLBACK ERROR');
            throw new TestException('ROLLBACK ERROR');
        }
    }
    //public function createSavepoint()
    //{
    //    $this->savepointSerial++;
    //    $this->logger->log('createSavepoint('.$this->savepointSerial.')');
    //    return 'savepoint'.$this->savepointSerial;
    //}
    //public function releaseSavepoint($savepoint)
    //{
    //    $this->logger->log('releaseSavepoint('.$savepoint.')');
    //    if($this->commitError) {
    //        $this->logger->log('RELEASE SAVEPOINT ERROR');
    //        throw new \Exception('RELEASE SAVEPOINT ERROR');
    //    }
    //}
    //public function rollbackSavepoint($savepoint)
    //{
    //    $this->logger->log('rollbackSavepoint('.$savepoint.')');
    //}
    public function suspend()
    {
        $this->logger->log('suspend:txObject('.$this->depth.')'.($this->name ? '['.$this->name.']':''));
        if(!$this->suspendSupported) {
            $this->logger->log('suspend is not supported');
            throw new TestException('suspend is not supported');
        }
        //if($this->suspended) {
        //    $this->logger->log('already suspended');
        //    throw new TestException('already suspended');
        //}
        //$this->suspended = true;
        $object = array('txObject',$this->depth);
        $this->depth = 0;
        return $object;
    }
    public function resume($txObject)
    {
        list($txt,$depth) = $txObject;
        $this->logger->log('resume:'.$txt.'('.$depth.')'.($this->name ? '['.$this->name.']':''));
        if(!$this->suspendSupported) {
            $this->logger->log('suspend is not supported');
            throw new TestException('suspend is not supported');
        }
        $this->depth = $depth;
        //if(!$this->suspended) {
        //    $this->logger->log('not suspended');
        //    throw new TestException('not suspended');
        //}
        //$this->suspended = true;
    }
}

class TestSynchronization implements Synchronization
{
    public $synchronizationRegistry;
    public $entityManagerFactory;
    public $logger;
    public $beforeCompletionError;
    public $afterCompletionError;
    public $transactionManager;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setEntityManagerFactory($entityManagerFactory)
    {
        $this->entityManagerFactory = $entityManagerFactory;
    }

    public function setSynchronizationRegistry($synchronizationRegistry)
    {
        $this->synchronizationRegistry = $synchronizationRegistry;
    }

    public function setTransactionManager($transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    public function getCurrentEntityManager()
    {
        $this->logger->log('getCurrentEntityManager');
        //$key = $this->synchronizationRegistry->getTransactionKey();
        //if($key==null)
        //    throw new \Exception('transaction is not active.');
        $key = __CLASS__;
        $entityManager = $this->synchronizationRegistry->getResource($key);
        if($entityManager==null) {
            $this->synchronizationRegistry->registerInterposedSynchronization($this);
            $entityManager = $this->entityManagerFactory->createEntityManager();
            if($tx = $this->transactionManager->getTransaction()) {
                if($txDef = $tx->getDefinition()) {
                    $entityManager->txName = $txDef->getName();
                }
            }
            $this->synchronizationRegistry->putResource($key, $entityManager);
        }
        return $entityManager;
    }

    public function beforeCompletion()
    {
        $this->logger->log('beforeCompletion');
        //$key = $this->synchronizationRegistry->getTransactionKey();
        //if($key==null)
        //    return;
        $key = __CLASS__;
        $entityManager = $this->synchronizationRegistry->getResource($key);
        if($entityManager)
            $entityManager->flush();
    }
    public function afterCompletion($success)
    {
        $this->logger->log('afterCompletion('.($success ? 'true':'false').')');
        //$key = $this->synchronizationRegistry->getTransactionKey();
        //if($key==null)
        //    return;
        $key = __CLASS__;
        $entityManager = $this->synchronizationRegistry->getResource($key);
        if($entityManager) {
            $entityManager->close();
            $this->synchronizationRegistry->putResource($key, null);
        }
    }
}
class TestDataSource
{
    protected $logger;
    protected $transactionManager;
    protected $connection;
    public $commitError;
    public $rollbackError;
    public $nestedTransactionAllowed = true;
    public $suspendSupported;
    public $name;
    public $maxDepth;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setTransactionManager($transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    public function getConnection()
    {
        //$this->logger->log('getConnection');
        if($this->connection) {
            $this->enlistResource($this->connection);
            return $this->connection;
        }
        //$this->logger->log('newConnection');
        $connection =  new TestResourceManager();
        $connection->setLogger($this->logger);
        $connection->commitError = $this->commitError;
        $connection->rollbackError = $this->rollbackError;
        //$connection->invalidSavepoint = $this->invalidSavepoint;
        $connection->nestedTransactionAllowed = $this->nestedTransactionAllowed;
        $connection->suspendSupported = $this->suspendSupported;
        $connection->name = $this->name;
        $connection->maxDepth = $this->maxDepth;
        $this->enlistResource($connection);
        $this->connection = $connection;
        return $connection;
    }

    public function enlistResource($connection)
    {
        $transaction = $this->transactionManager->getTransaction();
        if($transaction)
            $transaction->enlistResource($connection);
        else
            $this->logger->log('transaction is null');
    }
}

class TestEntityManagerFactory
{
    public $dataSource;
    protected $logger;
    public $flushError;
    public $closeError;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setDataSource($dataSource)
    {
        $this->dataSource = $dataSource;
    }

    public function createEntityManager()
    {
        $this->logger->log('createEntityManager');
        $entityManager = new TestEntityManager($this->dataSource);
        $entityManager->flushError = $this->flushError;
        $entityManager->closeError = $this->closeError;
        $entityManager->setLogger($this->logger);
        return $entityManager;
    }
}

class TestEntityManager
{
    public $serialNumber;
    public $resourceManager;
    protected $logger;
    public $flushError;
    public $closeError;
    public $txName;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    public function __construct($dataSource = null)
    {
        $this->dataSource = $dataSource;
    }
    public function getConnection()
    {
        return $this->dataSource->getConnection();
    }

    public function readAction()
    {
        $this->logger->log('readAction'.($this->txName ? '['.$this->txName.']':''));
        $this->getConnection()->access();
    }

    public function action()
    {
        $this->logger->log('action'.($this->txName ? '['.$this->txName.']':''));
    }

    public function flush()
    {
        //$this->logger->log('flush'.($this->txName ? '['.$this->txName.']':''));
        $this->getConnection()->access();
        if($this->flushError) {
            $this->logger->log('FLUSH ERROR');
            throw new \Exception('FLUSH ERROR');
        }
    }
    public function close()
    {
        //$this->logger->log('close'.($this->txName ? '['.$this->txName.']':''));
        $this->dataSource = null;
        if($this->closeError) {
            $this->logger->log('CLOSE ERROR');
            throw new \Exception('CLOSE ERROR');
        }
    }
}

class TestEntityManagerProxy
{
    public $entityManagerHolder;
    public function setEntityManagerHolder($entityManagerHolder)
    {
        $this->entityManagerHolder = $entityManagerHolder;
    }
    public function __call($method,array $params)
    {
        $entityManager = $this->entityManagerHolder->getCurrentEntityManager();
        return call_user_func_array(array($entityManager,$method),$params);
    }

    public function getSychronization()
    {
        return $this->entityManagerHolder;
    }
}

/**
* @TransactionManagement()
*/
class TestDao
{
    protected $entityManager;
    protected $dataSource;
    protected $logger;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setEntityManager($entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function setDataSource($dataSource)
    {
        $this->dataSource = $dataSource;
    }

    /**
    *  @TransactionAttribute(value='required',isolation='serializable')
    */
    public function testCommit($failure=false,$readAccess=false,$resource='orm')
    {
        $this->logger->log('in testCommit');
        switch($resource){
            case 'orm':
                if($readAccess)
                    $this->entityManager->readAction();
                $this->entityManager->action();
                break;
            case 'dataSource':
            case 'dataSourceLast':
                $this->dataSource->getConnection()->access();
                break;
        }
        if($failure)
            throw new TestException("error");
        $this->logger->log('out testCommit');
    }

    public function testRollback()
    {
        $this->logger->log('in testRollback');
        $this->entityManager->action();
        $this->logger->log('throw testRollback');
        throw new TestException("error");
    }

    /**
    *  @TransactionAttribute('required')
    */
    public function testTierOne($failure=false)
    {
        $this->logger->log('in testTierOne');
        $this->testCommit($failure);
        $this->logger->log('out testTierOne');
    }

    /**
    *  @TransactionAttribute('required')
    */
    public function testTierOneWithAccess($failure=false,$readAccess=false,$resource='orm',$failureTier1=false)
    {
        $this->logger->log('in testTierOne');
        switch($resource){
            case 'orm':
                if($readAccess)
                    $this->entityManager->readAction();
                $this->entityManager->action();
                break;
            case 'dataSource':
                $this->dataSource->getConnection()->access();
                break;
        }
        $this->testCommit($failure,false,$resource);
        if($resource=='dataSourceLast')
            $this->dataSource->getConnection()->access();
        if($failureTier1) {
            $this->logger->log('failure testTierOne');
            throw new TestException('failure testTierOne');
        }
        $this->logger->log('out testTierOne');
    }

    public function testNonOperation()
    {
    }

}

class Test extends TestCase
{
    public function setUp()
    {
    }

    public function getConfig()
    {
        return self::getStaticConfig();
    }

    public static function getStaticConfig()
    {
        $config = array(
            'module_manager' => array(
                'modules' => array(
                    'Rindow\Aop\Module' => true,
                    'Rindow\Transaction\Local\Module' => true,
                    //'Rindow\Module\Monolog\Module' => true,
                ),
                'annotation_manager' => true,
                'enableCache' => false,
            ),
            'aop' => array(
                //'debug' => true,
                'plugins' => array(
                    'Rindow\\Transaction\\Support\\AnnotationHandler'=>true,
                ),
                //'intercept_to' => array(
                //    __NAMESPACE__.'\TestDao'=>true,
                //),
                'transaction' => array(
                    'defaultTransactionManager' => 'LocalTransactionManager',
                    'managers' => array(
                        'LocalTransactionManager' => array(
                            'transactionManager' => __NAMESPACE__.'\TestTransactionManager',
                            'advisorClass' => 'Rindow\Transaction\Support\TransactionAdvisor',
                        ),
                    ),
                ),
            ),
            'container' => array(
                'component_paths' => array(
                    __DIR__ => true,
                ),
                'aliases' => array(
                    'TestLogger' => __NAMESPACE__.'\TestLogger',
                ),
                'components' => array(
                    __NAMESPACE__.'\TestLogger'=> array('proxy'=>'disable'),
                    __NAMESPACE__.'\TestDataSource' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'TestLogger'),
                            //'debug' => array('value' => true),
                            'transactionManager'=>array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                        ),
                    ),
                    __NAMESPACE__.'\TestTransactionManager' => array(
                        'class'=>'Rindow\Transaction\Local\TransactionManager',
                        //'properties' => array(
                        //    'useSavepointForNestedTransaction' => array('value'=>true),
                        //),
                    ),
                    __NAMESPACE__.'\TestTransactionSynchronizationRegistry' => array(
                        'class'=>'Rindow\Transaction\Support\TransactionSynchronizationRegistry',
                        'properties' => array(
                            'transactionManager' => array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                        ),
                    ),
                    __NAMESPACE__.'\TestSynchronization' => array(
                        'properties' => array(
                            'entityManagerFactory' => array('ref'=>__NAMESPACE__.'\TestEntityManagerFactory'),
                            'transactionManager' => array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                            'synchronizationRegistry' => array('ref'=>__NAMESPACE__.'\TestTransactionSynchronizationRegistry'),
                            'logger' => array('ref'=>'TestLogger'),
                        ),
                    ),
                    __NAMESPACE__.'\TestEntityManagerFactory' => array(
                        'properties' => array(
                            'dataSource' => array('ref'=>__NAMESPACE__.'\TestDataSource'),
                            'logger' => array('ref'=>'TestLogger'),
                        ),
                    ),
                    __NAMESPACE__.'\TestEntityManagerProxy' => array(
                        'properties' => array(
                            'entityManagerHolder' => array('ref'=>__NAMESPACE__.'\TestSynchronization'),
                        ),
                    ),
                    //__NAMESPACE__.'\TestTransactionAdvisor' => array(
                    //    'class' => 'Rindow\Transaction\Support\TransactionAdvisor',
                    //    'properties' => array(
                    //        'transactionManager' => array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                    //    ),
                    //),
                    __NAMESPACE__.'\TestDao' => array(
                        'properties' => array(
                            'dataSource' => array('ref'=>__NAMESPACE__.'\TestDataSource'),
                            'entityManager' => array('ref'=>__NAMESPACE__.'\TestEntityManagerProxy'),
                            'logger' => array('ref'=>'TestLogger'),
                        ),
                    ),
                ),
            ),
            'monolog' => array(
                'handlers' => array(
                    'default' => array(
                        'path'  => __DIR__.'/test.log',
                    ),
                ),
            ),
        );
        return $config;
    }

    public function testRequiredCommit()
    {
        $config = $this->getConfig();
        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $logger->log('before Call testCommit.');
        $test->testCommit($failure=false);
        $logger->log('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testCommit',
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'out testCommit',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'setIsolationLevel:4',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);

        $this->assertEquals(
            array('RindowTest\Transaction\Local\AnnotationHandlerTest\TestDao'=>true),
            $mm->getServiceLocator()->get('AopManager')->getInterceptTargets());
    }
}