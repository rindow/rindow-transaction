<?php
namespace Rindow\Transaction\Distributed;

class Module
{
    public function getConfig()
    {
        return array(
            'module_manager' => array(
                //'filters' => array(
                //    'Rindow\\Transaction\\Support\\MetadataFilter::apply',
                //),
            ),
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
                    'defaultTransactionManager' => 'Rindow\\Transaction\\Distributed\\DefaultTransactionManager',
                    'managers' => array(
                        'Rindow\\Transaction\\Distributed\\DefaultTransactionManager' => array(
                            'transactionManager' => 'Rindow\\Transaction\\Distributed\\DefaultTransactionManager',
                            'advisorClass' => 'Rindow\\Transaction\\Support\\TransactionAdvisor',
                        ),
                    ),
                ),
            ),
            'container' => array(
                'components' => array(
                    'Rindow\\Transaction\\Distributed\\DefaultTransactionSynchronizationRegistry' => array(
                        'class' => 'Rindow\\Transaction\\Support\\TransactionSynchronizationRegistry',
                        'properties' => array(
                            'transactionManager' => array('ref'=>'Rindow\\Transaction\\Distributed\\DefaultTransactionManager'),
                        ),
                        'proxy' => 'disable',
                    ),
                    'Rindow\\Transaction\\Distributed\\DefaultTransactionManager' => array(
                        'class' => 'Rindow\\Transaction\\Distributed\\TransactionManager',
                        'proxy' => 'disable',
                    ),
                ),
            ),
        );
    }
}
