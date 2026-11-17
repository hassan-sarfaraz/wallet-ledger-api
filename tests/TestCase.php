<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();

        if ($application['config']->get('database.connections.mysql.database') !== 'wallet_ledger_test') {
            throw new \RuntimeException('Tests may run only against wallet_ledger_test.');
        }

        return $application;
    }
}
