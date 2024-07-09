<?php

namespace IMEdge\SimpleDaemon;

interface DaemonComponent
{
    public function start(): void;

    public function stop(): void;
}
