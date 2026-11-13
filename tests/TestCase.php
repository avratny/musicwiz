<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Sperre vor RefreshDatabase & Co.: Tests leeren die Datenbank – nie eine andere als musicwiz_test */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');
        if ($database !== 'musicwiz_test') {
            throw new \RuntimeException('Tests laufen nur gegen musicwiz_test, nicht gegen „'.$database.'“.');
        }

        return parent::setUpTraits();
    }
}
