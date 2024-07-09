<?php

namespace IMEdge\SimpleDaemon;

use Amp\TimeoutCancellation;
use IMEdge\systemd\systemd;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

class SimpleDaemon implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var DaemonComponent[] */
    protected array $daemonTasks = [];
    protected bool $tasksStarted = false;
    protected bool $reloading = false;
    protected bool $shuttingDown = false;

    public function run(): void
    {
        $this->logger ??= new NullLogger();
        $this->registerSignalHandlers();
        systemd::notificationSocket()?->setReady();
        EventLoop::queue($this->startTasks(...));
        EventLoop::run();
    }

    public function attachTask(DaemonComponent $task): void
    {
        if ($task instanceof LoggerAwareInterface) {
            $task->setLogger($this->logger ?: new NullLogger());
        }

        $this->daemonTasks[] = $task;
        if ($this->tasksStarted) {
            $this->startTask($task);
        }
    }

    protected function startTasks(): void
    {
        $this->tasksStarted = true;
        foreach ($this->daemonTasks as $task) {
            $this->startTask($task);
        }
    }

    protected function startTask(DaemonComponent $task): void
    {
        $task->start();
    }

    protected function stopTasks(): void
    {
        $stopping = [];
        foreach ($this->daemonTasks as $id => $task) {
            $stopping[] = async(function () use ($id, $task) {
                $task->stop();
                unset($this->daemonTasks[$id]);
            });
        }
        awaitAll($stopping);
        $this->tasksStarted = false;
    }

    protected function registerSignalHandlers(): void
    {
        $sigHup = '';
        $sigHup = EventLoop::onSignal(SIGHUP, function () use (&$sigHup) {
            EventLoop::cancel($sigHup);
            $this->logger?->notice('Got SIGHUP, reloading');
            EventLoop::delay(0.05, $this->reload(...));
        });
        $sigInt = '';
        $sigInt = EventLoop::onSignal(SIGINT, function () use (&$sigInt) {
            $this->logger?->notice('Got SIGINT, shutting down');
            EventLoop::cancel($sigInt);
            $this->shutdown();
        });
        $sigTerm = '';
        $sigTerm = EventLoop::onSignal(SIGTERM, function () use (&$sigTerm) {
            EventLoop::cancel($sigTerm);
            $this->logger?->notice('Got SIGTERM, shutting down');
            $this->shutdown();
        });
    }

    public function reload(): void
    {
        if ($this->reloading) {
            $this->logger?->error('Ignoring reload request, reload is already in progress');
            return;
        }
        $this->reloading = true;
        $this->logger->notice('Stopping tasks, going gown for reload now');
        systemd::notificationSocket()?->setReloading('Reloading the main process');
        $this->runShutdown();
        $this->logger->notice('Everything stopped, restarting');
        EventLoop::delay(0.05, Process::restart(...));
    }

    public function shutdown(): void
    {
        $this->runShutdown();
        EventLoop::delay(0.1, EventLoop::getDriver()->stop(...));
    }

    protected function runShutdown(): void
    {
        if ($this->shuttingDown) {
            $this->logger?->error('Got shutdown request during shutdown, ignoring');
            return;
        }
        $this->shuttingDown = true;
        systemd::notificationSocket()?->setStatus('Shutting down');
        try {
            async($this->stopTasks(...))->await(new TimeoutCancellation(5));
        } catch (Throwable $e) {
            $this->logger?->error('Shutdown timed out, stopping anyway: ' . $e->getMessage());
        }
    }
}
