<?php
namespace RindowTest\Transaction\Local\TransactionAdvisorTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\ResourceManager;
use Interop\Lenient\Transaction\TransactionDefinition as TransactionDefinitionInterface;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\TransactionDefinition;
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
        //$this->logger->log('getCurrentEntityManager');
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

    public function testTierOne($failure=false)
    {
        $this->logger->log('in testTierOne');
        $this->testCommit($failure);
        $this->logger->log('out testTierOne');
    }

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
        usleep( RINDOW_TEST_CLEAR_CACHE_INTERVAL );
        \Rindow\Stdlib\Cache\CacheFactory::clearCache();
        usleep( RINDOW_TEST_CLEAR_CACHE_INTERVAL );
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
                ),
            ),
            'aop' => array(
                'intercept_to' => array(
                    __NAMESPACE__=>true,
                ),
            ),
            'container' => array(
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
                        'properties' => array(
                            //'useSavepointForNestedTransaction' => array('value'=>true),
                            //'logger' => array('ref'=>'TestLogger'),
                            //'debug' => array('value'=>true),
                        ),
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
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'class' => 'Rindow\Transaction\Support\TransactionAdvisor',
                        'properties' => array(
                            'transactionManager' => array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                        ),
                    ),
                    __NAMESPACE__.'\TestDao' => array(
                        'properties' => array(
                            'dataSource' => array('ref'=>__NAMESPACE__.'\TestDataSource'),
                            'entityManager' => array('ref'=>__NAMESPACE__.'\TestEntityManagerProxy'),
                            'logger' => array('ref'=>'TestLogger'),
                        ),
                    ),
                ),
            ),
        );
        return $config;
    }

    public function testRequiredCommit()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTest1',
                                    'timeout'=>300,
                                    'isolation'=>'serializable',
                                    'read_only'=>true,
                                    'no_rollback_for'=>__NAMESPACE__.'\TestException',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            //'transaction'=> array(
            //    'advisors' => array(
            //        __NAMESPACE__.'\TestTransactionAdvisor',
            //    ),
            //),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $logger->log('before Call testCommit.');
        $test->testCommit($failure=false);
        $logger->log('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTest1]',
            'out testCommit',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'setIsolationLevel:'.TransactionDefinition::ISOLATION_SERIALIZABLE,
            'setReadOnly:true',
            'setTimeout:300',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testRequiredRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testRollback())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testCommit.');
        try {
            $test->testRollback();
        } catch(TestException $e) {
            $logger->log('catch TestException.');
        }
        $logger->log('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testRollback',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action',
            'throw testRollback',
            'afterCompletion(false)',
            'catch TestException.',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testMandatoryActiveTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'mandatory' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOne())',
                                'attributes' => array(
                                    'name'=>'txTestTierOne',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $test->testTierOne();
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOne]',
            'out testCommit',
            'out testTierOne',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    /**
     * @expectedException Rindow\Transaction\Exception\IllegalStateException
     */
    public function testMandatoryNoTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'mandatory' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $test->testCommit();
    }

    public function testRequiresNewActiveTransactionNormal()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $test->testTierOneWithAccess($failure=false,$readAccess=false);
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            'action[txTestCommit]',
            'out testCommit',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'out testTierOne',
            'beforeCompletion',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testRequiresNewActiveTransactionOnConnectedResource()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        try {
            $test->testTierOneWithAccess($failure=false,$readAccess=true);
        } catch(\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $this->assertEquals('suspend failure',$exceptionMessage);
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'readAction[txTestTierOneWithAccess]',
            //'connect',
            'beginTransaction(1)',
            'access',
            'action[txTestTierOneWithAccess]',
            'suspend:txObject(1)',
            'suspend is not supported',
            'rollback(1)',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testRequiresNewOnNoResourceTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOne())',
                                'attributes' => array(
                                    'name'=>'txTestTierOne',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $test->testTierOne();
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestCommit]',
            'out testCommit',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',               // *** CAUTION ***
            'afterCompletion(true)',   // It start futility transaction after
            //'beginTransaction(1)',   // to resume to connected transaction.
            'out testTierOne',         // Because the TransactionManager can not sense
            //'beforeCompletion',      // when a program access to resource
            //'commit(1)',             // on connected transaction.
            //'afterCompletion(true)', // If a program never access to resource,
            'after Call testTierOne.', // a transaction will be futility.
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testRequiresNewActiveTransactionRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess($failure=true);
        } catch (TestException $e) {
            ;
        }
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            'action[txTestCommit]',
            'afterCompletion(false)',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testRequiresNewCommitsAndTierOneRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        try {
            $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='orm',$failureTier1=true);
        } catch (TestException $e) {
            $exceptionMessage = $e->getMessage();
        }
        $this->assertEquals('failure testTierOne',$exceptionMessage);
        $logger->log('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            'action[txTestCommit]',
            'out testCommit',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            //'beginTransaction(1)',
            'failure testTierOne',
            //'rollback(1)',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testSupports()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'supports' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOne())',
                                'attributes' => array(
                                    'name'=>'txTestTierOne',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $test->testTierOne();
        $logger->log('after Call testTierOne.');

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOne]',
            'out testCommit',
            'out testTierOne',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNotSupportedWithORM()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        try {
            $test->testTierOneWithAccess();
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals('transaction is not active.',$exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'in testCommit',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNotSupportedWithoutORM()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        try {
            $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='dataSourceLast');
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertNull($exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            //'getConnection',
            //'newConnection',
            'transaction is null',
            //'connect',
            'access',
            'out testCommit',
            //'getConnection',
            'beginTransaction(1)',
            'access',
            'out testTierOne',
            'commit(1)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNotSupportedRollbackWithORM()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess($failure=true);
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals('transaction is not active.',$exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'in testCommit',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNotSupportedRollbackWithoutORMTier2()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess($failure=true,$readAccess=false,$resource='dataSourceLast');
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals('error',$exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            //'getConnection',
            //'newConnection',
            'transaction is null',
            //'connect',
            'access',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNotSupportedRollbackWithoutORMTier1()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='dataSourceLast',$failureTier1=true);
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals('failure testTierOne',$exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            //'getConnection',
            //'newConnection',
            'transaction is null',
            //'connect',
            'access',
            'out testCommit',
            //'getConnection',
            'beginTransaction(1)',
            'access',
            'failure testTierOne',
            'rollback(1)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNeverNonOperation()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'never' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testNonOperation())',
                                'attributes' => array(
                                    'name'=>'txTestNonOperation',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');

        $logger->log('before Call testCommit.');
        $test->testNonOperation();
        $logger->log('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'after Call testCommit.',
        );

        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNeverWithoutSynchronization()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'never' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit()) || '.
                                                'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');

        $logger->log('before Call testCommit.');
        $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='dataSourceLast',$failureTier1=false);
        $logger->log('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testTierOne',
            'in testCommit',
            //'getConnection',
            //'newConnection',
            'transaction is null',
            //'connect',
            'access',
            'out testCommit',
            //'getConnection',
            'transaction is null',
            'access',
            'out testTierOne',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNeverActiveTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'never' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testCommit())',
                                'attributes' => array(
                                    'name'=>'txTestCommit',
                                ),
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::testTierOneWithAccess())',
                                'attributes' => array(
                                    'name'=>'txTestTierOneWithAccess',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');

        $logger->log('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='orm',$failureTier1=false);
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals("Existing transaction found for transaction marked with propagation 'never'",$exceptionMessage);
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTestTierOneWithAccess]',
            'afterCompletion(false)',
            'after Call testTierOne.',
        );

        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testNoRollbackFor()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    __NAMESPACE__.'\TestTransactionAdvisor' => array(
                        'advices' => array(
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestDao::test*())',
                                'attributes' => array(
                                    'no_rollback_for'=>__NAMESPACE__.'\TestException',
                                    'name'=>'txTest',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        try {
            $test->testTierOneWithAccess($failure=true,$readAccess=false,$resource='orm');
        } catch (TestException $e) {
            $exceptionMessage = $e->getMessage();
        }
        $logger->log('after Call testTierOne.');
        $this->assertEquals("error",$exceptionMessage);

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action[txTest]',
            'in testCommit',
            'action[txTest]',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }

    public function testLocalDefaultTransactionAdvisor()
    {
        $config = array(
            'module_manager' => array(
                'modules' => array(
                    'Rindow\Aop\Module' => true,
                ),
            ),
            'aop' => array(
                'aspects' => array(
                    'Rindow\\Transaction\\DefaultTransactionAdvisor' => array(
                        'pointcuts' => array(
                            'test'=>'execution('.__NAMESPACE__.'\TestDao::test*())',
                        ),
                        'advices' => array(
                            'required' => array(
                                'pointcut_ref' => array(
                                    'test' => true,
                                ),
                            ),
                        ),
                    ),
                ),
                'transaction' => array(
                    'defaultTransactionManager' => __NAMESPACE__.'\TestTransactionManager',
                    //'managers' => array(
                    //    __NAMESPACE__.'\TestTransactionManager' => array(
                    //        'transactionManager' => __NAMESPACE__.'\TestTransactionManager',
                    //        'advisorClass' => 'Rindow\\Transaction\\Support\\TransactionAdvisor',
                    //    ),
                    //),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDao');
        $logger = $mm->getServiceLocator()->get('TestLogger');
        $logger->log('before Call testTierOne.');
        $exceptionMessage = null;
        $test->testTierOneWithAccess($failure=false,$readAccess=false,$resource='orm');
        $logger->log('after Call testTierOne.');

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            'action',
            'in testCommit',
            'action',
            'out testCommit',
            'out testTierOne',
            'beforeCompletion',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }
}
