<?php
namespace Rindow\Transaction\Local;

class Module
{
    public function getConfig()
    {
        return array(
            'annotation' => array(
                'aliases' => array(
                    'Interop\\Lenient\\Transaction\\Annotation\\TransactionAttribute' => 
                        'Rindow\\Transaction\\Annotation\\TransactionAttribute',
                    'Interop\\Lenient\\Transaction\\Annotation\\TransactionManagement' => 
                        'Rindow\\Transaction\\Annotation\\TransactionManagement',
                ),
            ),
            'aop' => array(
                'plugins' => array(
                    'Rindow\\Transaction\\Support\\AnnotationHandler'=>true,
                ),
                'transaction' => array(
                    //'defaultTransactionManager' => 'your default transaction manager',
                    //'managers' => array(
                    //    configurations of your transaction managers
                    //    'your transaction manager' => array(
                    //        'transactionManager' => 'component name of transaction manager',
                    //        'advisorClass' => 'transaction advisor class',
                    //    ),
                    //),
                ),
                'aspects' => array(
                    'Rindow\\Transaction\\DefaultTransactionAdvisor' => array(
                        'component' => 'Rindow\\Transaction\\DefaultTransactionAdvisor',
                        // pointcuts for transaction boundary
                        //'pointcuts' => array(
                        //    'your reference name' => 'execute(Your\\Class\\Name::yourMethod())',
                        //),
                        'advices' => array(
                            'required' => array(
                                'type' => 'around',
                                // override pointcuts for transaction boundary
                                //'pointcut_ref' => array(
                                //    'your reference name'=>true,
                                //),
                            ),
                        ),
                    ),
                ),
            ),
            'container' => array(
                'components' => array(
                    'Rindow\\Transaction\\DefaultTransactionAdvisor'=>array(
                        'class' => 'Rindow\\Transaction\\Support\\TransactionAdvisor',
                        'properties' => array(
                            'transactionManager' => array('ref@'=>'aop::transaction::defaultTransactionManager'),
                        ),
                    ),
                ),
            ),
        );
    }
}
