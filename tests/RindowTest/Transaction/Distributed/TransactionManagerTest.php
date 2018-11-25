<?php
namespace RindowTest\Transaction\Distributed\TransactionManagerTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\Status;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\Xa\Xid as XidInterface;
use Interop\Lenient\Transaction\Xa\XAResource;
use Interop\Lenient\Transaction\Xa\XAException;
use Rindow\Transaction\Distributed\Xid;
use Rindow\Transaction\Distributed\Transaction;
use Rindow\Transaction\Distributed\TransactionManager;
use Rindow\Transaction\Exception;
use Monolog\Logger;

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

class TestSync implements Synchronization
{
    public function afterCompletion($status)
    {}
    public function beforeCompletion()
    {}
}

class TestLogger
{
    public function debug($msg,$context)
    {
        echo '['.$msg."]\n";
    }
    public function error($msg,$context)
    {
        echo '['.$msg."]\n";
    }
}

class Test extends TestCase
{
    public function createTestMock($className,$methods = array(), array $arguments = array())
    {
        $args = func_get_args();
        if(count($args)==0 || count($args)>3)
            throw new \Exception('illegal mock style');
        $builder = $this->getMockBuilder($className);
        $builder->setMethods($methods);
        $builder->setConstructorArgs($arguments);
        return $builder->getMock();
    }

    public function testCommitSuccess()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = new Logger('test');

        $xares->expects($this->once())
                ->method('prepare')
                ->will($this->returnValue(XAResource::XA_OK));
        $xares->expects($this->once())
                ->method('commit');
        $xares2->expects($this->once())
                ->method('prepare')
                ->will($this->returnValue(XAResource::XA_OK));
        $xares2->expects($this->once())
                ->method('commit');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->begin();
        $tx = $manager->getTransaction();

        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);

        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
        $manager->commit();
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());
    }

    public function testRollbackSuccess()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = new Logger('test');

        $xares->expects($this->once())
                ->method('rollback');
        $xares2->expects($this->once())
                ->method('rollback');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->begin();
        $tx = $manager->getTransaction();

        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);

        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
        $manager->rollback();
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());
    }

    public function testgetStatus()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = new Logger('test');

        $xares->expects($this->once())
                ->method('prepare')
                ->will($this->returnValue(XAResource::XA_OK));
        $xares->expects($this->once())
                ->method('commit');
        $xares2->expects($this->once())
                ->method('prepare')
                ->will($this->returnValue(XAResource::XA_OK));
        $xares2->expects($this->once())
                ->method('commit');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $this->assertNull($manager->getTransaction());
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());
        $manager->begin();
        $tx = $manager->getTransaction();
        $this->assertNotNull($tx);
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());

        $tx->enlistResource($xares);
        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
        $tx->enlistResource($xares2);

        $manager->commit();
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());
    }

    public function testSetRollbackOnly()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');

        $xares->expects($this->at(0))
                ->method('rollback');
        $xares2->expects($this->once())
                ->method('rollback');
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->stringContains('commit failure:'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->begin();
        $tx = $manager->getTransaction();

        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->setRollbackOnly();

        try {
            $manager->commit();
        } catch(Exception\TransactionException $e) {
            $this->assertStringStartsWith('commit failure:',$e->getMessage());
        }
    }

    public function testSuspendAndResume()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');

        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMSUSPEND)
                );
        $xares->expects($this->at(2))
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMRESUME)
                );

        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMSUSPEND)
                );
        $xares2->expects($this->at(2))
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMRESUME)
                );
        $logger->expects($this->never())
                ->method('error');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->begin();
        $tx = $manager->getTransaction();

        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);

        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
        $txSuspended = $manager->suspend();
        $this->assertEquals($txSuspended,$tx);
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $manager->getStatus());
        $manager->resume($txSuspended);
        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
    }

    public function testSetTransactionTimeoutAtFirst()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $xares2->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $logger->expects($this->never())
                ->method('error');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->setTransactionTimeout(12345);
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
    }

    public function testSetTransactionTimeoutLater()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $xares2->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $logger->expects($this->never())
                ->method('error');

        $manager = new TransactionManager();
        $manager->setLogger($logger);
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $manager->setTransactionTimeout(12345);
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
    */
    public function testCommitAtNoTransaction()
    {
        $manager = new TransactionManager();
        $manager->commit();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
    */
    public function testRollbackAtNoTransaction()
    {
        $manager = new TransactionManager();
        $manager->rollback();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
    */
    public function testSetRollbackOnlyAtNoTransaction()
    {
        $manager = new TransactionManager();
        $manager->setRollbackOnly();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\IllegalStateException
    */
    public function testSuspendAtNoTransaction()
    {
        $manager = new TransactionManager();
        $manager->suspend();
    }

    /**
     * @expectedException        Rindow\Transaction\Exception\NotSupportedException
    */
    public function testBeginAtActive()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $manager = new TransactionManager();
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $this->assertEquals(Status::STATUS_ACTIVE, $manager->getStatus());
        $manager->begin();
    }

    public function testBeginAtMarkedRollback()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->once())
                ->method('rollback');
        $manager = new TransactionManager();
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $manager->setRollbackOnly();
        $this->assertEquals(Status::STATUS_MARKED_ROLLBACK, $manager->getStatus());
        $manager->begin();
    }

    public function testBeginAtCommitted()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->once())
                ->method('commit');
        $manager = new TransactionManager();
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $tx->commit();
        $this->assertEquals(Status::STATUS_COMMITTED, $manager->getStatus());
        $manager->begin();
    }

    public function testBeginAtRolledback()
    {
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->anything(),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->once())
                ->method('rollback');
        $manager = new TransactionManager();
        $manager->begin();
        $tx = $manager->getTransaction();
        $tx->enlistResource($xares);
        $tx->rollback();
        $this->assertEquals(Status::STATUS_ROLLEDBACK, $manager->getStatus());
        $manager->begin();
    }

    public function testBeginAtResourceEmpty()
    {
        $manager = new TransactionManager();
        $manager->begin();
        $tx = $manager->getTransaction();
        $this->assertEquals(Status::STATUS_NO_TRANSACTION, $tx->getStatus());
        $manager->begin();
    }
}