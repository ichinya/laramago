<?php
declare(strict_types=1);
return array (
  'argv.php' => '<?php
$arguments=$_SERVER[\'argv\'] ?? []; $normalized=[];
// positive: argv-normalization
if (is_array($arguments)) {
    foreach ($arguments as $argument) {
        // positive: argv-element
        if (!is_string($argument)) { exit(2); }
        $normalized[]=$argument;
    }
}',
  'cases.php' => '<?php
namespace Example\\Boundary;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\ValidationException;
function consumeInteger(int $value): void {}
function configurationArray(): void {
    $rows = config(\'example.rows\');
    // positive: configuration-array
    if (!is_array($rows)) { throw new \\UnexpectedValueException(\'Configured rows must be an array.\'); }
    consumeInteger($rows); // independent Error remains
}
function configurationString(): void {
    $timezone = config(\'example.timezone\');
    // positive: configuration-string
    if (!is_string($timezone) || $timezone === \'\') { throw new \\LogicException(\'A timezone is required.\'); }
    consumeInteger($timezone); // independent Error remains
}
function configurationOptional(): void {
    $guard = config(\'example.optional\');
    // positive: configuration-optional
    if (!is_string($guard) && $guard !== null) { throw new \\LogicException(\'Invalid optional guard.\'); }
}
function configurationKeys(): void {
    $limits = config(\'example.table\');
    foreach ($limits as $key => $limit) {
        // positive: configuration-key
        if (!is_string($key)) { throw new \\UnexpectedValueException(\'Keys must be strings.\'); }
    }
}
function configurationInventory(): array {
    $errors = [];
    $rows = config(\'example.rows\');
    // positive: configuration-report
    if (!is_array($rows) || $rows === []) { $errors[] = \'Rows are required.\'; $rows = []; }
    foreach ($rows as $row) {
        // positive: configuration-row
        if (!is_array($row)) { $errors[] = \'Invalid row.\'; continue; }
        $id = $row[\'id\'] ?? null;
        // positive: configuration-field
        if (!is_string($id) || $id === \'\') { $errors[] = \'Invalid identifier.\'; continue; }
    }
    return $errors;
}
function requestData(Request $request): void {
    $result = $request->validate([\'name\'=>\'required|string\']);
    // positive: request-validation
    if (!is_array($result)) { throw ValidationException::withMessages([\'name\'=>\'Invalid fields.\']); }
    consumeInteger($result); // independent Error remains
}
function requestComposite(Request $request): void {
    $result = $request->validate([\'name\'=>\'required|string\']);
    // positive: request-composite
    if (!is_array($result) || !is_string($result[\'name\'] ?? null)) { throw new \\LogicException(\'Invalid fields.\'); }
}
function fixedIntegerOffsets(string $packed): bool {
    $words = unpack(\'N4\',$packed);
    if ($words === false) { return false; }
    for ($index=1; $index<=4; ++$index) {
        if (!isset($words[$index])) { return false; }
        $word=$words[$index];
        // positive: fixed-integer-offset
        if (!is_int($word)) { return false; }
    }
    return true;
}
function declaredFormal(array $rows): void {
    // negative: arbitrary-formal
    if (!is_array($rows)) { throw new \\UnexpectedValueException(\'Unrelated formal.\'); }
    consumeInteger($rows);
}
function businessBranch(): void {
    $rows=config(\'example.rows\');
    // negative: ordinary-business-branch
    if ($rows===[]) { return; }
}
function continuedExecution(): void {
    $rows=config(\'example.rows\');
    // negative: nonrejecting-body
    if (!is_array($rows)) { consumeInteger(1); }
}
function referencedBoundary(): void {
    $rows=config(\'example.rows\'); $alias=&$rows;
    // negative: reference-escape
    if (!is_array($rows)) { throw new \\UnexpectedValueException(\'Aliased input.\'); }
}
function effectfulCondition(): void {
    $rows=config(\'example.rows\');
    // negative: condition-assignment
    if (!is_array($rows) || ($state=1)===1) { throw new \\UnexpectedValueException(\'Effectful condition.\'); }
}
function unrelatedBoolean(bool $business): void {
    $rows=config(\'example.rows\');
    // negative: unrelated-boolean
    if (!is_array($rows) || $business) { throw new \\UnexpectedValueException(\'Unrelated condition.\'); }
}
function unknownProducer(mixed $value): void {
    // negative: unknown-producer
    if (!is_array($value)) { throw new \\UnexpectedValueException(\'Unknown producer.\'); }
}
function wrongUnpackProfile(string $packed): bool {
    $words=unpack(\'a4\',$packed);
    if ($words===false) { return false; }
    for ($index=1; $index<=4; ++$index) {
        if (!isset($words[$index])) { return false; }
        $word=$words[$index];
        // negative: unpack-format
        if (!is_int($word)) { return false; }
    }
    return true;
}
function absentOffsetPresence(string $packed): bool {
    $words=unpack(\'N4\',$packed);
    if ($words===false) { return false; }
    for ($index=1; $index<=4; ++$index) {
        $word=$words[$index];
        // negative: unproved-offset-presence
        if (!is_int($word)) { return false; }
    }
    return true;
}
function configurationRouteKeys(): void {
    $limits=config(\'example.routes\');
    // positive: configuration-route-keys
    if(!is_array($limits)){throw new \\UnexpectedValueException(\'Route limits must be an array.\');}
    consumeInteger($limits); // independent invalid-argument Error stays native
}',
  'cli.php' => '<?php
$raw=$_SERVER[\'argv\'] ?? null;
// positive: cli-list
if (!is_array($raw) || !array_is_list($raw) || count($raw)!==4) { exit(2); }
foreach (array_slice($raw,1) as $argument) {
    // positive: cli-slice-element
    if (!is_string($argument)) { throw new RuntimeException(\'Arguments must be strings.\'); }
}
$options=getopt(\'\', [\'group:\',\'json\']);
if ($options===false) { throw new RuntimeException(\'Options unavailable.\'); }
$group=$options[\'group\'] ?? null;
// positive: cli-getopt
if ($group!==null && !is_string($group)) { throw new RuntimeException(\'A group must be a string.\'); }',
  'composer.json' => '{"name":"example/boundary-guard-fixture","autoload":{"psr-4":{"Example\\\\Boundary\\\\":"src/"}}}',
  'config/example.php' => '<?php
return [\'rows\'=>[[\'id\'=>\'primary\',\'enabled\'=>true]], \'table\'=>[\'primary\'=>12], \'timezone\'=>\'UTC\', \'optional\'=>\'web\', \'routes\'=>[\'session/login\'=>[\'attempts\'=>5,\'decay_seconds\'=>60], \'reports/*\'=>[\'attempts\'=>3,\'decay_seconds\'=>120], \'calendar/day-view\'=>[\'attempts\'=>30,\'decay_seconds\'=>60]]];',
  'mutated-global.php' => '<?php
$_SERVER[\'argv\']=[\'application supplied\'];
$arguments=$_SERVER[\'argv\'] ?? []; $normalized=[];
// negative: changed-global-source
if (is_array($arguments)) { foreach ($arguments as $argument) { if (!is_string($argument)) { exit(2); } $normalized[]=$argument; } }',
  'shadow.php' => '<?php
namespace Example\\Shadow;
function is_array(mixed $value): bool { return true; }
$arguments=$_SERVER[\'argv\'] ?? []; $normalized=[];
// negative: predicate-shadow
if (is_array($arguments)) { foreach ($arguments as $argument) { if (!is_string($argument)) { exit(2); } $normalized[]=$argument; } }',
  'vendor/laravel/framework/src/Illuminate/Conditionable/Traits/Conditionable.php' => '<?php

namespace Illuminate\\Support\\Traits;

use Closure;
use Illuminate\\Support\\HigherOrderWhenProxy;

trait Conditionable
{
    /**
     * Apply the callback if the given "value" is (or resolves to) truthy.
     *
     * @template TWhenParameter
     * @template TWhenReturnType
     *
     * @param  (\\Closure($this): TWhenParameter)|TWhenParameter|null  $value
     * @param  (callable($this, TWhenParameter): TWhenReturnType)|null  $callback
     * @param  (callable($this, TWhenParameter): TWhenReturnType)|null  $default
     * @return $this|TWhenReturnType
     */
    public function when($value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if (func_num_args() === 0) {
            return new HigherOrderWhenProxy($this);
        }

        if (func_num_args() === 1) {
            return (new HigherOrderWhenProxy($this))->condition($value);
        }

        if ($value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Apply the callback if the given "value" is (or resolves to) falsy.
     *
     * @template TUnlessParameter
     * @template TUnlessReturnType
     *
     * @param  (\\Closure($this): TUnlessParameter)|TUnlessParameter|null  $value
     * @param  (callable($this, TUnlessParameter): TUnlessReturnType)|null  $callback
     * @param  (callable($this, TUnlessParameter): TUnlessReturnType)|null  $default
     * @return $this|TUnlessReturnType
     */
    public function unless($value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if (func_num_args() === 0) {
            return (new HigherOrderWhenProxy($this))->negateConditionOnCapture();
        }

        if (func_num_args() === 1) {
            return (new HigherOrderWhenProxy($this))->condition(! $value);
        }

        if (! $value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/Arrayable.php' => '<?php

namespace Illuminate\\Contracts\\Support;

/**
 * @template TKey of array-key
 * @template TValue
 */
interface Arrayable
{
    /**
     * Get the instance as an array.
     *
     * @return array<TKey, TValue>
     */
    public function toArray();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/CanBeEscapedWhenCastToString.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface CanBeEscapedWhenCastToString
{
    /**
     * Indicate that the object\'s string representation should be escaped when __toString is invoked.
     *
     * @param  bool  $escape
     * @return $this
     */
    public function escapeWhenCastingToString($escape = true);
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/DeferrableProvider.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface DeferrableProvider
{
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/DeferringDisplayableValue.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface DeferringDisplayableValue
{
    /**
     * Resolve the displayable value that the class is deferring.
     *
     * @return \\Illuminate\\Contracts\\Support\\Htmlable|string
     */
    public function resolveDisplayableValue();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/HasOnceHash.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface HasOnceHash
{
    /**
     * Compute the hash that should be used to represent the object when given to a function using "once".
     *
     * @return string
     */
    public function onceHash();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/Htmlable.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface Htmlable
{
    /**
     * Get content as a string of HTML.
     *
     * @return string
     */
    public function toHtml();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/Jsonable.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface Jsonable
{
    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0);
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/MessageBag.php' => '<?php

namespace Illuminate\\Contracts\\Support;

use Countable;

interface MessageBag extends Arrayable, Countable
{
    /**
     * Get the keys present in the message bag.
     *
     * @return array
     */
    public function keys();

    /**
     * Add a message to the bag.
     *
     * @param  string  $key
     * @param  string  $message
     * @return $this
     */
    public function add($key, $message);

    /**
     * Merge a new array of messages into the bag.
     *
     * @param  \\Illuminate\\Contracts\\Support\\MessageProvider|array  $messages
     * @return $this
     */
    public function merge($messages);

    /**
     * Determine if messages exist for a given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function has($key);

    /**
     * Get the first message from the bag for a given key.
     *
     * @param  string|null  $key
     * @param  string|null  $format
     * @return string
     */
    public function first($key = null, $format = null);

    /**
     * Get all of the messages from the bag for a given key.
     *
     * @param  string  $key
     * @param  string|null  $format
     * @return array
     */
    public function get($key, $format = null);

    /**
     * Get all of the messages for every key in the bag.
     *
     * @param  string|null  $format
     * @return array
     */
    public function all($format = null);

    /**
     * Remove a message from the bag.
     *
     * @param  string  $key
     * @return $this
     */
    public function forget($key);

    /**
     * Get the raw messages in the container.
     *
     * @return array
     */
    public function getMessages();

    /**
     * Get the default message format.
     *
     * @return string
     */
    public function getFormat();

    /**
     * Set the default message format.
     *
     * @param  string  $format
     * @return $this
     */
    public function setFormat($format = \':message\');

    /**
     * Determine if the message bag has any messages.
     *
     * @return bool
     */
    public function isEmpty();

    /**
     * Determine if the message bag has any messages.
     *
     * @return bool
     */
    public function isNotEmpty();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/MessageProvider.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface MessageProvider
{
    /**
     * Get the messages for the instance.
     *
     * @return \\Illuminate\\Contracts\\Support\\MessageBag
     */
    public function getMessageBag();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/Renderable.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface Renderable
{
    /**
     * Get the evaluated contents of the object.
     *
     * @return string
     */
    public function render();
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/Responsable.php' => '<?php

namespace Illuminate\\Contracts\\Support;

interface Responsable
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \\Illuminate\\Http\\Request  $request
     * @return \\Symfony\\Component\\HttpFoundation\\Response
     */
    public function toResponse($request);
}
',
  'vendor/laravel/framework/src/Illuminate/Contracts/Support/ValidatedData.php' => '<?php

namespace Illuminate\\Contracts\\Support;

use ArrayAccess;
use IteratorAggregate;

interface ValidatedData extends Arrayable, ArrayAccess, IteratorAggregate
{
    //
}
',
  'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php' => '<?php /**
 * Get / set the specified configuration value.
 *
 * If an array is passed as the key, we will assume you want to set an array of values.
 *
 * @param  array<string, mixed>|string|null  $key
 * @param  mixed  $default
 * @return ($key is null ? \\Illuminate\\Config\\Repository : ($key is string ? mixed : null))
 */
function config($key = \\null, $default = \\null)
{
    if (\\is_null($key)) {
        return \\app(\'config\');
    }
    if (\\is_array($key)) {
        return \\app(\'config\')->set($key);
    }
    return \\app(\'config\')->get($key, $default);
}
',
  'vendor/laravel/framework/src/Illuminate/Foundation/Providers/FoundationServiceProvider.php' => '<?php

namespace Illuminate\\Foundation\\Providers;

use Illuminate\\Console\\Events\\CommandFinished;
use Illuminate\\Console\\Scheduling\\Schedule;
use Illuminate\\Contracts\\Console\\Kernel as ConsoleKernel;
use Illuminate\\Contracts\\Container\\Container;
use Illuminate\\Contracts\\Events\\Dispatcher;
use Illuminate\\Contracts\\Foundation\\Application;
use Illuminate\\Contracts\\Foundation\\ExceptionRenderer;
use Illuminate\\Contracts\\Foundation\\MaintenanceMode as MaintenanceModeContract;
use Illuminate\\Contracts\\View\\Factory;
use Illuminate\\Database\\ConnectionInterface;
use Illuminate\\Database\\Grammar;
use Illuminate\\Foundation\\Console\\CliDumper;
use Illuminate\\Foundation\\Exceptions\\Renderer\\Listener;
use Illuminate\\Foundation\\Exceptions\\Renderer\\Mappers\\BladeMapper;
use Illuminate\\Foundation\\Exceptions\\Renderer\\Renderer;
use Illuminate\\Foundation\\Http\\HtmlDumper;
use Illuminate\\Foundation\\MaintenanceModeManager;
use Illuminate\\Foundation\\Precognition;
use Illuminate\\Foundation\\Vite;
use Illuminate\\Http\\Client\\Factory as HttpFactory;
use Illuminate\\Http\\Request;
use Illuminate\\Log\\Events\\MessageLogged;
use Illuminate\\Queue\\Events\\JobAttempted;
use Illuminate\\Support\\AggregateServiceProvider;
use Illuminate\\Support\\Defer\\DeferredCallbackCollection;
use Illuminate\\Support\\Facades\\URL;
use Illuminate\\Support\\Uri;
use Illuminate\\Testing\\LoggedExceptionCollection;
use Illuminate\\Testing\\ParallelTestingServiceProvider;
use Illuminate\\Validation\\ValidationException;
use Symfony\\Component\\ErrorHandler\\ErrorRenderer\\HtmlErrorRenderer;
use Symfony\\Component\\VarDumper\\Caster\\StubCaster;
use Symfony\\Component\\VarDumper\\Cloner\\AbstractCloner;

class FoundationServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array<int, class-string<\\Illuminate\\Support\\ServiceProvider>>
     */
    protected $providers = [
        FormRequestServiceProvider::class,
        ParallelTestingServiceProvider::class,
    ];

    /**
     * The singletons to register into the container.
     *
     * @var array
     */
    public $singletons = [
        HttpFactory::class => HttpFactory::class,
        Vite::class => Vite::class,
    ];

    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.\'/../Exceptions/views\' => $this->app->resourcePath(\'views/errors/\'),
            ], \'laravel-errors\');
        }

        if ($this->app->hasDebugModeEnabled() && ! $this->app->has(ExceptionRenderer::class)) {
            $this->app->make(Listener::class)->registerListeners(
                $this->app->make(Dispatcher::class)
            );
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        $this->registerConsoleSchedule();
        $this->registerDumper();
        $this->registerRequestValidation();
        $this->registerRequestSignatureValidation();
        $this->registerUriUrlGeneration();
        $this->registerDeferHandler();
        $this->registerExceptionTracking();
        $this->registerExceptionRenderer();
        $this->registerMaintenanceModeManager();
    }

    /**
     * Register the console schedule implementation.
     *
     * @return void
     */
    public function registerConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return $app->make(ConsoleKernel::class)->resolveConsoleSchedule();
        });
    }

    /**
     * Register a var dumper (with source) to debug variables.
     *
     * @return void
     */
    public function registerDumper()
    {
        AbstractCloner::$defaultCasters[ConnectionInterface::class] ??= [StubCaster::class, \'cutInternals\'];
        AbstractCloner::$defaultCasters[Container::class] ??= [StubCaster::class, \'cutInternals\'];
        AbstractCloner::$defaultCasters[Dispatcher::class] ??= [StubCaster::class, \'cutInternals\'];
        AbstractCloner::$defaultCasters[Factory::class] ??= [StubCaster::class, \'cutInternals\'];
        AbstractCloner::$defaultCasters[Grammar::class] ??= [StubCaster::class, \'cutInternals\'];

        $basePath = $this->app->basePath();

        $compiledViewPath = $this->app[\'config\']->get(\'view.compiled\');

        $format = $_SERVER[\'VAR_DUMPER_FORMAT\'] ?? null;

        match (true) {
            \'html\' == $format => HtmlDumper::register($basePath, $compiledViewPath),
            \'cli\' == $format => CliDumper::register($basePath, $compiledViewPath),
            \'server\' == $format => null,
            $format && \'tcp\' == parse_url($format, PHP_URL_SCHEME) => null,
            default => in_array(PHP_SAPI, [\'cli\', \'phpdbg\']) ? CliDumper::register($basePath, $compiledViewPath) : HtmlDumper::register($basePath, $compiledViewPath),
        };
    }

    /**
     * Register the "validate" macro on the request.
     *
     * @return void
     *
     * @throws \\Illuminate\\Validation\\ValidationException
     */
    public function registerRequestValidation()
    {
        Request::macro(\'validate\', function (array $rules, ...$params) {
            return tap(validator($this->all(), $rules, ...$params), function ($validator) {
                if ($this->isPrecognitive()) {
                    $validator->after(Precognition::afterValidationHook($this))
                        ->setRules(
                            $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
                        );
                }
            })->validate();
        });

        Request::macro(\'validateWithBag\', function (string $errorBag, array $rules, ...$params) {
            try {
                return $this->validate($rules, ...$params);
            } catch (ValidationException $e) {
                $e->errorBag = $errorBag;

                throw $e;
            }
        });
    }

    /**
     * Register the "hasValidSignature" macro on the request.
     *
     * @return void
     */
    public function registerRequestSignatureValidation()
    {
        Request::macro(\'hasValidSignature\', function ($absolute = true) {
            return URL::hasValidSignature($this, $absolute);
        });

        Request::macro(\'hasValidRelativeSignature\', function () {
            return URL::hasValidSignature($this, $absolute = false);
        });

        Request::macro(\'hasValidSignatureWhileIgnoring\', function ($ignoreQuery = [], $absolute = true) {
            return URL::hasValidSignature($this, $absolute, $ignoreQuery);
        });

        Request::macro(\'hasValidRelativeSignatureWhileIgnoring\', function ($ignoreQuery = []) {
            return URL::hasValidSignature($this, $absolute = false, $ignoreQuery);
        });
    }

    /**
     * Register the URL resolver for the URI generator.
     *
     * @return void
     */
    protected function registerUriUrlGeneration()
    {
        Uri::setUrlGeneratorResolver(fn () => app(\'url\'));
    }

    /**
     * Register the "defer" function termination handler.
     *
     * @return void
     */
    protected function registerDeferHandler()
    {
        $this->app->scoped(DeferredCallbackCollection::class);

        $this->app[\'events\']->listen(function (CommandFinished $event) {
            app(DeferredCallbackCollection::class)->invokeWhen(fn ($callback) => app()->runningInConsole() && ($event->exitCode === 0 || $callback->always));
        });

        $this->app[\'events\']->listen(function (JobAttempted $event) {
            if (in_array($event->connectionName, [\'sync\', \'deferred\'])) {
                return;
            }

            app(DeferredCallbackCollection::class)->invokeWhen(fn ($callback) => ($event->successful() || $callback->always));
        });
    }

    /**
     * Register an event listener to track logged exceptions.
     *
     * @return void
     */
    protected function registerExceptionTracking()
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        $this->app->instance(
            LoggedExceptionCollection::class,
            new LoggedExceptionCollection
        );

        $this->app->make(\'events\')->listen(MessageLogged::class, function ($event) {
            if (isset($event->context[\'exception\'])) {
                $this->app->make(LoggedExceptionCollection::class)
                    ->push($event->context[\'exception\']);
            }
        });
    }

    /**
     * Register the exceptions renderer.
     *
     * @return void
     */
    protected function registerExceptionRenderer()
    {
        $this->loadViewsFrom(__DIR__.\'/../Exceptions/views\', \'laravel-exceptions\');

        if (! $this->app->hasDebugModeEnabled()) {
            return;
        }

        $this->loadViewsFrom(__DIR__.\'/../resources/exceptions/renderer\', \'laravel-exceptions-renderer\');

        $this->app->singleton(Renderer::class, function (Application $app) {
            $errorRenderer = new HtmlErrorRenderer(
                $app[\'config\']->get(\'app.debug\'),
            );

            return new Renderer(
                $app->make(Factory::class),
                $app->make(Listener::class),
                $errorRenderer,
                $app->make(BladeMapper::class),
                $app->basePath(),
            );
        });

        $this->app->singleton(Listener::class);
    }

    /**
     * Register the maintenance mode manager service.
     *
     * @return void
     */
    public function registerMaintenanceModeManager()
    {
        $this->app->singleton(MaintenanceModeManager::class);

        $this->app->bind(
            MaintenanceModeContract::class,
            fn () => $this->app->make(MaintenanceModeManager::class)->driver()
        );
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Http/Concerns/CanBePrecognitive.php' => '<?php

namespace Illuminate\\Http\\Concerns;

use Illuminate\\Support\\Collection;

trait CanBePrecognitive
{
    /**
     * Filter the given array of rules into an array of rules that are included in precognitive headers.
     *
     * @param  array  $rules
     * @return array
     */
    public function filterPrecognitiveRules($rules)
    {
        if (! $this->headers->has(\'Precognition-Validate-Only\')) {
            return $rules;
        }

        $validateOnly = explode(\',\', $this->header(\'Precognition-Validate-Only\'));

        return (new Collection($rules))
            ->filter(fn ($rule, $attribute) => $this->shouldValidatePrecognitiveAttribute($attribute, $validateOnly))
            ->all();
    }

    /**
     * Determine if the given attribute should be validated.
     *
     * @param  string  $attribute
     * @param  array  $validateOnly
     * @return bool
     */
    protected function shouldValidatePrecognitiveAttribute($attribute, $validateOnly)
    {
        foreach ($validateOnly as $pattern) {
            $regex = \'/^\'.str_replace(\'\\*\', \'[^.]+\', preg_quote($pattern, \'/\')).\'$/\';

            if (preg_match($regex, $attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the request is attempting to be precognitive.
     *
     * @return bool
     */
    public function isAttemptingPrecognition()
    {
        return $this->header(\'Precognition\') === \'true\';
    }

    /**
     * Determine if the request is precognitive.
     *
     * @return bool
     */
    public function isPrecognitive()
    {
        return $this->attributes->get(\'precognitive\', false);
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithContentTypes.php' => '<?php

namespace Illuminate\\Http\\Concerns;

use Illuminate\\Support\\Str;

trait InteractsWithContentTypes
{
    /**
     * Determine if the request is sending JSON.
     *
     * @return bool
     */
    public function isJson()
    {
        return Str::contains($this->header(\'CONTENT_TYPE\') ?? \'\', [\'/json\', \'+json\']);
    }

    /**
     * Determine if the current request probably expects a JSON response.
     *
     * @return bool
     */
    public function expectsJson()
    {
        return ($this->ajax() && ! $this->pjax() && $this->acceptsAnyContentType()) || $this->wantsJson();
    }

    /**
     * Determine if the current request is asking for JSON.
     *
     * @return bool
     */
    public function wantsJson()
    {
        $acceptable = $this->getAcceptableContentTypes();

        return isset($acceptable[0]) && Str::contains(strtolower($acceptable[0]), [\'/json\', \'+json\']);
    }

    /**
     * Determine if the current request is asking for Markdown.
     *
     * @return bool
     */
    public function wantsMarkdown()
    {
        $acceptable = $this->getAcceptableContentTypes();

        return isset($acceptable[0]) && str_starts_with(strtolower($acceptable[0]), \'text/markdown\');
    }

    /**
     * Determines whether the current requests accepts a given content type.
     *
     * @param  string|array  $contentTypes
     * @return bool
     */
    public function accepts($contentTypes)
    {
        $accepts = $this->getAcceptableContentTypes();

        if (count($accepts) === 0) {
            return true;
        }

        $types = (array) $contentTypes;

        foreach ($accepts as $accept) {
            if ($accept && $pos = strpos($accept, \';\')) {
                $accept = trim(substr($accept, 0, $pos));
            }

            if ($accept === \'*/*\' || $accept === \'*\') {
                return true;
            }

            foreach ($types as $type) {
                $accept = strtolower($accept);

                $type = strtolower($type);

                if (self::matchesType($accept, $type) || $accept === strtok($type, \'/\').\'/*\') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the most suitable content type from the given array based on content negotiation.
     *
     * @param  string|array  $contentTypes
     * @return string|null
     */
    public function prefers($contentTypes)
    {
        $accepts = $this->getAcceptableContentTypes();

        $contentTypes = (array) $contentTypes;

        foreach ($accepts as $accept) {
            if ($accept && $pos = strpos($accept, \';\')) {
                $accept = trim(substr($accept, 0, $pos));
            }

            if (in_array($accept, [\'*/*\', \'*\'])) {
                return $contentTypes[0];
            }

            foreach ($contentTypes as $contentType) {
                $type = $contentType;

                if (! is_null($mimeType = $this->getMimeType($contentType))) {
                    $type = $mimeType;
                }

                $accept = strtolower($accept);

                $type = strtolower($type);

                if (self::matchesType($type, $accept) || $accept === strtok($type, \'/\').\'/*\') {
                    return $contentType;
                }
            }
        }
    }

    /**
     * Determine if the current request accepts any content type.
     *
     * @return bool
     */
    public function acceptsAnyContentType()
    {
        $acceptable = $this->getAcceptableContentTypes();

        return count($acceptable) === 0 || (
            isset($acceptable[0]) && ($acceptable[0] === \'*/*\' || $acceptable[0] === \'*\')
        );
    }

    /**
     * Determines whether a request accepts JSON.
     *
     * @return bool
     */
    public function acceptsJson()
    {
        return $this->accepts(\'application/json\');
    }

    /**
     * Determines whether a request accepts Markdown.
     *
     * @return bool
     */
    public function acceptsMarkdown()
    {
        return $this->accepts(\'text/markdown\');
    }

    /**
     * Determines whether a request accepts HTML.
     *
     * @return bool
     */
    public function acceptsHtml()
    {
        return $this->accepts(\'text/html\');
    }

    /**
     * Determine if the given content types match.
     *
     * @param  string  $actual
     * @param  string  $type
     * @return bool
     */
    public static function matchesType($actual, $type)
    {
        if ($actual === $type) {
            return true;
        }

        $split = explode(\'/\', $actual);

        return isset($split[1]) && preg_match(\'#\'.preg_quote($split[0], \'#\').\'/.+\\+\'.preg_quote($split[1], \'#\').\'#\', $type);
    }

    /**
     * Get the data format expected in the response.
     *
     * @param  string  $default
     * @return string
     */
    public function format($default = \'html\')
    {
        foreach ($this->getAcceptableContentTypes() as $type) {
            if ($format = $this->getFormat($type)) {
                return $format;
            }
        }

        return $default;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithFlashData.php' => '<?php

namespace Illuminate\\Http\\Concerns;

use Illuminate\\Database\\Eloquent\\Model;

trait InteractsWithFlashData
{
    /**
     * Retrieve an old input item.
     *
     * @param  string|null  $key
     * @param  \\Illuminate\\Database\\Eloquent\\Model|string|array|null  $default
     * @return string|array|null
     */
    public function old($key = null, $default = null)
    {
        $default = $default instanceof Model ? $default->getAttribute($key) : $default;

        return $this->hasSession() ? $this->session()->getOldInput($key, $default) : $default;
    }

    /**
     * Flash the input for the current request to the session.
     *
     * @return void
     */
    public function flash()
    {
        $this->session()->flashInput($this->input());
    }

    /**
     * Flash only some of the input to the session.
     *
     * @param  mixed  $keys
     * @return void
     */
    public function flashOnly($keys)
    {
        $this->session()->flashInput(
            $this->only(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * Flash only some of the input to the session.
     *
     * @param  mixed  $keys
     * @return void
     */
    public function flashExcept($keys)
    {
        $this->session()->flashInput(
            $this->except(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * Flush all of the old input from the session.
     *
     * @return void
     */
    public function flush()
    {
        $this->session()->flashInput([]);
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php' => '<?php

namespace Illuminate\\Http\\Concerns;

use Illuminate\\Http\\UploadedFile;
use Illuminate\\Image\\Image;
use Illuminate\\Support\\Arr;
use Illuminate\\Support\\Fluent;
use Illuminate\\Support\\Traits\\Dumpable;
use Illuminate\\Support\\Traits\\InteractsWithData;
use SplFileInfo;
use Symfony\\Component\\HttpFoundation\\InputBag;

trait InteractsWithInput
{
    use Dumpable, InteractsWithData;

    /**
     * Retrieve a server variable from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function server($key = null, $default = null)
    {
        return $this->retrieveItem(\'server\', $key, $default);
    }

    /**
     * Determine if a header is set on the request.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasHeader($key)
    {
        return ! is_null($this->header($key));
    }

    /**
     * Retrieve a header from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function header($key = null, $default = null)
    {
        return $this->retrieveItem(\'headers\', $key, $default);
    }

    /**
     * Get the bearer token from the request headers.
     *
     * @return string|null
     */
    public function bearerToken()
    {
        $header = $this->header(\'Authorization\', \'\');

        $position = strripos($header, \'Bearer \');

        if ($position !== false) {
            $header = substr($header, $position + 7);

            return str_contains($header, \',\') ? strstr($header, \',\', true) : $header;
        }
    }

    /**
     * Get the keys for all of the input and files.
     *
     * @return array
     */
    public function keys()
    {
        return array_merge(array_keys($this->input()), $this->files->keys());
    }

    /**
     * Get all of the input and files for the request.
     *
     * @param  mixed  $keys
     * @return array
     */
    public function all($keys = null)
    {
        $input = $this->input();

        $input = array_replace_recursive($input, $this->allFiles(), $input);

        if (! $keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Retrieve an input item from the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function input($key = null, $default = null)
    {
        return data_get(
            $this->getInputSource()->all() + $this->query->all(), $key, $default
        );
    }

    /**
     * Retrieve input from the request as a Fluent object instance.
     *
     * @param  array|string|null  $key
     * @param  array  $default
     * @return \\Illuminate\\Support\\Fluent
     */
    public function fluent($key = null, array $default = [])
    {
        $value = is_array($key) ? $this->only($key) : $this->input($key);

        return new Fluent($value ?? $default);
    }

    /**
     * Retrieve a query string item from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function query($key = null, $default = null)
    {
        return $this->retrieveItem(\'query\', $key, $default);
    }

    /**
     * Retrieve a request payload item from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function post($key = null, $default = null)
    {
        return $this->retrieveItem(\'request\', $key, $default);
    }

    /**
     * Determine if a cookie is set on the request.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasCookie($key)
    {
        return ! is_null($this->cookie($key));
    }

    /**
     * Retrieve a cookie from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function cookie($key = null, $default = null)
    {
        return $this->retrieveItem(\'cookies\', $key, $default);
    }

    /**
     * Get an array of all of the files on the request.
     *
     * @return array<string, \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]>
     */
    public function allFiles()
    {
        $files = $this->files->all();

        return $this->convertedFiles ??= $this->convertUploadedFiles($files);
    }

    /**
     * Convert the given array of Symfony UploadedFiles to custom Laravel UploadedFiles.
     *
     * @param  array<string, \\Symfony\\Component\\HttpFoundation\\File\\UploadedFile|\\Symfony\\Component\\HttpFoundation\\File\\UploadedFile[]>  $files
     * @return array<string, \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]>
     */
    protected function convertUploadedFiles(array $files)
    {
        return array_map(function ($file) {
            if (is_null($file) || (is_array($file) && empty(array_filter($file)))) {
                return $file;
            }

            return is_array($file)
                ? $this->convertUploadedFiles($file)
                : UploadedFile::createFromBase($file);
        }, $files);
    }

    /**
     * Determine if the uploaded data contains a file.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasFile($key)
    {
        if (! is_array($files = $this->file($key))) {
            $files = [$files];
        }

        return array_any($files, fn ($file) => $this->isValidFile($file));
    }

    /**
     * Check that the given file is a valid file instance.
     *
     * @param  mixed  $file
     * @return bool
     */
    protected function isValidFile($file)
    {
        return $file instanceof SplFileInfo && $file->getPath() !== \'\';
    }

    /**
     * Retrieve a file from the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? array<string, \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]> : \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]|null)
     */
    public function file($key = null, $default = null)
    {
        return data_get($this->allFiles(), $key, $default);
    }

    /**
     * Retrieve a file from the request as an image instance.
     */
    public function image(string $key): ?Image
    {
        $file = $this->file($key);

        if (! $file instanceof UploadedFile) {
            return null;
        }

        return new Image(fn () => $file->getContent(), $file);
    }

    /**
     * Retrieve data from the instance.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    protected function data($key = null, $default = null)
    {
        return $this->input($key, $default);
    }

    /**
     * Retrieve a parameter item from a given source.
     *
     * @param  string  $source
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    protected function retrieveItem($source, $key, $default)
    {
        if (is_null($key)) {
            return $this->$source->all();
        }

        if ($this->$source instanceof InputBag) {
            return $this->$source->all()[$key] ?? $default;
        }

        return $this->$source->get($key, $default);
    }

    /**
     * Dump the items.
     *
     * @param  mixed  $keys
     * @return $this
     */
    public function dump($keys = [])
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        dump($keys !== [] ? $this->only($keys) : $this->all());

        return $this;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Http/Request.php' => '<?php

namespace Illuminate\\Http;

use ArrayAccess;
use Closure;
use Illuminate\\Contracts\\Support\\Arrayable;
use Illuminate\\Session\\SymfonySessionDecorator;
use Illuminate\\Support\\Arr;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Str;
use Illuminate\\Support\\Traits\\Conditionable;
use Illuminate\\Support\\Traits\\Macroable;
use Illuminate\\Support\\Uri;
use RuntimeException;
use Symfony\\Component\\HttpFoundation\\Exception\\SessionNotFoundException;
use Symfony\\Component\\HttpFoundation\\InputBag;
use Symfony\\Component\\HttpFoundation\\Request as SymfonyRequest;
use Symfony\\Component\\HttpFoundation\\Session\\SessionInterface;

/**
 * @method array validate(array $rules, ...$params)
 * @method array validateWithBag(string $errorBag, array $rules, ...$params)
 * @method bool hasValidSignature(bool $absolute = true)
 * @method bool hasValidRelativeSignature()
 * @method bool hasValidSignatureWhileIgnoring($ignoreQuery = [], $absolute = true)
 * @method bool hasValidRelativeSignatureWhileIgnoring($ignoreQuery = [])
 */
class Request extends SymfonyRequest implements Arrayable, ArrayAccess
{
    use Concerns\\CanBePrecognitive,
        Concerns\\InteractsWithContentTypes,
        Concerns\\InteractsWithFlashData,
        Concerns\\InteractsWithInput,
        Conditionable,
        Macroable;

    /**
     * The decoded JSON content for the request.
     *
     * @var \\Symfony\\Component\\HttpFoundation\\InputBag|null
     */
    protected $json;

    /**
     * All of the converted files for the request.
     *
     * @var array<int, \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]>
     */
    protected $convertedFiles;

    /**
     * The user resolver callback.
     *
     * @var \\Closure
     */
    protected $userResolver;

    /**
     * The route resolver callback.
     *
     * @var \\Closure
     */
    protected $routeResolver;

    /**
     * The cached "Accept" header value.
     *
     * @var string|null
     */
    protected $cachedAcceptHeader;

    /**
     * Create a new Illuminate HTTP request from server variables.
     *
     * @return static
     */
    public static function capture()
    {
        static::enableHttpMethodParameterOverride();

        return static::createFromBase(SymfonyRequest::createFromGlobals());
    }

    /**
     * Return the Request instance.
     *
     * @return $this
     */
    public function instance()
    {
        return $this;
    }

    /**
     * Get the request method.
     *
     * @return string
     */
    public function method()
    {
        return $this->getMethod();
    }

    /**
     * Get a URI instance for the request.
     *
     * @return \\Illuminate\\Support\\Uri
     */
    public function uri()
    {
        return Uri::of($this->fullUrl());
    }

    /**
     * Get the root URL for the application.
     *
     * @return string
     */
    public function root()
    {
        return rtrim($this->getSchemeAndHttpHost().$this->getBaseUrl(), \'/\');
    }

    /**
     * Get the URL (no query string) for the request.
     *
     * @return string
     */
    public function url()
    {
        return rtrim(preg_replace(\'/\\?.*/\', \'\', $this->getUri()), \'/\');
    }

    /**
     * Get the full URL for the request.
     *
     * @return string
     */
    public function fullUrl()
    {
        $query = $this->getQueryString();

        $question = $this->getBaseUrl().$this->getPathInfo() === \'/\' ? \'/?\' : \'?\';

        return $query ? $this->url().$question.$query : $this->url();
    }

    /**
     * Get the full URL for the request with the added query string parameters.
     *
     * @param  array  $query
     * @return string
     */
    public function fullUrlWithQuery(array $query)
    {
        $question = $this->getBaseUrl().$this->getPathInfo() === \'/\' ? \'/?\' : \'?\';

        return count($this->query()) > 0
            ? $this->url().$question.Arr::query(array_merge($this->query(), $query))
            : $this->fullUrl().$question.Arr::query($query);
    }

    /**
     * Get the full URL for the request without the given query string parameters.
     *
     * @param  array|string  $keys
     * @return string
     */
    public function fullUrlWithoutQuery($keys)
    {
        $query = Arr::except($this->query(), $keys);

        $question = $this->getBaseUrl().$this->getPathInfo() === \'/\' ? \'/?\' : \'?\';

        return count($query) > 0
            ? $this->url().$question.Arr::query($query)
            : $this->url();
    }

    /**
     * Get the current path info for the request.
     *
     * @return string
     */
    public function path()
    {
        $pattern = trim($this->getPathInfo(), \'/\');

        return $pattern === \'\' ? \'/\' : $pattern;
    }

    /**
     * Get the current decoded path info for the request.
     *
     * @return string
     */
    public function decodedPath()
    {
        return rawurldecode($this->path());
    }

    /**
     * Get a segment from the URI (1 based index).
     *
     * @param  int  $index
     * @param  string|null  $default
     * @return string|null
     */
    public function segment($index, $default = null)
    {
        return Arr::get($this->segments(), $index - 1, $default);
    }

    /**
     * Get all of the segments for the request path.
     *
     * @return array
     */
    public function segments()
    {
        $segments = explode(\'/\', $this->decodedPath());

        return array_values(array_filter($segments, function ($value) {
            return $value !== \'\';
        }));
    }

    /**
     * Determine if the current request URI matches a pattern.
     *
     * @param  mixed  ...$patterns
     * @return bool
     */
    public function is(...$patterns)
    {
        return (new Collection($patterns))
            ->contains(fn ($pattern) => Str::is($pattern, $this->decodedPath()));
    }

    /**
     * Determine if the route name matches a given pattern.
     *
     * @param  mixed  ...$patterns
     * @return bool
     */
    public function routeIs(...$patterns)
    {
        return $this->route() && $this->route()->named(...$patterns);
    }

    /**
     * Determine if the current request URL and query string match a pattern.
     *
     * @param  mixed  ...$patterns
     * @return bool
     */
    public function fullUrlIs(...$patterns)
    {
        return (new Collection($patterns))
            ->contains(fn ($pattern) => Str::is($pattern, $this->fullUrl()));
    }

    /**
     * Get the host name.
     *
     * @return string
     */
    public function host()
    {
        return $this->getHost();
    }

    /**
     * Get the HTTP host being requested.
     *
     * @return string
     */
    public function httpHost()
    {
        return $this->getHttpHost();
    }

    /**
     * Get the scheme and HTTP host.
     *
     * @return string
     */
    public function schemeAndHttpHost()
    {
        return $this->getSchemeAndHttpHost();
    }

    /**
     * Determine if the request is the result of an AJAX call.
     *
     * @return bool
     */
    public function ajax()
    {
        return $this->isXmlHttpRequest();
    }

    /**
     * Determine if the request is the result of a PJAX call.
     *
     * @return bool
     */
    public function pjax()
    {
        return $this->headers->get(\'X-PJAX\') == true;
    }

    /**
     * Determine if the request is the result of a prefetch call.
     *
     * @return bool
     */
    public function prefetch()
    {
        return strcasecmp($this->server->get(\'HTTP_X_MOZ\') ?? \'\', \'prefetch\') === 0 ||
               strcasecmp($this->headers->get(\'Purpose\') ?? \'\', \'prefetch\') === 0 ||
               strcasecmp($this->headers->get(\'Sec-Purpose\') ?? \'\', \'prefetch\') === 0;
    }

    /**
     * Determine if the request is over HTTPS.
     *
     * @return bool
     */
    public function secure()
    {
        return $this->isSecure();
    }

    /**
     * Get the client IP address.
     *
     * @return string|null
     */
    public function ip()
    {
        return $this->getClientIp();
    }

    /**
     * Get the client IP addresses.
     *
     * @return array
     */
    public function ips()
    {
        return $this->getClientIps();
    }

    /**
     * Get the client user agent.
     *
     * @return string|null
     */
    public function userAgent()
    {
        return $this->headers->get(\'User-Agent\');
    }

    /**
     * {@inheritdoc}
     */
    #[\\Override]
    public function getAcceptableContentTypes(): array
    {
        $currentAcceptHeader = $this->headers->get(\'Accept\');

        if ($this->cachedAcceptHeader !== $currentAcceptHeader) {
            // Flush acceptable content types so Symfony re-calculates them...
            $this->acceptableContentTypes = null;
            $this->cachedAcceptHeader = $currentAcceptHeader;
        }

        return parent::getAcceptableContentTypes();
    }

    /**
     * Merge new input into the current request\'s input array.
     *
     * @param  array  $input
     * @return $this
     */
    public function merge(array $input)
    {
        return tap($this, function (Request $request) use ($input) {
            $request->getInputSource()
                ->replace((new Collection($input))->reduce(
                    function ($requestInput, $value, $key) {
                        Arr::set($requestInput, $key, $value);

                        return $requestInput;
                    },
                    $this->getInputSource()->all()
                ));
        });
    }

    /**
     * Merge new input into the request\'s input, but only when that key is missing from the request.
     *
     * @param  array  $input
     * @return $this
     */
    public function mergeIfMissing(array $input)
    {
        return $this->merge((new Collection($input))
            ->filter(fn ($value, $key) => $this->missing($key))
            ->toArray()
        );
    }

    /**
     * Replace the input values for the current request.
     *
     * @param  array  $input
     * @return $this
     */
    public function replace(array $input)
    {
        $this->getInputSource()->replace($input);

        return $this;
    }

    /**
     * This method belongs to Symfony HttpFoundation and is not usually needed when using Laravel.
     *
     * Instead, you may use the "input" method.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     *
     * @deprecated use ->input() instead
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this !== $result = $this->attributes->get($key, $this)) {
            return $result;
        }

        if ($this->query->has($key)) {
            return $this->query->all()[$key];
        }

        if ($this->request->has($key)) {
            return $this->request->all()[$key];
        }

        return $default;
    }

    /**
     * Get the JSON payload for the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? \\Symfony\\Component\\HttpFoundation\\InputBag : mixed)
     */
    public function json($key = null, $default = null)
    {
        if (! isset($this->json)) {
            $content = $this->getContent();

            $this->json = new InputBag((array) json_decode(trim($content) === \'\' ? \'[]\' : $content, true));
        }

        if (is_null($key)) {
            return $this->json;
        }

        return data_get($this->json->all(), $key, $default);
    }

    /**
     * Get the input source for the request.
     *
     * @return \\Symfony\\Component\\HttpFoundation\\InputBag
     */
    protected function getInputSource()
    {
        if ($this->isJson()) {
            return $this->json();
        }

        return in_array($this->getRealMethod(), [\'GET\', \'HEAD\']) ? $this->query : $this->request;
    }

    /**
     * Create a new request instance from the given Laravel request.
     *
     * @param  \\Illuminate\\Http\\Request  $from
     * @param  \\Illuminate\\Http\\Request|null  $to
     * @return static
     */
    public static function createFrom(self $from, $to = null)
    {
        $request = $to ?: new static;

        $files = array_filter($from->files->all());

        $request->initialize(
            $from->query->all(),
            $from->request->all(),
            $from->attributes->all(),
            $from->cookies->all(),
            $files,
            $from->server->all(),
            $from->getContent()
        );

        $request->headers->replace($from->headers->all());

        $request->setRequestLocale($from->getLocale());

        $request->setDefaultRequestLocale($from->getDefaultLocale());

        $request->setJson($from->json());

        if ($from->hasSession() && $session = $from->session()) {
            $request->setLaravelSession($session);
        }

        $request->setUserResolver($from->getUserResolver());

        $request->setRouteResolver($from->getRouteResolver());

        return $request;
    }

    /**
     * Create an Illuminate request from a Symfony instance.
     *
     * @param  \\Symfony\\Component\\HttpFoundation\\Request  $request
     * @return static
     */
    public static function createFromBase(SymfonyRequest $request)
    {
        $newRequest = new static(
            $request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), (new static)->filterFiles($request->files->all()) ?? [], $request->server->all()
        );

        $newRequest->headers->replace($request->headers->all());

        $newRequest->content = $request->content;

        if ($newRequest->isJson()) {
            $newRequest->request->replace($newRequest->json()->all());
            $newRequest->setJson($newRequest->request);
        }

        return $newRequest;
    }

    /**
     * {@inheritdoc}
     *
     * @return static
     */
    #[\\Override]
    public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static
    {
        return parent::duplicate($query, $request, $attributes, $cookies, $this->filterFiles($files), $server);
    }

    /**
     * Filter the given array of files, removing any empty values.
     *
     * @param  mixed  $files
     * @return mixed
     */
    protected function filterFiles($files)
    {
        if (! $files) {
            return;
        }

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $files[$key] = $this->filterFiles($files[$key]);
            }

            if (empty($files[$key])) {
                unset($files[$key]);
            }
        }

        return $files;
    }

    /**
     * {@inheritdoc}
     */
    #[\\Override]
    public function hasSession(bool $skipIfUninitialized = false): bool
    {
        return $this->session instanceof SymfonySessionDecorator;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \\Symfony\\Component\\HttpFoundation\\Exception\\SessionNotFoundException
     */
    #[\\Override]
    public function getSession(): SessionInterface
    {
        return $this->hasSession()
            ? $this->session
            : throw new SessionNotFoundException;
    }

    /**
     * Get the session associated with the request.
     *
     * @return \\Illuminate\\Contracts\\Session\\Session
     *
     * @throws \\RuntimeException
     */
    public function session()
    {
        if (! $this->hasSession()) {
            throw new RuntimeException(\'Session store not set on request.\');
        }

        return $this->session->store;
    }

    /**
     * Set the session instance on the request.
     *
     * @param  \\Illuminate\\Contracts\\Session\\Session  $session
     * @return void
     */
    public function setLaravelSession($session)
    {
        $this->session = new SymfonySessionDecorator($session);
    }

    /**
     * Set the locale for the request instance.
     *
     * @param  string  $locale
     * @return void
     */
    public function setRequestLocale(string $locale)
    {
        $this->locale = $locale;
    }

    /**
     * Set the default locale for the request instance.
     *
     * @param  string  $locale
     * @return void
     */
    public function setDefaultRequestLocale(string $locale)
    {
        $this->defaultLocale = $locale;
    }

    /**
     * Get the user making the request.
     *
     * @param  string|null  $guard
     * @return mixed
     */
    public function user($guard = null)
    {
        return call_user_func($this->getUserResolver(), $guard);
    }

    /**
     * Get the route handling the request.
     *
     * @param  string|null  $param
     * @param  mixed  $default
     * @return ($param is null ? \\Illuminate\\Routing\\Route : object|string|null)
     */
    public function route($param = null, $default = null)
    {
        $route = call_user_func($this->getRouteResolver());

        if (is_null($route) || is_null($param)) {
            return $route;
        }

        return $route->parameter($param, $default);
    }

    /**
     * Get a unique fingerprint for the request / route / IP address.
     *
     * @return string
     *
     * @throws \\RuntimeException
     */
    public function fingerprint()
    {
        if (! $route = $this->route()) {
            throw new RuntimeException(\'Unable to generate fingerprint. Route unavailable.\');
        }

        return sha1(implode(\'|\', array_merge(
            $route->methods(),
            [$route->getDomain(), $route->uri(), $this->ip()]
        )));
    }

    /**
     * Set the JSON payload for the request.
     *
     * @param  \\Symfony\\Component\\HttpFoundation\\InputBag  $json
     * @return $this
     */
    public function setJson($json)
    {
        $this->json = $json;

        return $this;
    }

    /**
     * Get the user resolver callback.
     *
     * @return \\Closure
     */
    public function getUserResolver()
    {
        return $this->userResolver ?: function () {
            //
        };
    }

    /**
     * Set the user resolver callback.
     *
     * @param  \\Closure  $callback
     * @return $this
     */
    public function setUserResolver(Closure $callback)
    {
        $this->userResolver = $callback;

        return $this;
    }

    /**
     * Get the route resolver callback.
     *
     * @return \\Closure
     */
    public function getRouteResolver()
    {
        return $this->routeResolver ?: function () {
            //
        };
    }

    /**
     * Set the route resolver callback.
     *
     * @param  \\Closure  $callback
     * @return $this
     */
    public function setRouteResolver(Closure $callback)
    {
        $this->routeResolver = $callback;

        return $this;
    }

    /**
     * Get all of the input and files for the request.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Determine if the given offset exists.
     *
     * @param  string  $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        $route = $this->route();

        return Arr::has(
            $this->all() + ($route ? $route->parameters() : []),
            $offset
        );
    }

    /**
     * Get the value at the given offset.
     *
     * @param  string  $offset
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->__get($offset);
    }

    /**
     * Set the value at the given offset.
     *
     * @param  string  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        $this->getInputSource()->set($offset, $value);
    }

    /**
     * Remove the value at the given offset.
     *
     * @param  string  $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        $this->getInputSource()->remove($offset);
    }

    /**
     * Check if an input element is set on the request.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return ! is_null($this->__get($key));
    }

    /**
     * Get an input element from the request.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return Arr::get($this->all(), $key, fn () => $this->route($key));
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php' => '<?php

namespace Illuminate\\Support\\Traits;

use BadMethodCallException;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

trait Macroable
{
    /**
     * The registered string macros.
     *
     * @var array
     */
    protected static $macros = [];

    /**
     * Register a custom macro.
     *
     * @param  string  $name
     * @param  object|callable  $macro
     *
     * @param-closure-this static  $macro
     *
     * @return void
     */
    public static function macro($name, $macro)
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Mix another object into the class.
     *
     * @param  object  $mixin
     * @param  bool  $replace
     * @return void
     *
     * @throws \\ReflectionException
     */
    public static function mixin($mixin, $replace = true)
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($replace || ! static::hasMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Checks if macro is registered.
     *
     * @param  string  $name
     * @return bool
     */
    public static function hasMacro($name)
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Flush the existing macros.
     *
     * @return void
     */
    public static function flushMacros()
    {
        static::$macros = [];
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \\BadMethodCallException
     */
    public static function __callStatic($method, $parameters)
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf(
                \'Method %s::%s does not exist.\', static::class, $method
            ));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo(null, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \\BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf(
                \'Method %s::%s does not exist.\', static::class, $method
            ));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            try {
                $macro = $macro->bindTo($this, static::class) ?? throw new RuntimeException;
            } catch (Throwable) {
                $macro = $macro->bindTo(null, static::class);
            }
        }

        return $macro(...$parameters);
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php' => '<?php

namespace Illuminate\\Support;

use Closure;
use Illuminate\\Console\\Application as Artisan;
use Illuminate\\Contracts\\Foundation\\CachesConfiguration;
use Illuminate\\Contracts\\Foundation\\CachesRoutes;
use Illuminate\\Contracts\\Support\\DeferrableProvider;
use Illuminate\\Database\\Eloquent\\Factory as ModelFactory;
use Illuminate\\View\\Compilers\\BladeCompiler;

/**
 * @property array<string, string> $bindings All of the container bindings that should be registered.
 * @property array<array-key, string> $singletons All of the singletons that should be registered.
 */
abstract class ServiceProvider
{
    /**
     * The application instance.
     *
     * @var \\Illuminate\\Contracts\\Foundation\\Application
     */
    protected $app;

    /**
     * All of the registered booting callbacks.
     *
     * @var array
     */
    protected $bootingCallbacks = [];

    /**
     * All of the registered booted callbacks.
     *
     * @var array
     */
    protected $bootedCallbacks = [];

    /**
     * The paths that should be published.
     *
     * @var array
     */
    public static $publishes = [];

    /**
     * The paths that should be published by group.
     *
     * @var array
     */
    public static $publishGroups = [];

    /**
     * The migration paths available for publishing.
     *
     * @var array
     */
    protected static $publishableMigrationPaths = [];

    /**
     * Commands that should be run during the "optimize" command.
     *
     * @var array<string, string>
     */
    public static array $optimizeCommands = [];

    /**
     * Commands that should be run during the "optimize:clear" command.
     *
     * @var array<string, string>
     */
    public static array $optimizeClearCommands = [];

    /**
     * Commands that should be run during the "reload" command.
     *
     * @var array<string, string>
     */
    public static array $reloadCommands = [];

    /**
     * Create a new service provider instance.
     *
     * @param  \\Illuminate\\Contracts\\Foundation\\Application  $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Register a booting callback to be run before the "boot" method is called.
     *
     * @param  \\Closure  $callback
     * @return void
     */
    public function booting(Closure $callback)
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a booted callback to be run after the "boot" method is called.
     *
     * @param  \\Closure  $callback
     * @return void
     */
    public function booted(Closure $callback)
    {
        $this->bootedCallbacks[] = $callback;
    }

    /**
     * Call the registered booting callbacks.
     *
     * @return void
     */
    public function callBootingCallbacks()
    {
        $index = 0;

        while ($index < count($this->bootingCallbacks)) {
            $this->app->call($this->bootingCallbacks[$index]);

            $index++;
        }
    }

    /**
     * Call the registered booted callbacks.
     *
     * @return void
     */
    public function callBootedCallbacks()
    {
        $index = 0;

        while ($index < count($this->bootedCallbacks)) {
            $this->app->call($this->bootedCallbacks[$index]);

            $index++;
        }
    }

    /**
     * Merge the given configuration with the existing configuration.
     *
     * @param  string  $path
     * @param  string  $key
     * @return void
     */
    protected function mergeConfigFrom($path, $key)
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make(\'config\');

            $config->set($key, array_merge(
                require $path, $config->get($key, [])
            ));
        }
    }

    /**
     * Replace the given configuration with the existing configuration recursively.
     *
     * @param  string  $path
     * @param  string  $key
     * @return void
     */
    protected function replaceConfigRecursivelyFrom($path, $key)
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make(\'config\');

            $config->set($key, array_replace_recursive(
                require $path, $config->get($key, [])
            ));
        }
    }

    /**
     * Load the given routes file if routes are not already cached.
     *
     * @param  string  $path
     * @return void
     */
    protected function loadRoutesFrom($path)
    {
        if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            require $path;
        }
    }

    /**
     * Register a view file namespace.
     *
     * @param  string|array  $path
     * @param  string  $namespace
     * @return void
     */
    protected function loadViewsFrom($path, $namespace)
    {
        $this->callAfterResolving(\'view\', function ($view) use ($path, $namespace) {
            if (isset($this->app->config[\'view\'][\'paths\']) &&
                is_array($this->app->config[\'view\'][\'paths\'])) {
                foreach ($this->app->config[\'view\'][\'paths\'] as $viewPath) {
                    if (is_dir($appPath = $viewPath.\'/vendor/\'.$namespace)) {
                        $view->addNamespace($namespace, $appPath);
                    }
                }
            }

            $view->addNamespace($namespace, $path);
        });
    }

    /**
     * Register the given view components with a custom prefix.
     *
     * @param  string  $prefix
     * @param  array  $components
     * @return void
     */
    protected function loadViewComponentsAs($prefix, array $components)
    {
        $this->callAfterResolving(BladeCompiler::class, function ($blade) use ($prefix, $components) {
            foreach ($components as $alias => $component) {
                $blade->component($component, is_string($alias) ? $alias : null, $prefix);
            }
        });
    }

    /**
     * Register a translation file namespace or path.
     *
     * @param  string  $path
     * @param  string|null  $namespace
     * @return void
     */
    protected function loadTranslationsFrom($path, $namespace = null)
    {
        $this->callAfterResolving(\'translator\', fn ($translator) => is_null($namespace)
            ? $translator->addPath($path)
            : $translator->addNamespace($namespace, $path));
    }

    /**
     * Register a JSON translation file path.
     *
     * @param  string  $path
     * @return void
     */
    protected function loadJsonTranslationsFrom($path)
    {
        $this->callAfterResolving(\'translator\', function ($translator) use ($path) {
            $translator->addJsonPath($path);
        });
    }

    /**
     * Register database migration paths.
     *
     * @param  array|string  $paths
     * @return void
     */
    protected function loadMigrationsFrom($paths)
    {
        $this->callAfterResolving(\'migrator\', function ($migrator) use ($paths) {
            foreach ((array) $paths as $path) {
                $migrator->path($path);
            }
        });
    }

    /**
     * Register Eloquent model factory paths.
     *
     * @deprecated Will be removed in a future Laravel version.
     *
     * @param  array|string  $paths
     * @return void
     */
    protected function loadFactoriesFrom($paths)
    {
        $this->callAfterResolving(ModelFactory::class, function ($factory) use ($paths) {
            foreach ((array) $paths as $path) {
                $factory->load($path);
            }
        });
    }

    /**
     * Setup an after resolving listener, or fire immediately if already resolved.
     *
     * @param  string  $name
     * @param  callable  $callback
     * @return void
     */
    protected function callAfterResolving($name, $callback)
    {
        $this->app->afterResolving($name, $callback);

        if ($this->app->resolved($name)) {
            $callback($this->app->make($name), $this->app);
        }
    }

    /**
     * Register migration paths to be published by the publish command.
     *
     * @param  array  $paths
     * @param  mixed  $groups
     * @return void
     */
    protected function publishesMigrations(array $paths, $groups = null)
    {
        $this->publishes($paths, $groups);

        if ($this->app->config->get(\'database.migrations.update_date_on_publish\', false)) {
            static::$publishableMigrationPaths = array_unique(array_merge(static::$publishableMigrationPaths, array_keys($paths)));
        }
    }

    /**
     * Register paths to be published by the publish command.
     *
     * @param  array  $paths
     * @param  mixed  $groups
     * @return void
     */
    protected function publishes(array $paths, $groups = null)
    {
        $this->ensurePublishArrayInitialized($class = static::class);

        static::$publishes[$class] = array_merge(static::$publishes[$class], $paths);

        foreach ((array) $groups as $group) {
            $this->addPublishGroup($group, $paths);
        }
    }

    /**
     * Ensure the publish array for the service provider is initialized.
     *
     * @param  string  $class
     * @return void
     */
    protected function ensurePublishArrayInitialized($class)
    {
        if (! array_key_exists($class, static::$publishes)) {
            static::$publishes[$class] = [];
        }
    }

    /**
     * Add a publish group / tag to the service provider.
     *
     * @param  string  $group
     * @param  array  $paths
     * @return void
     */
    protected function addPublishGroup($group, $paths)
    {
        if (! array_key_exists($group, static::$publishGroups)) {
            static::$publishGroups[$group] = [];
        }

        static::$publishGroups[$group] = array_merge(
            static::$publishGroups[$group], $paths
        );
    }

    /**
     * Get the paths to publish.
     *
     * @param  string|null  $provider
     * @param  string|null  $group
     * @return array
     */
    public static function pathsToPublish($provider = null, $group = null)
    {
        if (! is_null($paths = static::pathsForProviderOrGroup($provider, $group))) {
            return $paths;
        }

        return (new Collection(static::$publishes))->reduce(function ($paths, $p) {
            return array_merge($paths, $p);
        }, []);
    }

    /**
     * Get the paths for the provider or group (or both).
     *
     * @param  string|null  $provider
     * @param  string|null  $group
     * @return array
     */
    protected static function pathsForProviderOrGroup($provider, $group)
    {
        if ($provider && $group) {
            return static::pathsForProviderAndGroup($provider, $group);
        } elseif ($group && array_key_exists($group, static::$publishGroups)) {
            return static::$publishGroups[$group];
        } elseif ($provider && array_key_exists($provider, static::$publishes)) {
            return static::$publishes[$provider];
        } elseif ($group || $provider) {
            return [];
        }
    }

    /**
     * Get the paths for the provider and group.
     *
     * @param  string  $provider
     * @param  string  $group
     * @return array
     */
    protected static function pathsForProviderAndGroup($provider, $group)
    {
        if (! empty(static::$publishes[$provider]) && ! empty(static::$publishGroups[$group])) {
            return array_intersect_key(static::$publishes[$provider], static::$publishGroups[$group]);
        }

        return [];
    }

    /**
     * Get the service providers available for publishing.
     *
     * @return array
     */
    public static function publishableProviders()
    {
        return array_keys(static::$publishes);
    }

    /**
     * Get the migration paths available for publishing.
     *
     * @return array
     */
    public static function publishableMigrationPaths()
    {
        return static::$publishableMigrationPaths;
    }

    /**
     * Get the groups available for publishing.
     *
     * @return array
     */
    public static function publishableGroups()
    {
        return array_keys(static::$publishGroups);
    }

    /**
     * Register the package\'s custom Artisan commands.
     *
     * @param  mixed  $commands
     * @return void
     */
    public function commands($commands)
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        Artisan::starting(function ($artisan) use ($commands) {
            $artisan->resolveCommands($commands);
        });
    }

    /**
     * Register commands that should run on "optimize" or "optimize:clear".
     *
     * @param  string|null  $optimize
     * @param  string|null  $clear
     * @param  string|null  $key
     * @return void
     */
    protected function optimizes(?string $optimize = null, ?string $clear = null, ?string $key = null)
    {
        $key = $this->getProviderKey($key);

        if ($optimize) {
            static::$optimizeCommands[$key] = $optimize;
        }

        if ($clear) {
            static::$optimizeClearCommands[$key] = $clear;
        }
    }

    /**
     * Register commands that should run on "reload".
     *
     * @param  string  $reload
     * @param  string|null  $key
     * @return void
     */
    protected function reloads(string $reload, ?string $key = null)
    {
        $key = $this->getProviderKey($key);

        static::$reloadCommands[$key] = $reload;
    }

    /**
     * Get a short descriptive key for the current service provider.
     *
     * @param  string|null  $key
     * @return string
     */
    protected function getProviderKey(?string $key = null): string
    {
        $key ??= (new Stringable(get_class($this)))
            ->classBasename()
            ->before(\'ServiceProvider\')
            ->kebab()
            ->lower()
            ->trim()
            ->value();

        if (empty($key)) {
            $key = class_basename(get_class($this));
        }

        return $key;
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    /**
     * Get the events that trigger this service provider to register.
     *
     * @return array
     */
    public function when()
    {
        return [];
    }

    /**
     * Determine if the provider is deferred.
     *
     * @return bool
     */
    public function isDeferred()
    {
        return $this instanceof DeferrableProvider;
    }

    /**
     * Get the default providers for a Laravel application.
     *
     * @return \\Illuminate\\Support\\DefaultProviders
     */
    public static function defaultProviders()
    {
        return new DefaultProviders;
    }

    /**
     * Add the given provider to the application\'s provider bootstrap file.
     *
     * @param  string  $provider
     * @param  string|null  $path
     * @return bool
     */
    public static function addProviderToBootstrapFile(string $provider, ?string $path = null)
    {
        $path ??= app()->getBootstrapProvidersPath();

        if (! file_exists($path)) {
            return false;
        }

        if (function_exists(\'opcache_invalidate\')) {
            opcache_invalidate($path, true);
        }

        $providers = (new Collection(require $path))
            ->merge([$provider])
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($p) => \'    \'.$p.\'::class,\')
            ->implode(PHP_EOL);

        $content = \'<?php

return [
\'.$providers.\'
];\';

        file_put_contents($path, $content.PHP_EOL);

        return true;
    }

    /**
     * Remove a provider from the application\'s provider bootstrap file.
     *
     * @param  string|array  $providersToRemove
     * @param  string|null  $path
     * @param  bool  $strict
     * @return bool
     */
    public static function removeProviderFromBootstrapFile(string|array $providersToRemove, ?string $path = null, bool $strict = false)
    {
        $path ??= app()->getBootstrapProvidersPath();

        if (! file_exists($path)) {
            return false;
        }

        if (function_exists(\'opcache_invalidate\')) {
            opcache_invalidate($path, true);
        }

        $providersToRemove = Arr::wrap($providersToRemove);

        $providers = (new Collection(require $path))
            ->unique()
            ->sort()
            ->values()
            ->when(
                $strict,
                static fn (Collection $providerCollection) => $providerCollection->diff($providersToRemove),
                static fn (Collection $providerCollection) => $providerCollection->reject(fn (string $p) => Str::contains($p, $providersToRemove))
            )
            ->map(fn ($p) => \'    \'.$p.\'::class,\')
            ->implode(PHP_EOL);

        $content = \'<?php

return [
\'.$providers.\'
];\';

        file_put_contents($path, $content.PHP_EOL);

        return true;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/CapsuleManagerTrait.php' => '<?php

namespace Illuminate\\Support\\Traits;

use Illuminate\\Contracts\\Container\\Container;
use Illuminate\\Support\\Fluent;

trait CapsuleManagerTrait
{
    /**
     * The current globally used instance.
     *
     * @var object
     */
    protected static $instance;

    /**
     * The container instance.
     *
     * @var \\Illuminate\\Contracts\\Container\\Container
     */
    protected $container;

    /**
     * Setup the IoC container instance.
     *
     * @param  \\Illuminate\\Contracts\\Container\\Container  $container
     * @return void
     */
    protected function setupContainer(Container $container)
    {
        $this->container = $container;

        if (! $this->container->bound(\'config\')) {
            $this->container->instance(\'config\', new Fluent);
        }
    }

    /**
     * Make this capsule instance available globally.
     *
     * @return void
     */
    public function setAsGlobal()
    {
        static::$instance = $this;
    }

    /**
     * Get the IoC container instance.
     *
     * @return \\Illuminate\\Contracts\\Container\\Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     *
     * @param  \\Illuminate\\Contracts\\Container\\Container  $container
     * @return void
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/Dumpable.php' => '<?php

namespace Illuminate\\Support\\Traits;

trait Dumpable
{
    /**
     * Dump the given arguments and terminate execution.
     *
     * @param  mixed  ...$args
     * @return never
     */
    public function dd(...$args)
    {
        dd($this, ...$args);
    }

    /**
     * Dump the given arguments.
     *
     * @param  mixed  ...$args
     * @return $this
     */
    public function dump(...$args)
    {
        dump($this, ...$args);

        return $this;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/ForwardsCalls.php' => '<?php

namespace Illuminate\\Support\\Traits;

use BadMethodCallException;
use Error;

trait ForwardsCalls
{
    /**
     * Forward a method call to the given object.
     *
     * @param  mixed  $object
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \\BadMethodCallException
     */
    protected function forwardCallTo($object, $method, $parameters)
    {
        try {
            return $object->{$method}(...$parameters);
        } catch (Error|BadMethodCallException $e) {
            $pattern = \'~^Call to undefined method (?P<class>[^:]+)::(?P<method>[^\\(]+)\\(\\)$~\';

            if (! preg_match($pattern, $e->getMessage(), $matches)) {
                throw $e;
            }

            if ($matches[\'class\'] != get_class($object) ||
                $matches[\'method\'] != $method) {
                throw $e;
            }

            static::throwBadMethodCallException($method);
        }
    }

    /**
     * Forward a method call to the given object, returning $this if the forwarded call returned itself.
     *
     * @param  mixed  $object
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \\BadMethodCallException
     */
    protected function forwardDecoratedCallTo($object, $method, $parameters)
    {
        $result = $this->forwardCallTo($object, $method, $parameters);

        return $result === $object ? $this : $result;
    }

    /**
     * Throw a bad method call exception for the given method.
     *
     * @param  string  $method
     * @return never
     *
     * @throws \\BadMethodCallException
     */
    protected static function throwBadMethodCallException($method)
    {
        throw new BadMethodCallException(sprintf(
            \'Call to undefined method %s::%s()\', static::class, $method
        ));
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/InteractsWithData.php' => '<?php

namespace Illuminate\\Support\\Traits;

use Carbon\\CarbonInterval;
use Carbon\\Unit;
use Illuminate\\Support\\Arr;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\Date;
use Illuminate\\Support\\Number;
use Illuminate\\Support\\Stringable;
use stdClass;

use function Illuminate\\Support\\enum_value;

trait InteractsWithData
{
    /**
     * Retrieve all data from the instance.
     *
     * @param  mixed  $keys
     * @return array
     */
    abstract public function all($keys = null);

    /**
     * Retrieve data from the instance.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    abstract protected function data($key = null, $default = null);

    /**
     * Determine if the data contains a given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function exists($key)
    {
        return $this->has($key);
    }

    /**
     * Determine if the data contains a given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function has($key)
    {
        $keys = is_array($key) ? $key : func_get_args();

        $data = $this->all();

        return array_all($keys, fn ($value) => Arr::has($data, $value));
    }

    /**
     * Determine if the instance contains any of the given keys.
     *
     * @param  string|array  $keys
     * @return bool
     */
    public function hasAny($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        $data = $this->all();

        return Arr::hasAny($data, $keys);
    }

    /**
     * Apply the callback if the instance contains the given key.
     *
     * @template TReturn
     * @template TReturnDefault = never
     *
     * @param  string  $key
     * @param  callable(mixed): TReturn  $callback
     * @param  (callable(): TReturnDefault)|null  $default
     * @return $this|TReturn|TReturnDefault
     */
    public function whenHas($key, callable $callback, ?callable $default = null)
    {
        if ($this->has($key)) {
            return $callback(data_get($this->all(), $key)) ?: $this;
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Determine if the instance contains a non-empty value for the given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function filled($key)
    {
        $keys = is_array($key) ? $key : func_get_args();

        return array_all($keys, fn ($value) => ! $this->isEmptyString($value));
    }

    /**
     * Determine if the instance contains an empty value for the given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function isNotFilled($key)
    {
        $keys = is_array($key) ? $key : func_get_args();

        return array_all($keys, fn ($value) => $this->isEmptyString($value));
    }

    /**
     * Determine if the instance contains a non-empty value for any of the given keys.
     *
     * @param  string|array  $keys
     * @return bool
     */
    public function anyFilled($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return array_any($keys, fn ($key) => $this->filled($key));
    }

    /**
     * Apply the callback if the instance contains a non-empty value for the given key.
     *
     * @template TReturn
     * @template TReturnDefault = never
     *
     * @param  string  $key
     * @param  callable(mixed): TReturn  $callback
     * @param  (callable(): TReturnDefault)|null  $default
     * @return $this|TReturn|TReturnDefault
     */
    public function whenFilled($key, callable $callback, ?callable $default = null)
    {
        if ($this->filled($key)) {
            return $callback(data_get($this->all(), $key)) ?: $this;
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Apply the callback if the instance contains a valid enum value for the given key.
     *
     * @template TEnum of \\BackedEnum
     * @template TReturn
     * @template TReturnDefault = never
     *
     * @param  string  $key
     * @param  class-string<TEnum>  $enumClass
     * @param  callable(TEnum):TReturn  $callback
     * @param  (callable(): TReturnDefault)|null  $default
     * @return $this|TReturn|TReturnDefault
     */
    public function whenEnum($key, string $enumClass, callable $callback, ?callable $default = null)
    {
        if ($this->filled($key) && $this->isBackedEnum($enumClass)) {
            $value = $enumClass::tryFrom(data_get($this->all(), $key));

            if ($value !== null) {
                return $callback($value) ?: $this;
            }
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Determine if the instance is missing a given key.
     *
     * @param  string|array  $key
     * @return bool
     */
    public function missing($key)
    {
        $keys = is_array($key) ? $key : func_get_args();

        return ! $this->has($keys);
    }

    /**
     * Apply the callback if the instance is missing the given key.
     *
     * @template TReturn
     * @template TReturnDefault = never
     *
     * @param  string  $key
     * @param  callable(mixed): TReturn  $callback
     * @param  (callable(): TReturnDefault)|null  $default
     * @return $this|TReturn|TReturnDefault
     */
    public function whenMissing($key, callable $callback, ?callable $default = null)
    {
        if ($this->missing($key)) {
            return $callback(data_get($this->all(), $key)) ?: $this;
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Determine if the given key is an empty string for "filled".
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEmptyString($key)
    {
        $value = $this->data($key);

        return ! is_bool($value) && ! is_array($value) && trim((string) $value) === \'\';
    }

    /**
     * Retrieve data from the instance as a Stringable instance.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return \\Illuminate\\Support\\Stringable
     */
    public function str($key, $default = null)
    {
        return $this->string($key, $default);
    }

    /**
     * Retrieve data from the instance as a Stringable instance.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return \\Illuminate\\Support\\Stringable
     */
    public function string($key, $default = null)
    {
        return new Stringable($this->data($key, $default));
    }

    /**
     * Retrieve data as a boolean value.
     *
     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
     *
     * @param  string|null  $key
     * @param  bool  $default
     * @return bool
     */
    public function boolean($key = null, $default = false)
    {
        return filter_var($this->data($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retrieve data as an integer value.
     *
     * @param  string  $key
     * @param  int  $default
     * @return int
     */
    public function integer($key, $default = 0)
    {
        return (int) $this->data($key, $default);
    }

    /**
     * Retrieve data as a float value.
     *
     * @param  string  $key
     * @param  float  $default
     * @return float
     */
    public function float($key, $default = 0.0)
    {
        return (float) $this->data($key, $default);
    }

    /**
     * Retrieve data clamped between min and max values.
     *
     * @param  string  $key
     * @param  int|float  $min
     * @param  int|float  $max
     * @param  int|float  $default
     * @return float|int
     */
    public function clamp($key, $min, $max, $default = 0)
    {
        $value = $this->data($key, $default);

        if (! is_numeric($value)) {
            $value = $default;
        }

        return Number::clamp($value, $min, $max);
    }

    /**
     * Retrieve data from the instance as a Carbon instance.
     *
     * @param  string  $key
     * @param  string|null  $format
     * @param  \\UnitEnum|string|null  $tz
     * @return \\Illuminate\\Support\\Carbon|null
     *
     * @throws \\Carbon\\Exceptions\\InvalidFormatException
     */
    public function date($key, $format = null, $tz = null)
    {
        $tz = enum_value($tz);

        if ($this->isNotFilled($key)) {
            return null;
        }

        if (is_null($format)) {
            return Date::parse($this->data($key), $tz);
        }

        return Date::createFromFormat($format, $this->data($key), $tz);
    }

    /**
     * Retrieve data from the instance as a CarbonInterval instance.
     *
     * @param  string  $key
     * @param  \\Carbon\\Unit|string|null  $unit
     * @return \\Carbon\\CarbonInterval|null
     */
    public function interval($key, $unit = null)
    {
        if ($this->isNotFilled($key)) {
            return null;
        }

        $value = $this->data($key);

        if (is_null($unit)) {
            return CarbonInterval::make($value);
        }

        $unit = $unit instanceof Unit ? $unit : Unit::fromName($unit);

        return CarbonInterval::fromString(number_format((float) $value, 10, \'.\', \'\').\' \'.$unit->name);
    }

    /**
     * Retrieve data from the instance as an enum.
     *
     * @template TEnum of \\BackedEnum
     * @template TDefault of TEnum|null
     *
     * @param  string  $key
     * @param  class-string<TEnum>  $enumClass
     * @param  TDefault  $default
     * @return TEnum|TDefault
     */
    public function enum($key, $enumClass, $default = null)
    {
        if ($this->isNotFilled($key) || ! $this->isBackedEnum($enumClass)) {
            return value($default);
        }

        return $enumClass::tryFrom($this->data($key)) ?: value($default);
    }

    /**
     * Retrieve data from the instance as an array of enums.
     *
     * @template TEnum of \\BackedEnum
     *
     * @param  string  $key
     * @param  class-string<TEnum>  $enumClass
     * @return TEnum[]
     */
    public function enums($key, $enumClass)
    {
        if ($this->isNotFilled($key) || ! $this->isBackedEnum($enumClass)) {
            return [];
        }

        return $this->collect($key)
            ->map(fn ($value) => $enumClass::tryFrom($value))
            ->filter()
            ->all();
    }

    /**
     * Determine if the given enum class is backed.
     *
     * @param  class-string  $enumClass
     * @return bool
     */
    protected function isBackedEnum($enumClass)
    {
        return is_a($enumClass, \\BackedEnum::class, true);
    }

    /**
     * Retrieve data from the instance as an array.
     *
     * @param  array|string|null  $key
     * @return array
     */
    public function array($key = null)
    {
        return (array) (is_array($key) ? $this->only($key) : $this->data($key));
    }

    /**
     * Retrieve data from the instance as a collection.
     *
     * @param  array|string|null  $key
     * @return \\Illuminate\\Support\\Collection
     */
    public function collect($key = null)
    {
        return new Collection(is_array($key) ? $this->only($key) : $this->data($key));
    }

    /**
     * Get a subset containing the provided keys with values from the instance data.
     *
     * @param  mixed  $keys
     * @return array
     */
    public function only($keys)
    {
        $results = [];

        $data = $this->all();

        $placeholder = new stdClass;

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            $value = data_get($data, $key, $placeholder);

            if ($value !== $placeholder) {
                Arr::set($results, $key, $value);
            }
        }

        return $results;
    }

    /**
     * Get all of the data except for a specified array of items.
     *
     * @param  mixed  $keys
     * @return array
     */
    public function except($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        $results = $this->all();

        Arr::forget($results, $keys);

        return $results;
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/Localizable.php' => '<?php

namespace Illuminate\\Support\\Traits;

use Illuminate\\Container\\Container;

trait Localizable
{
    /**
     * Run the callback with the given locale.
     *
     * @template TReturn
     *
     * @param  string  $locale
     * @param  \\Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withLocale($locale, $callback)
    {
        if (! $locale) {
            return $callback();
        }

        $app = Container::getInstance();

        $original = $app->getLocale();

        try {
            $app->setLocale($locale);

            return $callback();
        } finally {
            $app->setLocale($original);
        }
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/ParsesSqlServerConfigurationUrls.php' => '<?php

namespace Illuminate\\Support\\Traits;

trait ParsesSqlServerConfigurationUrls
{
    /**
     * The SQL Server DSN option aliases.
     *
     * @var array<string, string>
     */
    protected static $sqlServerDsnOptionAliases = [
        \'app\' => \'appname\',
        \'authentication\' => \'authentication\',
        \'columnencryption\' => \'column_encryption\',
        \'connectionpooling\' => \'pooling\',
        \'encrypt\' => \'encrypt\',
        \'keystoreauthentication\' => \'key_store_authentication\',
        \'keystoreprincipalid\' => \'key_store_principal_id\',
        \'keystoresecret\' => \'key_store_secret\',
        \'logintimeout\' => \'login_timeout\',
        \'multipleactiveresultsets\' => \'multiple_active_result_sets\',
        \'multisubnetfailover\' => \'multi_subnet_failover\',
        \'transactionisolation\' => \'transaction_isolation\',
        \'trustservercertificate\' => \'trust_server_certificate\',
    ];

    /**
     * Determine if the given value is a SQL Server DSN.
     *
     * @param  string  $url
     * @return bool
     */
    protected function isSqlServerDsn($url)
    {
        return preg_match(\'#^sqlsrv:(?!//)#i\', $url) === 1;
    }

    /**
     * Parse a SQL Server DSN into a database configuration.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $dsn
     * @return array<string, mixed>
     */
    protected function parseSqlServerDsnConfiguration($config, $dsn)
    {
        $options = $this->parseSqlServerDsnOptions($dsn);

        [$host, $port] = $this->parseSqlServerDsnServer($options[\'server\'] ?? null);

        if (isset($options[\'applicationintent\'])) {
            unset($config[\'readonly\']);
        }

        return array_merge($config, $this->getSqlServerDsnConfigurationOptions($options), array_filter([
            \'driver\' => \'sqlsrv\',
            \'database\' => $options[\'database\'] ?? null,
            \'host\' => $host,
            \'port\' => $port,
        ], fn ($value) => ! is_null($value)));
    }

    /**
     * Get the database configuration options from a SQL Server DSN.
     *
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    protected function getSqlServerDsnConfigurationOptions($options)
    {
        $configuration = [];

        foreach (static::$sqlServerDsnOptionAliases as $option => $alias) {
            if (array_key_exists($option, $options)) {
                $configuration[$alias] = $options[$option];
            }
        }

        foreach ([\'connectionpooling\', \'multipleactiveresultsets\'] as $option) {
            if (array_key_exists($option, $options)) {
                $configuration[static::$sqlServerDsnOptionAliases[$option]] = filter_var($options[$option], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $options[$option];
            }
        }

        if (strcasecmp($options[\'applicationintent\'] ?? \'\', \'ReadOnly\') === 0) {
            $configuration[\'readonly\'] = true;
        }

        return $configuration;
    }

    /**
     * Parse the options from a SQL Server DSN.
     *
     * @param  string  $dsn
     * @return array<string, string>
     */
    protected function parseSqlServerDsnOptions($dsn)
    {
        preg_match_all(
            \'#(?:^|;)\\s*([^=;]+?)\\s*=\\s*(\\{(?:[^}]|}})*\\}|[^;]*)#\',
            substr($dsn, strlen(\'sqlsrv:\')),
            $matches,
            PREG_SET_ORDER
        );

        $options = [];

        foreach ($matches as $match) {
            $value = trim($match[2]);

            if (str_starts_with($value, \'{\') && str_ends_with($value, \'}\')) {
                $value = str_replace(\'}}\', \'}\', substr($value, 1, -1));
            }

            $options[strtolower(trim($match[1]))] = $value;
        }

        return $options;
    }

    /**
     * Parse the host and port from a SQL Server DSN Server option.
     *
     * @param  string|null  $server
     * @return array{0: string|null, 1: int|null}
     */
    protected function parseSqlServerDsnServer($server)
    {
        if (is_null($server)) {
            return [null, null];
        }

        if (preg_match(\'/^(.*),\\s*(\\d+)$/\', $server, $matches)) {
            return [trim($matches[1]), (int) $matches[2]];
        }

        return [$server, null];
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/ReadsClassAttributes.php' => '<?php

namespace Illuminate\\Support\\Traits;

use Exception;
use ReflectionClass;

trait ReadsClassAttributes
{
    /**
     * Get a configuration value from an attribute, falling back to a property.
     *
     * @param  object  $target
     * @param  class-string  $attributeClass
     * @param  string|null  $property
     * @param  mixed  $default
     * @return mixed
     */
    protected function getAttributeValue($target, string $attributeClass, ?string $property = null, $default = null)
    {
        $reflection = new ReflectionClass($target);

        $defaultProperties = $reflection->getDefaultProperties();

        if (isset($target->{$property}) && $target->{$property} !== ($defaultProperties[$property] ?? null)) {
            return $target->{$property};
        }

        if ($instance = $this->getAttributeInstance($target, $attributeClass, $attributeDeclaringClass)) {
            if ($this->propertyOverridesAttribute($target, $reflection, $property, $attributeDeclaringClass)) {
                return $target->{$property};
            }

            return $this->extractAttributeValue($instance);
        }

        return $target->{$property} ?? $default;
    }

    /**
     * Extract the value from an attribute instance.
     *
     * @param  object  $instance
     * @return mixed
     */
    protected function extractAttributeValue($instance)
    {
        $properties = get_object_vars($instance);

        return $properties === [] ? true : reset($properties);
    }

    /**
     * Get an instance of the given attribute class from the target class or its parents.
     *
     * @param  object  $target
     * @param  class-string  $attributeClass
     * @param  \\ReflectionClass|null  $declaringClass
     * @return object|null
     */
    protected function getAttributeInstance($target, string $attributeClass, ?ReflectionClass &$declaringClass = null)
    {
        $reflection = new ReflectionClass($target);

        try {
            do {
                $attributes = $reflection->getAttributes($attributeClass);

                if (count($attributes) > 0) {
                    $declaringClass = $reflection;

                    return $attributes[0]->newInstance();
                }

                foreach ($reflection->getTraits() as $trait) {
                    $attributes = $trait->getAttributes($attributeClass);

                    if (count($attributes) > 0) {
                        $declaringClass = $reflection;

                        return $attributes[0]->newInstance();
                    }
                }
            } while ($reflection = $reflection->getParentClass());
        } catch (Exception) {
            //
        }

        return null;
    }

    /**
     * Determine if a property declared on a child class overrides an inherited attribute.
     *
     * @param  object  $target
     * @param  \\ReflectionClass  $reflection
     * @param  string|null  $property
     * @param  \\ReflectionClass  $attributeDeclaringClass
     * @return bool
     */
    protected function propertyOverridesAttribute($target, ReflectionClass $reflection, ?string $property, ReflectionClass $attributeDeclaringClass)
    {
        if (is_null($property) || ! $reflection->hasProperty($property)) {
            return false;
        }

        $property = $reflection->getProperty($property);

        return $property->isPublic()
            && $property->isInitialized($target)
            && $property->getDeclaringClass()->isSubclassOf($attributeDeclaringClass->getName());
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Support/Traits/Tappable.php' => '<?php

namespace Illuminate\\Support\\Traits;

trait Tappable
{
    /**
     * Call the given Closure with this instance then return the instance.
     *
     * @param  (callable($this): mixed)|null  $callback
     * @return ($callback is null ? \\Illuminate\\Support\\HigherOrderTapProxy<$this> : $this)
     */
    public function tap($callback = null)
    {
        return tap($this, $callback);
    }
}
',
  'vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php' => '<?php

namespace Illuminate\\Validation;

use Exception;
use Illuminate\\Support\\Arr;
use Illuminate\\Support\\Facades\\Validator as ValidatorFacade;

class ValidationException extends Exception
{
    /**
     * The validator instance.
     *
     * @var \\Illuminate\\Contracts\\Validation\\Validator
     */
    public $validator;

    /**
     * The recommended response to send to the client.
     *
     * @var \\Symfony\\Component\\HttpFoundation\\Response|null
     */
    public $response;

    /**
     * The status code to use for the response.
     *
     * @var int
     */
    public $status = 422;

    /**
     * The name of the error bag.
     *
     * @var string
     */
    public $errorBag;

    /**
     * The path the client should be redirected to.
     *
     * @var string|null
     */
    public $redirectTo;

    /**
     * Create a new exception instance.
     *
     * @param  \\Illuminate\\Contracts\\Validation\\Validator  $validator
     * @param  \\Symfony\\Component\\HttpFoundation\\Response|null  $response
     * @param  string  $errorBag
     */
    public function __construct($validator, $response = null, $errorBag = \'default\')
    {
        parent::__construct(static::summarize($validator));

        $this->response = $response;
        $this->errorBag = $errorBag;
        $this->validator = $validator;
    }

    /**
     * Create a new validation exception from a plain array of messages.
     *
     * @param  array  $messages
     * @return static
     */
    public static function withMessages(array $messages)
    {
        return new static(tap(ValidatorFacade::make([], []), function ($validator) use ($messages) {
            foreach ($messages as $key => $value) {
                foreach (Arr::wrap($value) as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }));
    }

    /**
     * Create an error message summary from the validation errors.
     *
     * @param  \\Illuminate\\Contracts\\Validation\\Validator  $validator
     * @return string
     */
    protected static function summarize($validator)
    {
        $messages = $validator->errors()->all();

        if (! count($messages) || ! is_string($messages[0])) {
            return $validator->getTranslator()->get(\'The given data was invalid.\');
        }

        $message = array_shift($messages);

        if ($count = count($messages)) {
            $pluralized = $count === 1 ? \'error\' : \'errors\';

            $message .= \' \'.$validator->getTranslator()->choice("(and :count more $pluralized)", $count, [\'count\' => $count]);
        }

        return $message;
    }

    /**
     * Get all of the validation error messages.
     *
     * @return array
     */
    public function errors()
    {
        return $this->validator->errors()->messages();
    }

    /**
     * Set the HTTP status code to be used for the response.
     *
     * @param  int  $status
     * @return $this
     */
    public function status($status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set the error bag on the exception.
     *
     * @param  string  $errorBag
     * @return $this
     */
    public function errorBag($errorBag)
    {
        $this->errorBag = $errorBag;

        return $this;
    }

    /**
     * Set the URL to redirect to on a validation error.
     *
     * @param  string  $url
     * @return $this
     */
    public function redirectTo($url)
    {
        $this->redirectTo = $url;

        return $this;
    }

    /**
     * Get the underlying response instance.
     *
     * @return \\Symfony\\Component\\HttpFoundation\\Response|null
     */
    public function getResponse()
    {
        return $this->response;
    }
}
',
  'vendor/symfony/http-foundation/Request.php' => '<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\\Component\\HttpFoundation;

use Symfony\\Component\\HttpFoundation\\Exception\\BadRequestException;
use Symfony\\Component\\HttpFoundation\\Exception\\ConflictingHeadersException;
use Symfony\\Component\\HttpFoundation\\Exception\\JsonException;
use Symfony\\Component\\HttpFoundation\\Exception\\SessionNotFoundException;
use Symfony\\Component\\HttpFoundation\\Exception\\SuspiciousOperationException;
use Symfony\\Component\\HttpFoundation\\Session\\SessionInterface;

// Help opcache.preload discover always-needed symbols
class_exists(AcceptHeader::class);
class_exists(FileBag::class);
class_exists(HeaderBag::class);
class_exists(HeaderUtils::class);
class_exists(InputBag::class);
class_exists(ParameterBag::class);
class_exists(ServerBag::class);

/**
 * Request represents an HTTP request.
 *
 * The methods dealing with URL accept / return a raw path (% encoded):
 *   * getBasePath
 *   * getBaseUrl
 *   * getPathInfo
 *   * getRequestUri
 *   * getUri
 *   * getUriForPath
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Request
{
    public const HEADER_FORWARDED = 0b000001; // When using RFC 7239
    public const HEADER_X_FORWARDED_FOR = 0b000010;
    public const HEADER_X_FORWARDED_HOST = 0b000100;
    public const HEADER_X_FORWARDED_PROTO = 0b001000;
    public const HEADER_X_FORWARDED_PORT = 0b010000;
    public const HEADER_X_FORWARDED_PREFIX = 0b100000;

    public const HEADER_X_FORWARDED_AWS_ELB = 0b0011010; // AWS ELB doesn\'t send X-Forwarded-Host
    public const HEADER_X_FORWARDED_TRAEFIK = 0b0111110; // All "X-Forwarded-*" headers sent by Traefik reverse proxy

    public const METHOD_HEAD = \'HEAD\';
    public const METHOD_GET = \'GET\';
    public const METHOD_POST = \'POST\';
    public const METHOD_PUT = \'PUT\';
    public const METHOD_PATCH = \'PATCH\';
    public const METHOD_DELETE = \'DELETE\';
    public const METHOD_PURGE = \'PURGE\';
    public const METHOD_OPTIONS = \'OPTIONS\';
    public const METHOD_TRACE = \'TRACE\';
    public const METHOD_CONNECT = \'CONNECT\';
    public const METHOD_QUERY = \'QUERY\';

    private const FORWARDED_PARAMS = [
        self::HEADER_X_FORWARDED_FOR => \'for\',
        self::HEADER_X_FORWARDED_HOST => \'host\',
        self::HEADER_X_FORWARDED_PROTO => \'proto\',
        self::HEADER_X_FORWARDED_PORT => \'host\',
    ];

    /**
     * Names for headers that can be trusted when
     * using trusted proxies.
     *
     * The FORWARDED header is the standard as of rfc7239.
     *
     * The other headers are non-standard, but widely used
     * by popular reverse proxies (like Apache mod_proxy or Amazon EC2).
     */
    private const TRUSTED_HEADERS = [
        self::HEADER_FORWARDED => \'FORWARDED\',
        self::HEADER_X_FORWARDED_FOR => \'X_FORWARDED_FOR\',
        self::HEADER_X_FORWARDED_HOST => \'X_FORWARDED_HOST\',
        self::HEADER_X_FORWARDED_PROTO => \'X_FORWARDED_PROTO\',
        self::HEADER_X_FORWARDED_PORT => \'X_FORWARDED_PORT\',
        self::HEADER_X_FORWARDED_PREFIX => \'X_FORWARDED_PREFIX\',
    ];

    /**
     * This mapping is used when no exact MIME match is found in $formats.
     *
     * It enables mappings like application/soap+xml -> xml.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc6839
     * @see https://datatracker.ietf.org/doc/html/rfc7303
     * @see https://www.iana.org/assignments/media-types/media-types.xhtml
     */
    private const STRUCTURED_SUFFIX_FORMATS = [
        \'json\' => \'json\',
        \'xml\' => \'xml\',
        \'xhtml\' => \'html\',
        \'cbor\' => \'cbor\',
        \'zip\' => \'zip\',
        \'ber\' => \'asn1\',
        \'der\' => \'asn1\',
        \'tlv\' => \'tlv\',
        \'wbxml\' => \'xml\',
        \'yaml\' => \'yaml\',
    ];

    /**
     * Custom parameters.
     */
    public ParameterBag $attributes {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "attributes" of "%s" is deprecated; pass attributes as a constructor argument or call "initialize()" instead.\', static::class);

            $this->attributes = $value;
        }
    }

    /**
     * Request body parameters ($_POST).
     *
     * @see getPayload() for portability between content types
     */
    public InputBag $request {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "request" of "%s" is deprecated; pass the POST data as a constructor argument or call "initialize()" instead.\', static::class);

            $this->request = $value;
        }
    }

    /**
     * Query string parameters ($_GET).
     *
     * @var InputBag<string>
     */
    public InputBag $query {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "query" of "%s" is deprecated; pass query parameters as a constructor argument or call "initialize()" instead.\', static::class);

            $this->query = $value;
        }
    }

    /**
     * Server and execution environment parameters ($_SERVER).
     */
    public ServerBag $server {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "server" of "%s" is deprecated; pass server parameters as a constructor argument or call "initialize()" instead.\', static::class);

            $this->server = $value;
        }
    }

    /**
     * Uploaded files ($_FILES).
     */
    public FileBag $files {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "files" of "%s" is deprecated; pass files as a constructor argument or call "initialize()" instead.\', static::class);

            $this->files = $value;
        }
    }

    /**
     * Cookies ($_COOKIE).
     *
     * @var InputBag<string>
     */
    public InputBag $cookies {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "cookies" of "%s" is deprecated; pass cookies as a constructor argument or call "initialize()" instead.\', static::class);

            $this->cookies = $value;
        }
    }

    /**
     * Headers (taken from the $_SERVER).
     */
    public HeaderBag $headers {
        set {
            trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "headers" of "%s" is deprecated; pass header parameters as a constructor argument or call "initialize()" instead.\', static::class);

            $this->headers = $value;
        }
    }

    /**
     * @var string|resource|false|null
     */
    protected $content;

    /**
     * @var string[]|null
     */
    protected ?array $languages = null;

    /**
     * @var string[]|null
     */
    protected ?array $charsets = null;

    /**
     * @var string[]|null
     */
    protected ?array $encodings = null;

    /**
     * @var string[]|null
     */
    protected ?array $acceptableContentTypes = null;

    protected ?string $pathInfo = null;
    protected ?string $requestUri = null;
    protected ?string $baseUrl = null;
    protected ?string $basePath = null;
    protected ?string $method = null;
    protected ?string $format = null;
    protected SessionInterface|\\Closure|null $session = null;
    protected ?string $locale = null;
    protected string $defaultLocale = \'en\';

    /**
     * @var array<string, string[]>|null
     */
    protected static ?array $formats = null;

    /**
     * @var string[]
     */
    protected static array $trustedProxies = [];

    /**
     * @var string[]
     */
    protected static array $trustedHostPatterns = [];

    /**
     * @var string[]
     */
    protected static array $trustedHosts = [];

    protected static bool $httpMethodParameterOverride = false;

    /**
     * The HTTP methods that can be overridden.
     *
     * @var uppercase-string[]|null
     */
    protected static ?array $allowedHttpMethodOverride = null;

    protected static ?\\Closure $requestFactory = null;

    private ?string $preferredFormat = null;

    private bool $isHostValid = true;
    private bool $isForwardedValid = true;
    private bool $isSafeContentPreferred;

    private array $trustedValuesCache = [];

    private static int $trustedHeaderSet = -1;

    private static ?string $trustedHostsRegexp = null;

    private bool $isIisRewrite = false;

    /**
     * @param array                $query      The GET parameters
     * @param array                $request    The POST parameters
     * @param array                $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
     * @param array                $cookies    The COOKIE parameters
     * @param array                $files      The FILES parameters
     * @param array                $server     The SERVER parameters
     * @param string|resource|null $content    The raw body data
     */
    public function __construct(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        $this->initialize($query, $request, $attributes, $cookies, $files, $server, $content);
    }

    /**
     * Sets the parameters for this request.
     *
     * This method also re-initializes all properties.
     *
     * @param array                $query      The GET parameters
     * @param array                $request    The POST parameters
     * @param array                $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
     * @param array                $cookies    The COOKIE parameters
     * @param array                $files      The FILES parameters
     * @param array                $server     The SERVER parameters
     * @param string|resource|null $content    The raw body data
     */
    public function initialize(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null): void
    {
        self::setProperty($this, \'request\', new InputBag($request));
        self::setProperty($this, \'query\', new InputBag($query));
        self::setProperty($this, \'attributes\', new ParameterBag($attributes));
        self::setProperty($this, \'cookies\', new InputBag($cookies));
        self::setProperty($this, \'files\', new FileBag($files));
        self::setProperty($this, \'server\', new ServerBag($server));
        self::setProperty($this, \'headers\', new HeaderBag($this->server->getHeaders()));

        $this->content = $content;
        $this->languages = null;
        $this->charsets = null;
        $this->encodings = null;
        $this->acceptableContentTypes = null;
        $this->pathInfo = null;
        $this->requestUri = null;
        $this->baseUrl = null;
        $this->basePath = null;
        $this->method = null;
        $this->format = null;
    }

    /**
     * Creates a new request with values from PHP\'s super globals.
     */
    public static function createFromGlobals(): static
    {
        if (!\\in_array($_SERVER[\'REQUEST_METHOD\'] ?? null, [\'PUT\', \'DELETE\', \'PATCH\', \'QUERY\'], true)) {
            return self::createRequestFromFactory($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER);
        }

        try {
            [$post, $files] = request_parse_body();
        } catch (\\RequestParseBodyException) {
            $post = $_POST;
            $files = $_FILES;
        }

        return self::createRequestFromFactory($_GET, $post, [], $_COOKIE, $files, $_SERVER);
    }

    /**
     * Creates a Request based on a given URI and configuration.
     *
     * The information contained in the URI always take precedence
     * over the other information (server and parameters).
     *
     * @param string               $uri        The URI
     * @param string               $method     The HTTP method
     * @param array                $parameters The query (GET) or request (POST) parameters
     * @param array                $cookies    The request cookies ($_COOKIE)
     * @param array                $files      The request files ($_FILES)
     * @param array                $server     The server parameters ($_SERVER)
     * @param string|resource|null $content    The raw body data
     *
     * @throws BadRequestException When the URI is invalid
     */
    public static function create(string $uri, string $method = \'GET\', array $parameters = [], array $cookies = [], array $files = [], array $server = [], $content = null): static
    {
        $server = array_replace([
            \'SERVER_NAME\' => \'localhost\',
            \'SERVER_PORT\' => 80,
            \'HTTP_HOST\' => \'localhost\',
            \'HTTP_USER_AGENT\' => \'Symfony\',
            \'HTTP_ACCEPT\' => \'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\',
            \'HTTP_ACCEPT_LANGUAGE\' => \'en-us,en;q=0.5\',
            \'HTTP_ACCEPT_CHARSET\' => \'ISO-8859-1,utf-8;q=0.7,*;q=0.7\',
            \'REMOTE_ADDR\' => \'127.0.0.1\',
            \'SCRIPT_NAME\' => \'\',
            \'SCRIPT_FILENAME\' => \'\',
            \'SERVER_PROTOCOL\' => \'HTTP/1.1\',
            \'REQUEST_TIME\' => time(),
            \'REQUEST_TIME_FLOAT\' => microtime(true),
        ], $server);

        $server[\'PATH_INFO\'] = \'\';
        $server[\'REQUEST_METHOD\'] = strtoupper($method);

        if (($i = strcspn($uri, \':/?#\')) && \':\' === ($uri[$i] ?? null) && (strspn($uri, \'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+-.\') !== $i || strcspn($uri, \'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ\'))) {
            throw new BadRequestException(\'Invalid URI: Scheme is malformed.\');
        }
        if (false === $components = parse_url(\\strlen($uri) !== strcspn($uri, \'?#\') ? $uri : $uri.\'#\')) {
            throw new BadRequestException(\'Invalid URI.\');
        }

        $part = ($components[\'user\'] ?? \'\').\':\'.($components[\'pass\'] ?? \'\');

        if (\':\' !== $part && \\strlen($part) !== strcspn($part, \'[]\')) {
            throw new BadRequestException(\'Invalid URI: Userinfo is malformed.\');
        }
        if (($part = $components[\'host\'] ?? \'\') && !self::isHostValid($part)) {
            throw new BadRequestException(\'Invalid URI: Host is malformed.\');
        }
        if (false !== ($i = strpos($uri, \'\\\\\')) && $i < strcspn($uri, \'?#\')) {
            throw new BadRequestException(\'Invalid URI: A URI cannot contain a backslash.\');
        }
        if (\\strlen($uri) !== strcspn($uri, "\\r\\n\\t")) {
            throw new BadRequestException(\'Invalid URI: A URI cannot contain CR/LF/TAB characters.\');
        }
        if (\'\' !== $uri && (\\ord($uri[0]) <= 32 || \\ord($uri[-1]) <= 32)) {
            throw new BadRequestException(\'Invalid URI: A URI must not start nor end with ASCII control characters or spaces.\');
        }

        if (isset($components[\'host\'])) {
            $server[\'SERVER_NAME\'] = $components[\'host\'];
            $server[\'HTTP_HOST\'] = $components[\'host\'];
        }

        if (isset($components[\'scheme\'])) {
            if (\'https\' === $components[\'scheme\']) {
                $server[\'HTTPS\'] = \'on\';
                $server[\'SERVER_PORT\'] = 443;
            } else {
                unset($server[\'HTTPS\']);
                $server[\'SERVER_PORT\'] = 80;
            }
        }

        if (isset($components[\'port\'])) {
            $server[\'SERVER_PORT\'] = $components[\'port\'];
            $server[\'HTTP_HOST\'] .= \':\'.$components[\'port\'];
        }

        if (isset($components[\'user\'])) {
            $server[\'PHP_AUTH_USER\'] = $components[\'user\'];
        }

        if (isset($components[\'pass\'])) {
            $server[\'PHP_AUTH_PW\'] = $components[\'pass\'];
        }

        if (\'\' === $path = $components[\'path\'] ?? \'\') {
            $components[\'path\'] = \'/\';
        } elseif (!isset($components[\'scheme\']) && !isset($components[\'host\']) && \'/\' !== $path[0]) {
            if (false !== $pos = strpos($path, \'/\')) {
                $path = substr($path, 0, $pos);
            }

            if (str_contains($path, \':\')) {
                throw new BadRequestException(\'Invalid URI: Path is malformed.\');
            }
        }

        switch (strtoupper($method)) {
            case \'POST\':
            case \'PUT\':
            case \'DELETE\':
            case \'QUERY\':
                if (!isset($server[\'CONTENT_TYPE\'])) {
                    $server[\'CONTENT_TYPE\'] = \'application/x-www-form-urlencoded\';
                }
                // no break
            case \'PATCH\':
                $request = $parameters;
                $query = [];
                break;
            default:
                $request = [];
                $query = $parameters;
                break;
        }

        $queryString = \'\';
        if (isset($components[\'query\'])) {
            parse_str(html_entity_decode($components[\'query\']), $qs);

            if ($query) {
                $query = array_replace($qs, $query);
                $queryString = http_build_query($query, \'\', \'&\');
            } else {
                $query = $qs;
                $queryString = $components[\'query\'];
            }
        } elseif ($query) {
            $queryString = http_build_query($query, \'\', \'&\');
        }

        $server[\'REQUEST_URI\'] = $components[\'path\'].(\'\' !== $queryString ? \'?\'.$queryString : \'\');
        $server[\'QUERY_STRING\'] = $queryString;

        return self::createRequestFromFactory($query, $request, [], $cookies, $files, $server, $content);
    }

    /**
     * Sets a callable able to create a Request instance.
     *
     * This is mainly useful when you need to override the Request class
     * to keep BC with an existing system. It should not be used for any
     * other purpose.
     */
    public static function setFactory(?callable $callable): void
    {
        self::$requestFactory = null === $callable ? null : $callable(...);
    }

    /**
     * Clones a request and overrides some of its parameters.
     *
     * @param array|null $query      The GET parameters
     * @param array|null $request    The POST parameters
     * @param array|null $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
     * @param array|null $cookies    The COOKIE parameters
     * @param array|null $files      The FILES parameters
     * @param array|null $server     The SERVER parameters
     */
    public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static
    {
        $dup = clone $this;
        if (null !== $query) {
            self::setProperty($dup, \'query\', new InputBag($query));
        }
        if (null !== $request) {
            self::setProperty($dup, \'request\', new InputBag($request));
        }
        if (null !== $attributes) {
            self::setProperty($dup, \'attributes\', new ParameterBag($attributes));
        }
        if (null !== $cookies) {
            self::setProperty($dup, \'cookies\', new InputBag($cookies));
        }
        if (null !== $files) {
            self::setProperty($dup, \'files\', new FileBag($files));
        }
        if (null !== $server) {
            self::setProperty($dup, \'server\', new ServerBag($server));
            self::setProperty($dup, \'headers\', new HeaderBag($dup->server->getHeaders()));
        }
        $dup->languages = null;
        $dup->charsets = null;
        $dup->encodings = null;
        $dup->acceptableContentTypes = null;
        $dup->pathInfo = null;
        $dup->requestUri = null;
        $dup->baseUrl = null;
        $dup->basePath = null;
        $dup->method = null;
        $dup->format = null;

        if (!$dup->attributes->has(\'_format\') && $this->attributes->has(\'_format\')) {
            $dup->attributes->set(\'_format\', $this->attributes->get(\'_format\'));
        }

        if (!$dup->getRequestFormat(null)) {
            $dup->setRequestFormat($this->getRequestFormat(null));
        }

        return $dup;
    }

    /**
     * Clones the current request.
     *
     * Note that the session is not cloned as duplicated requests
     * are most of the time sub-requests of the main one.
     */
    public function __clone()
    {
        self::setProperty($this, \'query\', clone $this->query);
        self::setProperty($this, \'request\', clone $this->request);
        self::setProperty($this, \'attributes\', clone $this->attributes);
        self::setProperty($this, \'cookies\', clone $this->cookies);
        self::setProperty($this, \'files\', clone $this->files);
        self::setProperty($this, \'server\', clone $this->server);
        self::setProperty($this, \'headers\', clone $this->headers);
    }

    public function __toString(): string
    {
        $content = $this->getContent();

        $cookieHeader = \'\';
        $cookies = [];

        foreach ($this->cookies as $k => $v) {
            $cookies[] = \\is_array($v) ? http_build_query([$k => $v], \'\', \'; \', \\PHP_QUERY_RFC3986) : "$k=$v";
        }

        if ($cookies) {
            $cookieHeader = \'Cookie: \'.implode(\'; \', $cookies)."\\r\\n";
        }

        return
            \\sprintf(\'%s %s %s\', $this->getMethod(), $this->getRequestUri(), $this->server->get(\'SERVER_PROTOCOL\'))."\\r\\n".
            $this->headers.
            $cookieHeader."\\r\\n".
            $content;
    }

    /**
     * Overrides the PHP global variables according to this request instance.
     *
     * It overrides $_GET, $_POST, $_REQUEST, $_SERVER, $_COOKIE.
     * $_FILES is never overridden, see rfc1867
     */
    public function overrideGlobals(): void
    {
        $this->server->set(\'QUERY_STRING\', static::normalizeQueryString(http_build_query($this->query->all(), \'\', \'&\')));

        $_GET = $this->query->all();
        $_POST = $this->request->all();
        $_SERVER = $this->server->all();
        $_COOKIE = $this->cookies->all();

        foreach ($this->headers->all() as $key => $value) {
            $key = strtoupper(str_replace(\'-\', \'_\', $key));
            if (\\in_array($key, [\'CONTENT_TYPE\', \'CONTENT_LENGTH\', \'CONTENT_MD5\'], true)) {
                $_SERVER[$key] = implode(\', \', $value);
            } else {
                $_SERVER[\'HTTP_\'.$key] = implode(\', \', $value);
            }
        }

        $request = [\'g\' => $_GET, \'p\' => $_POST, \'c\' => $_COOKIE];

        $requestOrder = \\ini_get(\'request_order\') ?: \\ini_get(\'variables_order\');
        $requestOrder = preg_replace(\'#[^cgp]#\', \'\', strtolower($requestOrder)) ?: \'gp\';

        $_REQUEST = [[]];

        foreach (str_split($requestOrder) as $order) {
            $_REQUEST[] = $request[$order];
        }

        $_REQUEST = array_merge(...$_REQUEST);
    }

    /**
     * Sets a list of trusted proxies.
     *
     * You should only list the reverse proxies that you manage directly.
     *
     * @param array                          $proxies          A list of trusted proxies, the string \'REMOTE_ADDR\' will be replaced with $_SERVER[\'REMOTE_ADDR\'] and \'PRIVATE_SUBNETS\' by IpUtils::PRIVATE_SUBNETS
     * @param int-mask-of<Request::HEADER_*> $trustedHeaderSet A bit field to set which headers to trust from your proxies
     */
    public static function setTrustedProxies(array $proxies, int $trustedHeaderSet): void
    {
        if (false !== $i = array_search(\'REMOTE_ADDR\', $proxies, true)) {
            if (isset($_SERVER[\'REMOTE_ADDR\'])) {
                $proxies[$i] = $_SERVER[\'REMOTE_ADDR\'];
            } else {
                unset($proxies[$i]);
                $proxies = array_values($proxies);
            }
        }

        if (false !== ($i = array_search(\'PRIVATE_SUBNETS\', $proxies, true)) || false !== ($i = array_search(\'private_ranges\', $proxies, true))) {
            unset($proxies[$i]);
            $proxies = array_merge($proxies, IpUtils::PRIVATE_SUBNETS);
        }

        self::$trustedProxies = $proxies;
        self::$trustedHeaderSet = $trustedHeaderSet;
    }

    /**
     * Gets the list of trusted proxies.
     *
     * @return string[]
     */
    public static function getTrustedProxies(): array
    {
        return self::$trustedProxies;
    }

    /**
     * Gets the set of trusted headers from trusted proxies.
     *
     * @return int A bit field of Request::HEADER_* that defines which headers are trusted from your proxies
     */
    public static function getTrustedHeaderSet(): int
    {
        return self::$trustedHeaderSet;
    }

    /**
     * Sets a list of trusted host patterns.
     *
     * You should only list the hosts you manage using regexs.
     *
     * @param array $hostPatterns A list of trusted host patterns
     */
    public static function setTrustedHosts(array $hostPatterns): void
    {
        self::$trustedHostPatterns = array_map(static fn ($hostPattern) => \\sprintf(\'{%s}i\', $hostPattern), $hostPatterns);
        // the branch reset group keeps capturing groups, back references and inline modifiers local to each pattern
        self::$trustedHostsRegexp = $hostPatterns ? \\sprintf(\'{(?|(?:%s))}i\', implode(\')|(?:\', $hostPatterns)) : null;
    }

    /**
     * Gets the list of trusted host patterns.
     *
     * @return string[]
     */
    public static function getTrustedHosts(): array
    {
        return self::$trustedHostPatterns;
    }

    /**
     * Normalizes a query string.
     *
     * It builds a normalized query string, where keys/value pairs are alphabetized,
     * have consistent escaping and unneeded delimiters are removed.
     */
    public static function normalizeQueryString(?string $qs): string
    {
        if (\'\' === ($qs ?? \'\')) {
            return \'\';
        }

        $qs = HeaderUtils::parseQuery($qs);
        ksort($qs);

        return http_build_query($qs, \'\', \'&\', \\PHP_QUERY_RFC3986);
    }

    /**
     * Enables support for the _method request parameter to determine the intended HTTP method.
     *
     * Be warned that enabling this feature might lead to CSRF issues in your code.
     * Check that you are using CSRF tokens when required.
     * If the HTTP method parameter override is enabled, an html-form with method "POST" can be altered
     * and used to send a "PUT" or "DELETE" request via the _method request parameter.
     * If these methods are not protected against CSRF, this presents a possible vulnerability.
     *
     * The HTTP method can only be overridden when the real HTTP method is POST.
     */
    public static function enableHttpMethodParameterOverride(): void
    {
        self::$httpMethodParameterOverride = true;
    }

    /**
     * Checks whether support for the _method request parameter is enabled.
     */
    public static function getHttpMethodParameterOverride(): bool
    {
        return self::$httpMethodParameterOverride;
    }

    /**
     * Sets the list of HTTP methods that can be overridden.
     *
     * Set to null to allow all methods to be overridden (default). Set to an
     * empty array to disallow overrides entirely. Otherwise, provide the list
     * of uppercased method names that are allowed.
     *
     * @param uppercase-string[]|null $methods
     */
    public static function setAllowedHttpMethodOverride(?array $methods): void
    {
        if (array_intersect($methods ?? [], [\'GET\', \'HEAD\', \'CONNECT\', \'TRACE\'])) {
            throw new \\InvalidArgumentException(\'The HTTP methods "GET", "HEAD", "CONNECT", and "TRACE" cannot be overridden.\');
        }

        self::$allowedHttpMethodOverride = $methods;
    }

    /**
     * Gets the list of HTTP methods that can be overridden.
     *
     * @return uppercase-string[]|null
     */
    public static function getAllowedHttpMethodOverride(): ?array
    {
        return self::$allowedHttpMethodOverride;
    }

    /**
     * Gets the Session.
     *
     * @throws SessionNotFoundException When session is not set properly
     */
    public function getSession(): SessionInterface
    {
        $session = $this->session;
        if (!$session instanceof SessionInterface && null !== $session) {
            $this->setSession($session = $session());
        }

        if (null === $session) {
            throw new SessionNotFoundException(\'Session has not been set.\');
        }

        return $session;
    }

    /**
     * Whether the request contains a Session which was started in one of the
     * previous requests.
     */
    public function hasPreviousSession(): bool
    {
        // the check for $this->session avoids malicious users trying to fake a session cookie with proper name
        return $this->hasSession() && $this->cookies->has($this->getSession()->getName());
    }

    /**
     * Whether the request contains a Session object.
     *
     * This method does not give any information about the state of the session object,
     * like whether the session is started or not. It is just a way to check if this Request
     * is associated with a Session instance.
     *
     * @param bool $skipIfUninitialized When true, ignores factories injected by `setSessionFactory`
     */
    public function hasSession(bool $skipIfUninitialized = false): bool
    {
        return null !== $this->session && (!$skipIfUninitialized || $this->session instanceof SessionInterface);
    }

    public function setSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    /**
     * @internal
     *
     * @param callable(): SessionInterface $factory
     */
    public function setSessionFactory(callable $factory): void
    {
        $this->session = $factory(...);
    }

    /**
     * Returns the client IP addresses.
     *
     * In the returned array the most trusted IP address is first, and the
     * least trusted one last. The "real" client IP address is the last one,
     * but this is also the least trusted one. Trusted proxies are stripped.
     *
     * Use this method carefully; you should use getClientIp() instead.
     *
     * @see getClientIp()
     */
    public function getClientIps(): array
    {
        $ip = $this->server->get(\'REMOTE_ADDR\');

        if (!$this->isFromTrustedProxy()) {
            return [$ip];
        }

        return $this->getTrustedValues(self::HEADER_X_FORWARDED_FOR, $ip) ?: [$ip];
    }

    /**
     * Returns the client IP address.
     *
     * This method can read the client IP address from the "X-Forwarded-For" header
     * when trusted proxies were set via "setTrustedProxies()". The "X-Forwarded-For"
     * header value is a comma+space separated list of IP addresses, the left-most
     * being the original client, and each successive proxy that passed the request
     * adding the IP address where it received the request from.
     *
     * @see getClientIps()
     * @see https://wikipedia.org/wiki/X-Forwarded-For
     */
    public function getClientIp(): ?string
    {
        return $this->getClientIps()[0];
    }

    /**
     * Returns current script name.
     */
    public function getScriptName(): string
    {
        return $this->server->get(\'SCRIPT_NAME\', $this->server->get(\'ORIG_SCRIPT_NAME\', \'\'));
    }

    /**
     * Returns the path being requested relative to the executed script.
     *
     * The path info always starts with a /.
     *
     * Suppose this request is instantiated from /mysite on localhost:
     *
     *  * http://localhost/mysite              returns \'/\'
     *  * http://localhost/mysite/about        returns \'/about\'
     *  * http://localhost/mysite/enco%20ded   returns \'/enco%20ded\'
     *  * http://localhost/mysite/about?var=1  returns \'/about\'
     *
     * @return string The raw path (i.e. not urldecoded)
     */
    public function getPathInfo(): string
    {
        return $this->pathInfo ??= $this->preparePathInfo();
    }

    /**
     * Returns the root path from which this request is executed.
     *
     * Suppose that an index.php file instantiates this request object:
     *
     *  * http://localhost/index.php         returns an empty string
     *  * http://localhost/index.php/page    returns an empty string
     *  * http://localhost/web/index.php     returns \'/web\'
     *  * http://localhost/we%20b/index.php  returns \'/we%20b\'
     *
     * @return string The raw path (i.e. not urldecoded)
     */
    public function getBasePath(): string
    {
        return $this->basePath ??= $this->prepareBasePath();
    }

    /**
     * Returns the root URL from which this request is executed.
     *
     * The base URL never ends with a /.
     *
     * This is similar to getBasePath(), except that it also includes the
     * script filename (e.g. index.php) if one exists.
     *
     * @return string The raw URL (i.e. not urldecoded)
     */
    public function getBaseUrl(): string
    {
        $trustedPrefix = \'\';

        // the proxy prefix must be prepended to any prefix being needed at the webserver level
        if ($this->isFromTrustedProxy() && $trustedPrefixValues = $this->getTrustedValues(self::HEADER_X_FORWARDED_PREFIX)) {
            $trustedPrefix = rtrim($trustedPrefixValues[0], \'/\');
        }

        return $trustedPrefix.$this->getBaseUrlReal();
    }

    /**
     * Returns the real base URL received by the webserver from which this request is executed.
     * The URL does not include trusted reverse proxy prefix.
     *
     * @return string The raw URL (i.e. not urldecoded)
     */
    private function getBaseUrlReal(): string
    {
        return $this->baseUrl ??= $this->prepareBaseUrl();
    }

    /**
     * Gets the request\'s scheme.
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? \'https\' : \'http\';
    }

    /**
     * Returns the port on which the request is made.
     *
     * This method can read the client port from the "X-Forwarded-Port" header
     * when trusted proxies were set via "setTrustedProxies()".
     *
     * The "X-Forwarded-Port" header must contain the client port.
     *
     * @return int|string|null Can be a string if fetched from the server bag
     */
    public function getPort(): int|string|null
    {
        if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_PORT)) {
            $host = $host[0];
        } elseif ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {
            $host = $host[0];
        } elseif (!$host = $this->headers->get(\'HOST\')) {
            return $this->server->get(\'SERVER_PORT\');
        }

        if (\'[\' === $host[0]) {
            $pos = strpos($host, \':\', strrpos($host, \']\'));
        } else {
            $pos = strrpos($host, \':\');
        }

        if (false !== $pos && $port = substr($host, $pos + 1)) {
            return (int) $port;
        }

        return \'https\' === $this->getScheme() ? 443 : 80;
    }

    /**
     * Returns the user.
     */
    public function getUser(): ?string
    {
        return $this->headers->get(\'PHP_AUTH_USER\');
    }

    /**
     * Returns the password.
     */
    public function getPassword(): ?string
    {
        return $this->headers->get(\'PHP_AUTH_PW\');
    }

    /**
     * Gets the user info.
     *
     * @return string|null A user name if any and, optionally, scheme-specific information about how to gain authorization to access the server
     */
    public function getUserInfo(): ?string
    {
        $userinfo = $this->getUser();

        $pass = $this->getPassword();
        if (\'\' != $pass) {
            $userinfo .= ":$pass";
        }

        return $userinfo;
    }

    /**
     * Returns the HTTP host being requested.
     *
     * The port name will be appended to the host if it\'s non-standard.
     */
    public function getHttpHost(): string
    {
        $scheme = $this->getScheme();
        $port = $this->getPort();

        if ((\'http\' === $scheme && 80 == $port) || (\'https\' === $scheme && 443 == $port)) {
            return $this->getHost();
        }

        return $this->getHost().\':\'.$port;
    }

    /**
     * Returns the requested URI (path and query string).
     *
     * @return string The raw URI (i.e. not URI decoded)
     */
    public function getRequestUri(): string
    {
        return $this->requestUri ??= $this->prepareRequestUri();
    }

    /**
     * Gets the scheme and HTTP host.
     *
     * If the URL was called with basic authentication, the user
     * and the password are not added to the generated string.
     */
    public function getSchemeAndHttpHost(): string
    {
        return $this->getScheme().\'://\'.$this->getHttpHost();
    }

    /**
     * Generates a normalized URI (URL) for the Request.
     *
     * @see getQueryString()
     */
    public function getUri(): string
    {
        if (null !== $qs = $this->getQueryString()) {
            $qs = \'?\'.$qs;
        }

        return $this->getSchemeAndHttpHost().$this->getBaseUrl().$this->getPathInfo().$qs;
    }

    /**
     * Generates a normalized URI for the given path.
     *
     * @param string $path A path to use instead of the current one
     */
    public function getUriForPath(string $path): string
    {
        return $this->getSchemeAndHttpHost().$this->getBaseUrl().$path;
    }

    /**
     * Returns the path as relative reference from the current Request path.
     *
     * Only the URIs path component (no schema, host etc.) is relevant and must be given.
     * Both paths must be absolute and not contain relative parts.
     * Relative URLs from one resource to another are useful when generating self-contained downloadable document archives.
     * Furthermore, they can be used to reduce the link size in documents.
     *
     * Example target paths, given a base path of "/a/b/c/d":
     * - "/a/b/c/d"     -> ""
     * - "/a/b/c/"      -> "./"
     * - "/a/b/"        -> "../"
     * - "/a/b/c/other" -> "other"
     * - "/a/x/y"       -> "../../x/y"
     */
    public function getRelativeUriForPath(string $path): string
    {
        // be sure that we are dealing with an absolute path
        if (!isset($path[0]) || \'/\' !== $path[0]) {
            return $path;
        }

        if ($path === $basePath = $this->getPathInfo()) {
            return \'\';
        }

        $sourceDirs = explode(\'/\', isset($basePath[0]) && \'/\' === $basePath[0] ? substr($basePath, 1) : $basePath);
        $targetDirs = explode(\'/\', substr($path, 1));
        array_pop($sourceDirs);
        $targetFile = array_pop($targetDirs);

        foreach ($sourceDirs as $i => $dir) {
            if (isset($targetDirs[$i]) && $dir === $targetDirs[$i]) {
                unset($sourceDirs[$i], $targetDirs[$i]);
            } else {
                break;
            }
        }

        $targetDirs[] = $targetFile;
        $path = str_repeat(\'../\', \\count($sourceDirs)).implode(\'/\', $targetDirs);

        // A reference to the same base directory or an empty subdirectory must be prefixed with "./".
        // This also applies to a segment with a colon character (e.g., "file:colon") that cannot be used
        // as the first segment of a relative-path reference, as it would be mistaken for a scheme name
        // (see https://tools.ietf.org/html/rfc3986#section-4.2).
        return !isset($path[0]) || \'/\' === $path[0]
            || false !== ($colonPos = strpos($path, \':\')) && ($colonPos < ($slashPos = strpos($path, \'/\')) || false === $slashPos)
            ? "./$path" : $path;
    }

    /**
     * Generates the normalized query string for the Request.
     *
     * It builds a normalized query string, where keys/value pairs are alphabetized
     * and have consistent escaping.
     */
    public function getQueryString(): ?string
    {
        $qs = static::normalizeQueryString($this->server->get(\'QUERY_STRING\'));

        return \'\' === $qs ? null : $qs;
    }

    /**
     * Checks whether the request is secure or not.
     *
     * This method can read the client protocol from the "X-Forwarded-Proto" header
     * when trusted proxies were set via "setTrustedProxies()".
     *
     * The "X-Forwarded-Proto" header must contain the protocol: "https" or "http".
     */
    public function isSecure(): bool
    {
        if ($this->isFromTrustedProxy() && $proto = $this->getTrustedValues(self::HEADER_X_FORWARDED_PROTO)) {
            return \\in_array(strtolower($proto[0]), [\'https\', \'on\', \'ssl\', \'1\'], true);
        }

        $https = $this->server->get(\'HTTPS\');

        return $https && (!\\is_string($https) || \'off\' !== strtolower($https));
    }

    /**
     * Returns the host name.
     *
     * This method can read the client host name from the "X-Forwarded-Host" header
     * when trusted proxies were set via "setTrustedProxies()".
     *
     * The "X-Forwarded-Host" header must contain the client host name.
     *
     * @throws SuspiciousOperationException when the host name is invalid or not trusted
     */
    public function getHost(): string
    {
        if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {
            $host = $host[0];
        } else {
            $host = $this->headers->get(\'HOST\') ?: $this->server->get(\'SERVER_NAME\') ?: $this->server->get(\'SERVER_ADDR\', \'\');
        }

        // trim and remove port number from host
        // host is lowercase as per RFC 952/2181
        $host = strtolower(preg_replace(\'/:\\d+$/\', \'\', trim($host)));

        // the host can come from the user (HTTP_HOST and depending on the configuration, SERVER_NAME too can come from the user)
        if ($host && !self::isHostValid($host)) {
            if (!$this->isHostValid) {
                return \'\';
            }
            $this->isHostValid = false;

            throw new SuspiciousOperationException(\\sprintf(\'Invalid Host "%s".\', $host));
        }

        if (self::$trustedHostsRegexp) {
            // to avoid host header injection attacks, you should provide a list of trusted host patterns

            if (preg_match(self::$trustedHostsRegexp, $host)) {
                return $host;
            }

            if (!$this->isHostValid) {
                return \'\';
            }
            $this->isHostValid = false;

            throw new SuspiciousOperationException(\\sprintf(\'Untrusted Host "%s".\', $host));
        }

        return $host;
    }

    /**
     * Sets the request method.
     */
    public function setMethod(string $method): void
    {
        $this->method = null;
        $this->server->set(\'REQUEST_METHOD\', $method);
    }

    /**
     * Gets the request "intended" method.
     *
     * If the X-HTTP-Method-Override header is set, and if the method is a POST,
     * then it is used to determine the "real" intended HTTP method.
     *
     * The _method request parameter can also be used to determine the HTTP method,
     * but only if enableHttpMethodParameterOverride() has been called.
     *
     * The method is always an uppercased string.
     *
     * @see getRealMethod()
     */
    public function getMethod(): string
    {
        if (null !== $this->method) {
            return $this->method;
        }

        $this->method = strtoupper($this->server->get(\'REQUEST_METHOD\', \'GET\'));

        if (\'POST\' !== $this->method || !(self::$allowedHttpMethodOverride ?? true)) {
            return $this->method;
        }

        $method = $this->headers->get(\'X-HTTP-METHOD-OVERRIDE\');

        if (!$method && self::$httpMethodParameterOverride) {
            $method = $this->request->get(\'_method\', $this->query->get(\'_method\', \'POST\'));
        }

        if (!\\is_string($method)) {
            return $this->method;
        }

        $method = strtoupper($method);

        if (\\in_array($method, [\'GET\', \'HEAD\', \'CONNECT\', \'TRACE\'], true)) {
            return $this->method;
        }

        if (self::$allowedHttpMethodOverride && !\\in_array($method, self::$allowedHttpMethodOverride, true)) {
            return $this->method;
        }

        if (\\strlen($method) !== strspn($method, \'ABCDEFGHIJKLMNOPQRSTUVWXYZ\')) {
            throw new SuspiciousOperationException(\'Invalid HTTP method override.\');
        }

        return $this->method = $method;
    }

    /**
     * Gets the "real" request method.
     *
     * @see getMethod()
     */
    public function getRealMethod(): string
    {
        return strtoupper($this->server->get(\'REQUEST_METHOD\', \'GET\'));
    }

    /**
     * Gets the mime type associated with the format.
     */
    public function getMimeType(string $format): ?string
    {
        if (null === static::$formats) {
            static::initializeFormats();
        }

        return isset(static::$formats[$format]) ? static::$formats[$format][0] : null;
    }

    /**
     * Gets the mime types associated with the format.
     *
     * @return string[]
     */
    public static function getMimeTypes(string $format): array
    {
        if (null === static::$formats) {
            static::initializeFormats();
        }

        return static::$formats[$format] ?? [];
    }

    /**
     * Gets the format associated with the mime type.
     *
     *  Resolution order:
     *   1) Exact match on the full MIME type (e.g. "application/json").
     *   2) Match on the canonical MIME type (i.e. before the first ";" parameter).
     *   3) If the type is "application/*+suffix", use the structured syntax suffix
     *      mapping (e.g. "application/foo+json" → "json"), when available.
     *   4) If $subtypeFallback is true and no match was found:
     *      - return the MIME subtype (without "x-" prefix), provided it does not
     *        contain a "+" (e.g. "application/x-yaml" → "yaml", "text/csv" → "csv").
     *
     * @param string|null $mimeType        The mime type to check
     * @param bool        $subtypeFallback Whether to fall back to the subtype if no exact match is found
     */
    public function getFormat(?string $mimeType, bool $subtypeFallback = false): ?string
    {
        $canonicalMimeType = null;
        if ($mimeType && false !== $pos = strpos($mimeType, \';\')) {
            $canonicalMimeType = trim(substr($mimeType, 0, $pos));
        }

        if (null === static::$formats) {
            static::initializeFormats();
        }

        $exactFormat = null;
        $canonicalFormat = null;

        foreach (static::$formats as $format => $mimeTypes) {
            if (\\in_array($mimeType, $mimeTypes, true)) {
                $exactFormat = $format;
            }
            if (null !== $canonicalMimeType && \\in_array($canonicalMimeType, $mimeTypes, true)) {
                $canonicalFormat = $format;
            }
        }

        if ($format = $exactFormat ?? $canonicalFormat) {
            return $format;
        }

        if (!$canonicalMimeType ??= $mimeType) {
            return null;
        }

        if (str_starts_with($canonicalMimeType, \'application/\') && str_contains($canonicalMimeType, \'+\')) {
            $suffix = substr(strrchr($canonicalMimeType, \'+\'), 1);
            if (isset(self::STRUCTURED_SUFFIX_FORMATS[$suffix])) {
                return self::STRUCTURED_SUFFIX_FORMATS[$suffix];
            }
        }

        if ($subtypeFallback && str_contains($canonicalMimeType, \'/\')) {
            [, $subtype] = explode(\'/\', $canonicalMimeType, 2);
            if (str_starts_with($subtype, \'x-\')) {
                $subtype = substr($subtype, 2);
            }
            if (!str_contains($subtype, \'+\')) {
                return $subtype;
            }
        }

        return null;
    }

    /**
     * Associates a format with mime types.
     *
     * @param string|string[] $mimeTypes The associated mime types (the preferred one must be the first as it will be used as the content type)
     */
    public function setFormat(string $format, string|array $mimeTypes): void
    {
        if (null === static::$formats) {
            static::initializeFormats();
        }

        static::$formats[$format] = (array) $mimeTypes;
    }

    /**
     * Gets the request format.
     *
     * Here is the process to determine the format:
     *
     *  * format defined by the user (with setRequestFormat())
     *  * _format request attribute
     *  * $default
     *
     * @see getPreferredFormat
     */
    public function getRequestFormat(?string $default = \'html\'): ?string
    {
        $this->format ??= $this->attributes->get(\'_format\');

        return $this->format ?? $default;
    }

    /**
     * Sets the request format.
     */
    public function setRequestFormat(?string $format): void
    {
        $this->format = $format;
    }

    /**
     * Gets the usual name of the format associated with the request\'s media type (provided in the Content-Type header).
     *
     * @see Request::$formats
     */
    public function getContentTypeFormat(): ?string
    {
        return $this->getFormat($this->headers->get(\'CONTENT_TYPE\', \'\'));
    }

    /**
     * Sets the default locale.
     */
    public function setDefaultLocale(string $locale): void
    {
        $this->defaultLocale = $locale;

        if (null === $this->locale) {
            $this->setPhpDefaultLocale($locale);
        }
    }

    /**
     * Get the default locale.
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Sets the locale.
     */
    public function setLocale(string $locale): void
    {
        $this->setPhpDefaultLocale($this->locale = $locale);
    }

    /**
     * Get the locale.
     */
    public function getLocale(): string
    {
        return $this->locale ?? $this->defaultLocale;
    }

    /**
     * Checks if the request method is of specified type.
     *
     * @param string $method Uppercase request method (GET, POST etc)
     */
    public function isMethod(string $method): bool
    {
        return $this->getMethod() === strtoupper($method);
    }

    /**
     * Checks whether or not the method is safe.
     *
     * @see https://tools.ietf.org/html/rfc7231#section-4.2.1
     */
    public function isMethodSafe(): bool
    {
        return \\in_array($this->getMethod(), [\'GET\', \'HEAD\', \'OPTIONS\', \'TRACE\', \'QUERY\'], true);
    }

    /**
     * Checks whether or not the method is idempotent.
     */
    public function isMethodIdempotent(): bool
    {
        return \\in_array($this->getMethod(), [\'HEAD\', \'GET\', \'PUT\', \'DELETE\', \'TRACE\', \'OPTIONS\', \'PURGE\', \'QUERY\'], true);
    }

    /**
     * Checks whether the method is cacheable or not.
     *
     * @see https://tools.ietf.org/html/rfc7231#section-4.2.3
     */
    public function isMethodCacheable(): bool
    {
        return \\in_array($this->getMethod(), [\'GET\', \'HEAD\', \'QUERY\'], true);
    }

    /**
     * Returns the protocol version.
     *
     * If the application is behind a proxy, the protocol version used in the
     * requests between the client and the proxy and between the proxy and the
     * server might be different. This returns the former (from the "Via" header)
     * if the proxy is trusted (see "setTrustedProxies()"), otherwise it returns
     * the latter (from the "SERVER_PROTOCOL" server parameter).
     */
    public function getProtocolVersion(): ?string
    {
        if ($this->isFromTrustedProxy()) {
            preg_match(\'~^(HTTP/)?([1-9]\\.[0-9])\\b~\', $this->headers->get(\'Via\') ?? \'\', $matches);

            if ($matches) {
                return \'HTTP/\'.$matches[2];
            }
        }

        return $this->server->get(\'SERVER_PROTOCOL\');
    }

    /**
     * Returns the request body content.
     *
     * @param bool $asResource If true, a resource will be returned
     *
     * @return string|resource
     *
     * @psalm-return ($asResource is true ? resource : string)
     */
    public function getContent(bool $asResource = false)
    {
        if ($asResource) {
            if (\\is_resource($this->content)) {
                rewind($this->content);

                return $this->content;
            }

            // Content passed in parameter (test)
            if (\\is_string($this->content)) {
                $resource = fopen(\'php://temp\', \'r+\');
                fwrite($resource, $this->content);
                rewind($resource);

                return $resource;
            }

            $this->content = false;

            return fopen(\'php://input\', \'r\');
        }

        if (\\is_resource($this->content)) {
            rewind($this->content);

            return stream_get_contents($this->content);
        }

        if (null === $this->content || false === $this->content) {
            $this->content = file_get_contents(\'php://input\');
        }

        return $this->content;
    }

    /**
     * Gets the decoded form or json request body.
     *
     * @throws JsonException When the body cannot be decoded to an array
     */
    public function getPayload(): InputBag
    {
        if ($this->request->count()) {
            return clone $this->request;
        }

        if (\'\' === $content = $this->getContent()) {
            return new InputBag([]);
        }

        try {
            $content = json_decode($content, true, 512, \\JSON_BIGINT_AS_STRING | \\JSON_THROW_ON_ERROR);
        } catch (\\JsonException $e) {
            throw new JsonException(\'Could not decode request body.\', $e->getCode(), $e);
        }

        if (!\\is_array($content)) {
            throw new JsonException(\\sprintf(\'JSON content was expected to decode to an array, "%s" returned.\', get_debug_type($content)));
        }

        return new InputBag($content);
    }

    /**
     * Gets the request body decoded as array, typically from a JSON payload.
     *
     * @see getPayload() for portability between content types
     *
     * @throws JsonException When the body cannot be decoded to an array
     */
    public function toArray(): array
    {
        if (\'\' === $content = $this->getContent()) {
            throw new JsonException(\'Request body is empty.\');
        }

        try {
            $content = json_decode($content, true, 512, \\JSON_BIGINT_AS_STRING | \\JSON_THROW_ON_ERROR);
        } catch (\\JsonException $e) {
            throw new JsonException(\'Could not decode request body.\', $e->getCode(), $e);
        }

        if (!\\is_array($content)) {
            throw new JsonException(\\sprintf(\'JSON content was expected to decode to an array, "%s" returned.\', get_debug_type($content)));
        }

        return $content;
    }

    /**
     * Gets the Etags.
     */
    public function getETags(): array
    {
        return preg_split(\'/\\s*,\\s*/\', $this->headers->get(\'If-None-Match\', \'\'), -1, \\PREG_SPLIT_NO_EMPTY);
    }

    public function isNoCache(): bool
    {
        return $this->headers->hasCacheControlDirective(\'no-cache\') || \'no-cache\' == $this->headers->get(\'Pragma\');
    }

    /**
     * Gets the preferred format for the response by inspecting, in the following order:
     *   * the request format set using setRequestFormat;
     *   * the values of the Accept HTTP header.
     *
     * Note that if you use this method, you should send the "Vary: Accept" header
     * in the response to prevent any issues with intermediary HTTP caches.
     */
    public function getPreferredFormat(?string $default = \'html\'): ?string
    {
        if (!isset($this->preferredFormat) && null !== $preferredFormat = $this->getRequestFormat(null)) {
            $this->preferredFormat = $preferredFormat;
        }

        if ($this->preferredFormat ?? null) {
            return $this->preferredFormat;
        }

        foreach ($this->getAcceptableContentTypes() as $mimeType) {
            if ($this->preferredFormat = $this->getFormat($mimeType)) {
                return $this->preferredFormat;
            }
        }

        return $default;
    }

    /**
     * Returns the preferred language.
     *
     * @param string[] $locales An array of ordered available locales
     */
    public function getPreferredLanguage(?array $locales = null): ?string
    {
        $preferredLanguages = $this->getLanguages();

        if (!$locales) {
            return $preferredLanguages[0] ?? null;
        }

        $locales = array_map($this->formatLocale(...), $locales);
        if (!$preferredLanguages) {
            return $locales[0];
        }

        $combinations = array_merge(...array_map($this->getLanguageCombinations(...), $preferredLanguages));
        foreach ($combinations as $combination) {
            foreach ($locales as $locale) {
                if (str_starts_with($locale, $combination)) {
                    return $locale;
                }
            }
        }

        return $locales[0];
    }

    /**
     * Gets a list of languages acceptable by the client browser ordered in the user browser preferences.
     *
     * @return string[]
     */
    public function getLanguages(): array
    {
        if (null !== $this->languages) {
            return $this->languages;
        }

        $languages = AcceptHeader::fromString($this->headers->get(\'Accept-Language\'))->all();
        $this->languages = [];
        foreach ($languages as $acceptHeaderItem) {
            $lang = $acceptHeaderItem->getValue();
            $this->languages[] = self::formatLocale($lang);
        }
        $this->languages = array_unique($this->languages);

        return $this->languages;
    }

    /**
     * Strips the locale to only keep the canonicalized language value.
     *
     * Depending on the $locale value, this method can return values like :
     * - language_Script_REGION: "fr_Latn_FR", "zh_Hans_TW"
     * - language_Script: "fr_Latn", "zh_Hans"
     * - language_REGION: "fr_FR", "zh_TW"
     * - language: "fr", "zh"
     *
     * Invalid locale values are returned as is.
     *
     * @see https://wikipedia.org/wiki/IETF_language_tag
     * @see https://datatracker.ietf.org/doc/html/rfc5646
     */
    private static function formatLocale(string $locale): string
    {
        [$language, $script, $region] = self::getLanguageComponents($locale);

        return implode(\'_\', array_filter([$language, $script, $region]));
    }

    /**
     * Returns an array of all possible combinations of the language components.
     *
     * For instance, if the locale is "fr_Latn_FR", this method will return:
     * - "fr_Latn_FR"
     * - "fr_Latn"
     * - "fr_FR"
     * - "fr"
     *
     * @return string[]
     */
    private static function getLanguageCombinations(string $locale): array
    {
        [$language, $script, $region] = self::getLanguageComponents($locale);

        return array_unique([
            implode(\'_\', array_filter([$language, $script, $region])),
            implode(\'_\', array_filter([$language, $script])),
            implode(\'_\', array_filter([$language, $region])),
            $language,
        ]);
    }

    /**
     * Returns an array with the language components of the locale.
     *
     * For example:
     * - If the locale is "fr_Latn_FR", this method will return "fr", "Latn", "FR"
     * - If the locale is "fr_FR", this method will return "fr", null, "FR"
     * - If the locale is "zh_Hans", this method will return "zh", "Hans", null
     *
     * @see https://wikipedia.org/wiki/IETF_language_tag
     * @see https://datatracker.ietf.org/doc/html/rfc5646
     *
     * @return array{string, string|null, string|null}
     */
    private static function getLanguageComponents(string $locale): array
    {
        $locale = str_replace(\'_\', \'-\', strtolower($locale));
        $pattern = \'/^([a-zA-Z]{2,3}|i-[a-zA-Z]{5,})(?:-([a-zA-Z]{4}))?(?:-([a-zA-Z]{2}))?(?:-(.+))?$/\';
        if (!preg_match($pattern, $locale, $matches)) {
            return [$locale, null, null];
        }
        if (str_starts_with($matches[1], \'i-\')) {
            // Language not listed in ISO 639 that are not variants
            // of any listed language, which can be registered with the
            // i-prefix, such as i-cherokee
            $matches[1] = substr($matches[1], 2);
        }

        return [
            $matches[1],
            isset($matches[2]) ? ucfirst(strtolower($matches[2])) : null,
            isset($matches[3]) ? strtoupper($matches[3]) : null,
        ];
    }

    /**
     * Gets a list of charsets acceptable by the client browser in preferable order.
     *
     * @return string[]
     */
    public function getCharsets(): array
    {
        return $this->charsets ??= array_map(\'strval\', array_keys(AcceptHeader::fromString($this->headers->get(\'Accept-Charset\'))->all()));
    }

    /**
     * Gets a list of encodings acceptable by the client browser in preferable order.
     *
     * @return string[]
     */
    public function getEncodings(): array
    {
        return $this->encodings ??= array_map(\'strval\', array_keys(AcceptHeader::fromString($this->headers->get(\'Accept-Encoding\'))->all()));
    }

    /**
     * Gets a list of content types acceptable by the client browser in preferable order.
     *
     * @return string[]
     */
    public function getAcceptableContentTypes(): array
    {
        return $this->acceptableContentTypes ??= array_map(\'strval\', array_keys(AcceptHeader::fromString($this->headers->get(\'Accept\'))->all()));
    }

    /**
     * Returns true if the request is an XMLHttpRequest.
     *
     * It works if your JavaScript library sets an X-Requested-With HTTP header.
     * It is known to work with common JavaScript frameworks:
     *
     * @see https://wikipedia.org/wiki/List_of_Ajax_frameworks#JavaScript
     */
    public function isXmlHttpRequest(): bool
    {
        return \'XMLHttpRequest\' == $this->headers->get(\'X-Requested-With\');
    }

    /**
     * Checks whether the client browser prefers safe content or not according to RFC8674.
     *
     * @see https://tools.ietf.org/html/rfc8674
     */
    public function preferSafeContent(): bool
    {
        if (isset($this->isSafeContentPreferred)) {
            return $this->isSafeContentPreferred;
        }

        if (!$this->isSecure()) {
            // see https://tools.ietf.org/html/rfc8674#section-3
            return $this->isSafeContentPreferred = false;
        }

        return $this->isSafeContentPreferred = AcceptHeader::fromString($this->headers->get(\'Prefer\'))->has(\'safe\');
    }

    /*
     * The following methods are derived from code of the Zend Framework (1.10dev - 2010-01-24)
     *
     * Code subject to the new BSD license (https://framework.zend.com/license).
     *
     * Copyright (c) 2005-2010 Zend Technologies USA Inc. (https://www.zend.com/)
     */

    protected function prepareRequestUri(): string
    {
        $requestUri = \'\';

        if ($this->isIisRewrite() && \'\' != $this->server->get(\'UNENCODED_URL\')) {
            // IIS7 with URL Rewrite: make sure we get the unencoded URL (double slash problem)
            $requestUri = $this->server->get(\'UNENCODED_URL\');
            $this->server->remove(\'UNENCODED_URL\');
        } elseif ($this->server->has(\'REQUEST_URI\')) {
            $requestUri = $this->server->get(\'REQUEST_URI\');

            if (\'\' !== $requestUri && \'/\' === $requestUri[0]) {
                // To only use path and query remove the fragment.
                if (false !== $pos = strpos($requestUri, \'#\')) {
                    $requestUri = substr($requestUri, 0, $pos);
                }
            } else {
                // HTTP proxy reqs setup request URI with scheme and host [and port] + the URL path,
                // only use URL path.
                $uriComponents = parse_url($requestUri);

                if (isset($uriComponents[\'path\'])) {
                    $requestUri = $uriComponents[\'path\'];
                }

                if (isset($uriComponents[\'query\'])) {
                    $requestUri .= \'?\'.$uriComponents[\'query\'];
                }
            }
        } elseif ($this->server->has(\'ORIG_PATH_INFO\')) {
            // IIS 5.0, PHP as CGI
            $requestUri = $this->server->get(\'ORIG_PATH_INFO\');
            if (\'\' != $this->server->get(\'QUERY_STRING\')) {
                $requestUri .= \'?\'.$this->server->get(\'QUERY_STRING\');
            }
            $this->server->remove(\'ORIG_PATH_INFO\');
        }

        // normalize the request URI to ease creating sub-requests from this request
        $this->server->set(\'REQUEST_URI\', $requestUri);

        return $requestUri;
    }

    /**
     * Prepares the base URL.
     */
    protected function prepareBaseUrl(): string
    {
        $filename = basename($this->server->get(\'SCRIPT_FILENAME\', \'\'));

        if (basename($this->server->get(\'SCRIPT_NAME\', \'\')) === $filename) {
            $baseUrl = $this->server->get(\'SCRIPT_NAME\');
        } elseif (basename($this->server->get(\'PHP_SELF\', \'\')) === $filename) {
            $baseUrl = $this->server->get(\'PHP_SELF\');
        } elseif (basename($this->server->get(\'ORIG_SCRIPT_NAME\', \'\')) === $filename) {
            $baseUrl = $this->server->get(\'ORIG_SCRIPT_NAME\'); // 1and1 shared hosting compatibility
        } else {
            // Backtrack up the script_filename to find the portion matching
            // php_self
            $path = $this->server->get(\'PHP_SELF\', \'\');
            $file = $this->server->get(\'SCRIPT_FILENAME\', \'\');
            $segs = explode(\'/\', trim($file, \'/\'));
            $segs = array_reverse($segs);
            $index = 0;
            $last = \\count($segs);
            $baseUrl = \'\';
            do {
                $seg = $segs[$index];
                $baseUrl = \'/\'.$seg.$baseUrl;
                ++$index;
            } while ($last > $index && (false !== $pos = strpos($path, $baseUrl)) && 0 != $pos);
        }

        // Does the baseUrl have anything in common with the request_uri?
        $requestUri = $this->getRequestUri();
        if (\'\' !== $requestUri && \'/\' !== $requestUri[0]) {
            $requestUri = \'/\'.$requestUri;
        }

        if ($baseUrl && null !== $prefix = $this->getUrlencodedPrefix($requestUri, $baseUrl)) {
            // full $baseUrl matches
            return $prefix;
        }

        if ($baseUrl && null !== $prefix = $this->getUrlencodedPrefix($requestUri, rtrim(\\dirname($baseUrl), \'/\'.\\DIRECTORY_SEPARATOR).\'/\')) {
            // directory portion of $baseUrl matches
            return rtrim($prefix, \'/\'.\\DIRECTORY_SEPARATOR);
        }

        $truncatedRequestUri = $requestUri;
        if (false !== $pos = strpos($requestUri, \'?\')) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }

        $basename = basename($baseUrl ?? \'\');
        if (!$basename || !strpos(rawurldecode($truncatedRequestUri), $basename)) {
            // no match whatsoever; set it blank
            return \'\';
        }

        // If using mod_rewrite or ISAPI_Rewrite strip the script filename
        // out of baseUrl. $pos !== 0 makes sure it is not matching a value
        // from PATH_INFO or QUERY_STRING
        if (\\strlen($requestUri) >= \\strlen($baseUrl) && (false !== $pos = strpos($requestUri, $baseUrl)) && 0 !== $pos) {
            $baseUrl = substr($requestUri, 0, $pos + \\strlen($baseUrl));
        }

        return rtrim($baseUrl, \'/\'.\\DIRECTORY_SEPARATOR);
    }

    /**
     * Prepares the base path.
     */
    protected function prepareBasePath(): string
    {
        $baseUrl = $this->getBaseUrl();
        if (!$baseUrl) {
            return \'\';
        }

        $filename = basename($this->server->get(\'SCRIPT_FILENAME\'));
        if (basename($baseUrl) === $filename) {
            $basePath = \\dirname($baseUrl);
        } else {
            $basePath = $baseUrl;
        }

        if (\'\\\\\' === \\DIRECTORY_SEPARATOR) {
            $basePath = str_replace(\'\\\\\', \'/\', $basePath);
        }

        return rtrim($basePath, \'/\');
    }

    /**
     * Prepares the path info.
     */
    protected function preparePathInfo(): string
    {
        if (null === ($requestUri = $this->getRequestUri())) {
            return \'/\';
        }

        // Remove the query string from REQUEST_URI
        if (false !== $pos = strpos($requestUri, \'?\')) {
            $requestUri = substr($requestUri, 0, $pos);
        }
        if (\'\' !== $requestUri && \'/\' !== $requestUri[0]) {
            $requestUri = \'/\'.$requestUri;
        }

        if (null === ($baseUrl = $this->getBaseUrlReal())) {
            return $requestUri;
        }

        $pathInfo = substr($requestUri, \\strlen($baseUrl));
        if (\'\' === $pathInfo || \'/\' !== $pathInfo[0]) {
            return \'/\'.$pathInfo;
        }

        return $pathInfo;
    }

    /**
     * Initializes HTTP request formats.
     */
    protected static function initializeFormats(): void
    {
        static::$formats = [
            \'html\' => [\'text/html\', \'application/xhtml+xml\'],
            \'txt\' => [\'text/plain\'],
            \'js\' => [\'application/javascript\', \'application/x-javascript\', \'text/javascript\'],
            \'css\' => [\'text/css\'],
            \'json\' => [\'application/json\', \'application/x-json\'],
            \'jsonld\' => [\'application/ld+json\'],
            \'xml\' => [\'text/xml\', \'application/xml\', \'application/x-xml\'],
            \'rdf\' => [\'application/rdf+xml\'],
            \'atom\' => [\'application/atom+xml\'],
            \'rss\' => [\'application/rss+xml\'],
            \'form\' => [\'application/x-www-form-urlencoded\', \'multipart/form-data\'],
            \'soap\' => [\'application/soap+xml\'],
            \'problem\' => [\'application/problem+json\'],
            \'hal\' => [\'application/hal+json\', \'application/hal+xml\'],
            \'jsonapi\' => [\'application/vnd.api+json\'],
            \'yaml\' => [\'text/yaml\', \'application/x-yaml\'],
            \'wbxml\' => [\'application/vnd.wap.wbxml\'],
            \'pdf\' => [\'application/pdf\'],
            \'csv\' => [\'text/csv\'],
        ];
    }

    private function setPhpDefaultLocale(string $locale): void
    {
        // if either the class Locale doesn\'t exist, or an exception is thrown when
        // setting the default locale, the intl module is not installed, and
        // the call can be ignored:
        try {
            if (class_exists(\\Locale::class, false)) {
                \\Locale::setDefault($locale);
            }
        } catch (\\Exception) {
        }
    }

    /**
     * Returns the prefix as encoded in the string when the string starts with
     * the given prefix, null otherwise.
     */
    private function getUrlencodedPrefix(string $string, string $prefix): ?string
    {
        if ($this->isIisRewrite()) {
            // ISS with UrlRewriteModule might report SCRIPT_NAME/PHP_SELF with wrong case
            // see https://github.com/php/php-src/issues/11981
            if (0 !== stripos(rawurldecode($string), $prefix)) {
                return null;
            }
        } elseif (!str_starts_with(rawurldecode($string), $prefix)) {
            return null;
        }

        $len = \\strlen($prefix);

        if (preg_match(\\sprintf(\'#^(%%[[:xdigit:]]{2}|.){%d}#\', $len), $string, $match)) {
            return $match[0];
        }

        return null;
    }

    private static function createRequestFromFactory(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null): static
    {
        if (self::$requestFactory) {
            $request = (self::$requestFactory)($query, $request, $attributes, $cookies, $files, $server, $content);

            if (!$request instanceof self) {
                throw new \\LogicException(\'The Request factory must return an instance of Symfony\\Component\\HttpFoundation\\Request.\');
            }

            return $request;
        }

        return new static($query, $request, $attributes, $cookies, $files, $server, $content);
    }

    /**
     * Indicates whether this request originated from a trusted proxy.
     *
     * This can be useful to determine whether or not to trust the
     * contents of a proxy-specific header.
     */
    public function isFromTrustedProxy(): bool
    {
        return self::$trustedProxies && IpUtils::checkIp($this->server->get(\'REMOTE_ADDR\', \'\'), self::$trustedProxies);
    }

    /**
     * This method is rather heavy because it splits and merges headers, and it\'s called by many other methods such as
     * getPort(), isSecure(), getHost(), getClientIps(), getBaseUrl() etc. Thus, we try to cache the results for
     * best performance.
     */
    private function getTrustedValues(int $type, ?string $ip = null): array
    {
        $cacheKey = $type."\\0".((self::$trustedHeaderSet & $type) ? $this->headers->get(self::TRUSTED_HEADERS[$type]) : \'\');
        $cacheKey .= "\\0".$ip."\\0".$this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);

        if (isset($this->trustedValuesCache[$cacheKey])) {
            return $this->trustedValuesCache[$cacheKey];
        }

        $clientValues = [];
        $forwardedValues = [];

        if ((self::$trustedHeaderSet & $type) && $this->headers->has(self::TRUSTED_HEADERS[$type])) {
            foreach (explode(\',\', $this->headers->get(self::TRUSTED_HEADERS[$type])) as $v) {
                $clientValues[] = (self::HEADER_X_FORWARDED_PORT === $type ? \'0.0.0.0:\' : \'\').trim($v);
            }
        }

        if ((self::$trustedHeaderSet & self::HEADER_FORWARDED) && (isset(self::FORWARDED_PARAMS[$type])) && $this->headers->has(self::TRUSTED_HEADERS[self::HEADER_FORWARDED])) {
            $forwarded = $this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);
            $parts = HeaderUtils::split($forwarded, \',;=\');
            $param = self::FORWARDED_PARAMS[$type];
            foreach ($parts as $subParts) {
                if (null === $v = HeaderUtils::combine($subParts)[$param] ?? null) {
                    continue;
                }
                if (self::HEADER_X_FORWARDED_PORT === $type) {
                    if (str_ends_with($v, \']\') || false === $v = strrchr($v, \':\')) {
                        $v = $this->isSecure() ? \':443\' : \':80\';
                    }
                    $v = \'0.0.0.0\'.$v;
                }
                $forwardedValues[] = $v;
            }
        }

        if (null !== $ip) {
            $clientValues = $this->normalizeAndFilterClientIps($clientValues, $ip);
            $forwardedValues = $this->normalizeAndFilterClientIps($forwardedValues, $ip);
        }

        if ($forwardedValues === $clientValues || !$clientValues) {
            return $this->trustedValuesCache[$cacheKey] = $forwardedValues;
        }

        if (!$forwardedValues) {
            return $this->trustedValuesCache[$cacheKey] = $clientValues;
        }

        if (!$this->isForwardedValid) {
            return $this->trustedValuesCache[$cacheKey] = null !== $ip ? [\'0.0.0.0\', $ip] : [];
        }
        $this->isForwardedValid = false;

        throw new ConflictingHeadersException(\\sprintf(\'The request has both a trusted "%s" header and a trusted "%s" header, conflicting with each other. You should either configure your proxy to remove one of them, or configure your project to distrust the offending one.\', self::TRUSTED_HEADERS[self::HEADER_FORWARDED], self::TRUSTED_HEADERS[$type]));
    }

    private function normalizeAndFilterClientIps(array $clientIps, string $ip): array
    {
        if (!$clientIps) {
            return [];
        }
        $clientIps[] = $ip; // Complete the IP chain with the IP the request actually came from
        $firstTrustedIp = null;

        foreach ($clientIps as $key => $clientIp) {
            if (strpos($clientIp, \'.\')) {
                // Strip :port from IPv4 addresses. This is allowed in Forwarded
                // and may occur in X-Forwarded-For.
                $i = strpos($clientIp, \':\');
                if ($i) {
                    $clientIps[$key] = $clientIp = substr($clientIp, 0, $i);
                }
            } elseif (str_starts_with($clientIp, \'[\')) {
                // Strip brackets and :port from IPv6 addresses.
                $i = strpos($clientIp, \']\', 1);
                $clientIps[$key] = $clientIp = substr($clientIp, 1, $i - 1);
            }

            if (!filter_var($clientIp, \\FILTER_VALIDATE_IP)) {
                unset($clientIps[$key]);

                continue;
            }

            if (IpUtils::checkIp($clientIp, self::$trustedProxies)) {
                unset($clientIps[$key]);

                // Fallback to this when the client IP falls into the range of trusted proxies
                $firstTrustedIp ??= $clientIp;
            }
        }

        // Now the IP chain contains only untrusted proxies and the client IP
        return $clientIps ? array_reverse($clientIps) : [$firstTrustedIp];
    }

    /**
     * Is this IIS with UrlRewriteModule?
     *
     * This method consumes, caches and removed the IIS_WasUrlRewritten env var,
     * so we don\'t inherit it to sub-requests.
     */
    private function isIisRewrite(): bool
    {
        if (1 === $this->server->getInt(\'IIS_WasUrlRewritten\')) {
            $this->isIisRewrite = true;
            $this->server->remove(\'IIS_WasUrlRewritten\');
        }

        return $this->isIisRewrite;
    }

    /**
     * See https://url.spec.whatwg.org/.
     */
    private static function isHostValid(string $host): bool
    {
        if (\'[\' === $host[0]) {
            return \']\' === $host[-1] && filter_var(substr($host, 1, -1), \\FILTER_VALIDATE_IP, \\FILTER_FLAG_IPV6);
        }

        if (preg_match(\'/\\.[0-9]++\\.?$/D\', $host)) {
            return null !== filter_var($host, \\FILTER_VALIDATE_IP, \\FILTER_FLAG_IPV4 | \\FILTER_NULL_ON_FAILURE);
        }

        // use preg_replace() instead of preg_match() to prevent DoS attacks with long host names
        return \'\' === preg_replace(\'/[-a-zA-Z0-9_]++\\.?/\', \'\', $host);
    }

    private static function setProperty(self $request, string $name, mixed $value): void
    {
        static $cache;

        $r = $cache[$name] ??= new \\ReflectionProperty(self::class, $name);

        $r->setRawValue($request, $value);
    }
}
',
);
