<?php

namespace Woody\Symfony\Bundle;

use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Woody\Symfony\Bundle\Command\SwooleRunCommand;

/**
 * Class WoodyBundle
 *
 * @package Woody\Symfony\Bundle
 */
class WoodyBundle extends Bundle
{

    /**
     * @inheritdoc
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
    }

    /**
     * @inheritdoc
     */
    public function registerCommands(Application $application)
    {
        $application->add(new SwooleRunCommand());
    }
}
