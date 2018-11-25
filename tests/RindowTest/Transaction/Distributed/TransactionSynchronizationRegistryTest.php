<?php
namespace RindowTest\Transaction\Distributed\TransactionSynchronizationRegistryTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\Status;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\Xa\XAResource;
use Interop\Lenient\Transaction\Xa\Xid as XidInterface;

use Rindow\Transaction\Support\TransactionSynchronizationRegistry;
use Rindow\Transaction\Distributed\TransactionManager;
use Rindow\Transaction\Distributed\Transaction;
use Rindow\Transaction\Distributed\Xid;


class TestSynchronization implements Synchronization
{
    public function beforeCompletion()
    {}
    public function afterCompletion($status)
    {}
}

class TestXAResource implements XAResource
{
    public function commit(/*XidInterface*/ $xid, $onePhase)
    {}
    public function end(/*XidInterface*/ $xid, $flags)
    {}
    public function forget(/*XidInterface*/ $xid)
    {}
    public function getTransactionTimeout()
    {}
    public function isSameRM(/*XAResource*/ $xares)
    {}
    public function prepare(/*XidInterface*/ $xid)
    {}
    public function recover($flag)
    {}
    public function rollback(/*XidInterface*/ $xid)
    {}
    public function setTransactionTimeout($seconds)
    {}
    public function start(/*XidInterface*/ $xid, $flags)
    {}
}

class TestTransaction extends Transaction
{
    public function getXid()
    {
        return $this->xid;
    }
    public function getXAResources()
    {
        return $this->xaResources;
    }

    public function getSynchronizations()
    {
        return $this->synchronizations;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getSuspended()
    {
        return $this->suspended;
    }
}

class TestTransactionManager extends TransactionManager
{
	public function newTransaction()
	{
		return new TestTransaction(new Xid());
	}
}

class Test extends TestCase
{
	public function testNormal()
	{
		$sync = new TestSynchronization();
		$txManager = new TestTransactionManager();
		$registry = new TransactionSynchronizationRegistry($txManager);

		$this->assertEquals(Status::STATUS_NO_TRANSACTION,
			$registry->getTransactionStatus());
		$txManager->begin();
		$this->assertEquals(Status::STATUS_NO_TRANSACTION,
			$registry->getTransactionStatus());
		$registry->registerInterposedSynchronization($sync);
		$this->assertEquals(Status::STATUS_ACTIVE,
			$registry->getTransactionStatus());
		$txManager->getTransaction()->enlistResource(new TestXAResource());
		$this->assertEquals(Status::STATUS_ACTIVE,
			$registry->getTransactionStatus());
		$syncs = $txManager->getTransaction()->getSynchronizations();
		$this->assertEquals(1,count($syncs));
		$this->assertEquals($syncs[spl_object_hash($sync)],$sync);
	}

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
     * @expectedExceptionMessage transaction is not active
    */
	public function testNoTransaction()
	{
		$sync = new TestSynchronization();
		$txManager = new TestTransactionManager();
		$registry = new TransactionSynchronizationRegistry($txManager);

		$registry->registerInterposedSynchronization($sync);
	}

	public function testResource()
	{
		$sync = new TestSynchronization();
		$txManager = new TestTransactionManager();
		$registry = new TransactionSynchronizationRegistry($txManager);
		$txManager->begin();
		$key = 'KEY'; //$registry->getTransactionKey();
		$registry->putResource($key,'Foo');
		$this->assertEquals('Foo',$registry->getResource($key));
	}

	public function testRollbackOnly()
	{
		$sync = new TestSynchronization();
		$txManager = new TestTransactionManager();
		$registry = new TransactionSynchronizationRegistry($txManager);

		$txManager->begin();
		$txManager->getTransaction()->enlistResource(new TestXAResource());
		$this->assertFalse($registry->getRollbackOnly());
		$registry->setRollbackOnly();
		$this->assertTrue($registry->getRollbackOnly());

		$this->assertEquals(Status::STATUS_MARKED_ROLLBACK,
			$txManager->getStatus());
	}
}