<?php

namespace App\Logging;

use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\WebProcessor;

/**
 * Custom Logger Factory for Centralized Logging
 * 
 * Creates Monolog loggers configured for ELK/Loki/Graylog.
 */
class CentralizedLoggerFactory
{
    /**
     * Create a custom Monolog instance for ELK Stack
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger($config['name'] ?? 'absensi');

        // Add handlers based on configuration
        $this->addHandlers($logger, $config);

        // Add processors
        $this->addProcessors($logger, $config);

        return $logger;
    }

    /**
     * Add handlers to logger
     */
    protected function addHandlers(Logger $logger, array $config): void
    {
        $level = Level::fromName($config['level'] ?? 'debug');
        
        // Primary handler based on driver
        $driver = $config['driver'] ?? 'elk';

        switch ($driver) {
            case 'elk':
            case 'logstash':
                $this->addLogstashHandler($logger, $config, $level);
                break;

            case 'loki':
                $this->addLokiHandler($logger, $config, $level);
                break;

            case 'graylog':
                $this->addGraylogHandler($logger, $config, $level);
                break;

            case 'fluentd':
                $this->addFluentdHandler($logger, $config, $level);
                break;

            default:
                $this->addFileHandler($logger, $config, $level);
        }

        // Always add local file fallback for reliability
        if ($config['local_fallback'] ?? true) {
            $this->addLocalFallbackHandler($logger, $level);
        }
    }

    /**
     * Add Logstash/ELK handler
     */
    protected function addLogstashHandler(Logger $logger, array $config, Level $level): void
    {
        $host = $config['host'] ?? env('LOGSTASH_HOST', 'localhost');
        $port = $config['port'] ?? env('LOGSTASH_PORT', 5044);
        $protocol = $config['protocol'] ?? 'tcp';

        if ($protocol === 'udp') {
            $handler = new SyslogUdpHandler($host, $port, LOG_USER, $level);
        } else {
            // TCP socket handler
            $handler = new SocketHandler("tcp://{$host}:{$port}", $level);
            $handler->setPersistent(true);
            $handler->setConnectionTimeout($config['timeout'] ?? 5);
        }

        $handler->setFormatter(new JsonLogFormatter());
        $logger->pushHandler($handler);
    }

    /**
     * Add Grafana Loki handler
     */
    protected function addLokiHandler(Logger $logger, array $config, Level $level): void
    {
        $host = $config['host'] ?? env('LOKI_HOST', 'localhost');
        $port = $config['port'] ?? env('LOKI_PORT', 3100);

        // Loki uses HTTP push, create a custom handler or use stream to stdout for Promtail
        $stream = $config['stream'] ?? 'php://stdout';
        
        $handler = new StreamHandler($stream, $level);
        $handler->setFormatter(new JsonLogFormatter());
        
        $logger->pushHandler($handler);
    }

    /**
     * Add Graylog handler
     */
    protected function addGraylogHandler(Logger $logger, array $config, Level $level): void
    {
        $host = $config['host'] ?? env('GRAYLOG_HOST', 'localhost');
        $port = $config['port'] ?? env('GRAYLOG_PORT', 12201);

        // GELF UDP handler
        $handler = new SyslogUdpHandler($host, $port, LOG_USER, $level);
        $handler->setFormatter(new JsonLogFormatter());
        
        $logger->pushHandler($handler);
    }

    /**
     * Add Fluentd handler
     */
    protected function addFluentdHandler(Logger $logger, array $config, Level $level): void
    {
        $host = $config['host'] ?? env('FLUENTD_HOST', 'localhost');
        $port = $config['port'] ?? env('FLUENTD_PORT', 24224);

        $handler = new SocketHandler("tcp://{$host}:{$port}", $level);
        $handler->setFormatter(new JsonLogFormatter());
        
        $logger->pushHandler($handler);
    }

    /**
     * Add file handler (for local or cloud storage)
     */
    protected function addFileHandler(Logger $logger, array $config, Level $level): void
    {
        $path = $config['path'] ?? storage_path('logs/centralized.log');
        
        $handler = new StreamHandler($path, $level);
        $handler->setFormatter(new JsonLogFormatter());
        
        $logger->pushHandler($handler);
    }

    /**
     * Add local fallback handler for reliability
     */
    protected function addLocalFallbackHandler(Logger $logger, Level $level): void
    {
        $handler = new StreamHandler(
            storage_path('logs/centralized-fallback.log'),
            $level
        );
        $handler->setFormatter(new JsonLogFormatter());
        
        $logger->pushHandler($handler);
    }

    /**
     * Add processors to enrich log data
     */
    protected function addProcessors(Logger $logger, array $config): void
    {
        // Add memory usage
        if ($config['memory_usage'] ?? true) {
            $logger->pushProcessor(new MemoryUsageProcessor());
        }

        // Add web request info
        if ($config['web_processor'] ?? true) {
            $logger->pushProcessor(new WebProcessor());
        }

        // Add file/line info for errors
        if ($config['introspection'] ?? false) {
            $logger->pushProcessor(new IntrospectionProcessor(Level::Error));
        }

        // Custom context processor
        $logger->pushProcessor(function ($record) {
            // Merge LogContext into extra
            $record['extra'] = array_merge(
                $record['extra'] ?? [],
                ['log_context' => LogContext::all()]
            );
            return $record;
        });
    }
}
