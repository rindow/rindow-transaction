<?php
namespace RindowTest\Transaction\Local\TransactionManagerWithoutSynchronizationTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\ResourceManager;
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
}

class TestResourceManager implements ResourceManager
{
    //public $listener;
    public $isolationLevel = TransactionDefinitionInterface::ISOLATION_DEFAULT;
    public $timeout;
    public $nestedTransactionAllowed = true;
    //public $allowSavepointForNestedTransaction = true;
    //public $savepointSerial = 0;
    //public $connected = false;
    public $logger;
    public $commitError;
    public $rollbackError;
    //public $invalidSavepoint;
    public $suspendSupported;
    //public $suspended =false;
    public $depth = 0;
    public $name;
    public $maxDepth;

    public function getName()
    {
        return $this->name;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
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
        $this->logger->log('setIsolationLevel');
        $this->isolationLevel = $isolationLevel;
    }
    public function getIsolationLevel()
    {
        return $this->isolationLevel;
    }

    public function setTimeout($seconds)
    {
        $this->logger->log('setTimeout');
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
    //    if($this->invalidSavepoint)
    //        return null;
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
        $config = array(
            'module_manager' => array(
                'modules' => array(
                ),
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
                    __NAMESPACE__.'\TestDataSource2' => array(
                        'class' => __NAMESPACE__.'\TestDataSource',
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
                ),
            ),
        );
        return $config;
    }

    public function testNormalCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
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
        $testDataSource->getConnection()->access();
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
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'commit txLevel1',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnTailCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
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
        $logger->log('begin txLevel2');
        $txLevel2 = $transactionManager->begin();
        $logger->log('in txLevel2');
        $this->assertEquals(spl_object_hash($txLevel2),spl_object_hash($transactionManager->getTransaction()));
        $this->assertNotEquals(spl_object_hash($txLevel2),spl_object_hash($txLevel1));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel2->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());

        $testDataSource->getConnection()->access();
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
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit txLevel2',
            'commit(2)',
            'commit txLevel1',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnEachLevelPart1Commit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transactionManager->begin();
        $logger->log('in txLevel1');
        $testDataSource->getConnection()->access();
        $transactionManager->begin();
        $logger->log('in txLevel2');
        $testDataSource->getConnection()->access();
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
            'in txLevel1',
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'access',
            'in txLevel2',
            'getConnection',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnEachLevelPart2Commit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transactionManager->begin();
        $logger->log('in txLevel1');
        $transactionManager->begin();
        $logger->log('in txLevel2');
        $testDataSource->getConnection()->access();
        $transactionManager->commit();
        $testDataSource->getConnection()->access();
        $transactionManager->commit();

        $result = array(
            'in txLevel1',
            'in txLevel2',
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'getConnection',
            'access',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNormalRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
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
        $testDataSource->getConnection()->access();
        $logger->log('rollback txLevel1');
        $transactionManager->rollback();
        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertFalse($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel1->getStatus());
        $result = array(
            'begin txLevel1',
            'in txLevel1',
            'getConnection',
            'newConnection',
            //'setConnectedEventListener',
            //'connect',
            'beginTransaction(1)',
            'access',
            'rollback txLevel1',
            'rollback(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestLevel2AndAccessOnTailRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
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
        $logger->log('begin txLevel2');
        $txLevel2 = $transactionManager->begin();
        $logger->log('in txLevel2');
        $this->assertEquals(spl_object_hash($txLevel2),spl_object_hash($transactionManager->getTransaction()));
        $this->assertNotEquals(spl_object_hash($txLevel2),spl_object_hash($txLevel1));
        $this->assertTrue($transactionManager->isActive());
        $this->assertTrue($txLevel2->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());

        $testDataSource->getConnection()->access();
        $logger->log('rollback txLevel2');
        $transactionManager->rollback();
        $this->assertEquals(spl_object_hash($txLevel1),spl_object_hash($transactionManager->getTransaction()));
        $this->assertTrue($transactionManager->isActive());
        $this->assertFalse($txLevel2->isActive());
        $this->assertTrue($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_ACTIVE,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ACTIVE,$txLevel1->getStatus());
        $logger->log('rollback txLevel1');
        $transactionManager->rollback();
        $this->assertNull($transactionManager->getTransaction());
        $this->assertFalse($transactionManager->isActive());
        $this->assertFalse($txLevel2->isActive());
        $this->assertFalse($txLevel1->isActive());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$transactionManager->getStatus());
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel2->getStatus());
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$txLevel1->getStatus());

        $result = array(
            'begin txLevel1',
            'in txLevel1',
            'begin txLevel2',
            'in txLevel2',
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'rollback txLevel2',
            'rollback(2)',
            'rollback txLevel1',
            'rollback(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testRollbackOnly()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transaction = $transactionManager->begin();
        $transaction->setRollbackOnly();
        $logger->log('in txLevel1');
        $testDataSource->getConnection()->access();
        $transactionManager->commit();

        $result = array(
            'in txLevel1',
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'access',
            'rollback(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testNestedTransactionNotAllowedOnLevel1()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->nestedTransactionAllowed = false;

        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $testDataSource->getConnection()->access();
        $transactionManager->commit();

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'access',
            'getConnection',
            'access',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\NotSupportedException
     * @expectedExceptionMessage Nested transaction is not allowed by a resource manager.
     */
    public function testNestedTransactionNotAllowedOnLevel2()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->nestedTransactionAllowed = false;

        $transactionManager->begin();
        $transactionManager->begin();
        $testDataSource->getConnection()->access();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
     * @expectedExceptionMessage transaction has nested level active transaction.
     */
    public function testCommitInTheWrongOrderOnCommit()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transaction1 = $transactionManager->begin();
        $transaction2 = $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $transaction1->commit();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
     * @expectedExceptionMessage transaction has nested level active transaction.
     */
    public function testCommitInTheWrongOrderOnRollback()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transaction1 = $transactionManager->begin();
        $transaction2 = $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $transaction1->rollback();
    }

    public function testCommitFail()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->commitError = true;

        try {
            $transaction1 = $transactionManager->begin();
            $transaction2 = $transactionManager->begin();
            $testDataSource->getConnection()->access();
            $transactionManager->commit();
        } catch(TestException $e) {
            ;
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$transaction2->getStatus());
        $this->assertEquals(0,$transaction1->getChildCounter());
        $this->assertEquals(Status::STATUS_ACTIVE,$transaction1->getStatus());
        $this->assertEquals($transaction1,$transactionManager->getTransaction());

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'COMMIT ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testBeginTransactionFail()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->maxDepth = 1;

        try {
            $transaction1 = $transactionManager->begin();
            $transaction2 = $transactionManager->begin();
            $testDataSource->getConnection()->access();
        } catch(TestException $e) {
            ;
        }
        $this->assertEquals(Status::STATUS_ACTIVE,$transaction2->getStatus());
        $this->assertCount(0,$transaction2->getResources());
        $this->assertEquals(1,$transaction1->getChildCounter());
        $this->assertEquals(Status::STATUS_ACTIVE,$transaction1->getStatus());
        $this->assertCount(1,$transaction1->getResources());
        $this->assertEquals($transaction2,$transactionManager->getTransaction());

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'BEGIN TRANSACTION ERROR(2)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testRollbackFail()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->rollbackError = true;

        try {
            $transaction1 = $transactionManager->begin();
            $transaction2 = $transactionManager->begin();
            $testDataSource->getConnection()->access();
            $transactionManager->rollback();
        } catch(TestException $e) {
            ;
        }
        $this->assertEquals(Status::STATUS_UNKNOWN,$transaction2->getStatus());
        $this->assertEquals(0,$transaction1->getChildCounter());
        $this->assertEquals(Status::STATUS_ACTIVE,$transaction1->getStatus());
        $this->assertEquals($transaction1,$transactionManager->getTransaction());

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'rollback(2)',
            'ROLLBACK ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndNewTransactionWithoutResource()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transactionManager->begin();
        $transactionManager->begin();
        $suspended = $transactionManager->suspend();
        $this->assertNull($transactionManager->getTransaction());

        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $transactionManager->commit();

        $transactionManager->resume($suspended);
        $testDataSource->getConnection()->access();
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'getConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'commit(2)',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testSuspendAndNewTransactionWithResourceSuspendSupported()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->suspendSupported = true;

        $transactionManager->begin();
        $transactionManager->begin();
        $testDataSource->getConnection()->access();

        $suspended = $transactionManager->suspend();
        $this->assertNull($transactionManager->getTransaction());

        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $transactionManager->commit();

        $transactionManager->resume($suspended);
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)',
            'beginTransaction(2)',
            'access',
            'suspend:txObject(2)',
            'getConnection',
            'beginTransaction(1)',
            'access',
            'commit(1)',
            'resume:txObject(2)',
            'commit(2)',
            'commit(1)',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\TransactionException
     * @expectedExceptionMessage suspend failure
     */
    public function testSuspendAndNewTransactionWithResourceSuspendNotSupported()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->suspendSupported = false;

        $transactionManager->begin();
        $transactionManager->begin();
        $testDataSource->getConnection()->access();

        $suspended = $transactionManager->suspend();
    }

    public function testCommitEmptyTransaction()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transaction = $transactionManager->begin();
        $transactionManager->commit();
        $this->assertEquals(Status::STATUS_COMMITTED,$transaction->getStatus());
        $this->assertNull($transactionManager->getTransaction());
    }

    public function testRollbackEmptyTransaction()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');

        $transaction = $transactionManager->begin();
        $transactionManager->rollback();
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$transaction->getStatus());
        $this->assertNull($transactionManager->getTransaction());
    }

    public function testCommitMultiResources()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $testDataSource2 = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource2');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->name = 'db1';
        $testDataSource2->name = 'db2';

        $definition = new TransactionDefinition();
        $definition->setOption('priorities',array('db2'=>1));
        $transactionManager->begin($definition);
        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $testDataSource2->getConnection()->access();
        $transactionManager->commit();
        $transactionManager->commit();

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db1]',
            'beginTransaction(2)[db1]',
            'access[db1]',
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db2]',
            'beginTransaction(2)[db2]',
            'access[db2]',
            'commit(2)[db1]',
            'commit(2)[db2]',
            'commit(1)[db2]',
            'commit(1)[db1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testRollbackMultiResources()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $testDataSource2 = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource2');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->name = 'db1';
        $testDataSource2->name = 'db2';

        $definition = new TransactionDefinition();
        $definition->setOption('priorities',array('db2'=>1));
        $transactionManager->begin($definition);
        $transactionManager->begin();
        $testDataSource->getConnection()->access();
        $testDataSource2->getConnection()->access();
        $transactionManager->rollback();
        $transactionManager->rollback();

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db1]',
            'beginTransaction(2)[db1]',
            'access[db1]',
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db2]',
            'beginTransaction(2)[db2]',
            'access[db2]',
            'rollback(2)[db1]',
            'rollback(2)[db2]',
            'rollback(1)[db2]',
            'rollback(1)[db1]',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testCommitFailMultiResources()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $testDataSource2 = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource2');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->name = 'db1';
        $testDataSource2->name = 'db2';
        $testDataSource->commitError = true;

        $definition = new TransactionDefinition();
        $definition->setOption('priorities',array('db2'=>1));
        $transaction1 = $transactionManager->begin($definition);
        $transaction2 = $transactionManager->begin();
        $transaction1->getErrorAfterCommitEvents()
            ->attach('db2', function($event) {
                $event->getTarget()->logger->log('db2 error event');
            });
        $testDataSource->getConnection()->access();
        $testDataSource2->getConnection()->access();
        try {
            $transactionManager->commit();
        } catch(TestException $e) {
            ;
        }
        try {
            $transactionManager->commit();
        } catch(TestException $e) {
            ;
        }

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db1]',
            'beginTransaction(2)[db1]',
            'access[db1]',
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db2]',
            'beginTransaction(2)[db2]',
            'access[db2]',

            'commit(2)[db1]',
            'COMMIT ERROR',
            'rollback(2)[db2]',

            'commit(1)[db2]',
            'commit(1)[db1]',
            'COMMIT ERROR',
            'db2 error event',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }

    public function testRollbackFailMultiResources()
    {
        $mm = new ModuleManager($this->getConfig());
        $transactionManager = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestTransactionManager');
        $testDataSource = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource');
        $testDataSource2 = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestDataSource2');
        $logger = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger');
        $testDataSource->name = 'db1';
        $testDataSource2->name = 'db2';
        $testDataSource->rollbackError = true;

        $definition = new TransactionDefinition();
        $definition->setOption('priorities',array('db2'=>1));
        $transaction1 = $transactionManager->begin($definition);
        $transaction2 = $transactionManager->begin();
        $transaction1->getErrorAfterCommitEvents()
            ->attach('db2', function($event) {
                $event->getTarget()->logger->log('db2 error event');
            });
        $testDataSource->getConnection()->access();
        $testDataSource2->getConnection()->access();
        try {
            $transactionManager->rollback();
        } catch(TestException $e) {
            ;
        }
        try {
            $transactionManager->rollback();
        } catch(TestException $e) {
            ;
        }

        $result = array(
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db1]',
            'beginTransaction(2)[db1]',
            'access[db1]',
            'getConnection',
            'newConnection',
            'beginTransaction(1)[db2]',
            'beginTransaction(2)[db2]',
            'access[db2]',

            'rollback(2)[db1]',
            'ROLLBACK ERROR',
            'rollback(2)[db2]',

            'rollback(1)[db2]',
            'rollback(1)[db1]',
            'ROLLBACK ERROR',
        );
        $this->assertEquals($result,$mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
        //print_r($mm->getServiceLocator()->get(__NAMESPACE__.'\TestLogger')->logdata);
    }
}
