Transaction manager
===================
Master: [![Build Status](https://travis-ci.com/rindow/rindow-transaction.png?branch=master)](https://travis-ci.com/rindow/rindow-transaction)

This module integrates and manages transaction processing of resources

In general, it supports Declarative-transaction management with AOP, but it can also be used as a standalone library.

It also supports distributed transactions using the "Xa interface" as an option.

It has the following features

- Transaction management including Synchronization(used for ORM etc.)
- Local transaction and Distributed transaction manager.
- Supports Declarative-transaction management
- Supports annotation based configuration.
- Supports file based configuration.
