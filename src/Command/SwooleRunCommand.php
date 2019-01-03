<?php


namespace Woody\Symfony\Bundle\Command;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Woody\Http\Message\Response;
use Woody\Http\Message\ServerRequest;
use Woody\Http\Server\Middleware\Dispatcher;
use Woody\Middleware\CorrelationId\CorrelationIdMiddleware;
use Woody\Middleware\Exception\ExceptionMiddleware;
use Woody\Middleware\Logs\LogsMiddleware;
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

        // @todo: clean this code used by Woody\Request
        if (!defined('DOCUMENT_ROOT')) {
            define('DOCUMENT_ROOT', $_SERVER['PROJECT_DIR']);
        }
        if (!defined('SCRIPT_FILENAME')) {
            define('SCRIPT_FILENAME', $_SERVER['PROJECT_DIR'].'/'.$_SERVER['SCRIPT_NAME']);
        }

        $debug = isset($_SERVER['APP_DEBUG']) ? boolval($_SERVER['APP_DEBUG']) : false;

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
            function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) use ($debug) {
                try {
                    // Reset SERVER with minimal values.
                    $_SERVER = $_ENV;

                    $logHandler = new ErrorLogHandler();
                    $memoryUsageProcessor = new MemoryUsageProcessor(true, false);
                    $logger = new Logger('swoole', [$logHandler], [$memoryUsageProcessor]);

                    $dispatcher = new Dispatcher();
                    $dispatcher->enableDebug($debug);
                    $dispatcher->pipe(new CorrelationIdMiddleware());
                    $dispatcher->pipe(new LogsMiddleware($logger));
                    $dispatcher->pipe(new ExceptionMiddleware());
                    $dispatcher->pipe(new WhoopsMiddleware());
                    $dispatcher->pipe(new SymfonyMiddleware());

                    $request = ServerRequest::createFromSwoole($swooleRequest);
                    $response = $dispatcher->handle($request);

                    Response::send($response, $swooleResponse);
                } catch (\Throwable $t) {
                    $swooleResponse->status(500);
                    $swooleResponse->end('Internal Error');
                }
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
