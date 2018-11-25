<?php
namespace RindowTest\Transaction\Distributed\TransactionTest;

use PHPUnit\Framework\TestCase;
use Interop\Lenient\Transaction\Status;
use Interop\Lenient\Transaction\Synchronization;
use Interop\Lenient\Transaction\Xa\Xid as XidInterface;
use Interop\Lenient\Transaction\Xa\XAResource;

use Rindow\Transaction\Xa\XAException;
use Rindow\Transaction\Distributed\Xid;
use Rindow\Transaction\Distributed\Transaction;
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

	public function testEnlistResourceNormal()
	{
		$xid = new Xid('foo');
		$xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$tx = new TestTransaction($xid);
		$xares->expects($this->never())
				->method('setTransactionTimeout');
		$xares->expects($this->once())
				->method('start')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMNOFLAGS)
                );
		$xares2->expects($this->never())
				->method('setTransactionTimeout');
		$xares2->expects($this->once())
				->method('start')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMNOFLAGS)
                );

        $this->assertTrue($tx->enlistResource($xares));
        $resources = $tx->getXAResources();
        $this->assertEquals(1,count($resources));
        $this->assertTrue(isset($resources[spl_object_hash($xares)]));
        $this->assertEquals($xares,$resources[spl_object_hash($xares)]->res);

        $this->assertTrue($tx->enlistResource($xares2));
        $resources = $tx->getXAResources();
        $this->assertEquals(2,count($resources));
        $this->assertTrue(isset($resources[spl_object_hash($xares2)]));
        $this->assertEquals($xares,$resources[spl_object_hash($xares2)]->res);
	}

	public function testEnlistResourceDuplicate()
	{
		$xid = new Xid('foo');
		$xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$tx = new TestTransaction($xid);
		$xares->expects($this->never())
				->method('setTransactionTimeout');
		$xares->expects($this->once())
				->method('start')
                ->with(
                        $this->equalTo($xid),
                        $this->equalTo(XAResource::TMNOFLAGS)
                );

        $this->assertTrue($tx->enlistResource($xares));
        $resources = $tx->getXAResources();
        $this->assertEquals(1,count($resources));
        $this->assertTrue(isset($resources[spl_object_hash($xares)]));
        $this->assertEquals($xares,$resources[spl_object_hash($xares)]->res);

        $this->assertFalse($tx->enlistResource($xares));
        $resources = $tx->getXAResources();
        $this->assertEquals(1,count($resources));
        $this->assertTrue(isset($resources[spl_object_hash($xares)]));
        $this->assertEquals($xares,$resources[spl_object_hash($xares)]->res);
	}

	public function testDelistResourceNormal()
	{
		$xid = new Xid('foo');
		$xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$tx = new TestTransaction($xid);
		$xares->expects($this->never())
				->method('setTransactionTimeout');
		$xares->expects($this->at(0))
				->method('start')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMNOFLAGS)
                );
 		$xares->expects($this->at(1))
				->method('end')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMSUSPEND)
                );

        $this->assertTrue($tx->enlistResource($xares));
        $resources = $tx->getXAResources();
        $this->assertEquals(1,count($resources));
        $this->assertTrue(isset($resources[spl_object_hash($xares)]));
        $this->assertEquals($xares,$resources[spl_object_hash($xares)]->res);

        $this->assertTrue($tx->delistResource($xares,XAResource::TMSUSPEND));
        $resources = $tx->getXAResources();
        $this->assertEquals(0,count($resources));
	}

    /**
     * @expectedException        Rindow\Transaction\Exception\DomainException
     */
	public function testDelistResourceNotfound()
	{
		$xid = new Xid('foo');
		$xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$tx = new TestTransaction($xid);

        $tx->delistResource($xares,XAResource::TMSUSPEND);
	}

	public function testRegisterSynchronization()
	{
		$xid = new Xid('foo');
		$sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
		$sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
		$tx = new TestTransaction($xid);
		$sync->expects($this->never())
				->method('afterCompletion');
		$sync->expects($this->never())
				->method('beforeCompletion');
		$sync2->expects($this->never())
				->method('afterCompletion');
		$sync2->expects($this->never())
				->method('beforeCompletion');

        $tx->registerSynchronization($sync);
        $syncs = $tx->getSynchronizations();
        $this->assertEquals(1,count($syncs));
        $this->assertTrue(isset($syncs[spl_object_hash($sync)]));
        $this->assertEquals($sync,$syncs[spl_object_hash($sync)]);

        $tx->registerSynchronization($sync2);
        $syncs = $tx->getSynchronizations();
        $this->assertEquals(2,count($syncs));
        $this->assertTrue(isset($syncs[spl_object_hash($sync2)]));
        $this->assertEquals($sync2,$syncs[spl_object_hash($sync2)]);
	}

	public function testCommitSuccess()
	{
		$xid = new Xid('foo');
		$xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
		$sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
		$sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
		$xares->expects($this->never())
				->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
		$xares->expects($this->at(0))
				->method('start')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMNOFLAGS)
                );
		$xares->expects($this->at(1))
				->method('end')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMSUCCESS)
                );
		$xares->expects($this->at(2))
				->method('prepare')
                ->with(
                	$this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
		$xares->expects($this->at(3))
				->method('commit')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(false)
                );
		$xares2->expects($this->never())
				->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('rollback');
		$xares2->expects($this->at(0))
				->method('start')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMNOFLAGS)
                );
		$xares2->expects($this->at(1))
				->method('end')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(XAResource::TMSUCCESS)
                );
		$xares2->expects($this->at(2))
				->method('prepare')
                ->with(
                	$this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
		$xares2->expects($this->at(3))
				->method('commit')
                ->with(
                	$this->equalTo($xid),
                	$this->equalTo(false)
                );

		$sync->expects($this->at(0))
				->method('beforeCompletion');
		$sync->expects($this->at(1))
				->method('afterCompletion')
                ->with(
                	$this->equalTo(Status::STATUS_COMMITTED)
                );
		$sync2->expects($this->at(0))
				->method('beforeCompletion');
		$sync2->expects($this->at(1))
				->method('afterCompletion')
                ->with(
                	$this->equalTo(Status::STATUS_COMMITTED)
                );

		$tx = new TestTransaction($xid);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->commit();
        $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
	}

    public function testCommitFail()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                    
                }));
        $xares->expects($this->at(3))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::prepare: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testCommitFailAtEnd()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::end: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testCommitFailAtCommit()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
        $xares->expects($this->at(3))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(false)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares2->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
        $xares2->expects($this->at(3))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(false)
                );

        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_UNKNOWN)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_UNKNOWN)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::commit: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure with unknown status: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_UNKNOWN))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\RuntimeException $e) {
            $this->assertEquals('commit failure with unknown status: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_UNKNOWN,$tx->getStatus());
        }
    }

    public function testCommitFailAtBeforeCompletion()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->at(0))
                ->method('beforeCompletion')
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('synchronization::beforeCompletion: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testCommitSuccessWithFailAtAfterCompletion()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
        $xares->expects($this->at(3))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(false)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares2->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_OK));
        $xares2->expects($this->at(3))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(false)
                );

        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('synchronization::afterCompletion: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure with unknown status: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_UNKNOWN))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\RuntimeException $e) {
            $this->assertEquals('commit failure with unknown status: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_UNKNOWN,$tx->getStatus());
        }
    }

    public function testOnePhaseCommitSuccess()
    {
        $xid = new Xid('foo');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(true)
                );
        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );

        $tx = new TestTransaction($xid);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->commit();
        $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
    }

    public function testOnePhaseCommitFail()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(true)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares->expects($this->at(3))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::commit: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testOnePhaseCommitFailAtEnd()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::end: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testOnePhaseCommitFailAtBeforeCompletion()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $sync->expects($this->at(0))
                ->method('beforeCompletion')
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('synchronization::beforeCompletion: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testOnePhaseCommitSuccessWithFailAtAfterCompletion()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('commit')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(true)
                );
        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('synchronization::afterCompletion: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('commit failure with unknown status: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_UNKNOWN))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\RuntimeException $e) {
            $this->assertEquals('commit failure with unknown status: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_UNKNOWN,$tx->getStatus());
        }
    }

    public function testRollbackSuccess()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->rollback();
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
    }

    public function testRollbackFailAtEnd()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::end: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->rollback();
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
    }

    public function testRollbackFailAtrollback()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_UNKNOWN)
                );
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_UNKNOWN)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('xaResource::rollback: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('rollback failure with unknown status: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_UNKNOWN))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->rollback();
            $this->assertTrue(false);
        } catch(Exception\RuntimeException $e) {
            $this->assertEquals('rollback failure with unknown status.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_UNKNOWN,$tx->getStatus());
        }
    }

    public function testRollbackFailAtAfterCompletion()
    {
        $xid = new Xid('foo');
        $exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        //$logger = new Logger('test');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                )
                ->will($this->returnCallback(function() use ($exception){
                    throw $exception;
                }));
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->at(0))
                ->method('error')
                ->with(
                    $this->equalTo('synchronization::afterCompletion: Dummy Error.[0]'),
                    $this->equalTo(array($exception))
                );
        $logger->expects($this->at(1))
                ->method('error')
                ->with(
                    $this->equalTo('rollback failure with unknown status: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_UNKNOWN))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        try{
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->rollback();
            $this->assertTrue(false);
        } catch(Exception\RuntimeException $e) {
            $this->assertEquals('rollback failure with unknown status.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_UNKNOWN,$tx->getStatus());
        }
    }

    public function testOnePhaseRollbackSuccess()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());

        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->rollback();
        $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
    }

    public function testCommitAtInvalidStatus()
    {
        $xid = new Xid('foo');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('transaction status is not active.: foo'),
                    $this->equalTo(array())
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->setStatus(Status::STATUS_COMMITTED);

        try{
            $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\IllegalStateException $e) {
            $this->assertEquals('transaction status is not active.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
        }
    }

    public function testRollbackAtInvalidStatus()
    {
        $xid = new Xid('foo');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('transaction status is not active.: foo'),
                    $this->equalTo(array())
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->setStatus(Status::STATUS_COMMITTED);

        try{
            $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
            $tx->rollback();
            $this->assertTrue(false);
        } catch(Exception\IllegalStateException $e) {
            $this->assertEquals('transaction status is not active.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
        }
    }

    public function testSetRollbackOnly()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMFAIL)
                );
        $xares2->expects($this->at(2))
                ->method('rollback')
                ->with(
                    $this->equalTo($xid)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->at(0))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_ROLLEDBACK)
                );
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('commit failure: foo'),
                    $this->equalTo(array('status'=>Status::STATUS_ROLLEDBACK))
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);

        $tx->setRollbackOnly();
        try{
            $this->assertEquals(Status::STATUS_MARKED_ROLLBACK,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\TransactionException $e) {
            $this->assertEquals('commit failure: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ROLLEDBACK,$tx->getStatus());
        }
    }

    public function testSuspend()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUSPEND)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUSPEND)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->never())
                ->method('afterCompletion');
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->never())
                ->method('afterCompletion');
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);

        $this->assertFalse($tx->getSuspended());
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->doSuspend();
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $this->assertTrue($tx->getSuspended());
    }

    public function testResume()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUSPEND)
                );
        $xares->expects($this->at(2))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMRESUME)
                );
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUSPEND)
                );
        $xares2->expects($this->at(2))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMRESUME)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->never())
                ->method('afterCompletion');
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->never())
                ->method('afterCompletion');
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);

        $this->assertFalse($tx->getSuspended());
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->doSuspend();
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $this->assertTrue($tx->getSuspended());
        $tx->doResume();
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $this->assertFalse($tx->getSuspended());
    }

    public function testCommitAtSuspended()
    {
        $xid = new Xid('foo');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('transaction is suspended.: foo'),
                    $this->equalTo(array())
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->doSuspend();

        try{
            $this->assertTrue($tx->getSuspended());
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->commit();
            $this->assertTrue(false);
        } catch(Exception\IllegalStateException $e) {
            $this->assertEquals('transaction is suspended.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        }
    }

    public function testRollbackAtSuspended()
    {
        $xid = new Xid('foo');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $logger->expects($this->once())
                ->method('error')
                ->with(
                    $this->equalTo('transaction is suspended.: foo'),
                    $this->equalTo(array())
                );

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->doSuspend();

        try{
            $this->assertTrue($tx->getSuspended());
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
            $tx->rollback();
            $this->assertTrue(false);
        } catch(Exception\IllegalStateException $e) {
            $this->assertEquals('transaction is suspended.: foo',$e->getMessage());
            $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        }
    }

    public function testSetTransactionTimeoutAtFirst()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('end');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $xares2->expects($this->never())
                ->method('end');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->once())
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->never())
                ->method('afterCompletion');
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->never())
                ->method('afterCompletion');
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->doSetTransactionTimeout(12345);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
    }

    public function testSetTransactionTimeoutLater()
    {
        $xid = new Xid('foo');
        //$exception = new XAException('Dummy Error.');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $logger = $this->createTestMock(__NAMESPACE__.'\TestLogger');
        $xares->expects($this->never())
                ->method('end');
        $xares->expects($this->never())
                ->method('prepare');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->never())
                ->method('commit');
        $xares->expects($this->once())
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );
        $xares2->expects($this->never())
                ->method('end');
        $xares2->expects($this->never())
                ->method('prepare');
        $xares2->expects($this->never())
                ->method('commit');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->once())
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->once())
                ->method('setTransactionTimeout')
                ->with(
                    $this->equalTo(12345)
                );

        $sync->expects($this->never())
                ->method('beforeCompletion');
        $sync->expects($this->never())
                ->method('afterCompletion');
        $sync2->expects($this->never())
                ->method('beforeCompletion');
        $sync2->expects($this->never())
                ->method('afterCompletion');
        $logger->expects($this->never())
                ->method('error');

        $tx = new TestTransaction($xid);
        $tx->setLogger($logger);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $tx->doSetTransactionTimeout(12345);
    }

    public function testCommitReadOnly()
    {
        $xid = new Xid('foo');
        $xares = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $xares2 = $this->createTestMock(__NAMESPACE__.'\TestXAResource');
        $sync = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $sync2 = $this->createTestMock(__NAMESPACE__.'\TestSync');
        $xares->expects($this->never())
                ->method('setTransactionTimeout');
        $xares->expects($this->never())
                ->method('rollback');
        $xares->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_RDONLY));
        $xares->expects($this->never())
                ->method('commit');
        $xares2->expects($this->never())
                ->method('setTransactionTimeout');
        $xares2->expects($this->never())
                ->method('rollback');
        $xares2->expects($this->at(0))
                ->method('start')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMNOFLAGS)
                );
        $xares2->expects($this->at(1))
                ->method('end')
                ->with(
                    $this->equalTo($xid),
                    $this->equalTo(XAResource::TMSUCCESS)
                );
        $xares2->expects($this->at(2))
                ->method('prepare')
                ->with(
                    $this->equalTo($xid)
                )
                ->will($this->returnValue(XAResource::XA_RDONLY));
        $xares2->expects($this->never())
                ->method('commit');

        $sync->expects($this->at(0))
                ->method('beforeCompletion');
        $sync->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );
        $sync2->expects($this->at(0))
                ->method('beforeCompletion');
        $sync2->expects($this->at(1))
                ->method('afterCompletion')
                ->with(
                    $this->equalTo(Status::STATUS_COMMITTED)
                );

        $tx = new TestTransaction($xid);
        $tx->enlistResource($xares);
        $tx->enlistResource($xares2);
        $tx->registerSynchronization($sync);
        $tx->registerSynchronization($sync2);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
        $tx->commit();
        $this->assertEquals(Status::STATUS_COMMITTED,$tx->getStatus());
    }

    public function testGetStatusAtResourceEmpty()
    {
        $xid = new Xid('foo');
        $tx = new TestTransaction($xid);
        $this->assertEquals(Status::STATUS_NO_TRANSACTION,$tx->getStatus());
    }

    public function testGetStatusAtOneResource()
    {
        $xid = new Xid('foo');
        $xares = new TestXAResource();
        $tx = new TestTransaction($xid);
        $tx->enlistResource($xares);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
    }

    public function testGetStatusAtOneSynchronization()
    {
        $xid = new Xid('foo');
        $sync = new TestSync();
        $tx = new TestTransaction($xid);
        $tx->registerSynchronization($sync);
        $this->assertEquals(Status::STATUS_ACTIVE,$tx->getStatus());
    }
}