<?php
namespace Rindow\Transaction\Xa;

use Interop\Lenient\Transaction\Xa\XaException as XaExceptionInterface;

class XaException extends \RuntimeException implements XaExceptionInterface
{
}
