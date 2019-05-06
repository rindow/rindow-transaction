<?php
namespace RindowTest\Transaction\Local\TransactionManagerWithSynchronizationTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\ResourceManager;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\TransactionDefinition as TransactionDefinitionInterface;
use Interop\Lenient\Transaction\Status;
use Rindow\Container\ModuleManager;
use Rindow\Transaction\Support\TransactionDefinition;

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
    //    call_user_func($this->listener);
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
    //    if($this->createSavepointError) {
    //        $this->logger->log('CREATESAVEPOINT ERROR');
    //        throw new \Exception('CREATESAVEPOINT ERROR');
    //    }
    //    return 'savepoint'.$this->savepointSerial;
    //}
    //public function releaseSavepoint($savepoint)
    //{
    //    $this->logger->log('releaseSavepoint('.$savepoint.')');
    //    if($this->commitError) {
    //        $this->logger->log('RELEASE SAVEPOINT ERROR');
    //        throw new \Exception('RELEASE SAVEPOINT ERROR');
    //    }
    //    if($this->releaseSavepointError) {
    //        $this->logger->log('RELEASESAVEPOINT ERROR');
    //        throw new \Exception('RELEASESAVEPOINT ERROR');
    //    }
    //}
    //public function rollbackSavepoint($savepoint)
    //{
    //    $this->logger->log('rollbackSavepoint('.$savepoint.')');
    //    if($this->rollbackSavepointError) {
    //        $this->logger->log('ROLLBACKSAVEPOINT ERROR');
    //        throw new \Exception('ROLLBACKSAVEPOINT ERROR');
    //    }
    //}
    //public function isConnected()
    //{
    //    return $this->connected;
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
        //    throw new Exception\DomainException('transaction is not actvie.');
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
    //public $invalidSavepoint;
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
        $this->logger->log('getConnection');
        if($this->connection) {
            $this->enlistResource($this->connection);
            return $this->connection;
        }
        $this->logger->log('newConnection');
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
        $this->logger->log('flush'.($this->txName ? '['.$this->txName.']':''));
        $this->getConnection()->access();
        if($this->flushError) {
            $this->logger->log('FLUSH ERROR');
            throw new \Exception('FLUSH ERROR');
        }
    }
    public function close()
    {
        $this->logger->log('close'.($this->txName ? '['.$this->txName.']':''));
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

class Test extends TestCase
{
    public function setUp()
    {
    }

    public function dumpTrace($e)
    {
        while($e) {
            echo "------------------\n";
            echo $e->getMessage()."\n";
            echo $e->getFile().'('.$e->getLine().')'."\n";
            echo $e->getTraceAsString();
            $e = $e->getPrevious();
        }
    }

    public function getConfig()
    {
        $config = array(
            'module_manager' => array(
                'modules' => array(
                ),
                'enableCache' => false,
            ),
            'container' => array(
                'aliases' => array(
                    'Logger' => __NAMESPACE__.'\TestLogger',
                ),
                'components' => array(
                    __NAMESPACE__.'\TestLogger'=> array('proxy'=>'disable'),
                    __NAMESPACE__.'\TestDataSource' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'Logger'),
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
                            'synchronizationRegistry' => array('ref'=>__NAMESPACE__.'\TestTransactionSynchronizationRegistry'),
                            'transactionManager' => array('ref'=>__NAMESPACE__.'\TestTransactionManager'),
                            'logger' => array('ref'=>'Logger'),
                        ),
                    ),
                    __NAMESPACE__.'\TestEntityManagerFactory' => array(
                        'properties' => array(
                            'dataSource' => array('ref'=>__NAMESPACE__.'\TestDataSource'),
                            'logger' => array('ref'=>'Logger'),
                        ),
                    ),
                    __NAMESPACE__.'\TestEntityManagerProxy' => array(
                        'properties' => array(
                            'entityManagerHolder' => array('ref'=>__NAMESPACE__.'\TestSynchronization'),
                        ),
                    ),
                ),
            ),
        );
        return $config;
    }

    public function testNormalCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $logger->log('begin txLevel1');
        $txLevel1 = $transactionManager->begin();
        $logger->log('in txLevel1');
        $this->assertEquals(spl_object_hash($txLevel1),spl_object_hash($transactionManager->getTransaction()));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());
        $testEntityManagerProxy->action();
        $logger->log('commit txLevel1');
        $transactionManager->commit();
        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertFalse($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_COMMITTED,$txLevel1->getStatus());
        $result = array(
            'begin txLevel1',
            'in txLevel1',
            'getCurrentEntityManager',
            'createEntityManager',
            //'setConnectedEventListener',
            'action',
            'commit txLevel1',
            'beforeCompletion',
            'flush',
            'getConnection',
            'newConnection',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnTailCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $logger->log('begin txLevel1');
        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $txLevel1 = $transactionManager->begin($definition);
        $logger->log('in txLevel1');
        $this->assertEquals(spl_object_hash($txLevel1),spl_object_hash($transactionManager->getTransaction()));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());

        $logger->log('begin txLevel2');
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $txLevel2 = $transactionManager->begin($definition);
        $logger->log('in txLevel2');
        $this->assertEquals(spl_object_hash($txLevel2),spl_object_hash($transactionManager->getTransaction()));
        $this->assertNotEquals(spl_object_hash($txLevel2),spl_object_hash($txLevel1));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel2->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());

        $testEntityManagerProxy->action();

        $logger->log('commit txLevel2');
        $transactionManager->commit();
        $this->assertEquals(spl_object_hash($txLevel1),spl_object_hash($transactionManager->getTransaction()));
        $this->assertTrue($transactionManager->isActive());
        $this->assertFalse($txLevel2->isActive());
        $this->assertTrue($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_COMMITTED,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());

        $logger->log('commit txLevel1');
        $transactionManager->commit();
        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertFalse($txLevel2->isActive());
        $this->assertFalse($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_COMMITTED,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_COMMITTED,$txLevel1->getStatus());

        $result = array(
            'begin txLevel1',
            'in txLevel1',

            'begin txLevel2',
            'in txLevel2',

            'getCurrentEntityManager',
            'createEntityManager',
            //'setConnectedEventListener',
            'action[tx2]',

            'commit txLevel2',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'connect',
            'beginTransaction(1)',
            //'createSavepoint(1)',
            'beginTransaction(2)',
            'access',
            //'releaseSavepoint(savepoint1)',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2]',

            'commit txLevel1',
            //'beforeCompletion',      // *** CAUTION ***
            'commit(1)',                  // change the position of 'synchronization' object
            //'afterCompletion(true)', // from a shared component to a closed component
            //                         // in a each part of nested transaction.
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnEachLevelPart1Commit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2]',
            'beforeCompletion',
            'flush[tx1]',
            'getConnection',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnEachLevelPart2Commit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $transactionManager->begin($definition);
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $testEntityManagerProxy->action();
        $transactionManager->commit();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'beforeCompletion',
            'flush[tx1]',
            'getConnection',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2Include2TransactionAndAccessOnEachLevelPart2Commit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $transactionManager->begin($definition);
        $definition = new TransactionDefinition();
        $definition->setName('tx2-1');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $definition = new TransactionDefinition();
        $definition->setName('tx2-2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $testEntityManagerProxy->action();
        $transactionManager->commit();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2-1]',
            'beforeCompletion',
            'flush[tx2-1]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2-1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2-2]',
            'beforeCompletion',
            'flush[tx2-2]',
            'getConnection',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2-2]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'beforeCompletion',
            'flush[tx1]',
            'getConnection',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNoConnectionCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $transactionManager->begin();
        $transactionManager->begin();
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNormalRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $txLevel1 = $transactionManager->begin();
        $this->assertEquals(spl_object_hash($txLevel1),spl_object_hash($transactionManager->getTransaction()));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());
        $testEntityManagerProxy->action();
        $transactionManager->rollback();
        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertFalse($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel1->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            //'getConnection',
            //'newConnection',
            //'setConnectedEventListener',
            'action',
            'afterCompletion(false)',
            'close',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testAccessAndRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');

        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $transactionManager->rollback();

        $result = array(
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'rollback(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2CommitAndRollbackPart1()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $transactionManager->rollback();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2]',
            'rollback(1)',
            'afterCompletion(false)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2CommitAndRollbackPart2()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $transactionManager->begin($definition);
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $testEntityManagerProxy->action();
        $transactionManager->rollback();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'afterCompletion(true)',
            'close[tx2]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'rollback(1)',
            'afterCompletion(false)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2RollbackOnly()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $definition = new TransactionDefinition();
        $definition->setName('tx1');
        $txLevel1 = $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $definition = new TransactionDefinition();
        $definition->setName('tx2');
        $txLevel2 = $transactionManager->begin($definition);

        $transactionManager->getTransaction()->setRollbackOnly();

        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel2->getStatus());
        $transactionManager->commit();
        $this->assertEquals(Status::STATUS_COMMITTED,$txLevel1->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'afterCompletion(false)',
            'close[tx2]',
            'beforeCompletion',
            'flush[tx1]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInCommitting()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $dataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $dataSource->commitError = true;

        try {
            $txLevel1 = $transactionManager->begin();
            $testEntityManagerProxy->action();
            $transactionManager->commit();
        } catch(TestException $e) {
            $logger->log('exception:'.$e->getMessage());
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$txLevel1->getStatus());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'beforeCompletion',
            'flush',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'COMMIT ERROR',
            'afterCompletion(false)',
            'close',
            'exception:COMMIT ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInFlushing()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $entityManagerFactory = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerFactory');
        $entityManagerFactory->flushError = true;

        try {
            $txLevel1 = $transactionManager->begin();
            $testEntityManagerProxy->action();
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel1->getStatus());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'beforeCompletion',
            'flush',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'FLUSH ERROR',
            'rollback(1)',
            'afterCompletion(false)',
            'close',
            'exception:FLUSH ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInClosing()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $entityManagerFactory = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerFactory');
        $entityManagerFactory->closeError = true;

        try {
            $txLevel1 = $transactionManager->begin();
            $testEntityManagerProxy->action();
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$txLevel1->getStatus());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'beforeCompletion',
            'flush',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close',
            'CLOSE ERROR',
            'exception:CLOSE ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $dataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $dataSource->rollbackError = true;

        try {
            $txLevel1 = $transactionManager->begin();
            $testEntityManagerProxy->action();
            $dataSource->getConnection()->access();
            $transactionManager->rollback();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$txLevel1->getStatus());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'rollback(1)',
            'ROLLBACK ERROR',
            'afterCompletion(false)',
            'close',
            'exception:ROLLBACK ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInFlushingAndRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $entityManagerFactory = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerFactory');
        $entityManagerFactory->flushError = true;
        $dataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $dataSource->rollbackError = true;

        try {
            $txLevel1 = $transactionManager->begin();
            $testEntityManagerProxy->action();
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$txLevel1->getStatus());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action',
            'beforeCompletion',
            'flush',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'FLUSH ERROR',
            'rollback(1)',
            'ROLLBACK ERROR',
            'afterCompletion(false)',
            'close',
            'exception:FLUSH ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInLevel2Commiting()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $dataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $dataSource->commitError = true;

        try {
            $definition = new TransactionDefinition();
            $definition->setName('tx1');
            $transactionManager->begin($definition);
            try {
                $testEntityManagerProxy->action();
                $definition = new TransactionDefinition();
                $definition->setName('tx2');
                $transactionManager->begin($definition);
                try {
                    $testEntityManagerProxy->action();
                } catch(\Exception $e) {
                    $logger->log('exception in level2 transaction:'.$e->getMessage());
                    $transactionManager->rollback();
                    throw $e;
                }
                $logger->log('commiting level 2');
                $transactionManager->commit();
            } catch(\Exception $e) {
                $logger->log('exception at level1 transaction:'.$e->getMessage());
                $transactionManager->rollback();
                throw $e;
            }
            $logger->log('commiting level 1');
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'commiting level 2',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'COMMIT ERROR',
            'afterCompletion(false)',
            'close[tx2]',
            'exception at level1 transaction:COMMIT ERROR',
            'rollback(1)',
            'afterCompletion(false)',
            'close[tx1]',
            'exception:COMMIT ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }


    public function testErrorInLevel2Flush()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $entityManagerFactory = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerFactory');
        $entityManagerFactory->flushError = true;

        try {
            $definition = new TransactionDefinition();
            $definition->setName('tx1');
            $transactionManager->begin($definition);
            try {
                $testEntityManagerProxy->action();
                $definition = new TransactionDefinition();
                $definition->setName('tx2');
                $transactionManager->begin($definition);
                try {
                    $testEntityManagerProxy->action();
                } catch(\Exception $e) {
                    $logger->log('exception in level2 transaction:'.$e->getMessage());
                    $transactionManager->rollback();
                    throw $e;
                }
                $logger->log('commiting level 2');
                $transactionManager->commit();
            } catch(\Exception $e) {
                $logger->log('exception at level1 transaction:'.$e->getMessage());
                $transactionManager->rollback();
                throw $e;
            }
            $logger->log('commiting level 1');
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2]',
            'commiting level 2',
            'beforeCompletion',
            'flush[tx2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'FLUSH ERROR',
            'rollback(2)',
            'afterCompletion(false)',
            'close[tx2]',
            'exception at level1 transaction:FLUSH ERROR',
            'rollback(1)',
            'afterCompletion(false)',
            'close[tx1]',
            'exception:FLUSH ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testErrorInLevel2TransactionBegin()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $dataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $dataSource->maxDepth = 1;

        try {
            $definition = new TransactionDefinition();
            $definition->setName('tx1');
            $transactionManager->begin($definition);
            try {
                $testEntityManagerProxy->action();
                $definition = new TransactionDefinition();
                $definition->setName('tx2-1');
                $transactionManager->begin($definition);
                try {
                    $testEntityManagerProxy->action();
                } catch(\Exception $e) {
                    $logger->log('exception in level2-1 transaction:'.$e->getMessage());
                    $transactionManager->rollback();
                    throw $e;
                }
                $logger->log('commiting level 2-1');
                $transactionManager->commit();
            } catch(\Exception $e) {
                $logger->log('exception at level2-1 transaction:'.$e->getMessage());
                $transactionManager->rollback();// rollback tx1
                throw $e;
            }
            try {
                $testEntityManagerProxy->action();
                $definition = new TransactionDefinition();
                $definition->setName('tx2-2');
                $transactionManager->begin($definition);
                try {
                    $testEntityManagerProxy->action();
                } catch(\Exception $e) {
                    $logger->log('exception in level2-2 transaction:'.$e->getMessage());
                    $transactionManager->rollback();
                    throw $e;
                }
                $logger->log('commiting level 2-2');
                $transactionManager->commit();
            } catch(\Exception $e) {
                $logger->log('exception at level2-2 transaction:'.$e->getMessage());
                $transactionManager->rollback();
                throw $e;
            }
            $logger->log('commiting level 1');
            $transactionManager->commit();
        } catch(\Exception $e) {
            $logger->log('exception:'.$e->getMessage());
        }

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx2-1]',
            'commiting level 2-1',
            'beforeCompletion',
            'flush[tx2-1]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'BEGIN TRANSACTION ERROR(2)',
            'afterCompletion(false)',
            'close[tx2-1]',
            'exception at level2-1 transaction:BEGIN TRANSACTION ERROR(2)',
            'rollback(1)',
            'afterCompletion(false)',
            'close[tx1]',
            'exception:BEGIN TRANSACTION ERROR(2)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndResumeEachAccess()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $definition = new TransactionDefinition();
        $definition->setName('tx1-1');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $txObjs = $transactionManager->suspend();
        $definition = new TransactionDefinition();
        $definition->setName('tx1-2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $transactionManager->resume($txObjs);
        $transactionManager->commit();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1-1]',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1-2]',
            'beforeCompletion',
            'flush[tx1-2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1-2]',
            'beforeCompletion',
            'flush[tx1-1]',
            'getConnection',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1-1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndResumeTier1NoAccess()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $definition = new TransactionDefinition();
        $definition->setName('tx1-1');
        $transactionManager->begin($definition);
        $txObjs = $transactionManager->suspend();
        $definition = new TransactionDefinition();
        $definition->setName('tx1-2');
        $transactionManager->begin($definition);
        $testEntityManagerProxy->action();
        $transactionManager->commit();
        $transactionManager->resume($txObjs);
        $transactionManager->commit();

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1-2]',
            'beforeCompletion',
            'flush[tx1-2]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1-2]',
            //'beginTransaction',      // *** CAUTION ***
            //'beforeCompletion',      // transaction will be started without access.
            //'commit',                // Because tx-mgr can not sense a access on
            //'afterCompletion(true)', // connected resource.
            //                         // *** CAUTION ***
            //                         // See 'testNestLevel2AndAccessOnTailCommit'
            //                         // about 'beforeCompletion' and 'afterCompletion(true)'
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndResumeConnectedResourceWithoutResourceSupport()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $exceptionMessage = null;
        try {
            $definition = new TransactionDefinition();
            $definition->setName('tx1-1');
            $transactionManager->begin($definition);
            $testEntityManagerProxy->readAction();
            $txObjs = $transactionManager->suspend();
            //$definition = new TransactionDefinition();
            //$definition->setName('tx1-2');
            //$transactionManager->begin($definition);
            //$testEntityManagerProxy->action();
            //$transactionManager->commit();
            //$transactionManager->resume($txObjs);
            //$transactionManager->commit();
        } catch(\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $this->assertEquals('suspend failure',$exceptionMessage);

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'readAction[tx1-1]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'suspend:txObject(1)',
            'suspend is not supported',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndResumeConnectedResourceWithResourceSupport()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testEntityManagerProxy = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestEntityManagerProxy');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $testDataSource->suspendSupported = true;

        $exceptionMessage = null;
        try {
            $definition = new TransactionDefinition();
            $definition->setName('tx1-1');
            $transactionManager->begin($definition);
            $testEntityManagerProxy->readAction();
            $txObjs = $transactionManager->suspend();
            $definition = new TransactionDefinition();
            $definition->setName('tx1-2');
            $transactionManager->begin($definition);
            $testEntityManagerProxy->action();
            $transactionManager->commit();
            $transactionManager->resume($txObjs);
            $transactionManager->commit();
        } catch(\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }
        $this->assertNull($exceptionMessage);

        $result = array(
            'getCurrentEntityManager',
            'createEntityManager',
            'readAction[tx1-1]',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'suspend:txObject(1)',
            'getCurrentEntityManager',
            'createEntityManager',
            'action[tx1-2]',
            'beforeCompletion',
            'flush[tx1-2]',
            'getConnection',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1-2]',
            'resume:txObject(1)',
            'beforeCompletion',
            'flush[tx1-1]',
            'getConnection',
            'access',
            'commit(1)',
            'afterCompletion(true)',
            'close[tx1-1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }
}
