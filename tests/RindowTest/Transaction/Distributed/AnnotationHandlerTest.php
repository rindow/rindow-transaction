<?php
namespace RindowTest\Transaction\Distributed\AnnotationHandlerTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\Xa\XAResource;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\TransactionDefinition;
use Interop\Lenient\Transaction\Annotation\TransactionAttribute;
use Interop\Lenient\Transaction\Annotation\TransactionManagement;
use Rindow\Container\ModuleManager;


class TestLogger
{
    public $logdata = array();
    protected $logger;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    public function log($message)
    {
        $this->logdata[] = $message;
        if($this->logger)
            $this->logger->debug($message);
    }
}
class TestXAResource implements XAResource
{
    protected $logger;
    protected $connection;

    public function __construct($connection) {
        $this->logger = $connection->getLogger();
        $this->connection = $connection;
    }
    public function start(/*Xid*/ $xid, $flags)
    {
        $this->connection->connect();
        $this->logger->log('XA:start');
    }

    public function end(/*Xid*/ $xid, $flags)
    {
        $this->connection->connect();
        $f = ($flags & XAResource::TMSUCCESS)? 'success' : 'fails';
        $this->logger->log('XA:end('.$f.')' );
    }

    public function prepare(/*Xid*/ $xid)
    {
        $this->connection->connect();
        $this->logger->log('XA:prepare');
    }

    public function commit(/*Xid*/ $xid, $onePhase)
    {
        $this->connection->connect();
        $f = $onePhase ? '(onePhase)' : '';
        $this->logger->log('XA:commit'.$f);
    }

    public function rollback(/*Xid*/ $xid)
    {
        $this->connection->connect();
        $this->logger->log('XA:rollback');
    }

    public function forget(/*Xid*/ $xid)
    {
        $this->connection->connect();
        $this->logger->log('XA:forget');
    }

    public function getTransactionTimeout()
    {
        $this->logger->log('XA:getTransactionTimeout');
    }

    public function isSameRM(/*XAResource*/ $xares)
    {
        $this->logger->log('XA:isSameRM');
    }

    public function recover($flag)
    {
        $this->connection->connect();
        $this->logger->log('XA:recover');
    }

    public function setTransactionTimeout($seconds)
    {
        $this->logger->log('XA:setTransactionTimeout');
    }
}
class TestConnection 
{
    public $listener;
    public $isolationLevel = TransactionDefinition::ISOLATION_DEFAULT;
    public $timeout;
    public $savepointSerial = 0;
    public $connected = false;
    public $logger;
    public $commitError;
    public $rollbackError;
    public $suspendSupported;
    public $suspended =false;
    public $xaResource;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    public function getLogger()
    {
        return $this->logger;
    }
    public function getXAResource()
    {
        if($this->xaResource==null)
            $this->xaResource = new TestXAResource($this);
        return $this->xaResource;
    }
    public function connect()
    {
        if($this->connected)
            return;
        $this->logger->log('connect');
        $this->connected = true;
        if($this->listener)
            call_user_func($this->listener);
    }
    public function access()
    {
        $this->connect();
        $this->logger->log('access');
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
    public function getReadOnlyTransaction()
    {
        return $this->readOnlyTransaction;
    }
    public function isConnected()
    {
        return $this->connected;
    }
}
class TestSynchronization implements Synchronization
{
    public $synchronizationRegistry;
    public $entityManagerFactory;
    public $logger;

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
            $this->logger->log('enlistResource');
            $transaction->enlistResource($this->connection);
            return $this->connection;
        }
        $this->logger->log('newConnection');
        $connection =  new TestConnection();
        $connection->setLogger($this->logger);
        $connection->commitError = $this->commitError;
        $connection->rollbackError = $this->rollbackError;
        $transaction = $this->transactionManager->getTransaction();
        if($transaction) {
            $this->logger->log('enlistResource');
            $transaction->enlistResource($connection->getXAResource());
        } else {
            $this->logger->log('no Transaction');
        }
        $this->connection = $connection;
        return $connection;
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
        $connection = $this->dataSource->getConnection();
        $entityManager = new TestEntityManager($connection);
        $entityManager->flushError = $this->flushError;
        $entityManager->closeError = $this->closeError;
        $entityManager->setLogger($this->logger);
        return $entityManager;
    }
}

class TestEntityManager
{
    public $resourceManager;
    protected $logger;
    public $flushError;
    public $closeError;

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    public function __construct($resourceManager = null)
    {
        $this->resourceManager = $resourceManager;
    }

    public function action()
    {
        $this->logger->log('action');
    }

    public function find()
    {
        $this->logger->log('find');
        $this->resourceManager->access();
    }

    public function flush()
    {
        //$this->logger->log('flush');
        $this->resourceManager->access();
        if($this->flushError) {
            $this->logger->log('FLUSH ERROR');
            throw new \Exception('FLUSH ERROR');
        }
    }
    public function close()
    {
        //$this->logger->log('close');
        $this->resourceManager = null;
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
                    $this->entityManager->find();
                $this->entityManager->action();
                break;
            default:
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
    public function testTierOneWithAccess($failure=false,$readAccess=false,$resource='orm')
    {
        $this->logger->log('in testTierOne');
        switch($resource){
            case 'orm':
                if($readAccess)
                    $this->entityManager->find();
                $this->entityManager->action();
                break;
            default:
                $this->dataSource->getConnection()->access();
                break;
        }
        $this->testCommit($failure,false,$resource);
        $this->logger->log('out testTierOne');
    }
}
class TestException extends \Exception
{}

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
                    'Rindow\Transaction\Distributed\Module' => true,
                    //'Rindow\Module\Monolog\Module' => true,
                ),
                'annotation_manager' => true,
            ),
            'aop' => array(
                //'debug' => true,
                'intercept_to' => array(
                    __NAMESPACE__.'\TestDao'=>true,
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
                    'Rindow\\Transaction\\Distributed\\DefaultTransactionManager' => array(
                        'properties' => array(
                            //'logger' => array('ref'=>'Logger'),
                            //'debug' => array('value' => true),
                        ),
                    ),
                    'Rindow\\Transaction\\Distributed\\DefaultTransactionSynchronizationRegistry' => array(
                        'properties' => array(
                            //'logger' => array('ref'=>'Logger'),
                            //'debug' => array('value' => true),
                        ),
                    ),
                    __NAMESPACE__.'\TestLogger' => array(
                        'properties' => array(
                            //'logger' => array('ref'=>'Logger'),
                        ),
                    ),
                     __NAMESPACE__.'\TestDataSource' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'TestLogger'),
                            //'debug' => array('value' => true),
                            'transactionManager'=>array('ref'=>'Rindow\\Transaction\\Distributed\\DefaultTransactionManager'),
                        ),
                    ),
                    __NAMESPACE__.'\TestSynchronization' => array(
                        'properties' => array(
                            'entityManagerFactory' => array('ref'=>__NAMESPACE__.'\TestEntityManagerFactory'),
                            'synchronizationRegistry' => array('ref'=>'Rindow\\Transaction\\Distributed\\DefaultTransactionSynchronizationRegistry'),
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
                        //'path'  => __DIR__.'/test.log',
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
            'getConnection',
            'newConnection',
            'enlistResource',
            'connect',
            'XA:start',
            'action',
            'out testCommit',
            'beforeCompletion',
            'access',
            'XA:end(success)',
            'XA:commit(onePhase)',
            'afterCompletion(true)',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->logdata);
        //print_r($logger->logdata);
    }
}