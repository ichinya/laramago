<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\LivewireEventContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;

$root = sys_get_temp_dir().'/laramago-livewire-events-'.bin2hex(random_bytes(8));
if (! mkdir($root)) {
    throw new RuntimeException('Cannot create fixture directory.');
}
$file = $root.'/Events.php';
$trap = $root.'/executed';
file_put_contents($file, <<<'PHP'
    <?php
    namespace App\Livewire;

    use Livewire\Attributes\On as Listen;

    file_put_contents(__DIR__.'/executed', 'bad');

    #[Listen('refresh-all')]
    class Events extends \Livewire\Component
    {
        protected $listeners = [
            'saved' => 'saved',
            '0' => 'numericEvent',
            1 => 'integerEvent',
            'post-updated.{post.id}' => 'dynamic',
            'echo:orders,OrderShipped' => 'echoEvent',
            'echo-private:orders,OrderShipped' => 'privateEvent',
            'echo-presence:orders,joining' => 'presenceEvent',
            'unknown' => $variable,
        ];

        #[Listen('created')]
        #[Listen(event: ['published', 'archived'])]
        #[Listen('changed.{id}')]
        public function handle(): void
        {
            $this->dispatch('created', title: 'Hello');
            if ($enabled) {
                $this->dispatch(event: 'published');
            }
            $callback = fn () => $this->dispatch('archived');
            $this->dispatch("changed.$id");
            $other->dispatch('not-this');
            (new class { public function send() { $this->dispatch('not-component'); } })->send();
            function nested() { $this->dispatch('not-component-either'); }
        }
    }
    PHP);

$contracts = new LivewireEventContracts(new PhpSource($root));
$scan = $contracts->inspect($file, 'App\Livewire\Events');
$listeners = $scan === null
    ? []
    : array_map(static fn (array $entry): string => $entry['event'].':'.$entry['method'], $scan['listeners']);
$dispatches = $scan === null
    ? []
    : array_map(static fn (array $entry): string => $entry['event'].':'.$entry['method'], $scan['dispatches']);
if (
    $listeners !== [
        'refresh-all:$refresh',
        'saved:saved',
        'numericEvent:numericEvent',
        'integerEvent:integerEvent',
        'created:handle',
        'published:handle',
        'archived:handle',
    ]
    || $dispatches !== ['created:handle', 'published:handle', 'archived:handle']
    || ! $scan['unresolved']
    || file_exists($trap)
    || $contracts->inspect($file, 'App\Livewire\Absent') !== null
) {
    throw new RuntimeException('Unexpected Livewire event syntax scan: '.json_encode($scan));
}

file_put_contents($file, <<<'PHP'
    <?php
    namespace App\Livewire;
    class OverrideListeners extends \Livewire\Component {
        protected $listeners = ['saved' => 'handle'];
        protected function getListeners() { return ['other' => 'handle']; }
    }
    PHP);
$override = (new LivewireEventContracts(new PhpSource($root)))->inspect($file, 'App\Livewire\OverrideListeners');
if ($override === null || ! $override['unresolved']) {
    throw new RuntimeException('Listener override must mark the snapshot unresolved.');
}

unlink($file);
rmdir($root);
echo "Livewire event source contracts passed.\n";
