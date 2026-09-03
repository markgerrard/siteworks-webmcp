<?php

namespace App\Livewire\Concerns;

trait DemoUnavailable
{
    public bool $demo = false;

    public function initializeDemoUnavailable(): void
    {
        $this->demo = (bool) config('demo.enabled');
    }

    protected function demoUnavailable(string $feature): void
    {
        if (! config('demo.enabled')) {
            return;
        }

        session()->flash($this->demoNoticeChannel(), 'Not available in this demo');
    }

    protected function demoNoticeChannel(): string
    {
        return 'demo-msg';
    }
}
