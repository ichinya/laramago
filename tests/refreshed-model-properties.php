<?php

declare(strict_types=1);

// Analyze invented declarations; no application bootstrap or database is executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago refreshed properties '.bin2hex(random_bytes(8));
mkdir($workspace);
$vendor = 'packages';
file_put_contents($workspace.'/composer.json', json_encode(['config' => ['vendor-dir' => $vendor], 'autoload' => ['files' => ['bootstrap.php']], 'extra' => ['laramago' => new stdClass]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bootstrap"); throw new RuntimeException("Application bootstrap executed.");');
// Native Laravel v13.31.0 excerpts: copyright Taylor Otwell, MIT.
// Full terms: fixtures/analysis/factory-result-LICENSE.md.
$nativeSources = [
    'laravel/framework/src/Illuminate/Database/Eloquent/Model.php' => <<<'NATIVE_Model'
<?php
namespace Illuminate\Database\Eloquent;
use ArrayAccess;
use Closure;
use Exception;
use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\CanBeEscapedWhenCastToString;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope as LocalScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable as SupportStringable;
use Illuminate\Support\Traits\ForwardsCalls;
use JsonException;
use JsonSerializable;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use function Illuminate\Support\enum_value;
abstract class Model {
    /** The name of the "created at" column. @var string|null */
    const CREATED_AT = 'created_at';
    /** The name of the "updated at" column. @var string|null */
    const UPDATED_AT = 'updated_at';
use \Illuminate\Database\Eloquent\Concerns\HasAttributes;
use \Illuminate\Database\Eloquent\Concerns\PreventsCircularRecursion;
use \Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use \Illuminate\Database\Eloquent\Concerns\HasRelationships;
use \Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use \Illuminate\Database\Eloquent\Concerns\HasEvents;
/**
     * The connection name for the model.
     *
     * @var \UnitEnum|string|null
     */
    protected $connection;
/**
     * The table associated with the model.
     *
     * @var string|null
     */
    protected $table;
/**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';
/**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';
/**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;
/**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = [];
/**
     * The relationship counts that should be eager loaded on every query.
     *
     * @var array
     */
    protected $withCount = [];
/**
     * Indicates whether lazy loading will be prevented on this model.
     *
     * @var bool
     */
    public $preventsLazyLoading = false;
/**
     * The number of models to return for pagination.
     *
     * @var int
     */
    protected $perPage = 15;
/**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;
/**
     * Indicates if the model was inserted during the object's lifecycle.
     *
     * @var bool
     */
    public $wasRecentlyCreated = false;
/**
     * Indicates that the object's string representation should be escaped when __toString is invoked.
     *
     * @var bool
     */
    protected $escapeWhenCastingToString = false;
/**
     * The connection resolver instance.
     *
     * @var \Illuminate\Database\ConnectionResolverInterface
     */
    protected static $resolver;
/**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher|null
     */
    protected static $dispatcher;
/**
     * The models that are currently being booted.
     *
     * @var array
     */
    protected static $booting = [];
/**
     * The array of booted models.
     *
     * @var array
     */
    protected static $booted = [];
/**
     * The callbacks that should be executed after the model has booted.
     *
     * @var array
     */
    protected static $bootedCallbacks = [];
/**
     * The array of trait initializers that will be called on each new instance.
     *
     * @var array
     */
    protected static $traitInitializers = [];
/**
     * The array of global scopes on the model.
     *
     * @var array
     */
    protected static $globalScopes = [];
/**
     * The list of models classes that should not be affected with touch.
     *
     * @var array
     */
    protected static $ignoreOnTouch = [];
/**
     * Indicates whether lazy loading should be restricted on all models.
     *
     * @var bool
     */
    protected static $modelsShouldPreventLazyLoading = false;
/**
     * Indicates whether relations should be automatically loaded on all models when they are accessed.
     *
     * @var bool
     */
    protected static $modelsShouldAutomaticallyEagerLoadRelationships = false;
/**
     * The callback that is responsible for handling lazy loading violations.
     *
     * @var (callable(self, string): mixed)|null
     */
    protected static $lazyLoadingViolationCallback;
/**
     * Indicates if an exception should be thrown instead of silently discarding non-fillable attributes.
     *
     * @var bool
     */
    protected static $modelsShouldPreventSilentlyDiscardingAttributes = false;
/**
     * The callback that is responsible for handling discarded attribute violations.
     *
     * @var (callable(self, array): mixed)|null
     */
    protected static $discardedAttributeViolationCallback;
/**
     * Indicates if an exception should be thrown when trying to access a missing attribute on a retrieved model.
     *
     * @var bool
     */
    protected static $modelsShouldPreventAccessingMissingAttributes = false;
/**
     * The callback that is responsible for handling missing attribute violations.
     *
     * @var (callable(self, string): mixed)|null
     */
    protected static $missingAttributeViolationCallback;
/**
     * Indicates if broadcasting is currently enabled.
     *
     * @var bool
     */
    protected static $isBroadcasting = true;
/**
     * The Eloquent query builder class to use for the model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Builder<*>>
     */
    protected static string $builder = Builder::class;
/**
     * The Eloquent collection class to use for the model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Collection<*, *>>
     */
    protected static string $collectionClass = Collection::class;
/**
     * Cache of soft deletable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isSoftDeletable;
/**
     * Cache of prunable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isPrunable;
/**
     * Cache of mass prunable models.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isMassPrunable;
/**
     * Cache of resolved class attributes.
     *
     * @var array<class-string<self>, array<class-string, mixed>>
     */
    protected static array $classAttributes = [];
/**
     * Eager load relations on the model.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function load($relations)
    {
        $query = $this->newQueryWithoutRelationships()->with(
            is_string($relations) ? func_get_args() : $relations
        );

        $query->eagerLoadRelations([$this]);

        return $this;
    }
/**
     * Set the keys for a select query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSelectQuery());

        return $query;
    }
/**
     * Get the primary key value for a select query.
     *
     * @return mixed
     */
    protected function getKeyForSelectQuery()
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }
/**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function newModelQuery()
    {
        return $this->newEloquentBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);
    }
/**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function newQueryWithoutScopes()
    {
        return $this->newModelQuery()
            ->with($this->with)
            ->withCount($this->withCount);
    }
/**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    public function newEloquentBuilder($query)
    {
        $builderClass = $this->resolveCustomBuilderClass();

        if ($builderClass && is_subclass_of($builderClass, Builder::class)) {
            return new $builderClass($query);
        }

        return new static::$builder($query);
    }
/**
     * Reload a fresh model instance from the database.
     *
     * @param  array|string  $with
     * @return static|null
     */
    public function fresh($with = [])
    {
        if (! $this->exists) {
            return;
        }

        return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
            ->useWritePdo()
            ->with(is_string($with) ? func_get_args() : $with)
            ->first();
    }
/**
     * Reload the current model instance with fresh attributes from the database.
     *
     * @return $this
     */
    public function refresh()
    {
        if (! $this->exists) {
            return $this;
        }

        return $this->refreshUsingQuery($this->newQueryWithoutScopes());
    }
/**
     * Reload the current model instance using the given query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return $this
     */
    protected function refreshUsingQuery(Builder $query)
    {
        $this->setRawAttributes(
            $this->setKeysForSelectQuery($query)
                ->useWritePdo()
                ->firstOrFail()
                ->attributes
        );

        $this->load((new BaseCollection($this->relations))->reject(
            fn ($relation) => $relation instanceof Pivot
                || (is_object($relation) && isset(class_uses_recursive($relation)[AsPivot::class]))
        )->keys()->all());

        $this->syncOriginal();

        return $this;
    }
/**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }
/**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }
/**
     * Update the model in the database.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }
/**
     * Get a new query builder with no relationships loaded.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function newQueryWithoutRelationships()
    {
        return $this->registerGlobalScopes($this->newModelQuery());
    }
/**
     * Resolve the custom Eloquent builder class from the model attributes.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Builder>|false
     */
    protected function resolveCustomBuilderClass()
    {
        return static::resolveClassAttribute(UseEloquentBuilder::class, 'builderClass') ?? false;
    }
/**
     * Get the primary key for the model.
     *
     * @return string
     */
    public function getKeyName()
    {
        return $this->primaryKey;
    }
/**
     * Get the auto-incrementing key type.
     *
     * @return string
     */
    public function getKeyType()
    {
        return $this->keyType;
    }
/**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing()
    {
        return $this->incrementing;
    }
/**
     * Get a new query builder instance for the connection.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        return $this->getConnection()->query();
    }
/**
     * Get the database connection for the model.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getConnection()
    {
        return static::resolveConnection($this->getConnectionName());
    }
/**
     * Fill the model with an array of attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        $fillable = $this->fillableFromArray($attributes);

        foreach ($fillable as $key => $value) {
            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded || static::preventsSilentlyDiscardingAttributes()) {
                if (isset(static::$discardedAttributeViolationCallback)) {
                    call_user_func(static::$discardedAttributeViolationCallback, $this, [$key]);
                } else {
                    throw new MassAssignmentException(sprintf(
                        'Add [%s] to fillable property to allow mass assignment on [%s].',
                        $key, get_class($this)
                    ));
                }
            }
        }

        if (count($attributes) !== count($fillable) &&
            static::preventsSilentlyDiscardingAttributes()) {
            $keys = array_diff(array_keys($attributes), array_keys($fillable));

            if (isset(static::$discardedAttributeViolationCallback)) {
                call_user_func(static::$discardedAttributeViolationCallback, $this, $keys);
            } else {
                throw new MassAssignmentException(sprintf(
                    'Add fillable property [%s] to allow mass assignment on [%s].',
                    implode(', ', $keys),
                    get_class($this)
                ));
            }
        }

        return $this;
    }
/**
     * Save the model to the database.
     *
     * @param  array  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $this->mergeAttributesFromCachedCasts();

        $query = $this->newModelQuery();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->isDirty() ?
                $this->performUpdate($query) : true;
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($query);

            if (! $this->getConnectionName() &&
                $connection = $query->getConnection()) {
                $this->setConnection($connection->getName());
            }
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }
/**
     * Perform any actions that are necessary after the model is saved.
     *
     * @param  array  $options
     * @return void
     */
    protected function finishSave(array $options)
    {
        $this->fireModelEvent('saved', false);

        if ($this->isDirty() && ($options['touch'] ?? true)) {
            $this->touchOwners();
        }

        $this->syncOriginal();
    }
/**
     * Perform a model update operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }
/**
     * Set the keys for a save update query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }
/**
     * Get the primary key value for a save query.
     *
     * @return mixed
     */
    protected function getKeyForSaveQuery()
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }
/**
     * Perform a model insert operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        if ($this->usesUniqueIds()) {
            $this->setUniqueIds();
        }

        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->getAttributesForInsert();

        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        else {
            if (empty($attributes)) {
                return true;
            }

            $query->insert($attributes);
        }

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }
/**
     * Determine if discarding guarded attribute fills is disabled.
     *
     * @return bool
     */
    public static function preventsSilentlyDiscardingAttributes()
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }
/**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }
    /** Create a new instance of the given model.
     * @param array<string, mixed> $attributes
     * @param bool $exists
     * @return static */
    public function newInstance($attributes = [], $exists = false)
    {
        $model = new static;

        $model->exists = $exists;

        $model->setConnection(
            $this->getConnectionName()
        );

        $model->setTable($this->getTable());

        $model->mergeCasts($this->casts);

        $model->fill((array) $attributes);

        return $model;
    }
}
NATIVE_Model,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php' => <<<'NATIVE_HasAttributes'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;
use Brick\Math\BigDecimal;
use Illuminate\Support\Exceptions\MathException;
use Brick\Math\RoundingMode;
use BackedEnum;
use Brick\Math\Exception\MathException as BrickMathException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\InvalidCastException;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Stringable;
use ValueError;
use function Illuminate\Support\enum_value;
trait HasAttributes {
/**
     * The model's attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [];
/**
     * The model attribute's original state.
     *
     * @var array<string, mixed>
     */
    protected $original = [];
/**
     * The changed model attributes.
     *
     * @var array<string, mixed>
     */
    protected $changes = [];
/**
     * The previous state of the changed model attributes.
     *
     * @var array<string, mixed>
     */
    protected $previous = [];
/**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [];
/**
     * The attributes that have been cast using custom classes.
     *
     * @var array
     */
    protected $classCastCache = [];
/**
     * The attributes that have been cast using "Attribute" return type mutators.
     *
     * @var array
     */
    protected $attributeCastCache = [];
/**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var string[]
     */
    protected static $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'hashed',
        'immutable_date',
        'immutable_datetime',
        'immutable_custom_datetime',
        'int',
        'integer',
        'json',
        'json:unicode',
        'object',
        'real',
        'string',
        'timestamp',
    ];
/**
     * The storage format of the model's date columns.
     *
     * @var string|null
     */
    protected $dateFormat;
/**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [];
/**
     * Indicates whether attributes are snake cased on arrays.
     *
     * @var bool
     */
    public static $snakeAttributes = true;
/**
     * The cache of the mutated attributes for each class.
     *
     * @var array
     */
    protected static $mutatorCache = [];
/**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     *
     * @var array
     */
    protected static $attributeMutatorCache = [];
/**
     * The cache of the "Attribute" return type marked mutated, gettable attributes for each class.
     *
     * @var array
     */
    protected static $getAttributeMutatorCache = [];
/**
     * The cache of the "Attribute" return type marked mutated, settable attributes for each class.
     *
     * @var array
     */
    protected static $setAttributeMutatorCache = [];
/**
     * The cache of the converted cast types.
     *
     * @var array
     */
    protected static $castTypeCache = [];
/**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var \Illuminate\Contracts\Encryption\Encrypter|null
     */
    public static $encrypter;
/**
     * Determine whether an attribute exists on the model.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasAttribute($key)
    {
        if (! $key) {
            return false;
        }

        return array_key_exists($key, $this->attributes) ||
            array_key_exists($key, $this->casts) ||
            $this->hasGetMutator($key) ||
            $this->hasAttributeMutator($key) ||
            $this->isClassCastable($key);
    }
/**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if ($this->hasAttribute($key)) {
            return $this->getAttributeValue($key);
        }

        // Here we will determine if the model base class itself contains this given key
        // since we don't want to treat any of those methods as relationships because
        // they are all intended as helper methods and none of these are relations.
        if (method_exists(self::class, $key)) {
            return $this->throwMissingAttributeExceptionIfApplicable($key);
        }

        return $this->isRelation($key) || $this->relationLoaded($key)
            ? $this->getRelationValue($key)
            : $this->throwMissingAttributeExceptionIfApplicable($key);
    }
/**
     * Get a plain attribute (not a relationship).
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }
/**
     * Get an attribute from the $attributes array.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getAttributeFromArray($key)
    {
        $this->mergeAttributeFromCachedCasts($key);

        return $this->attributes[$key] ?? null;
    }
/**
     * Determine if a get mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        return method_exists($this, 'get'.Str::studly($key).'Attribute');
    }
/**
     * Determine if a "Attribute" return type marked mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasAttributeMutator($key)
    {
        if (isset(static::$attributeMutatorCache[get_class($this)][$key])) {
            return static::$attributeMutatorCache[get_class($this)][$key];
        }

        if (! method_exists($this, $method = Str::camel($key))) {
            return static::$attributeMutatorCache[get_class($this)][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$attributeMutatorCache[get_class($this)][$key] =
                    $returnType instanceof ReflectionNamedType &&
                    $returnType->getName() === Attribute::class;
    }
/**
     * Determine if a "Attribute" return type marked get mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasAttributeGetMutator($key)
    {
        if (isset(static::$getAttributeMutatorCache[get_class($this)][$key])) {
            return static::$getAttributeMutatorCache[get_class($this)][$key];
        }

        if (! $this->hasAttributeMutator($key)) {
            return static::$getAttributeMutatorCache[get_class($this)][$key] = false;
        }

        return static::$getAttributeMutatorCache[get_class($this)][$key] = is_callable($this->{Str::camel($key)}()->get);
    }
/**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return $value;
        }

        // If the key is one of the encrypted castable types, we'll first decrypt
        // the value and update the cast type so we may leverage the following
        // logic for casting this value to any additionally specified types.
        if ($this->isEncryptedCastable($key)) {
            $value = $this->fromEncryptedString($value);

            $castType = Str::after($castType, 'encrypted:');
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return $this->fromFloat($value);
            case 'decimal':
                return $this->asDecimal($value, explode(':', $this->getCasts()[$key], 2)[1]);
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
            case 'json:unicode':
                return $this->fromJson($value);
            case 'collection':
                return new BaseCollection($this->fromJson($value));
            case 'date':
                return $this->asDate($value);
            case 'datetime':
            case 'custom_datetime':
                return $this->asDateTime($value);
            case 'immutable_date':
                return $this->asDate($value)->toImmutable();
            case 'immutable_custom_datetime':
            case 'immutable_datetime':
                return $this->asDateTime($value)->toImmutable();
            case 'timestamp':
                return $this->asTimestamp($value);
        }

        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key, $value);
        }

        return $value;
    }
/**
     * Get the type of cast for a model attribute.
     *
     * @param  string  $key
     * @return string
     */
    protected function getCastType($key)
    {
        $castType = $this->getCasts()[$key];

        if (isset(static::$castTypeCache[$castType])) {
            return static::$castTypeCache[$castType];
        }

        if ($this->isCustomDateTimeCast($castType)) {
            $convertedCastType = 'custom_datetime';
        } elseif ($this->isImmutableCustomDateTimeCast($castType)) {
            $convertedCastType = 'immutable_custom_datetime';
        } elseif ($this->isDecimalCast($castType)) {
            $convertedCastType = 'decimal';
        } elseif (class_exists($castType)) {
            $convertedCastType = $castType;
        } else {
            $convertedCastType = trim(strtolower($castType));
        }

        return static::$castTypeCache[$castType] = $convertedCastType;
    }
/**
     * Return a decimal as string.
     *
     * @param  float|string  $value
     * @param  int  $decimals
     * @return string
     *
     * @throws \Illuminate\Support\Exceptions\MathException
     */
    protected function asDecimal($value, $decimals)
    {
        try {
            return (string) BigDecimal::of((string) $value)->toScale($decimals, RoundingMode::HalfUp);
        } catch (BrickMathException $e) {
            throw new MathException('Unable to cast value to a decimal.', previous: $e);
        }
    }
/**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string  $key
     * @param  array|string|null  $types
     * @return bool
     */
    public function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }
/**
     * Get the attributes that should be cast.
     *
     * @return array
     */
    public function getCasts()
    {
        if ($this->getIncrementing()) {
            return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
        }

        return $this->casts;
    }
/**
     * Determine if the given key is cast using a custom class.
     *
     * @param  string  $key
     * @return bool
     *
     * @throws \Illuminate\Database\Eloquent\InvalidCastException
     */
    protected function isClassCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this->getModel(), $key, $castType);
    }
/**
     * Determine if the given key is cast using an enum.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEnumCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (is_subclass_of($castType, Castable::class)) {
            return false;
        }

        return enum_exists($castType);
    }
/**
     * Get all of the current attributes on the model.
     *
     * @return array<string, mixed>
     */
    public function getAttributes()
    {
        $this->mergeAttributesFromCachedCasts();

        return $this->attributes;
    }
/**
     * Set the array of model attributes. No checking is done.
     *
     * @param  array  $attributes
     * @param  bool  $sync
     * @return $this
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        $this->classCastCache = [];
        $this->attributeCastCache = [];

        return $this;
    }
/**
     * Sync the original attributes with the current.
     *
     * @return $this
     */
    public function syncOriginal()
    {
        $this->original = $this->getAttributes();

        return $this;
    }
/**
     * Transform a raw model value using mutators, casts, etc.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transformModelValue($key, $value)
    {
        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        } elseif ($this->hasAttributeGetMutator($key)) {
            return $this->mutateAttributeMarkedAttribute($key, $value);
        }

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependent upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            if (static::preventsAccessingMissingAttributes() &&
                ! array_key_exists($key, $this->attributes) &&
                ($this->isEnumCastable($key) ||
                 in_array($this->getCastType($key), static::$primitiveCastTypes))) {
                $this->throwMissingAttributeExceptionIfApplicable($key);
            }

            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if ($value !== null
            && \in_array($key, $this->getDates(), false)) {
            return $this->asDateTime($value);
        }

        return $value;
    }
/**
     * Merge new casts with existing casts on the model.
     *
     * @param  array  $casts
     * @return $this
     */
    public function mergeCasts($casts)
    {
        $casts = $this->ensureCastsAreStringValues($casts);

        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }
/**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEncryptedCastable($key)
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }
/**
     * Merge the cast class and attribute cast attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromCachedCasts()
    {
        $this->mergeAttributesFromClassCasts();
        $this->mergeAttributesFromAttributeCasts();
    }
/**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromClassCasts()
    {
        foreach ($this->classCastCache as $key => $value) {
            $this->mergeAttributeFromClassCasts($key);
        }
    }
/**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromAttributeCasts()
    {
        foreach ($this->attributeCastCache as $key => $value) {
            $this->mergeAttributeFromAttributeCasts($key);
        }
    }
/**
     * Merge the cast class and attribute cast attribute back into the model.
     *
     * @return void
     */
    protected function mergeAttributeFromCachedCasts(string $key)
    {
        $this->mergeAttributeFromClassCasts($key);
        $this->mergeAttributeFromAttributeCasts($key);
    }
/**
     * Merge the cast class attribute back into the model.
     *
     * @return void
     */
    protected function mergeAttributeFromClassCasts(string $key): void
    {
        if (! isset($this->classCastCache[$key])) {
            return;
        }

        $value = $this->classCastCache[$key];

        $caster = $this->resolveCasterClass($key);

        $this->attributes = array_merge(
            $this->attributes,
            $caster instanceof CastsInboundAttributes
                ? [$key => $value]
                : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
        );
    }
/**
     * Merge the cast class attribute back into the model.
     *
     * @return void
     */
    protected function mergeAttributeFromAttributeCasts(string $key): void
    {
        if (! isset($this->attributeCastCache[$key])) {
            return;
        }

        $value = $this->attributeCastCache[$key];

        $attribute = $this->{Str::camel($key)}();

        if ($attribute->get && ! $attribute->set) {
            return;
        }

        $callback = $attribute->set ?: function ($value) use ($key) {
            $this->attributes[$key] = $value;
        };

        $this->attributes = array_merge(
            $this->attributes,
            $this->normalizeCastClassResponse(
                $key, $callback($value, $this->attributes)
            )
        );
    }
/**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // this model, such as "json_encoding" a listing of data for storage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        } elseif ($this->hasAttributeSetMutator($key)) {
            return $this->setAttributeMarkedMutatedAttributeValue($key, $value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        elseif (! is_null($value) && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isEnumCastable($key)) {
            $this->setEnumCastableAttribute($key, $value);

            return $this;
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (! is_null($value) && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (str_contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (! is_null($value) && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($key, $value);
        }

        if (! is_null($value) && $this->hasCast($key, 'hashed')) {
            $value = $this->castAttributeAsHashedString($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }
/**
     * Get the model's original attribute values.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getOriginal($key = null, $default = null)
    {
        return (new static)->setRawAttributes(
            $this->original, $sync = true
        )->getOriginalWithoutRewindingModel($key, $default);
    }
/**
     * Sync the changed attributes.
     *
     * @return $this
     */
    public function syncChanges()
    {
        $this->changes = $this->getDirty();
        $this->previous = array_intersect_key($this->getRawOriginal(), $this->changes);

        return $this;
    }
/**
     * Get the attributes that have been changed since the last sync.
     *
     * @return array<string, mixed>
     */
    public function getDirty()
    {
        $dirty = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (! $this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }
/**
     * Get the attributes that have been changed since the last sync for an update operation.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        return $this->getDirty();
    }
/**
     * Determine if a set mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        return method_exists($this, 'set'.Str::studly($key).'Attribute');
    }
/**
     * Determine if an "Attribute" return type marked set mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasAttributeSetMutator($key)
    {
        $class = get_class($this);

        if (isset(static::$setAttributeMutatorCache[$class][$key])) {
            return static::$setAttributeMutatorCache[$class][$key];
        }

        if (! method_exists($this, $method = Str::camel($key))) {
            return static::$setAttributeMutatorCache[$class][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$setAttributeMutatorCache[$class][$key] =
                    $returnType instanceof ReflectionNamedType &&
                    $returnType->getName() === Attribute::class &&
                    is_callable($this->{$method}()->set);
    }
/**
     * Determine if the given attribute is a date or date castable.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateAttribute($key)
    {
        return in_array($key, $this->getDates(), true) ||
            $this->isDateCastable($key);
    }
/**
     * Get the attributes that should be converted to dates.
     *
     * @return array<int, string|null>
     */
    public function getDates()
    {
        return $this->usesTimestamps() ? [
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ] : [];
    }
/**
     * Determine whether a value is JSON castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isJsonCastable($key)
    {
        return $this->hasCast($key, ['array', 'json', 'json:unicode', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }
/**
     * Determine if the model or any of the given attribute(s) have been modified.
     *
     * @param  array<string>|string|null  $attributes
     * @return bool
     */
    public function isDirty($attributes = null)
    {
        return $this->hasChanges(
            $this->getDirty(), is_array($attributes) ? $attributes : func_get_args()
        );
    }
/**
     * Determine if any of the given attributes were changed when the model was last saved.
     *
     * @param  array<string>  $changes
     * @param  array<string>|string|null  $attributes
     * @return bool
     */
    protected function hasChanges($changes, $attributes = null)
    {
        // If no specific attributes were provided, we will just see if the dirty array
        // already contains any attributes. If it does we will just return that this
        // count is greater than zero. Else, we need to check specific attributes.
        if (empty($attributes)) {
            return count($changes) > 0;
        }

        // Here we will spin through every attribute and see if this is in the array of
        // dirty attributes. If it is, we will return true and if we make it through
        // all of the attributes for the entire array we will return false at end.
        foreach (Arr::wrap($attributes) as $attribute) {
            if (array_key_exists($attribute, $changes)) {
                return true;
            }
        }

        return false;
    }
/**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat ?: $this->getConnection()->getQueryGrammar()->getDateFormat();
    }
/**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateCastable($key)
    {
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }
/**
     * Determine if the new and old values for a given key are equivalent.
     *
     * @param  string  $key
     * @return bool
     */
    public function originalIsEquivalent($key)
    {
        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = Arr::get($this->attributes, $key);
        $original = Arr::get($this->original, $key);

        if ($attribute === $original) {
            return true;
        } elseif (is_null($attribute)) {
            return false;
        } elseif ($this->isDateAttribute($key) || $this->isDateCastableWithCustomFormat($key)) {
            return $this->fromDateTime($attribute) ===
                $this->fromDateTime($original);
        } elseif ($this->hasCast($key, ['object', 'collection'])) {
            return $this->fromJson($attribute) ===
                $this->fromJson($original);
        } elseif ($this->hasCast($key, ['real', 'float', 'double'])) {
            if ($original === null) {
                return false;
            }

            return abs($this->castAttribute($key, $attribute) - $this->castAttribute($key, $original)) < PHP_FLOAT_EPSILON * 4;
        } elseif ($this->isEncryptedCastable($key) && ! empty(static::currentEncrypter()->getPreviousKeys())) {
            return false;
        } elseif ($this->hasCast($key, static::$primitiveCastTypes)) {
            return $this->castAttribute($key, $attribute) ===
                $this->castAttribute($key, $original);
        } elseif ($this->isClassCastable($key) && Str::startsWith($this->getCasts()[$key], [AsArrayObject::class, AsCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        } elseif ($this->isClassCastable($key) && Str::startsWith($this->getCasts()[$key], [AsEnumArrayObject::class, AsEnumCollection::class])) {
            return $this->fromJson($attribute) === $this->fromJson($original);
        } elseif ($this->isClassCastable($key) && $original !== null && Str::startsWith($this->getCasts()[$key], [AsEncryptedArrayObject::class, AsEncryptedCollection::class])) {
            if (empty(static::currentEncrypter()->getPreviousKeys())) {
                return $this->fromEncryptedString($attribute) === $this->fromEncryptedString($original);
            }

            return false;
        } elseif ($this->isClassComparable($key)) {
            return $this->compareClassCastableAttribute($key, $original, $attribute);
        }

        return is_numeric($attribute) && is_numeric($original)
            && strcmp((string) $attribute, (string) $original) === 0;
    }
/**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Date::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'), $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value, date_default_timezone_get());
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        try {
            $date = Date::createFromFormat($format, $value);
        } catch (InvalidArgumentException) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }
/**
     * Determine if the given value is a standard date format.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isStandardDateFormat($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }
/**
     * Convert a DateTime to a storable string.
     *
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }
/**
     * Determine whether a value is Date / DateTime custom-castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateCastableWithCustomFormat($key)
    {
        return $this->hasCast($key, ['custom_datetime', 'immutable_custom_datetime']);
    }
    /**
     * Decode the given JSON back into an array or object.
     *
     * @param  string|null  $value
     * @param  bool  $asObject
     * @return mixed
     */
    public function fromJson($value, $asObject = false)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Json::decode($value, ! $asObject);
    }
}
NATIVE_HasAttributes,
    'laravel/framework/src/Illuminate/Database/Eloquent/Casts/Json.php' => <<<'NATIVE_Json'
<?php
namespace Illuminate\Database\Eloquent\Casts;
class Json
{
    /** @var callable|null */
    protected static $encoder;
    /** @var callable|null */
    protected static $decoder;
    public static function encode(mixed $value, int $flags = 0): mixed
    {
        return isset(static::$encoder)
            ? (static::$encoder)($value, $flags)
            : json_encode($value, $flags);
    }
    public static function decode(mixed $value, ?bool $associative = true): mixed
    {
        return isset(static::$decoder)
            ? (static::$decoder)($value, $associative)
            : json_decode($value, $associative);
    }
    public static function encodeUsing(?callable $encoder): void
    {
        static::$encoder = $encoder;
    }
    public static function decodeUsing(?callable $decoder): void
    {
        static::$decoder = $decoder;
    }
}
NATIVE_Json,
    'testo/assert/Assert.php' => <<<'NATIVE_Assert'
<?php
declare(strict_types=1);
namespace Testo;
use Testo\Assert\Api\Builtin\ArrayType;
use Testo\Assert\Api\Builtin\FloatType;
use Testo\Assert\Api\Builtin\IntType;
use Testo\Assert\Api\Builtin\IterableType;
use Testo\Assert\Api\Builtin\NumericType;
use Testo\Assert\Api\Builtin\ObjectType;
use Testo\Assert\Api\Builtin\StringType;
use Testo\Assert\Api\Json\JsonAbstract;
use Testo\Assert\Internal\Assertion\AssertArray;
use Testo\Assert\Internal\Assertion\AssertFloat;
use Testo\Assert\Internal\Assertion\AssertInt;
use Testo\Assert\Internal\Assertion\AssertIterable;
use Testo\Assert\Internal\Assertion\AssertJson;
use Testo\Assert\Internal\Assertion\AssertNumeric;
use Testo\Assert\Internal\Assertion\AssertObject;
use Testo\Assert\Internal\Assertion\AssertString;
use Testo\Assert\Internal\StaticState;
use Testo\Assert\Internal\Support;
use Testo\Assert\State\Assertion\AssertionException;
use Testo\Assert\State\Assertion\ComparisonFailure;
use Testo\Assert\State\Test\Fail;
use Testo\Common\Attribute\AssertMethod;
final class Assert {
/**
     * Asserts that two values are the same (identical).
     *
     * @template ExpectedType
     *
     * @param mixed $actual The actual value to compare against the expected value.
     * @param ExpectedType $expected The expected value.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert =ExpectedType $actual
     * @phpstan-assert =ExpectedType $actual
     */
    #[AssertMethod]
    public static function same(mixed $actual, mixed $expected, string $message = ''): void
    {
        $actual === $expected
            ? StaticState::success($actual, 'is the same', $message)
            : StaticState::fail(new ComparisonFailure(
                expected: $expected,
                actual: $actual,
                value: Support::stringify($actual),
                assertion: 'is the same as `' . Support::stringify($expected) . '`',
                context: $message,
                reason: 'expected `' . Support::stringify($expected) . '`, got `' . Support::stringify($actual) . '`',
            ));
    }
/**
     * Asserts that the value is true.
     *
     * @param mixed $actual The actual value to check.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert true $actual
     * @phpstan-assert true $actual
     */
    #[AssertMethod]
    public static function true(mixed $actual, string $message = ''): void
    {
        $actual === true
            ? StaticState::success($actual, 'is exactly `true`', $message)
            : StaticState::fail(new ComparisonFailure(
                expected: true,
                actual: $actual,
                value: Support::stringify($actual),
                assertion: 'is exactly `true`',
                context: $message,
                reason: 'expected `true`, got `' . Support::stringify($actual) . '`',
            ));
    }
/**
     * Asserts that the value is false.
     *
     * @param mixed $actual The actual value to check.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert false $actual
     * @phpstan-assert false $actual
     */
    #[AssertMethod]
    public static function false(mixed $actual, string $message = ''): void
    {
        $actual === false
            ? StaticState::success($actual, 'is exactly `false`', $message)
            : StaticState::fail(new ComparisonFailure(
                expected: false,
                actual: $actual,
                value: Support::stringify($actual),
                assertion: 'is exactly `false`',
                context: $message,
                reason: 'expected `false`, got `' . Support::stringify($actual) . '`',
            ));
    }
}
NATIVE_Assert,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/PreventsCircularRecursion.php' => <<<'NATIVE_PreventsCircularRecursion'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Illuminate\Support\Arr;
use Illuminate\Support\Onceable;
use WeakMap;
trait PreventsCircularRecursion {
/**
     * The cache of objects processed to prevent infinite recursion.
     *
     * @var WeakMap<static, array<string, mixed>>
     */
    protected static $recursionCache;
/**
     * Prevent a method from being called multiple times on the same object within the same call stack.
     *
     * @param  callable  $callback
     * @param  mixed  $default
     * @return mixed
     */
    protected function withoutRecursion($callback, $default = null)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $onceable = Onceable::tryFromTrace($trace, $callback);

        if (is_null($onceable)) {
            return call_user_func($callback);
        }

        $stack = static::getRecursiveCallStack($this);

        if (array_key_exists($onceable->hash, $stack)) {
            return is_callable($stack[$onceable->hash])
                ? static::setRecursiveCallValue($this, $onceable->hash, call_user_func($stack[$onceable->hash]))
                : $stack[$onceable->hash];
        }

        try {
            static::setRecursiveCallValue($this, $onceable->hash, $default);

            return call_user_func($onceable->callable);
        } finally {
            static::clearRecursiveCallValue($this, $onceable->hash);
        }
    }
}
NATIVE_PreventsCircularRecursion,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php' => <<<'NATIVE_GuardsAttributes'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Relations\Pivot;
trait GuardsAttributes {
/**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [];
/**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = ['*'];
/**
     * Indicates if all mass assignment is enabled.
     *
     * @var bool
     */
    protected static $unguarded = false;
/**
     * The actual columns that exist on the database and can be guarded.
     *
     * @var array<class-string,list<string>>
     */
    protected static $guardableColumns = [];
/**
     * Get the fillable attributes for the model.
     *
     * @return array<string>
     */
    public function getFillable()
    {
        return $this->fillable;
    }
/**
     * Get the guarded attributes for the model.
     *
     * @return array<string>
     */
    public function getGuarded()
    {
        return self::$unguarded === true
            ? []
            : $this->guarded;
    }
/**
     * Determine if the given attribute may be mass assigned.
     *
     * @param  string  $key
     * @return bool
     */
    public function isFillable($key)
    {
        if (static::$unguarded) {
            return true;
        }

        // If the key is in the "fillable" array, we can of course assume that it's
        // a fillable attribute. Otherwise, we will check the guarded array when
        // we need to determine if the attribute is black-listed on the model.
        if (in_array($key, $this->getFillable())) {
            return true;
        }

        // If the attribute is explicitly listed in the "guarded" array then we can
        // return false immediately. This means this attribute is definitely not
        // fillable and there is no point in going any further in this method.
        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->getFillable()) &&
            ! str_contains($key, '.') &&
            ! str_starts_with($key, '_');
    }
/**
     * Determine if the given key is guarded.
     *
     * @param  string  $key
     * @return bool
     */
    public function isGuarded($key)
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        return $this->getGuarded() == ['*'] ||
               ! empty(preg_grep('/^'.preg_quote($key, '/').'$/i', $this->getGuarded())) ||
               ! $this->isGuardableColumn($key);
    }
/**
     * Determine if the given column is a valid, guardable column.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isGuardableColumn($key)
    {
        if ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key) || $this->isClassCastable($key)) {
            return true;
        }

        if (! isset(static::$guardableColumns[get_class($this)])) {
            $columns = $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable());

            if (empty($columns)) {
                return true;
            }

            static::$guardableColumns[get_class($this)] = $columns;
        }

        return in_array($key, static::$guardableColumns[get_class($this)]);
    }
/**
     * Determine if the model is totally guarded.
     *
     * @return bool
     */
    public function totallyGuarded()
    {
        return count($this->getFillable()) === 0 && $this->getGuarded() == ['*'];
    }
/**
     * Get the fillable attributes of a given array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fillableFromArray(array $attributes)
    {
        if (count($this->getFillable()) > 0 && ! static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        return $attributes;
    }
}
NATIVE_GuardsAttributes,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasTimestamps.php' => <<<'NATIVE_HasTimestamps'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
trait HasTimestamps {
/**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
/**
     * The list of models classes that have timestamps temporarily disabled.
     *
     * @var array
     */
    protected static $ignoreTimestampsOn = [];
/**
     * Update the creation and update timestamps.
     *
     * @return $this
     */
    public function updateTimestamps()
    {
        $time = $this->freshTimestamp();

        $updatedAtColumn = $this->getUpdatedAtColumn();

        if (! is_null($updatedAtColumn) && ! $this->isDirty($updatedAtColumn)) {
            $this->setUpdatedAt($time);
        }

        $createdAtColumn = $this->getCreatedAtColumn();

        if (! $this->exists && ! is_null($createdAtColumn) && ! $this->isDirty($createdAtColumn)) {
            $this->setCreatedAt($time);
        }

        return $this;
    }
/**
     * Determine if the model uses timestamps.
     *
     * @return bool
     */
    public function usesTimestamps()
    {
        return $this->timestamps && ! static::isIgnoringTimestamps($this::class);
    }
/**
     * Set the value of the "created at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setCreatedAt($value)
    {
        $this->{$this->getCreatedAtColumn()} = $value;

        return $this;
    }
/**
     * Set the value of the "updated at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setUpdatedAt($value)
    {
        $this->{$this->getUpdatedAtColumn()} = $value;

        return $this;
    }
/**
     * Get a fresh timestamp for the model.
     *
     * @return \Illuminate\Support\Carbon
     */
    public function freshTimestamp()
    {
        return Date::now();
    }
/**
     * Get the name of the "created at" column.
     *
     * @return string|null
     */
    public function getCreatedAtColumn()
    {
        return static::CREATED_AT;
    }
/**
     * Get the name of the "updated at" column.
     *
     * @return string|null
     */
    public function getUpdatedAtColumn()
    {
        return static::UPDATED_AT;
    }
/**
     * Determine if the given model is ignoring timestamps / touches.
     *
     * @param  string|null  $class
     * @return bool
     */
    public static function isIgnoringTimestamps($class = null)
    {
        $class ??= static::class;

        return array_any(static::$ignoreTimestampsOn, fn ($ignoredClass) => $class === $ignoredClass || is_subclass_of($class, $ignoredClass));
    }
}
NATIVE_HasTimestamps,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasRelationships.php' => <<<'NATIVE_HasRelationships'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Closure;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\PendingHasThroughRelationship;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
trait HasRelationships {
/**
     * The loaded relationships for the model.
     *
     * @var array
     */
    protected $relations = [];
/**
     * The relationships that should be touched on save.
     *
     * @var array
     */
    protected $touches = [];
/**
     * The relationship autoloader callback.
     *
     * @var \Closure|null
     */
    protected $relationAutoloadCallback = null;
/**
     * The relationship autoloader callback context.
     *
     * @var mixed
     */
    protected $relationAutoloadContext = null;
/**
     * The many to many relationship methods.
     *
     * @var string[]
     */
    public static $manyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];
/**
     * The relation resolver callbacks.
     *
     * @var array
     */
    protected static $relationResolvers = [];
/**
     * Touch the owning relations of the model.
     *
     * @return void
     */
    public function touchOwners()
    {
        $this->withoutRecursion(function () {
            foreach ($this->getTouchedRelations() as $relation) {
                $this->$relation()->touch();

                if ($this->$relation instanceof self) {
                    $this->$relation->fireModelEvent('saved', false);

                    $this->$relation->touchOwners();
                } elseif ($this->$relation instanceof EloquentCollection) {
                    $this->$relation->each->touchOwners();
                }
            }
        });
    }
/**
     * Get the relationships that are touched on save.
     *
     * @return array
     */
    public function getTouchedRelations()
    {
        return $this->touches;
    }
}
NATIVE_HasRelationships,
    'laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php' => <<<'NATIVE_HasEvents'
<?php
namespace Illuminate\Database\Eloquent\Concerns;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\NullDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ReflectionClass;
trait HasEvents {
/**
     * The event map for the model.
     *
     * Allows for object-based events for native Eloquent events.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [];
/**
     * User exposed observable events.
     *
     * These are extra user-defined events observers may subscribe to.
     *
     * @var string[]
     */
    protected $observables = [];
/**
     * Fire the given event for the model.
     *
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // First, we will get the proper method to call on the event dispatcher, and then we
        // will attempt to fire a custom, object based event for the given event. If that
        // returns a result we can return that result, or we'll call the string events.
        $method = $halt ? 'until' : 'dispatch';

        $result = $this->filterModelEventResults(
            $this->fireCustomModelEvent($event, $method)
        );

        if ($result === false) {
            return false;
        }

        return ! empty($result) ? $result : static::$dispatcher->{$method}(
            "eloquent.{$event}: ".static::class, $this
        );
    }
/**
     * Fire a custom model event for the given event.
     *
     * @param  string  $event
     * @param  'until'|'dispatch'  $method
     * @return array|null|void
     */
    protected function fireCustomModelEvent($event, $method)
    {
        if (! isset($this->dispatchesEvents[$event])) {
            return;
        }

        $result = static::$dispatcher->$method(new $this->dispatchesEvents[$event]($this));

        if (! is_null($result)) {
            return $result;
        }
    }
/**
     * Filter the model event results.
     *
     * @param  mixed  $result
     * @return mixed
     */
    protected function filterModelEventResults($result)
    {
        if (is_array($result)) {
            $result = array_filter($result, function ($response) {
                return ! is_null($response);
            });
        }

        return $result;
    }
}
NATIVE_HasEvents,
];
foreach ($nativeSources as $path => $contents) {
    if (!is_dir(dirname($workspace.'/'.$vendor.'/'.$path))) { mkdir(dirname($workspace.'/'.$vendor.'/'.$path), recursive: true); }
    file_put_contents($workspace.'/'.$vendor.'/'.$path, $contents);
}

file_put_contents($workspace.'/support.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent {
    /** @template TModel of Model */
    class Builder {
        /** @return TModel */ public function firstOrFail(): Model { throw new \RuntimeException('Database execution is forbidden.'); }
    }
}
namespace Illuminate\Database\Eloquent\Attributes {
    #[\Attribute] final class UseEloquentBuilder { public function __construct(public string $builderClass) {} }
}
namespace Illuminate\Contracts\Database\Eloquent {
    interface CastsAttributes {
        public function get(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): mixed;
        public function set(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): mixed;
    }
}
namespace Testo\Assert\Internal {
    final class StaticState {
        public static function success(mixed $value, string $assertion, string $message): void {}
        public static function fail(\Throwable $failure): never { throw $failure; }
    }
    final class Support { public static function stringify(mixed $value): string { return ''; } }
}
namespace Testo\Common\Attribute {
    #[\Attribute] final class AssertMethod {}
}
namespace Testo\Assert\State\Assertion {
    class AssertionException extends \RuntimeException {}
    final class ComparisonFailure extends \RuntimeException {
        public function __construct(mixed $expected, mixed $actual, string $value, string $assertion, string $context, string $reason) {}
    }
}
PHP);
file_put_contents($workspace.'/models.php', <<<'PHP'
<?php
namespace Fixture;
use Illuminate\Database\Eloquent\Model;
interface ModelMarkerContract {}
interface ModelAuditContract extends ModelMarkerContract {}
trait ModelMarkerTrait {}
trait ModelAuditTrait { use ModelMarkerTrait; }
trait NoopRefreshTrait {
    /** @return $this */ public function refresh() { return $this; }
}
/** @mixin Model
 * @property bool $flag
 * @property-read int|null $units
 * @property-read string $cost
 * @property-write int|float|string $cost
 * @property-read float $hours */
class Row extends Model implements ModelAuditContract {
    use ModelAuditTrait;
    protected $table = 'fixture_rows';
    protected $guarded = [];
    protected $fillable = ['flag', 'label'];
    protected $hidden = ['cost', 'hours'];
    protected $casts = ['flag' => 'boolean', 'units' => 'integer', 'cost' => 'decimal:2', 'hours' => 'decimal:2'];
    public function setAlteredAtAttribute(mixed $value): void { $this->exists = false; }
    /* model snapshot reserve: ................................................................................................................ */
}
/** @phpstan-type ActiveFlag bool
 * @property ActiveFlag $flag */
final class AliasRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
}
final class InferredRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
}
/** @property bool $flag */
final class InterfaceHierarchyRow extends Model implements ModelAuditContract {
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
}
/** @property bool $flag */
final class TraitHierarchyRow extends Model {
    use ModelAuditTrait;
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
}
/** @property bool $flag */
final class LiteralListRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
    protected $fillable = ['flag', 'label'];
    protected $hidden = ['cost', 'payload'];
}
/** @property bool $flag */
final class InterfaceTraitListRow extends Model implements ModelAuditContract {
    use ModelAuditTrait;
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
    protected $fillable = ['flag', 'label'];
    protected $hidden = ['cost', 'payload'];
}
/** @property int $units */
final class TraitReloadOverrideRow extends Model implements ModelAuditContract {
    use NoopRefreshTrait;
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected $fillable = ['units', 'label'];
}
/** @property int $units */
final class NoopRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    /** @return $this */ public function refresh() { return $this; }
}
/** @property int $units */
final class DelegatedNoopRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function refreshUsingQuery(\Illuminate\Database\Eloquent\Builder $query) { return $this; }
}
/** @property int $units */
final class RawNoopRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function setRawAttributes(array $attributes, $sync = false) { return $this; }
}
/** @mixin Model
 * @property int $units */
final class ConstantRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function getUnitsAttribute(): int { return 17; }
}
/** @property int $units */
final class ModernConstantRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function units(): \Illuminate\Database\Eloquent\Casts\Attribute { throw new \RuntimeException('Fixture body execution is forbidden.'); }
}
final class ShadowRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public int $units = 17;
}
/** @mixin Model */
final class ReadonlyShadowRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public readonly int $units;
    public function __construct() { $this->units = 17; }
}
/** @property int $units */
final class CustomCastRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => IntegerCast::class];
}
final class IntegerCast {
    public function get(Model $model, string $key, mixed $value, array $attributes): int { return 17; }
}
/** @property int $units */
final class ChangedCastsRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function getCasts() { return ['units' => 'string']; }
}
/** @property int $units */
final class ChangedReaderRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function getAttributeFromArray($key) { return 17; }
}
/** @property-read string $cost */
final class ChangedDecimalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['cost' => 'decimal:2'];
    protected function asDecimal($value, $decimals) { return '17.00'; }
}
/** @property int $units */
final class RestoringLoadRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function load($relations) { $this->attributes['units'] = 17; return $this; }
}
/** @property int $units */
final class RestoringOriginalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function syncOriginal() { $this->attributes['units'] = 17; return $this; }
}
/** @property int $units */
final class ChangedQueryRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function newQueryWithoutScopes() { throw new \RuntimeException('No normally returning reload.'); }
}
/** @mixin Model
 * @property-read 17 $units */
final class NarrowReadRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
}
/** @mixin Model
 * @property int $units */
class DivergentRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
}
final class DivergentChild extends DivergentRow {
    /** @return $this */ public function refresh() { return $this; }
}
/** @property int $units */
final class CustomBuilderRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected static string $builder = CustomBuilder::class;
}
final class CustomBuilder extends \Illuminate\Database\Eloquent\Builder {}
/** @property int $units */
final class BuilderResolverRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function resolveCustomBuilderClass() { return CustomBuilder::class; }
}
/** @property int $units */
final class RestoringCacheRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function mergeAttributesFromCachedCasts() { $this->attributes['units'] = 17; }
}
/** @property int $units */
#[\Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder(CustomBuilder::class)]
final class BuilderAttributeRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
}
/** @property int $units */
final class RestoringSingleCacheRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function mergeAttributeFromCachedCasts(string $key) { $this->attributes[$key] = 17; }
}
/** @property int $units */
final class GeneratedRow extends Model {
    protected $table = 'generated_rows';
    protected $casts = ['units' => 'integer'];
}
/** @property int $units */
final class UnknownSchemaRow extends Model {
    protected $table = 'unknown_rows';
    protected $casts = ['units' => 'integer'];
}
/** @property-read string $cost */
final class WholeDecimalRow extends Model {
    protected $table = 'whole_rows';
    protected $casts = ['cost' => 'decimal:0'];
}
/** @property int $units */
final class CustomSaveRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function save($options = []) { $this->exists = false; return true; }
}
/** @property bool $flag */
final class ExistenceSetterRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['flag' => 'boolean'];
    public function setFlagAttribute(mixed $value): void { $this->exists = false; }
}
/** @property int $units */
final class CustomUpdatedCastRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer', 'note' => UnsavedNoteCast::class];
}
final class UnsavedNoteCast implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes {
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed { return $value; }
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed { $model->exists = false; return $value; }
}
/** @property int $units */
final class DirtyOverrideRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function isDirty($attributes = null) { $this->exists = false; return false; }
}
/** @property int $units */
final class DateDetectionOverrideRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    protected function isDateCastableWithCustomFormat($key) { $this->exists = false; return false; }
}
/** @property int $units */
final class TimestampSetterRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function __set($key, $value) { $this->exists = false; }
}
/** @property int $units */
final class TimestampKeyRow extends Model {
    protected $table = 'fixture_rows';
    protected $guarded = [];
    protected $casts = ['units' => 'integer'];
    const UPDATED_AT = 'altered_at';
    public function setAlteredAtAttribute(mixed $value): void { $this->exists = false; }
}
/** @property int $units
 * @property bool $flag
 * @property array $payload
 * @property string $label */
final class NativeSupplementalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer', 'flag' => 'boolean', 'payload' => 'array'];
}
/** @property int $units
 * @property array $payload */
final class JsonSupplementalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer', 'payload' => 'array'];
    public function fromJson($value, $asObject = false) { $this->exists = false; return []; }
}
/** @property int $units
 * @property string $label */
final class RawSupplementalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer'];
    public function getDates() { $this->exists = false; return []; }
}
/** @property int $units
 * @property array $payload */
final class CustomSupplementalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer', 'payload' => UnsavedPayloadCast::class];
}
final class UnsavedPayloadCast implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes {
    public function get(Model $model, string $key, mixed $value, array $attributes): array { $model->exists = false; return []; }
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed { return $value; }
}
/** @property int $units
 * @property array $payload */
final class UnknownSupplementalRow extends Model {
    protected $table = 'fixture_rows';
    protected $casts = ['units' => 'integer', 'payload' => UnresolvedPayloadCast::class];
}
PHP);
$mixinCases = [
    'NativeMixinRow' => ['Model', 'exact native model mixin', true],
    'ArbitraryMixinRow' => ['PlainMixin', 'arbitrary model mixin', false],
    'UnknownMixinRow' => ['UnresolvedMixin', 'unresolved model mixin', false],
    'SelfMixinRow' => ['self', 'self model mixin', false],
    'TraitMixinRow' => ['MarkerMixin', 'trait model mixin', false],
    'InterfaceMixinRow' => ['MixinContract', 'interface model mixin', false],
    'GenericMixinRow' => ['Model<mixed>', 'parameterized native model mixin', false],
    'IntersectionMixinRow' => ['Model&PlainMixin', 'intersection native model mixin', false],
    'UnionMixinRow' => ['Model|PlainMixin', 'union native model mixin', false],
    'StaticMixinRow' => ['static', 'static model mixin', false],
];
$mixinSource = "\nclass PlainMixin {}\ntrait MarkerMixin {}\ninterface MixinContract {}\n";
foreach ($mixinCases as $class => [$type]) {
    $mixinSource .= '/** @mixin '.$type."\n * @property bool \$flag */\nfinal class ".$class." extends Model {\n"
        ."protected \$table = 'fixture_rows';\nprotected \$casts = ['flag' => 'boolean'];\n}\n";
}
file_put_contents($workspace.'/models.php', $mixinSource, FILE_APPEND);
$jsonModels = [
    'NativeArraySideRow' => ['array', ''],
    'NativeJsonSideRow' => ['json', ''],
    'NativeUnicodeSideRow' => ['json:unicode', ''],
    'ChangedJsonSideRow' => ['array', 'public function fromJson($value, $asObject = false) { $this->exists = false; return []; }'],
    'ChangedDirtySideRow' => ['array', 'public function getDirty() { $this->exists = false; return []; }'],
    'ChangedEquivalenceSideRow' => ['array', 'public function originalIsEquivalent($key) { $this->exists = false; return true; }'],
    'ChangedSaveSideRow' => ['array', 'public function save($options = []) { $this->exists = false; return true; }'],
    'CustomJsonSideRow' => ['Fixture\\UnsavedNoteCast', ''],
    'ObjectJsonSideRow' => ['object', ''],
    'CollectionJsonSideRow' => ['collection', ''],
    'EncryptedJsonSideRow' => ['encrypted:array', ''],
    'ParameterizedJsonSideRow' => ['array:custom', ''],
    'NarrowJsonSideRow' => ['array', ''],
];
foreach ($jsonModels as $class => [$cast, $methods]) {
    $flag = $class === 'NarrowJsonSideRow' ? 'true' : 'bool';
    $direction = $class === 'NarrowJsonSideRow' ? '-read' : '';
    file_put_contents($workspace.'/models.php', "\n/** @property".$direction." ".$flag." \$flag */\nfinal class ".$class." extends Model {\n"
        ."protected \$table = 'fixture_rows';\nprotected \$casts = ['flag' => 'boolean', 'payload' => ".var_export($cast, true)."];\n"
        .$methods."\n}\n", FILE_APPEND);
}
mkdir($workspace.'/database/migrations', recursive: true);
file_put_contents($workspace.'/database/migrations/2020_01_01_000000_create_fixture_rows.php', <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
file_put_contents(__DIR__.'/../../executed', 'migration');
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        Schema::create('fixture_rows', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('flag');
            $table->unsignedInteger('units')->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->decimal('hours', 8, 2);
            $table->json('payload');
            $table->string('label');
        });
        Schema::create('generated_rows', static function (Blueprint $table): void {
            $table->integer('units')->storedAs('17');
        });
        Schema::create('whole_rows', static function (Blueprint $table): void {
            $table->decimal('cost', 8, 0);
        });
    }
};
PHP);

$same = 'Assert::same($row->units, 17); ';
$reload = '$row->refresh(); ';
$zero = 'Assert::same($row->units, 0);';
$cases = [
    'boolean same uncertain existence' => ['Row', 'Assert::same($row->flag, true); '.$reload.'Assert::same($row->flag, false);', true],
    'boolean true false' => ['Row', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', true],
    'boolean native update then refresh' => ['Row', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'unrelated native array dirty comparison' => ['NativeArraySideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'unrelated native JSON dirty comparison' => ['NativeJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'unrelated native Unicode JSON dirty comparison' => ['NativeUnicodeSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'native JSON keeps unsafe boolean method Error' => ['NativeArraySideRow', '$row->flag->missing(); Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'JSON field update remains unaudited' => ['NativeArraySideRow', 'Assert::true($row->flag); $row->update(["payload" => "changed"]); '.$reload.'Assert::false($row->flag);', false],
    'JSON member path update remains unaudited' => ['NativeArraySideRow', 'Assert::true($row->flag); $row->update(["payload->flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON reader override changes existence' => ['ChangedJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON dirty override changes existence' => ['ChangedDirtySideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON equivalence override changes existence' => ['ChangedEquivalenceSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON save override changes existence' => ['ChangedSaveSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON custom cast remains uncertain' => ['CustomJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON object cast remains unaudited' => ['ObjectJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON collection cast remains unaudited' => ['CollectionJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated encrypted JSON remains unaudited' => ['EncryptedJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'unrelated JSON parameters remain unaudited' => ['ParameterizedJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'native JSON preserves stronger boolean read' => ['NarrowJsonSideRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'native JSON receiver alias remains unsafe' => ['NativeArraySideRow', '$alias = $row; Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'native JSON source-bound decoder activation' => ['NativeArraySideRow', 'Assert::true($row->flag); \Illuminate\Database\Eloquent\Casts\Json::decodeUsing("strlen"); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'native JSON alias decoder activation' => ['NativeArraySideRow', 'Assert::true($row->flag); JsonCodec::decodeUsing("strlen"); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'native JSON uncertain static callback dispatch' => ['NativeArraySideRow', 'Assert::true($row->flag); $codec = "Illuminate\\\\Database\\\\Eloquent\\\\Casts\\\\Json"; $codec::decodeUsing("strlen"); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'nullable integer zero' => ['Row', $same.$reload.$zero, true],
    'nullable integer null' => ['Row', $same.$reload.'Assert::same($row->units, null);', true],
    'decimal read separated from write' => ['Row', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, "28.00");', true],
    'declared alias' => ['AliasRow', 'Assert::same($row->flag, true); '.$reload.'Assert::same($row->flag, false);', true],
    'inferred nullable integer' => ['InferredRow', $same.$reload.$zero, true],
    'implemented interface hierarchy' => ['InterfaceHierarchyRow', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', true],
    'used trait hierarchy' => ['TraitHierarchyRow', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', true],
    'nonempty literal model lists' => ['LiteralListRow', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', true],
    'interfaces traits and literal model lists' => ['InterfaceTraitListRow', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', true],
    'nonempty literal lists under native update' => ['LiteralListRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'interface trait literal lists under native update' => ['InterfaceTraitListRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', true],
    'canonical whole decimal' => ['WholeDecimalRow', 'Assert::same($row->cost, "17"); '.$reload.'Assert::same($row->cost, "28");', true],
    'compatible intermediate property assertion' => ['Row', $same.$reload.'Assert::same($row->cost, "28.00"); '.$zero, true],
    'no refresh' => ['Row', $same.$zero, false],
    'fresh retains original' => ['Row', $same.'$row->fresh(); '.$zero, false],
    'conditional refresh' => ['Row', $same.'if ($condition) { '.$reload.'} '.$zero, false],
    'caught refresh' => ['Row', $same.'try { '.$reload.'} catch (\Throwable $failure) {} '.$zero, false],
    'known false existence' => ['Row', '$row->exists = false; '.$same.$reload.$zero, false],
    'asserted false existence' => ['Row', 'Assert::false($row->exists); '.$same.$reload.$zero, false],
    'surviving branch known unsaved' => ['Row', $same.'if ($row->exists) { return; } '.$reload.$zero, false],
    'known new unsaved receiver' => ['Row', '$row = new Row(); '.$same.$reload.$zero, false],
    'old receiver reference' => ['Row', '$alias =& $row; '.$same.$reload.$zero, false],
    'old receiver alias' => ['Row', '$alias = $row; '.$same.$reload.$zero, false],
    'captured receiver' => ['Row', '$callback = static function () use ($row): void {}; '.$same.$reload.$zero, false],
    'receiver reassigned' => ['Row', $same.'$row = new Row(); '.$reload.$zero, false],
    'different refreshed receiver' => ['Row', $same.'$other = new Row(); $other->refresh(); '.$zero, false],
    'property write after reload' => ['Row', $same.$reload.'$row->units = 17; '.$zero, false],
    'runtime cast mutation' => ['Row', $same.'$row->mergeCasts(["units" => "string"]); '.$reload.$zero, false],
    'source-known exists changed after refresh' => ['Row', $same.$reload.'$row->exists = false; '.$zero, false],
    'no-op wrapper' => ['NoopRow', $same.$reload.$zero, false],
    'trait override retains no-op reload' => ['TraitReloadOverrideRow', $same.$reload.$zero, false],
    'no-op delegated reload' => ['DelegatedNoopRow', $same.$reload.$zero, false],
    'no-op raw mutation' => ['RawNoopRow', $same.$reload.$zero, false],
    'constant legacy accessor' => ['ConstantRow', $same.$reload.$zero, false],
    'constant modern accessor' => ['ModernConstantRow', $same.$reload.$zero, false],
    'real property shadow' => ['ShadowRow', $same.$reload.$zero, false],
    'readonly property shadow' => ['ReadonlyShadowRow', $same.$reload.$zero, false],
    'custom cast' => ['CustomCastRow', $same.$reload.$zero, false],
    'changed cast reader' => ['ChangedCastsRow', $same.$reload.$zero, false],
    'changed raw reader' => ['ChangedReaderRow', $same.$reload.$zero, false],
    'changed decimal formatter' => ['ChangedDecimalRow', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, "28.00");', false],
    'load restores old value' => ['RestoringLoadRow', $same.$reload.$zero, false],
    'sync original restores old value' => ['RestoringOriginalRow', $same.$reload.$zero, false],
    'changed query dispatch' => ['ChangedQueryRow', $same.$reload.$zero, false],
    'stronger explicit read domain' => ['NarrowReadRow', $same.$reload.$zero, false],
    'known divergent descendant' => ['DivergentRow', $same.$reload.$zero, false],
    'custom builder property' => ['CustomBuilderRow', $same.$reload.$zero, false],
    'custom builder resolver' => ['BuilderResolverRow', $same.$reload.$zero, false],
    'cache merging restores old value' => ['RestoringCacheRow', $same.$reload.$zero, false],
    'custom builder class attribute' => ['BuilderAttributeRow', $same.$reload.$zero, false],
    'single cache merging restores old value' => ['RestoringSingleCacheRow', $same.$reload.$zero, false],
    'generated storage field' => ['GeneratedRow', $same.$reload.$zero, false],
    'unknown storage schema' => ['UnknownSchemaRow', $same.$reload.$zero, false],
    'whole decimal trailing dot' => ['WholeDecimalRow', 'Assert::same($row->cost, "17"); '.$reload.'Assert::same($row->cost, "28.");', false],
    'delegated save makes receiver unsaved' => ['CustomSaveRow', $same.'$row->update(["label" => "changed"]); '.$reload.$zero, false],
    'updated property setter makes receiver unsaved' => ['ExistenceSetterRow', 'Assert::true($row->flag); $row->update(["flag" => "0"]); '.$reload.'Assert::false($row->flag);', false],
    'updated custom cast makes receiver unsaved' => ['CustomUpdatedCastRow', $same.'$row->update(["note" => 9]); '.$reload.$zero, false],
    'dirty check makes receiver unsaved' => ['DirtyOverrideRow', $same.'$row->update(["label" => "changed"]); '.$reload.$zero, false],
    'helper input alias makes receiver unsaved' => ['Row', '$input->exists = false; '.$same.$reload.$zero, false],
    'helper input is known unsaved before binding' => ['Row', $same.$reload.$zero, false, '$input->exists = false; '],
    'compound existence assignment is a no-op reload' => ['Row', $same.'$row->exists &= false; '.$reload.$zero, false],
    'destructured existence assignment is a no-op reload' => ['Row', $same.'[$row->exists] = [false]; '.$reload.$zero, false],
    'compound attribute write after reload' => ['Row', $same.$reload.'$row->units += 17; '.$zero, false],
    'unset existence state is uncertain' => ['Row', $same.'unset($row->exists); '.$reload.$zero, false],
    'unrelated custom cast under update' => ['CustomUpdatedCastRow', $same.'$row->update(["flag" => "0"]); '.$reload.$zero, false],
    'date detection changes existence under update' => ['DateDetectionOverrideRow', $same.'$row->update(["flag" => "0"]); '.$reload.$zero, false],
    'timestamp setter changes existence under update' => ['TimestampSetterRow', $same.'$row->update(["flag" => "0"]); '.$reload.$zero, false],
    'timestamp constant selects existence setter under update' => ['TimestampKeyRow', $same.'$row->update(["label" => "changed"]); '.$reload.$zero, false],
    'unknown expression cannot become null literal' => ['Row', $same.$reload.'Assert::same($row->units, $this->nullValue());', false],
    'explicit float excludes decimal string' => ['Row', 'Assert::same($row->hours, 17.0); '.$reload.'Assert::same($row->hours, "28.00");', false],
    'decimal write union excludes float read' => ['Row', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, 28.0);', false],
    'decimal malformed' => ['Row', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, "invalid");', false],
    'decimal noncanonical scale' => ['Row', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, "28.0");', false],
    'decimal precision overflow' => ['Row', 'Assert::same($row->cost, "17.00"); '.$reload.'Assert::same($row->cost, "9999999.00");', false],
    'genuine unreachable branch' => ['Row', $same.$reload.'throw new \RuntimeException("Unreachable."); '.$zero, false],
    'genuine never expression' => ['Row', $same.$reload.'Assert::same($this->neverValue(), 0);', false],
    'incompatible read before later assertion' => ['Row', $same.$reload.'Assert::same($row->hours, "28.00"); '.$zero, false],
    'unknown intermediate expectation' => ['Row', $same.$reload.'Assert::same($row->cost, $this->nullValue()); '.$zero, false],
    'native array supplemental read' => ['NativeSupplementalRow', $same.'$snapshot = $row->payload; '.$reload.$zero, true],
    'native primitive supplemental read' => ['NativeSupplementalRow', $same.'$flag = $row->flag; '.$reload.$zero, true],
    'raw virtual supplemental read is deferred' => ['NativeSupplementalRow', $same.'$label = $row->label; '.$reload.$zero, false],
    'unread JSON override leaves primitive read native' => ['JsonSupplementalRow', $same.$reload.$zero, true],
    'unread custom cast leaves primitive read native' => ['CustomSupplementalRow', $same.$reload.$zero, true],
    'JSON supplemental read before old observation changes existence' => ['JsonSupplementalRow', '$snapshot = $row->payload; '.$same.$reload.$zero, false],
    'JSON supplemental read after old observation changes existence' => ['JsonSupplementalRow', $same.'$snapshot = $row->payload; '.$reload.$zero, false],
    'custom supplemental read before old observation changes existence' => ['CustomSupplementalRow', '$snapshot = $row->payload; '.$same.$reload.$zero, false],
    'custom supplemental read after old observation changes existence' => ['CustomSupplementalRow', $same.'$snapshot = $row->payload; '.$reload.$zero, false],
    'unresolved supplemental read cast is uncertain' => ['UnknownSupplementalRow', $same.'$snapshot = $row->payload; '.$reload.$zero, false],
    'raw supplemental read date dispatch changes existence' => ['RawSupplementalRow', $same.'$label = $row->label; '.$reload.$zero, false],
];
foreach ($mixinCases as $class => [, $label, $positive]) {
    $cases[$label] = [$class, 'Assert::same($row->flag, true); '.$reload.'Assert::same($row->flag, false);', $positive];
}
$originHelpers = [
    'sourceHelperOwnFalse' => ['helper mutates existence before forwarding input', '$value->exists = false; return $value;'],
    'sourceHelperAliasFalse' => ['helper alias mutates existence', '$alias = $value; $alias->exists = false; return $value;'],
    'sourceHelperReferenceFalse' => ['helper reference mutates existence', '$alias =& $value; $alias->exists = false; return $value;'],
    'sourceHelperNestedFalse' => ['helper nested alias mutates existence', '$owners = ["entry" => $value]; $owners["entry"]->exists = false; return $value;'],
    'sourceHelperConditionalFalse' => ['helper conditional existence mutation', 'if ($value->flag) { $value->exists = false; } return $value;'],
    'sourceHelperAssertedFalse' => ['helper establishes false existence', 'Assert::false($value->exists); return $value;'],
    'sourceHelperNewFilled' => ['helper returns a filled fresh model', 'return (new Row)->fill(["flag" => true]);'],
    'sourceHelperMake' => ['helper returns native model make', 'return Row::make(["flag" => true]);'],
    'sourceHelperNewInstance' => ['helper returns native new instance', 'return $value->newInstance(["flag" => true]);'],
    'sourceHelperNewModelInstance' => ['helper returns query new model instance', 'return Row::query()->newModelInstance(["flag" => true]);'],
    'sourceHelperConstructedAlias' => ['helper returns a constructed local alias', '$created = new Row; $alias = $created; $alias->fill(["flag" => true]); return $alias;'],
    'sourceHelperReturnedFalseAlias' => ['helper returns an existence-mutated alias', '$alias = $value; $alias->exists = false; return $alias;'],
    'sourceHelperConditionalNew' => ['helper conditionally constructs an unsaved model', 'if ($value->flag) { return (new Row)->fill(["flag" => true]); } return $value;'],
    'sourceHelperReplicate' => ['helper returns native replicated model', 'return $value->replicate();'],
    'sourceHelperReplicateQuietly' => ['helper returns native quietly replicated model', 'return $value->replicateQuietly();'],
    'sourceHelperMakeOne' => ['helper returns native factory make one', 'return Row::factory()->makeOne(["flag" => true]);'],
    'sourceHelperFactoryNewModel' => ['helper returns native factory new model', 'return Row::factory()->newModel()->fill(["flag" => true]);'],
];
foreach ($originHelpers as $helper => [$label]) {
    $cases[$label] = ['Row', 'Assert::true($row->flag); '.$reload.'Assert::false($row->flag);', false, '', $helper];
}
$source = "<?php\nnamespace Fixture;\nuse Testo\\Assert;\nuse Illuminate\\Database\\Eloquent\\Casts\\Json as JsonCodec;\nfinal class RefreshScenarios {\n";
foreach (array_unique(array_column($cases, 0)) as $class) {
    $source .= 'private function source'.$class.'('.$class.' $value): '.$class.' { return $value; }' ."\n";
}
foreach ($originHelpers as $helper => [, $helperBody]) {
    $source .= 'private function '.$helper.'(Row $value): Row { '.$helperBody." }\n";
}
$source .= 'private function neverValue(): never { throw new \RuntimeException("No value."); }'."\n";
$source .= 'private function nullValue(): null { return null; }'."\n";
$ranges = [];
foreach ($cases as $label => [$class, $body, $positive]) {
    $start = strlen($source);
    $helper = $cases[$label][4] ?? 'source'.$class;
    $source .= 'public function scenario'.count($ranges).'('.$class.' $input, bool $condition = true): void { '.($cases[$label][3] ?? '').'$row = $this->'.$helper.'($input); '.$body." }\n";
    $ranges[$label] = [$start, strlen($source)];
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/proof.php', <<<'PHP'
<?php
namespace Fixture;
use Testo\Assert;
final class RefreshProof {
    private function source(Row $value): Row { return $value; }
    public function check(Row $input): void {
        $row = $this->source($input);
        Assert::same($row->flag, true);
        $row->update(['flag' => '0']);
        $row->refresh();
        Assert::same($row->flag, false);
    }
}
PHP);
file_put_contents($workspace.'/json-proof.php', <<<'PHP'
<?php
namespace Fixture;
use Testo\Assert;
final class JsonRefreshProof {
    private function source(NativeArraySideRow $value): NativeArraySideRow { return $value; }
    public function check(NativeArraySideRow $input): void {
        $row = $this->source($input);
        $row->flag->missing();
        Assert::true($row->flag);
        $row->update(['flag' => '0']);
        $row->refresh();
        Assert::false($row->flag);
    }
}
PHP);
file_put_contents($workspace.'/json-shadow.php', <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Casts;
function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed {
    throw new \RuntimeException('Namespaced JSON decoder body must never execute.');
}
PHP);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$mode = $argv[3];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/refreshed-properties', 'Refreshed properties', 'Independent native mutation and virtual read domains');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $registry->registerIssueFilterHook(new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter($index));
    }
};
$plugins = in_array($mode, ['standalone', 'json-standalone', 'json-shadow-standalone'], true) ? [] : [new \Ichinya\Laramago\Analyzer\LaravelPlugin($argv[2])];
if (in_array($mode, ['standalone', 'proven', 'contexts', 'json-standalone', 'json-shadow-standalone'], true)) { $plugins[] = $plugin; }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/refreshed-properties', name: 'Refreshed properties', version: '1', analyzerPlugins: $plugins)))->run();
PHP);

file_put_contents($workspace.'/proof-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/refresh-contexts', 'Refresh contexts', 'Exact source, diagnostic and effective metadata controls');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter($index);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            public function __construct(private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $modelFile = $this->root.'/models.php';
                $bytes = file_get_contents($modelFile);
                $reserve = '/* model snapshot reserve: ................................................................................................................ */';
                $changedReader = 'public function getFlagAttribute(): bool { return true; }';
                $changedReader .= str_repeat(' ', strlen($reserve) - strlen($changedReader));
                $changedTimestamp = "const UPDATED_AT = 'altered_at';";
                $changedTimestamp .= str_repeat(' ', strlen($reserve) - strlen($changedTimestamp));
                if (!str_contains($bytes, $reserve)) { throw new \RuntimeException('Missing equal-length model snapshot control.'); }
                $modelBindings = [
                    'constant getter' => str_replace($reserve, $changedReader, $bytes),
                    'changed primitive cast' => str_replace("'flag' => 'boolean'", "'flag' => 'integer'", $bytes),
                    'stronger current read declaration' => str_replace('@property bool $flag', '@property true $flag', $bytes),
                    'own timestamp constant replaces inherited declaration' => str_replace($reserve, $changedTimestamp, $bytes),
                    'changed fillable literal list' => str_replace("protected \$fillable = ['flag', 'label'];", "protected \$fillable = ['cost', 'label'];", $bytes),
                    'changed hidden literal list' => str_replace("protected \$hidden = ['cost', 'hours'];", "protected \$hidden = ['flag', 'hours'];", $bytes),
                ];
                foreach ($modelBindings as $label => $changedBytes) {
                    if ($changedBytes === $bytes || strlen($changedBytes) !== strlen($bytes)) { throw new \RuntimeException('Invalid equal-length model snapshot control: '.$label); }
                    $probe = new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter(clone $this->filter->provenance);
                    file_put_contents($modelFile, $changedBytes);
                    try {
                        if ($probe->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh accepted changed model source before first domain resolution: '.$label); }
                    } finally { file_put_contents($modelFile, $bytes); }
                }
                $nativeBindings = [
                    'decimal import binding' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', 'use Brick\\Math\\BigDecimal;', 'use Trick\\Math\\BigDecimal;'],
                    'assertion import binding' => ['packages/testo/assert/Assert.php', 'use Testo\\Assert\\Internal\\StaticState;', 'use Testo\\Assert\\External\\StaticState;'],
                    'assertion namespace binding' => ['packages/testo/assert/Assert.php', 'namespace Testo;', 'namespace Other;'],
                    'created timestamp constant selects a setter' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', "const CREATED_AT = 'created_at';", "const CREATED_AT = 'altered_at';"],
                    'updated timestamp constant selects a setter' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', "const UPDATED_AT = 'updated_at';", "const UPDATED_AT = 'altered_at';"],
                ];
                foreach ($nativeBindings as $label => [$path, $original, $replacement]) {
                    $file = $this->root.'/'.$path;
                    $originalBytes = file_get_contents($file);
                    $changedBytes = str_replace($original, $replacement, $originalBytes, $replaced);
                    if ($replaced !== 1 || strlen($changedBytes) !== strlen($originalBytes)) { throw new \RuntimeException('Invalid equal-length native binding control: '.$label); }
                    $probe = new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter(clone $this->filter->provenance);
                    file_put_contents($file, $changedBytes);
                    try {
                        if ($probe->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh accepted changed native binding before first domain resolution: '.$label); }
                    } finally { file_put_contents($file, $originalBytes); }
                }
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { return $result; }
                $checks = count($modelBindings) + count($nativeBindings);
                foreach (['span-start', 'span-end', 'foreign', 'duplicate', 'kind', 'primary-message', 'code', 'level', 'message', 'context-source', 'context-file', 'notes', 'help', 'link'] as $variant) {
                    $annotations = $context->issue->annotations;
                    $annotation = $annotations[0];
                    $annotations[0] = new \Mago\Sdk\Reporting\Annotation(
                        $variant === 'kind' ? \Mago\Sdk\Reporting\AnnotationKind::Secondary : $annotation->kind,
                        new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span-start' ? 1 : 0), $annotation->span->end + ($variant === 'span-end' ? 1 : 0)),
                        $variant === 'primary-message' ? 'Unknown property observation.' : $annotation->message,
                        $variant === 'foreign' ? 'other.php' : $annotation->file);
                    if ($variant === 'duplicate') { $annotations[] = $annotation; }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue(
                        $variant === 'level' ? \Mago\Sdk\Reporting\Level::Warning : $context->issue->level,
                        $variant === 'code' ? 'invalid-property-write' : $context->issue->code,
                        $variant === 'message' ? 'Unknown property assertion.' : $context->issue->message,
                        $variant === 'notes' ? ['Unknown property constraint.'] : $context->issue->notes,
                        $variant === 'help' ? 'Unknown read contract.' : $context->issue->help,
                        $variant === 'link' ? 'https://example.invalid/unknown-rule' : $context->issue->link,
                        $annotations, $context->issue->edits);
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? 'other.php' : $context->file,
                        $variant === 'context-source' ? str_replace('flag', 'other', $context->contents) : $context->contents, $issue);
                    if ($this->filter->filterIssue($changed) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh context accepted '.$variant); }
                    $checks++;
                }
                foreach (['proof.php', 'models.php', 'packages/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', 'packages/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php'] as $path) {
                    $file = $this->root.'/'.$path;
                    $bytes = file_get_contents($file);
                    file_put_contents($file, '<?php /* changed source snapshot */');
                    try {
                        if ($this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh context accepted changed disk bytes: '.$path); }
                        $checks++;
                    } finally { file_put_contents($file, $bytes); }
                }
                $targets = [
                    'caller' => $context->codebase->getMethod('Fixture\\RefreshProof', 'check'),
                    'origin' => $context->codebase->getMethod('Fixture\\RefreshProof', 'source'),
                    'reload' => $context->codebase->getMethod('Fixture\\Row', 'refresh') ?? $context->codebase->getDeclaringMethod('Fixture\\Row', 'refresh'),
                    'delegated reload' => $context->codebase->getMethod('Fixture\\Row', 'refreshUsingQuery') ?? $context->codebase->getDeclaringMethod('Fixture\\Row', 'refreshUsingQuery'),
                    'raw mutation' => $context->codebase->getMethod('Fixture\\Row', 'setRawAttributes') ?? $context->codebase->getDeclaringMethod('Fixture\\Row', 'setRawAttributes'),
                    'read dispatch' => $context->codebase->getMethod('Fixture\\Row', 'getAttributeValue') ?? $context->codebase->getDeclaringMethod('Fixture\\Row', 'getAttributeValue'),
                    'assertion' => $context->codebase->getMethod('Testo\\Assert', 'same'),
                ];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                foreach ($targets as $role => $metadata) {
                    if ($metadata === null) { throw new \RuntimeException('Missing refresh metadata control: '.$role); }
                    foreach (['file', 'name-file', 'name-span', 'body-span', 'identifier-class', 'original-name', 'kind', 'static', 'reference'] as $variant) {
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'name-file') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->nameLocation->span); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation->file, new \Mago\Sdk\Span($metadata->nameLocation->span->start + 1, $metadata->nameLocation->span->end)); }
                        if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start, $metadata->location->span->end - 1)); }
                        if ($variant === 'identifier-class') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, $metadata->identifier->name, 'OtherOwner'); }
                        if ($variant === 'original-name') { $values['originalName'] = 'other'; }
                        if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure; }
                        if ($variant === 'static') { $values['static'] = !$metadata->static; }
                        if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        $replaced = 0;
                        foreach ($snapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $metadata) { $cache->values[$operation][$key] = $changed; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh metadata accepted '.$role.' '.$variant); }
                            $checks++;
                        } finally { $cache->values = $snapshot; }
                    }
                }
                $row = $context->codebase->getClassLike('Fixture\\Row');
                $ancestor = $context->codebase->getClassLike('Illuminate\\Database\\Eloquent\\Model');
                if ($row === null || $ancestor === null || count($row->mixins) !== 1) { throw new \RuntimeException('Missing exact native Model mixin metadata.'); }
                $nativeAtom = \Mago\Sdk\Analyzer\Type::namedObject('Illuminate\\Database\\Eloquent\\Model')->atomicTypes[0];
                $otherAtom = \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\PlainMixin')->atomicTypes[0];
                $intersected = get_object_vars($nativeAtom);
                $intersected['intersections'] = [$otherAtom];
                $mixinTypes = [
                    'arbitrary class' => \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\PlainMixin'),
                    'unresolved class' => \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\UnresolvedMixin'),
                    'self class' => \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\Row'),
                    'trait' => \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\MarkerMixin'),
                    'interface' => \Mago\Sdk\Analyzer\Type::namedObject('Fixture\\MixinContract'),
                    'parameters' => \Mago\Sdk\Analyzer\Type::namedObject('Illuminate\\Database\\Eloquent\\Model', \Mago\Sdk\Analyzer\Type::mixed()),
                    'intersection' => \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\NamedObjectType(...$intersected)),
                    'union' => \Mago\Sdk\Analyzer\Type::fromAtomics($nativeAtom, $otherAtom),
                    'duplicate union atoms' => \Mago\Sdk\Analyzer\Type::fromAtomics($nativeAtom, $nativeAtom),
                ];
                foreach (['static', 'isThis', 'remappedParameters', 'variances'] as $variant) {
                    $values = get_object_vars($nativeAtom);
                    $values[$variant] = $variant === 'variances' ? [\Mago\Sdk\Analyzer\Type\Variance::Covariant] : true;
                    if ($variant === 'variances') { $values['parameters'] = null; }
                    $mixinTypes[$variant] = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\NamedObjectType(...$values));
                }
                $mixinVariants = [];
                foreach ($mixinTypes as $variant => $type) {
                    $values = get_object_vars($row);
                    $values['mixins'] = [$type];
                    $mixinVariants[$variant] = [$row, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values)];
                }
                $values = get_object_vars($row);
                $values['parentClasses'] = [];
                $mixinVariants['native mixin lacks actual ancestor'] = [$row, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values)];
                foreach ([\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait, \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface] as $kind) {
                    $values = get_object_vars($ancestor);
                    $values['kind'] = $kind;
                    $mixinVariants['native ancestor '.$kind->name] = [$ancestor, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values)];
                }
                $values = get_object_vars($ancestor);
                $values['mixins'] = [\Mago\Sdk\Analyzer\Type::namedObject('Fixture\\PlainMixin')];
                $mixinVariants['native ancestor has separate mixin'] = [$ancestor, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values)];
                $values = get_object_vars($ancestor);
                $values['unresolvedHierarchyDependencies'] = ['Fixture\\UnresolvedMixin'];
                $mixinVariants['native ancestor has incomplete hierarchy'] = [$ancestor, new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values)];
                $mixinSnapshot = $cache->values;
                foreach ($mixinVariants as $variant => [$original, $changed]) {
                    $replaced = 0;
                    foreach ($mixinSnapshot as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && strcasecmp($entry->name, $original->name) === 0) { $cache->values[$operation][$key] = $changed; $replaced++; }
                        }
                    }
                    try {
                        if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh accepted nonnative mixin metadata: '.$variant); }
                        $checks++;
                    } finally { $cache->values = $mixinSnapshot; }
                }
                foreach (['Fixture\\ModelAuditContract', 'Fixture\\ModelAuditTrait'] as $name) {
                    $original = $context->codebase->getClassLike($name);
                    $hierarchy = array_map('strtolower', [...$context->codebase->getClassAncestors('Fixture\\Row'), ...$row->usedTraits]);
                    if ($original === null || $original->hasIncompleteHierarchy()
                        || ! in_array($original->kind, [\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface, \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait], true)
                        || ! in_array(strtolower($name), $hierarchy, true)) {
                        throw new \RuntimeException('Missing complete non-class hierarchy control: '.$name);
                    }
                    $values = get_object_vars($original);
                    $values['unresolvedHierarchyDependencies'] = ['Fixture\\UnresolvedAncestor'];
                    $changed = new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values);
                    $hierarchySnapshot = $cache->values;
                    $replaced = 0;
                    foreach ($hierarchySnapshot as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && strcasecmp($entry->name, $original->name) === 0) { $cache->values[$operation][$key] = $changed; $replaced++; }
                        }
                    }
                    try {
                        if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh accepted incomplete non-class hierarchy: '.$name); }
                        $checks++;
                    } finally { $cache->values = $hierarchySnapshot; }
                }
                foreach (['$fillable' => ['flag', 'label'], '$hidden' => ['cost', 'hours']] as $name => $literals) {
                    $original = $context->codebase->getDeclaringProperty('Fixture\\Row', $name);
                    $list = $original?->defaultType?->type->atomicTypes[0] ?? null;
                    if (! $list instanceof \Mago\Sdk\Analyzer\Type\ListType || $list->knownCount !== 2 || count($list->knownElements ?? []) !== 2) {
                        throw new \RuntimeException('Missing exact nonempty model-default ListType: '.$name);
                    }
                    $element = static fn (int $index, bool $optional, \Mago\Sdk\Analyzer\Type $type) => new \Mago\Sdk\Analyzer\Type\ListElement($index, $optional, $type);
                    $first = \Mago\Sdk\Analyzer\Type::literalString($literals[0]);
                    $second = \Mago\Sdk\Analyzer\Type::literalString($literals[1]);
                    $variants = [
                        'unknown elements' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, null, 2, true),
                        'unknown count' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, $list->knownElements, null, true),
                        'optional element' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, true, $first), $element(1, false, $second)], 2, true),
                        'partial elements' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, $first)], 2, true),
                        'sparse elements' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, $first), $element(2, false, $second)], 2, true),
                        'duplicate indices' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, $first), $element(0, false, $second)], 2, true),
                        'wrong literal' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, \Mago\Sdk\Analyzer\Type::literalString('other')), $element(1, false, $second)], 2, true),
                        'wrong order' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, $second), $element(1, false, $first)], 2, true),
                        'nonliteral element' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [$element(0, false, \Mago\Sdk\Analyzer\Type::string()), $element(1, false, $second)], 2, true),
                        'wrong count' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, $list->knownElements, 3, true),
                        'empty shape' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, [], 0, false),
                        'inconsistent nonempty flag' => new \Mago\Sdk\Analyzer\Type\ListType($list->elementType, $list->knownElements, 2, false),
                    ];
                    $types = array_map(static fn ($atomic) => \Mago\Sdk\Analyzer\Type::fromAtomic($atomic), $variants);
                    $types['union default'] = \Mago\Sdk\Analyzer\Type::fromAtomics($list, \Mago\Sdk\Analyzer\Type::null()->atomicTypes[0]);
                    $listSnapshot = $cache->values;
                    foreach ($types as $variant => $type) {
                        $values = get_object_vars($original);
                        $values['defaultType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($original->defaultType->location, $type, $original->defaultType->fromDocblock, $original->defaultType->inferred);
                        $changed = new \Mago\Sdk\Analyzer\Metadata\PropertyMetadata(...$values);
                        $replaced = 0;
                        foreach ($listSnapshot as $operation => $entries) {
                            foreach ($entries as $key => $entry) {
                                if ($entry === $original) { $cache->values[$operation][$key] = $changed; $replaced++; }
                            }
                        }
                        try {
                            if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh accepted nonexact default list: '.$name.' '.$variant); }
                            $checks++;
                        } finally { $cache->values = $listSnapshot; }
                    }
                }
                $property = $context->codebase->getDeclaringMagicProperty('Fixture\\Row', '$flag');
                $propertySnapshot = $cache->values;
                $values = get_object_vars($property);
                $values['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($property->type->location, \Mago\Sdk\Analyzer\Type::true(), true, false);
                $changed = new \Mago\Sdk\Analyzer\Metadata\PropertyMetadata(...$values);
                $replaced = 0;
                foreach ($propertySnapshot as $operation => $entries) {
                    foreach ($entries as $key => $entry) {
                        if ($entry === $property) { $cache->values[$operation][$key] = $changed; $replaced++; }
                    }
                }
                try {
                    if ($replaced === 0 || $this->filter->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Refresh metadata ignored stronger explicit read domain.'); }
                    $checks++;
                } finally { $cache->values = $propertySnapshot; }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Refresh controls mutated a valid proof.'); }
                file_put_contents($this->root.'/context-checks.log', $checks."\n", FILE_APPEND);
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/refresh-contexts', name: 'Refresh contexts', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);

file_put_contents($workspace.'/json-proof-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/json-refresh-contexts', 'JSON refresh contexts', 'Genuine native unrelated JSON dispatch controls');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\RefreshedModelPropertyIssueFilter($index);
        $registry->registerIssueFilterHook(new class($filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $recorded = false;
            public function __construct(private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                $result = $this->filter->filterIssue($context);
                if ($this->recorded || !str_ends_with($context->file, 'json-proof.php')) { return $result; }
                $this->recorded = true;
                $checks = [];
                $expect = function (string $label, bool $accepted) use ($context, &$checks): void {
                    $actual = $this->filter->filterIssue($context) === \Mago\Sdk\Analyzer\IssueFilterDecision::Remove;
                    $checks[$label] = $actual === $accepted;
                    file_put_contents($this->root.'/json-controls-progress.json', json_encode($checks, JSON_THROW_ON_ERROR));
                    if (!$checks[$label]) { throw new \RuntimeException('JSON refresh control failed: '.$label); }
                };
                $expect('genuine native JSON positive', true);
                $codec = $context->codebase->getClass('Illuminate\\Database\\Eloquent\\Casts\\Json');
                $decode = $context->codebase->getMethod('Illuminate\\Database\\Eloquent\\Casts\\Json', 'decode');
                $reader = $context->codebase->getMethod('Fixture\\NativeArraySideRow', 'fromJson')
                    ?? $context->codebase->getDeclaringMethod('Fixture\\NativeArraySideRow', 'fromJson');
                $declaredReader = $context->codebase->getDeclaringMethod('Fixture\\NativeArraySideRow', 'fromJson');
                $builtin = $context->codebase->getFunction('json_decode');
                if ($codec === null || $decode === null || $reader === null || $declaredReader === null || $builtin === null) { throw new \RuntimeException('Missing genuine JSON metadata.'); }
                file_put_contents($this->root.'/json-native-metadata.txt', var_export(['codec' => $codec, 'decode' => $decode, 'reader' => $reader, 'declaredReader' => $declaredReader, 'builtin' => $builtin, 'issue' => $context->issue], true));
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $snapshot = $cache->values;
                $variants = [
                    'missing codec class' => [$codec, null],
                    'codec wrong name' => [$codec, ['name' => 'Other\\Json']],
                    'codec interface' => [$codec, ['kind' => \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]],
                    'codec incomplete hierarchy' => [$codec, ['unresolvedHierarchyDependencies' => ['Unknown']]],
                    'codec foreign parent' => [$codec, ['parentClasses' => ['Other\\Codec']]],
                    'codec foreign trait' => [$codec, ['usedTraits' => ['Other\\Codec']]],
                    'codec foreign file' => [$codec, ['location' => new \Mago\Sdk\SourceLocation('other.php', $codec->location->span)]],
                    'codec changed span' => [$codec, ['location' => new \Mago\Sdk\SourceLocation($codec->location->file, new \Mago\Sdk\Span($codec->location->span->start + 1, $codec->location->span->end))]],
                    'codec changed name file' => [$codec, ['nameLocation' => new \Mago\Sdk\SourceLocation('other.php', $codec->nameLocation->span)]],
                    'codec changed name span' => [$codec, ['nameLocation' => new \Mago\Sdk\SourceLocation($codec->nameLocation->file, new \Mago\Sdk\Span($codec->nameLocation->span->start + 1, $codec->nameLocation->span->end))]],
                    'builtin missing' => [$builtin, null],
                    'builtin wrong name' => [$builtin, ['name' => 'other_decode']],
                    'builtin removed native flag' => [$builtin, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($builtin->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN)]],
                    'builtin user-defined flag' => [$builtin, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($builtin->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED)]],
                    'builtin reference return' => [$builtin, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($builtin->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
                    'builtin changed arity' => [$builtin, ['parameters' => array_slice($builtin->parameters, 0, 3)]],
                    'builtin changed effective return' => [$builtin, ['returnType' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($builtin->returnType->location, \Mago\Sdk\Analyzer\Type::string(), $builtin->returnType->fromDocblock, $builtin->returnType->inferred)]],
                    'builtin changed declared return' => [$builtin, ['declaredReturnType' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($builtin->declaredReturnType->location, \Mago\Sdk\Analyzer\Type::string(), $builtin->declaredReturnType->fromDocblock, $builtin->declaredReturnType->inferred)]],
                ];
                foreach (['parameter name', 'parameter type', 'parameter default', 'parameter reference'] as $label) {
                    $parameters = $builtin->parameters;
                    $parameter = $parameters[1];
                    $values = get_object_vars($parameter);
                    if ($label === 'parameter name') { $values['name'] = '$other'; }
                    if ($label === 'parameter type') { $values['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->type->location, \Mago\Sdk\Analyzer\Type::string(), $parameter->type->fromDocblock, $parameter->type->inferred); }
                    if ($label === 'parameter default') { $values['defaultType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->defaultType->location, \Mago\Sdk\Analyzer\Type::true(), $parameter->defaultType->fromDocblock, $parameter->defaultType->inferred); }
                    if ($label === 'parameter reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                    $parameters[1] = new ($parameter::class)(...$values);
                    $variants['builtin '.$label] = [$builtin, ['parameters' => $parameters]];
                }
                foreach (['decoder' => $decode, 'receiver reader' => $reader] as $role => $method) {
                    $variants[$role.' missing'] = [$method, null];
                    $variants[$role.' wrong owner'] = [$method, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($method->identifier->kind, $method->identifier->name, 'Other\\Codec')]];
                    $variants[$role.' foreign file'] = [$method, ['location' => new \Mago\Sdk\SourceLocation('other.php', $method->location->span)]];
                    $variants[$role.' changed body span'] = [$method, ['location' => new \Mago\Sdk\SourceLocation($method->location->file, new \Mago\Sdk\Span($method->location->span->start, $method->location->span->end - 1))]];
                    $variants[$role.' changed name span'] = [$method, ['nameLocation' => new \Mago\Sdk\SourceLocation($method->nameLocation->file, new \Mago\Sdk\Span($method->nameLocation->span->start + 1, $method->nameLocation->span->end))]];
                    $variants[$role.' static dispatch'] = [$method, ['static' => !$method->static]];
                    $variants[$role.' reference return'] = [$method, ['flags' => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($method->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]];
                    $variants[$role.' changed arity'] = [$method, ['parameters' => array_slice($method->parameters, 0, 1)]];
                }
                foreach ($variants as $label => [$original, $changes]) {
                    $class = $original::class;
                    $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) {
                        if ($entry === $original || $original === $reader && $entry === $declaredReader) {
                            $cache->values[$operation][$key] = $changes === null ? null : new $class(...array_replace(get_object_vars($entry), $changes));
                            $replaced++;
                        }
                    } }
                    try {
                        if ($replaced === 0) { throw new \RuntimeException('Vacuous genuine JSON metadata mutation: '.$label); }
                        $expect($label, false);
                    } finally { $cache->values = $snapshot; }
                }
                $sourceVariants = [
                    'configured literal decoder default' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Casts/Json.php', 'protected static $decoder;', 'protected static $decoder = "strlen";'],
                    'changed codec decoder branch' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Casts/Json.php', ': json_decode($value, $associative);', ': json_encode($value, $associative);'],
                    'changed codec namespace' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Casts/Json.php', 'namespace Illuminate\\Database\\Eloquent\\Casts;', 'namespace Other\\Database\\Eloquent\\Casts;'],
                    'changed JSON import alias' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', 'use Illuminate\\Database\\Eloquent\\Casts\\Json;', 'use Other\\Database\\Eloquent\\Casts\\Json;'],
                    'changed native receiver dirty reader' => ['packages/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', 'foreach ($this->getAttributes() as $key => $value) {', 'foreach ($this->getCasts() as $key => $value) {'],
                ];
                foreach ($sourceVariants as $label => [$path, $before, $after]) {
                    $file = $this->root.'/'.$path;
                    $bytes = file_get_contents($file);
                    $changed = str_replace($before, $after, $bytes, $replaced);
                    if ($replaced !== 1) { throw new \RuntimeException('Vacuous JSON source control: '.$label.' '.$replaced); }
                    file_put_contents($file, $changed);
                    try { $expect($label, false); }
                    finally { file_put_contents($file, $bytes); }
                    $expect('restored '.$label, true);
                }
                $expect('restored native JSON metadata', true);
                file_put_contents($this->root.'/json-controls.json', json_encode($checks, JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/json-refresh-contexts', 'JSON refresh contexts', '1', analyzerPlugins: [$plugin])))->run();
PHP);

$analyze = static function (string $mode, int $workers = 1, bool $external = false, array $paths = ['cases.php']) use ($workspace, $package, $vendor, $nativeSources): array {
    $configuration = $external ? $workspace.' external configuration' : $workspace;
    if (!is_dir($configuration)) { mkdir($configuration); }
    $hosts = in_array($mode, ['native', 'json-native', 'json-shadow-native'], true) ? new stdClass : ['fixture' => [
        'command' => in_array($mode, ['integrated', 'json-shadow-integrated'], true)
            ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/'.match ($mode) { 'contexts' => 'proof-worker.php', 'json-contexts' => 'json-proof-worker.php', default => 'worker.php' }, $package.'/vendor/autoload.php', $workspace, $mode],
        'workers' => $workers, 'request-timeout-ms' => 120000,
    ]];
    file_put_contents($configuration.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $paths, 'includes' => [...array_map(static fn (string $path): string => $vendor.'/'.$path, array_keys($nativeSources)), 'models.php', 'support.php', ...(str_starts_with($mode, 'json-shadow-') ? ['json-shadow.php'] : [])]],
        'extension-hosts' => $hosts,
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $name = $mode.'-'.$workers.($external ? '-external' : '');
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$name.'.json', 'w'], 2 => ['file', $workspace.'/'.$name.'.log', 'w'],
    ], $pipes, $configuration);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error|worker .* exited|did not answer/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    return json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary') { continue; }
            $result[] = [$issue['code'], $issue['message'], $annotation['span']['file_id']['name'], $annotation['span']['start']['offset'], $annotation['span']['end']['offset']];
            break;
        }
    }
    sort($result);
    return $result;
};
$group = static function (array $issues) use ($ranges, $signature): array {
    $grouped = [];
    foreach ($signature($issues) as $issue) {
        if ($issue[2] !== 'cases.php') { continue; }
        foreach ($ranges as $label => [$start, $end]) {
            if ($issue[3] >= $start && $issue[3] < $end) { $grouped[$label][] = $issue; break; }
        }
    }
    return $grouped;
};
if (in_array('--prepare-only', $argv, true)) {
    require $package.'/vendor/autoload.php';
    $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
    $prepared = [...array_map(static fn (string $path): string => $vendor.'/'.$path, array_keys($nativeSources)),
        'models.php', 'support.php', 'cases.php', 'proof.php', 'json-proof.php', 'json-shadow.php', 'worker.php', 'proof-worker.php', 'json-proof-worker.php'];
    foreach ($prepared as $file) { $parser->parse(file_get_contents($workspace.'/'.$file)); }
    echo 'Prepared and parsed '.count($prepared).' generated sources without analyzer or fixture execution: '.$workspace."\n";
    exit(0);
}
$wholeSignature = static function (array $issues): array {
    $result = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($result);
    return $result;
};
$wholeWithin = static function (array $issues, string $file, ?array $range = null): array {
    return array_values(array_filter($issues, static function (array $issue) use ($file, $range): bool {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary' || $annotation['span']['file_id']['name'] !== $file) { continue; }
            $position = $annotation['span']['start']['offset'];
            return $range === null || $position >= $range[0] && $position < $range[1];
        }
        return false;
    }));
};
// Keep original 139 SDK controls; the JSON focus has independent genuine native controls.
$jsonLabels = array_keys(array_filter($cases, static fn (array $case): bool => isset($jsonModels[$case[0]])));
$native = $analyze('native');
$control = $analyze('control');
if (in_array('--baseline', $argv, true)) {
    foreach (['native' => $native, 'control' => $control] as $mode => $issues) {
        $grouped = $group($issues);
        foreach ($cases as $label => [, , $positive]) { echo $mode.' '.$label.': '.implode(',', array_column($grouped[$label] ?? [], 0))."\n"; }
    }
    echo 'Refreshed property baseline: workspace='.$workspace.".\n";
    exit(0);
}
$standalone = $analyze('standalone');
$proven = $analyze('proven');
foreach ([[$native, $standalone], [$control, $proven]] as [$beforeReports, $afterReports]) {
    foreach ($jsonLabels as $label) {
        $before = $wholeWithin($beforeReports, 'cases.php', $ranges[$label]);
        $after = $wholeWithin($afterReports, 'cases.php', $ranges[$label]);
        $targets = array_values(array_filter($before, static fn (array $issue): bool => $issue['level'] === 'Error' && $issue['code'] === 'impossible-type-comparison'));
        if (count($targets) !== 1) { throw new RuntimeException('Vacuous genuine JSON contradiction: '.$label.' '.$workspace); }
        $expected = $cases[$label][2] ? array_values(array_filter($before, static fn (array $issue): bool => $issue !== $targets[0])) : $before;
        if ($wholeSignature($after) !== $wholeSignature($expected)) { throw new RuntimeException('Complete JSON source report changed: '.$label.' '.$workspace); }
        if ($label === 'native JSON keeps unsafe boolean method Error'
            && count(array_filter($after, static fn (array $issue): bool => $issue['level'] === 'Error')) < 1) {
            throw new RuntimeException('Missing preserved unsafe JSON boolean use Error. '.$workspace);
        }
    }
}
$corrected = 0;
$retained = 0;
foreach ([[$native, $standalone], [$control, $proven]] as [$beforeReports, $afterReports]) {
    $before = $group($beforeReports);
    $after = $group($afterReports);
    foreach ($cases as $label => [, , $positive]) {
        $original = $before[$label] ?? [];
        $actual = $after[$label] ?? [];
        if (!$positive) {
            if (isset($cases[$label][4]) && !in_array('impossible-type-comparison', array_column($original, 0), true)) {
                throw new RuntimeException('Missing genuine helper-origin contradiction: '.$label.' '.json_encode($original).' '.$workspace);
            }
            if ($actual !== $original) { throw new RuntimeException('Unsafe refreshed property correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
            if ($original !== []) { $retained++; }
            continue;
        }
        $removed = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] === 'impossible-type-comparison'));
        $expected = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] !== 'impossible-type-comparison'));
        if (count($removed) !== 1) { throw new RuntimeException('Missing refreshed property native baseline: '.$label.' '.json_encode($original).' '.$workspace); }
        if ($actual !== $expected) { throw new RuntimeException('Missing exact refreshed property correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
        $corrected += count($removed);
    }
}
if ($signature($analyze('proven', external: true)) !== $signature($proven)) { throw new RuntimeException('External configuration lost the explicit application root. '.$workspace); }
$contexts = $analyze('contexts', paths: ['proof.php']);
$contextChecks = file_exists($workspace.'/context-checks.log') ? array_sum(array_map('intval', file($workspace.'/context-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($contextChecks !== 139 || in_array('impossible-type-comparison', array_column($contexts, 'code'), true)) { throw new RuntimeException('Missing exact source and metadata controls: '.$contextChecks.' '.$workspace); }
$jsonNative = $analyze('json-native', paths: ['json-proof.php']);
$jsonStandalone = $analyze('json-standalone', paths: ['json-proof.php']);
$jsonExpected = array_values(array_filter($jsonNative, static fn (array $issue): bool => $issue['code'] !== 'impossible-type-comparison'));
if (count($jsonNative) - count($jsonExpected) !== 1 || count(array_filter($jsonExpected, static fn (array $issue): bool => $issue['level'] === 'Error')) < 1
    || $wholeSignature($jsonStandalone) !== $wholeSignature($jsonExpected)) { throw new RuntimeException('Missing exact genuine JSON single-file correction and unsafe-use Error. '.$workspace); }
$jsonContexts = $analyze('json-contexts', paths: ['json-proof.php']);
$jsonChecks = file_exists($workspace.'/json-controls.json') ? json_decode(file_get_contents($workspace.'/json-controls.json'), true, flags: JSON_THROW_ON_ERROR) : [];
if (count($jsonChecks) !== 50 || in_array(false, $jsonChecks, true) || $wholeSignature($jsonContexts) !== $wholeSignature($jsonStandalone)) {
    throw new RuntimeException('Missing nonvacuous genuine native JSON controls: '.count($jsonChecks).' '.$workspace);
}
$jsonShadowNative = $analyze('json-shadow-native', paths: ['json-proof.php']);
$jsonShadowStandalone = $analyze('json-shadow-standalone', paths: ['json-proof.php']);
if (!in_array('impossible-type-comparison', array_column($jsonShadowNative, 'code'), true)
    || $wholeSignature($jsonShadowNative) !== $wholeSignature($jsonShadowStandalone)) { throw new RuntimeException('Effective namespaced JSON decoder must retain every complete native report. '.$workspace); }
echo 'JSON refresh checks: '.count($jsonLabels).' nonvacuous source cases, '.count($jsonChecks)." genuine metadata/source controls, complete reports, namespace shadow and unsafe-use Error preserved.\n";
if (in_array('--integrated', $argv, true)) {
    foreach ([1, 3] as $workers) {
        if ($signature($analyze('integrated', $workers)) !== $signature($proven)) { throw new RuntimeException('Integrated refreshed property diagnostics differ: '.$workers.' workers. '.$workspace); }
        if ($wholeSignature($analyze('integrated', $workers, paths: ['json-proof.php'])) !== $wholeSignature($jsonStandalone)
            || $wholeSignature($analyze('json-shadow-integrated', $workers, paths: ['json-proof.php'])) !== $wholeSignature($jsonShadowNative)) {
            throw new RuntimeException('Integrated whole JSON signatures differ: '.$workers.' workers. '.$workspace);
        }
    }
}
if (file_exists($workspace.'/executed')) { throw new RuntimeException('Analyzed bootstrap or migration executed.'); }
file_put_contents($workspace.'/runtime-controls.php', <<<'PHP'
<?php
declare(strict_types=1);
class MutableRecord {
    public function __construct(public bool $exists, protected array $attributes) {}
    public function __get(string $property): mixed { return $this->attributes[$property]; }
    public function refresh(): static {
        if (!$this->exists) { return $this; }
        $this->attributes = ['units' => 0, 'flag' => false, 'cost' => '28.00'];
        return $this;
    }
    public function fresh(): static { $copy = clone $this; return $copy->refresh(); }
}
final class ConstantRecord extends MutableRecord {
    public function __get(string $property): int { return 17; }
}
final class ShadowRecord extends MutableRecord { public int $units = 17; }
$old = ['units' => 17, 'flag' => true, 'cost' => '17.00'];
$persisted = new MutableRecord(true, $old);
$savedIdentity = $persisted->refresh() === $persisted;
$unsaved = new MutableRecord(false, $old);
$unsaved->refresh();
$constant = new ConstantRecord(true, $old);
$constant->refresh();
$shadow = new ShadowRecord(true, $old);
$shadow->refresh();
$original = new MutableRecord(true, $old);
$copy = $original->fresh();
echo json_encode([$savedIdentity, $persisted->units, $persisted->flag, $persisted->cost, $unsaved->units, $constant->units, $shadow->units, $original->units, $copy->units], JSON_THROW_ON_ERROR);
PHP);
$process = proc_open([PHP_BINARY, $workspace.'/runtime-controls.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
if (!is_resource($process)) { throw new RuntimeException('Cannot start invented runtime controls.'); }
fclose($pipes[0]);
$runtime = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0 || $stderr !== '' || json_decode($runtime, true, flags: JSON_THROW_ON_ERROR) !== [true, 0, false, '28.00', 17, 17, 17, 17, 0]) {
    throw new RuntimeException('Incorrect mutable, unsaved, accessor, property-shadow or fresh runtime boundary. '.$workspace);
}
require $package.'/vendor/autoload.php';
$scanChecks = (static function (string $root): int {
    $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties($root);
    $contents = file_get_contents($root.'/proof.php');
    $version = \Mago\Sdk\PHPVersion::fromParts(8, 5);
    $cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
        public bool $cancelled = false;
        public function isCancelled(): bool { return $this->cancelled; }
        public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $file = static function (string $path, string $bytes) use ($version): \Mago\Sdk\Syntax\SourceFile {
        return new \Mago\Sdk\Syntax\SourceFile($version, $path, $bytes, [],
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
    };
    $host = $file('proof.php', $contents);
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
        $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $known) use ($index, $contents, &$checks): void {
        if (($index->proofs('proof.php', $contents) !== []) !== $known) { throw new RuntimeException('Wrong refreshed-property scan state: '.$label); }
        $checks++;
    };
    $expect('unscanned', false);
    $scan([$host]); $expect('complete source', true);
    if ($index->proofs('other.php', $contents) !== [] || $index->proofs('proof.php', $contents.' ') !== []) { throw new RuntimeException('Foreign source snapshot selected refresh proof.'); }
    $checks += 2;
    $scan([$host], last: false); $expect('incomplete batch', false);
    $scan([], first: false); $expect('completed second batch', true);
    $scan([]); $expect('new empty generation', false);
    $scan([$host, $host]); $expect('duplicate path', false);
    $scan([$host]); $scan([$host], first: false); $expect('duplicate across batches', false);
    $scan([$host, $file('broken.php', '<?php function broken( {')]); $expect('parse failure', false);
    $scan([$host, $file('oversized.php', str_repeat(' ', 2_000_001))]); $expect('oversized file', false);
    $scan([$file('proof.php', str_replace('<?php', '<?php declare(ticks=1);', $contents))]); $expect('ticks deferred', false);
    foreach (['$row->exists &= false;', '[$row->exists] = [false];', 'unset($row->exists);', '$row->flag |= true;'] as $write) {
        $changed = str_replace('$row->refresh();', $write.' $row->refresh();', $contents);
        $scan([$file('proof.php', $changed)]);
        if ($index->proofs('proof.php', $changed) !== []) { throw new RuntimeException('Receiver write selected refreshed-property proof: '.$write); }
        $checks++;
    }
    $scan([$host]); $expect('recovered generation', true);
    if (PHP_OS_FAMILY === 'Windows') {
        $scan([$host, $file('PROOF.PHP', $contents)]); $expect('case-folded duplicate', false);
    }
    $scan([$host], last: false);
    $cancel->cancelled = true;
    try { $scan([$file('other.php', '<?php')], first: false); throw new RuntimeException('Cancelled scan completed.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Scan cancelled.') { throw $error; } }
    $cancel->cancelled = false;
    $expect('cancelled incomplete generation', false);
    $scan([$host]); $expect('recovered after cancellation', true);
    $proof = $index->proofs('proof.php', $contents)[0];
    if (!\Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties::current($proof)) { throw new RuntimeException('Current refresh source rejected.'); }
    file_put_contents($root.'/proof.php', $contents.' ');
    try {
        if (\Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties::current($proof)) { throw new RuntimeException('Changed refresh disk source retained.'); }
        $checks += 2;
    } finally { file_put_contents($root.'/proof.php', $contents); }
    return $checks;
})($workspace);
echo 'Refreshed model property checks passed: '.count($cases).' source cases, '.$corrected.' exact native/control corrections, '.$retained.' retained negative groups, '.$contextChecks.' issue and metadata controls, '.$scanChecks.' scan controls, 5 runtime boundaries, explicit root with spaces, nonstandard vendor directory and single-file analysis'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').".\n";
