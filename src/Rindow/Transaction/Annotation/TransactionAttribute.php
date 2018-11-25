<?php
namespace Rindow\Transaction\Annotation;

use Rindow\Stdlib\Entity\AbstractPropertyAccess;

/**
* The annotated method will be used as advice.
*
* @Annotation
* @Target({ METHOD })
*/
class TransactionAttribute extends AbstractPropertyAccess
{
    /**
    * @Enum("mandatory","nested","never","not_supported","required","requires_new","supports")
    */
    public $value;

    /**
    * rindow extentions
    */

    /**
    * @var string  transaction name of a definition
    */
    public $name;

    /**
    * @Enum("default","read_uncommitted","read_committed","repeatable_read","serializable")
    */
    public $isolation;

    /**
    * @var bool  read only transaction
    */
    public $readOnly;

    /**
    * @var number  transaction timeout
    */
    public $timeout;

    /**
    * @var array  name of no rollback for exception classes
    */
    public $noRollbackFor;
}