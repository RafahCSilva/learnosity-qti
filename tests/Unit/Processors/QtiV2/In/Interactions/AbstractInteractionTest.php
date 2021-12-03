<?php


namespace LearnosityQti\Tests\Unit\Processors\QtiV2\In\Interactions;

use LearnosityQti\Services\LogService;

abstract class AbstractInteractionTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        LogService::flush();
    }
}
