<?php

namespace Steets\FormsGdpr\Tests;

use Steets\FormsGdpr\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
