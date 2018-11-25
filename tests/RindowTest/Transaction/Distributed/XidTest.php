<?php
namespace RindowTest\Transaction\Distributed\XidTest;

use PHPUnit\Framework\TestCase;
use Rindow\Transaction\Distributed\Xid;

class Test extends TestCase
{
	public function testNormal()
	{
		$xid = new Xid();
		$this->assertTrue(is_string($xid->getGlobalTransactionId()));
		$this->assertNull($xid->getBranchQualifier());
		$this->assertNull($xid->getFormatId());

		$xid2 = new Xid();
		$this->assertNotEquals($xid->getGlobalTransactionId(),
								$xid2->getGlobalTransactionId());
	}

	public function testExplicit()
	{
		$xid = new Xid('foo','bar','boo');
		$this->assertEquals('foo',$xid->getGlobalTransactionId());
		$this->assertEquals('bar',$xid->getBranchQualifier());
		$this->assertEquals('boo',$xid->getFormatId());
	}

	public function testToString()
	{
		$xid = new Xid('foo','bar','boo');
		$this->assertEquals('foo:bar:boo',$xid);
	}
}