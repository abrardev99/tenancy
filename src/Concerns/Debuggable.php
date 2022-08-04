<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Stancl\Tenancy\Enums\LogMode;
use Stancl\Tenancy\Events\Contracts\TenancyEvent;
use Stancl\Tenancy\Tenancy;

/**
 * @mixin Tenancy
 */
trait Debuggable
{
    protected LogMode $logMode = LogMode::NONE;
    protected array $eventLog = [];

    public function log(LogMode $mode = LogMode::SILENT): Debuggable
    {
        $this->eventLog = [];
        $this->logMode = $mode;

        return $this;
    }

    public function logMode(): LogMode
    {
        return $this->logMode;
    }

    public function getLog(): array
    {
        return $this->eventLog;
    }

    public function logEvent(TenancyEvent $event): Debuggable
    {
        $this->eventLog[] = ['time' => now(), 'event' => get_class($event), 'tenant' => $this->tenant];

        return $this;
    }

    public function dump(Closure $dump = null): Debuggable
    {
        $dump ??= Closure::fromCallable('dd');

        // Dump the log if we were already logging in silent mode
        // Otherwise start logging in instant mode
        if ($this->logMode === LogMode::NONE) {
            $this->log(LogMode::INSTANT);
        }

        if ($this->logMode === LogMode::SILENT) {
            $dump($this->eventLog);
        }

        return $this;
    }

    public function dd(Closure $dump = null): void
    {
        $dump ??= Closure::fromCallable('dd');

        if ($this->logMode === LogMode::SILENT) {
            $dump($this->eventLog);
        } else {
            $dump($this);
        }
    }
}
