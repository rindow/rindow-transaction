<?php
namespace Rindow\Transaction\Xa;

use Interop\Lenient\Transaction\Xa\XAException as XAExceptionInterface;

class XAException extends \RuntimeException implements XAExceptionInterface
{
}
