<?php


namespace Woody\Symfony\Bundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Woody\Http\Message\Response;
use Woody\Http\Message\ServerRequest;
use Woody\Http\Server\Middleware\Dispatcher;
use Woody\Middleware\Symfony\SymfonyMiddleware;
use Woody\Middleware\Whoops\WhoopsMiddleware;

/**
 * Class SwooleRunCommand
 *
 * @package Woody\Symfony\Bundle\Command
 */
class SwooleRunCommand extends Command
{

    /**
     * @var string
     */
    protected static $defaultName = 'swoole:run';

    /**
     *
     */
    protected function configure()
    {
        // @todo: ask for ip, port...
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
            Request::setTrustedProxies(
                explode(',', $trustedProxies),
                Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST
            );
        }

        if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
            Request::setTrustedHosts([$trustedHosts]);
        }

        if (!defined('DOCUMENT_ROOT')) {
            define('DOCUMENT_ROOT', $_SERVER['PROJECT_DIR']);
        }
        if (!defined('SCRIPT_FILENAME')) {
            define('SCRIPT_FILENAME', $_SERVER['PROJECT_DIR'].'/'.$_SERVER['SCRIPT_NAME']);
        }

        $server = new \Swoole\Http\Server('0.0.0.0', 9501);
        $server->set($this->getServerSettings());

        $server->on(
            'start',
            function () use ($output) {
                $output->writeln('Server started');
            }
        );

        $server->on(
            'workerStart',
            function (\Swoole\Http\Server $server) use ($output) {
                $output->writeln('Worker started: '.memory_get_usage(true));
            }
        );

        $server->on(
            'workerStop',
            function (\Swoole\Http\Server $server) use ($output) {
                $output->writeln('Worker stopped: '.memory_get_usage(true));
            }
        );

        $server->on(
            'request',
            function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) {
                // Reset SERVER with minimal values.
                $_SERVER = $_ENV;

                $dispatcher = new Dispatcher();
                $dispatcher->pipe(new WhoopsMiddleware());
                $dispatcher->pipe(new SymfonyMiddleware());

                $request = ServerRequest::createFromSwoole($swooleRequest);
                $response = $dispatcher->handle($request);

                Response::send($response, $swooleResponse);
            }
        );

        $output->writeln('Server initializing');

        $server->start();
    }

    /**
     * @return array
     */
    protected function getServerSettings(): array
    {
        return [
            'max_request' => 1000,
            'document_root' => $this->getApplication()->getKernel()->getProjectDir().'/public',
            'enable_static_handler' => true,
        ];
    }
}