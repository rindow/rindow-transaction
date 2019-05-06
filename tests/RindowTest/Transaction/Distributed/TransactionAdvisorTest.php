<?php
namespace RindowTest\Transaction\Distributed\TransactionAdvisorTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\Xa\Xid as XidInterface;
use Interop\Lenient\Transaction\Xa\XAResource;
use Interop\Lenient\Transaction\Xa\XAException;
use Rindow\Container\ModuleManager;
use Rindow\Transaction\Distributed\Xid;
use Rindow\Transaction\Distributed\Transaction;
use Rindow\Transaction\Distributed\TransactionManager;
use Rindow\Transaction\Exception;
//use Monolog\Logger;

class TestXAResource implements XAResource
{
    protected $flagName = array(
        XAResource::TMNOFLAGS    => 'TMNOFLAGS',
        XAResource::TMJOIN       => 'TMJOIN',
        XAResource::TMENDRSCAN   => 'TMENDRSCAN',
        XAResource::TMSTARTRSCAN => 'TMSTARTRSCAN',
        XAResource::TMSUSPEND    => 'TMSUSPEND',
        XAResource::TMSUCCESS    => 'TMSUCCESS',
        XAResource::TMRESUME     => 'TMRESUME',
        XAResource::TMFAIL       => 'TMFAIL',
        XAResource::TMONEPHASE   => 'TMONEPHASE',
    );

	protected $logger;
	public function setLogger($logger)
	{
		$this->logger = $logger;
	}
    public function commit(/*XidInterface*/ $xid, $onePhase)
    {
    	$this->logger->debug('commit'.($onePhase ? '(onePhase)':''));
    }
    public function end(/*XidInterface*/ $xid, $flags)
    {
        $this->logger->debug('end('.$this->flagName[$flags].')');
    }
    public function forget(/*XidInterface*/ $xid)
    {}
    public function getTransactionTimeout()
    {}
    public function isSameRM(/*XAResource*/ $xares)
    {}
    public function prepare(/*XidInterface*/ $xid)
    {
        $this->logger->debug('prepare');
    	return XAResource::XA_OK;
    }
    public function recover($flag)
    {}
    public function rollback(/*XidInterface*/ $xid)
    {
    	$this->logger->debug('rollback');
    }
    public function setTransactionTimeout($seconds)
    {}
    public function start(/*XidInterface*/ $xid, $flags)
    {
    	$this->logger->debug('start('.$this->flagName[$flags].')');
    }
}

class TestSync implements Synchronization
{
	protected $logger;
	public function setLogger($logger)
	{
		$this->logger = $logger;
	}
    public function afterCompletion($status)
    {
    	$this->logger->debug('afterCompletion');
    }
    public function beforeCompletion()
    {
    	$this->logger->debug('beforeCompletion');
    }
}
class TestEntityManager
{
	protected $logger;
	public function setLogger($logger)
	{
		$this->logger = $logger;
	}
	public function persist($entity)
	{
    	$this->logger->debug('persist');
	}
}
class TestDistributedEntityManager
{
	protected $entityManager;
	protected $transactionManager;
	protected $synchronizationRegistry;
	protected $synchronization;
	protected $xaResource;
	protected $logger;

	public function setLogger($logger)
	{
		$this->logger = $logger;
	}

	public function setTransactionManager($transactionManager)
	{
		$this->transactionManager = $transactionManager;
	}
	public function setSynchronizationRegistry($synchronizationRegistry)
	{
		$this->synchronizationRegistry = $synchronizationRegistry;
	}
	public function setSynchronization($synchronization)
	{
		$this->synchronization = $synchronization;
	}
	public function setXaResource($xaResource)
	{
		$this->xaResource = $xaResource;
	}

	protected function getEntityManager()
	{
        $transaction = $this->transactionManager->getTransaction();
        if($transaction &&
                isset($this->entityManagers[spl_object_hash($transaction)]))
            return $this->entityManagers[spl_object_hash($transaction)];
		$entityManager = new TestEntityManager();
		$entityManager->setLogger($this->logger);
        if($transaction) {
            $transaction->enlistResource($this->xaResource);
            $this->synchronizationRegistry
                ->registerInterposedSynchronization($this->synchronization);
            $this->entityManagers[spl_object_hash($transaction)] = $entityManager;
        }
        return $entityManager;
	}
	public function persist($entity)
	{
		$this->getEntityManager()->persist($entity);
	}
}

class TestLogger
{
	protected $log = array();

	public function getLog()
	{
		return $this->log;
	}

    public function debug($msg,$context=null)
    {
        $this->log[] = $msg;
    }
    public function error($msg,$context=null)
    {
        $this->log[] = $msg;
    }
}

class Product
{
	protected $name;

	public function setName($name)
	{
		$this->name = $name;
	}
	public function getName()
	{
		return $this->name;
	}
}
class TestCommit
{
	protected $entityManager;
	protected $logger;

	public function setLogger($logger)
	{
		$this->logger = $logger;
	}

	public function setEntityManager($entityManager)
	{
		$this->entityManager = $entityManager;
	}

	public function testCommit($failure=false)
	{
		$this->logger->debug('in testCommit');
        $product = new Product();
        $product->setName('aaa');
        $this->entityManager->persist($product);
        if($failure)
            throw new TestException("error");
		$this->logger->debug('out testCommit');
	}

	public function testRollback()
	{
		$this->logger->debug('in testRollback');
        $product = new Product();
        $product->setName('aaa');
        $this->entityManager->persist($product);
		$this->logger->debug('throw testRollback');
        throw new TestException("error");
	}

	public function testTierOne($failure=false)
	{
		$this->logger->debug('in testTierOne');
		$this->testCommit($failure);
		$this->logger->debug('out testTierOne');
	}

    public function testTierOneWithAccess($failure=false)
    {
        $this->logger->debug('in testTierOne');
        $product = new Product();
        $product->setName('aaa');
        $this->entityManager->persist($product);
        $this->testCommit($failure);
        $this->logger->debug('out testTierOne');
    }

}
class TestException extends \Exception
{}

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
                    'Rindow\Transaction\Distributed\Module' => true,
                    //'Rindow\Module\Monolog\Module' => true,
                ),
                'enableCache' => false,
            ),
            'aop' => array(
                'plugins' => array(
                    'Rindow\\Transaction\\Support\\AnnotationHandler'=>true,
                ),
                'intercept_to' => array(
                    __NAMESPACE__=>true,
                ),
            ),
            'container' => array(
            	'aliases' => array(
            		'Logger' => __NAMESPACE__.'\TestLogger',
            	),
                'components' => array(
                    __NAMESPACE__.'\TestXAResource' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'Logger'),
                            //'debug' => array('value' => true),
                        ),
                    ),
                    __NAMESPACE__.'\TestSync' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'Logger'),
                            //'debug' => array('value' => true),
                        ),
                    ),
                    __NAMESPACE__.'\TestDistributedEntityManager' => array(
                        'properties' => array(
                            'logger' => array('ref'=>'Logger'),
                            'xaResource' => array('ref'=>__NAMESPACE__.'\TestXAResource'),
                            'synchronization' => array('ref'=>__NAMESPACE__.'\TestSync'),
                            'transactionManager' => array('ref'=>'Rindow\Transaction\Distributed\DefaultTransactionManager'),
                            'synchronizationRegistry' => array('ref'=>'Rindow\Transaction\Distributed\DefaultTransactionSynchronizationRegistry'),
                            //'debug' => array('value' => true),
                        ),
                    ),
                    __NAMESPACE__.'\TestCommit' => array(
                    	'properties' => array(
                    		'entityManager' => array('ref'=>__NAMESPACE__.'\TestDistributedEntityManager'),
                            'logger' => array('ref'=>'Logger'),
                    	),
                    ),
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                    	'properties' => array(
                    		'transactionManager' => array('ref'=>'Rindow\Transaction\Distributed\DefaultTransactionManager'),
                            //'logger' => array('ref'=>'Logger'),
                            //'debug' => array('value' => true),
                    	),
                    ),
                    __NAMESPACE__.'\TestLogger' => array(
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
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
			            'advices' => array(
			            	'required' => array(
			                    'type' => 'around',
			                    'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
			            	),
			            ),
			        ),
                ),
            ),
        );
    	$config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testCommit.');
        $test->testCommit();
        $logger->debug('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testCommit',
            'start(TMNOFLAGS)',
            'persist',
            'out testCommit',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->getLog());
    }

    public function testRequiredRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testRollback())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testCommit.');
        try {
            $test->testRollback();
        } catch(TestException $e) {
            ;
        }
        $logger->debug('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testRollback',
            'start(TMNOFLAGS)',
            'persist',
            'throw testRollback',
            'end(TMFAIL)',
            'rollback',
            'afterCompletion',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->getLog());
    }

    public function testMandatoryActiveTransaction()
    {
    	$config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
			            'advices' => array(
			            	'mandatory' => array(
			                    'type' => 'around',
			                    'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
			            	),
			            	'required' => array(
			                    'type' => 'around',
			                    'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOne())',
			            	),
			            ),
			        ),
                ),
            ),
        );
    	$config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        $test->testTierOne();
        $logger->debug('after Call testTierOne.');
        $result = array(
			'before Call testTierOne.',
			'in testTierOne',
			'in testCommit',
            'start(TMNOFLAGS)',
			'persist',
			'out testCommit',
			'out testTierOne',
			'beforeCompletion',
            'end(TMSUCCESS)',
			'commit(onePhase)',
			'afterCompletion',
			'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
    }

    /**
     * @expectedException Rindow\Transaction\Exception\IllegalStateException
     */
    public function testMandatoryNoTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'mandatory'=> array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $test->testCommit();
    }

    public function testRequiresNewActiveTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOneWithAccess())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        $test->testTierOneWithAccess();
        $logger->debug('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'start(TMNOFLAGS)',
            'persist',
            'end(TMSUSPEND)',
            'in testCommit',
            'start(TMNOFLAGS)',
            'persist',
            'out testCommit',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'start(TMRESUME)',
            'out testTierOne',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    public function testRequiresNewNoTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOne())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        $test->testTierOne();
        $logger->debug('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            'start(TMNOFLAGS)',
            'persist',
            'out testCommit',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'out testTierOne',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    public function testRequiresNewActiveTransactionRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'requiresNew' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOneWithAccess())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess(true);
        } catch (TestException $e) {
            ;
        }
        $logger->debug('after Call testTierOne.');
        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'start(TMNOFLAGS)',
            'persist',
            'end(TMSUSPEND)',
            'in testCommit',
            'start(TMNOFLAGS)',
            'persist',
            'end(TMFAIL)',
            'rollback',
            'afterCompletion',
            'start(TMRESUME)',
            'end(TMFAIL)',
            'rollback',
            'afterCompletion',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }


    public function testSupports()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'supports' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOne())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        $test->testTierOne();
        $logger->debug('after Call testTierOne.');

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'in testCommit',
            'start(TMNOFLAGS)',
            'persist',
            'out testCommit',
            'out testTierOne',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    public function testNotSupported()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOneWithAccess())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        $test->testTierOneWithAccess();
        $logger->debug('after Call testTierOne.');

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'start(TMNOFLAGS)',
            'persist',
            'end(TMSUSPEND)',
            'in testCommit',  // <<==== NO_TRANSACTION =====
            'persist',        //
            'out testCommit', // ==========================>>
            'start(TMRESUME)',
            'out testTierOne',
            'beforeCompletion',
            'end(TMSUCCESS)',
            'commit(onePhase)',
            'afterCompletion',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    public function testNotSupportedRollback()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'notSupported' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOneWithAccess())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');
        $logger->debug('before Call testTierOne.');
        try {
            $test->testTierOneWithAccess(true);
        } catch (TestException $e) {
            ;
        }
        $logger->debug('after Call testTierOne.');

        $result = array(
            'before Call testTierOne.',
            'in testTierOne',
            'start(TMNOFLAGS)',
            'persist',
            'end(TMSUSPEND)',
            'in testCommit',   // <<==== NO_TRANSACTION =====
            'persist',         // ==========================>>
            'start(TMRESUME)',
            'end(TMFAIL)',
            'rollback',
            'afterCompletion',
            'after Call testTierOne.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    public function testNeverNoTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'never' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');

        $logger->debug('before Call testCommit.');
        $test->testCommit();
        $logger->debug('after Call testCommit.');

        $result = array(
            'before Call testCommit.',
            'in testCommit',
            'persist',
            'out testCommit',
            'after Call testCommit.',
        );
        $this->assertEquals($result,$logger->getLog());
        //print_r($logger->getLog());
    }

    /**
     * @expectedException Rindow\Transaction\Exception\IllegalStateException
     */
    public function testNeverActiveTransaction()
    {
        $config = array(
            'aop' => array(
                'aspects' => array(
                    'Rindow\Transaction\Support\TransactionAdvisor' => array(
                        'advices' => array(
                            'never' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testCommit())',
                            ),
                            'required' => array(
                                'type' => 'around',
                                'pointcut' => 'execution('.__NAMESPACE__.'\TestCommit::testTierOneWithAccess())',
                            ),
                        ),
                    ),
                ),
            ),
        );
        $config = array_replace_recursive($config, $this->getConfig());

        $mm = new ModuleManager($config);
        $test = $mm->getServiceLocator()->get(__NAMESPACE__.'\TestCommit');
        $logger = $mm->getServiceLocator()->get('Logger');

        $test->testTierOneWithAccess();
    }
}
