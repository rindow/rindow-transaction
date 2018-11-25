<?php
namespace Rindow\Transaction\Annotation;

use Rindow\Stdlib\Entity\AbstractPropertyAccess;

/**
* Specifies whether a session bean or message driven bean has container managed transactions or bean managed transactions.
*
* @Annotation
* @Target({ TYPE })
*/
class TransactionManagement extends AbstractPropertyAccess
{
    /**
    * @var string or array    list or name of a transaction manager
    */
    public $value;
}