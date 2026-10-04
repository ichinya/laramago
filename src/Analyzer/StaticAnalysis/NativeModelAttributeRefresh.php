<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Verify the native replacement and primitive-reader dispatch, including delegated overrides. */
final class NativeModelAttributeRefresh
{
    private const SOURCE_BINDINGS = [
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php' => '9a6335e73e4bd87c882392a8f497c12e2ff58c6cc7338ee6b5e84553a9b75b0f',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Model.php' => '1f5e206a98d1db952a3b843a26b5b423c4a72dc105c7aa0fb65b7b28d1217625',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php' => '6a1c45a11e748cb303df0ab5da6ff28c9e9726bd8af90b8d8957b47a6dbfc93a',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php' => '74ce212f4cc91e1b6fc6025a0a2e553897869184b44cc25af0489ed0f2b1723b',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasRelationships.php' => 'c571b377b627420754dd2dcf297aab8f7f40c8ecc6eb367f53534cbdde6167e0',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasTimestamps.php' => 'fb0ac2ee5148846061ea14283f464ab3295a599a409afe85b3eeaf8fddd11b34',
        '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/PreventsCircularRecursion.php' => '5b68c23b0f374c92502f260fae343ea80f841eb35d86d477c194b78d8fe5d0a5',
        '/testo/assert/Assert.php' => 'bd4393799a6bf4799ca1e71efce2ffe4311bb5dedeb41a81987e026b4ca0b7b0',
    ];
    /** @var array<string,array{hash:string,valid:bool}> */
    private array $bindings = [];
    private const METHODS = [
        'mergeattributefromcachedcasts' => ['Concerns/HasAttributes', 'e24903d2f8e814202a0e5d91d1c8669db494fd10dbe2a4459adc9302b709a320', 1, 'Protected'],
        'mergeattributefromclasscasts' => ['Concerns/HasAttributes', '8d3cb84c8ac7d4b705b2891fe3e0e122d1fe380478cd394868244df2006961ef', 1, 'Protected'],
        'mergeattributefromattributecasts' => ['Concerns/HasAttributes', '73f03b69daa2699f8d5125d2deed749b94e3343333e4c7f9cc75314b2f813f9b', 1, 'Protected'],
        'resolvecustombuilderclass' => ['Model', '0a94fee4b43ed1a41e77c51d643562d549567ea08fec43f8323825a14da8b0bd', 0, 'Protected'],
        'newbasequerybuilder' => ['Model', 'b2b774f177ccba02715844c378253c8fbe4daf0accc3173a96d5e4089913c6db', 0, 'Protected'],
        'getconnection' => ['Model', '9acb69e70b593203d52f9b1b95f0927496dc838331ea422205305d2b19d7ad51', 0, 'Public'],
        'update' => ['Model', '9aa7d072aa50b9d408bdcd51f58cecf2a4285e4e5d3537f2ba42717725e68f45', 2, 'Public'],
        'newquerywithoutrelationships' => ['Model', '77e82a89d812be3f0f62d00369e3b08d4493287977866fc3e81c418837485746', 0, 'Public'],
        'getkeyname' => ['Model', '50522e6df78cf0868712c7251d40348b98a72f7ac57b143ebeb3bdd8bdc3d4b4', 0, 'Public'],
        'getkeytype' => ['Model', 'c8c2280b946bb3e52b65af5e3e8305d696f04cb4963d73165bd937bcd65ad105', 0, 'Public'],
        'getincrementing' => ['Model', '4c80cacb6ed65d2363711b2a672fff22426e8e330c0061ac03a0ceebdad14689', 0, 'Public'],
        'isencryptedcastable' => ['Concerns/HasAttributes', 'dd2fb9ad560ce714e0649a3fb85a59ff73d24c2ed9bdc0d6b52bdf75c1ddad46', 1, 'Protected'],
        'mergeattributesfromcachedcasts' => ['Concerns/HasAttributes', 'b8cd183bcb1613828fd6795ba8ccb99f5d3165f0c98082a21fdb26165e2f5605', 0, 'Protected'],
        'mergeattributesfromclasscasts' => ['Concerns/HasAttributes', '8e2b9268bc2a8c39e103a44bbcafb90cf2965c9b0ece4a07d861062abbe1b368', 0, 'Protected'],
        'mergeattributesfromattributecasts' => ['Concerns/HasAttributes', '46ff01f0929cc94cfbe9639e931cd0e1126d8518764f04c145fd37008cf3221e', 0, 'Protected'],
        'load' => ['Model', '699a5d667d86a36b4a884c733673b34f211e8515cfa767a8d5f317174e55cd21', 1, 'Public'],
        'setkeysforselectquery' => ['Model', '6ce159c8620e28123c883f68b4178b2536242eb1b782ee46d8608436a8304ae4', 1, 'Protected'],
        'getkeyforselectquery' => ['Model', '751f39a2ae28c1508b2cee9e2701e77024987ee9c676bf1dc0c0ed17419c6636', 0, 'Protected'],
        'newmodelquery' => ['Model', 'f33f9ac63a8bd2d475b75ab9cfb73f201d763ba0c25845be4d5d0a7e25a9633c', 0, 'Public'],
        'newquerywithoutscopes' => ['Model', '2c56284d820a428930019ca597af7ef798a651f99dfc80ed0301a7daa3cb7373', 0, 'Public'],
        'neweloquentbuilder' => ['Model', '621659d914954d18c5999cfc79d50d9ae6b6484664030f16817c688c3ea16d50', 1, 'Public'],
        'refresh' => ['Model', '91a641b7b0a90c78e6298e0ac685857e608ee732917dc2f14a7c6b53b34455f8', 0, 'Public'],
        'refreshusingquery' => ['Model', '25b808f0c8ed5fa91917443856de628915dc457ac7d1f2034b594e222e0db8eb', 1, 'Protected'],
        'getkey' => ['Model', '0be04952d7273f48c492cdba351b4ba29de8d91d557ccc8890a79e6eb93e5807', 0, 'Public'],
        '__get' => ['Model', '24092e0abed494b6b925dea929f2a3b792bf4bbb4d4c22f6a7f8831835c03f23', 1, 'Public'],
        'hasattribute' => ['Concerns/HasAttributes', '46baf624ae29e13633e75115ac82d7e94135fb7b2552f5d9587c17f69e030d7f', 1, 'Public'],
        'getattribute' => ['Concerns/HasAttributes', 'f7ec3b951d28eca251b32ab8be30a6a071229f9d8e6e8ce8c67c758fcda9bf18', 1, 'Public'],
        'getattributevalue' => ['Concerns/HasAttributes', 'fd45b09552a137400d006cf16f76992889a7fbf75cfb2c9b07c3a482fead4649', 1, 'Public'],
        'getattributefromarray' => ['Concerns/HasAttributes', '50822d065a584ee88d942d80ec4709d9fc40f379128b1f977bebee20bd26bfdc', 1, 'Protected'],
        'hasgetmutator' => ['Concerns/HasAttributes', '7783ef4a9ad7fbf459d5e1111d8d00947b2df3fd8419d01791d5e5b9e46dee62', 1, 'Public'],
        'hasattributemutator' => ['Concerns/HasAttributes', '57b134797dbb7a1136a099b595024d0a029c9c8c709c84159d5770597cc72a15', 1, 'Public'],
        'hasattributegetmutator' => ['Concerns/HasAttributes', 'a7f876b75be5b7b71dfee8fdf8b1c8734ad0c60bc239fd4885b13cea9d2eb432', 1, 'Public'],
        'castattribute' => ['Concerns/HasAttributes', 'dffc6479c50d1d62438e95a4983fbf45459ec94c59bb624d42d9caf7f5f090e1', 2, 'Protected'],
        'getcasttype' => ['Concerns/HasAttributes', '8a012b98797a00aac12d45b870ed424862de06c0a1b7b41e9ff96822b8ecb04a', 1, 'Protected'],
        'asdecimal' => ['Concerns/HasAttributes', 'f10ea46fd04552c49f9c3121668382da5d0d89131e05869af56cf96d8ff455db', 2, 'Protected'],
        'hascast' => ['Concerns/HasAttributes', '0b68c1f442a6ead60c6c3dab759ed056f8119f17f2e7c3f7d4e1f32573e5c160', 2, 'Public'],
        'getcasts' => ['Concerns/HasAttributes', 'd8e4be184cc49d3c58290f00d2bf997d7b5130d97b4dff6e7f78db76ceeb99e1', 0, 'Public'],
        'isclasscastable' => ['Concerns/HasAttributes', '688de37f87dc3b54a750e3e8d900a8ece2b9abbb504544c448d8cd5d0bc27f3e', 1, 'Protected'],
        'isenumcastable' => ['Concerns/HasAttributes', '39e9641ee42d2054c7208bdb4f266e58255597ae7c82d570f97b2cb7105de97c', 1, 'Protected'],
        'getattributes' => ['Concerns/HasAttributes', '30b9ee2ff67f0eeb661a0eadf7e8513f902fe5d8cdf1657e060e402bc7ac22b9', 0, 'Public'],
        'setrawattributes' => ['Concerns/HasAttributes', 'db5272127203e22e8dd191e0fc6e25b4333b9088c8e44eb55a125714bc90b861', 2, 'Public'],
        'syncoriginal' => ['Concerns/HasAttributes', '4f05ee8b1128a9fcb7dcda1057c6b9f6dc06f1c2ea744fafc5e8095c0a9122d9', 0, 'Public'],
        'transformmodelvalue' => ['Concerns/HasAttributes', '88b2984e02180364527ecd647f620741f2364075dbf0c983fc79247a71ae520d', 2, 'Protected'],
    ];
    private const ASSERTIONS = [
        'same' => '5af6b5ce41818c877b96bf53e1eaf0b2f9112ed47a524df8b7e6d6a8557661f3',
        'true' => '3653f40e5ab637764f6158223f6d2aeae99217760e853ece9dcca29518898b70',
        'false' => 'a34fb3f17a043604ad33fca8cf5502373d9128f46a80efdb02c489f8fbd398d6',
    ];
    private const JSON_READ_METHODS = [
        'fromjson' => ['Concerns/HasAttributes', '29cb77aecac80287fa1f544780b908b3401a32a480735e53b2dba85b9e94d675', 2, 'Public'],
    ];
    private const UPDATE_METHODS = [
        '__set' => ['Model', 'e02111d23b57f0e3bf4a9f552a5b05db63b8e1643d5a6ff0243fba3967221071', 2, 'Public'],
        'asdatetime' => ['Concerns/HasAttributes', '6b9fd446f44782cd59960ed3076529050df6dbd9bc51002d2ad86321db2f6fcb', 1, 'Protected'],
        'isstandarddateformat' => ['Concerns/HasAttributes', '09a6413bd1ff696982f998566e15bd43073fc787366bae4e1ecd192af671c88c', 1, 'Protected'],
        'fromdatetime' => ['Concerns/HasAttributes', '5514f0533bade3b94f453c987a07a04021d45ef268899bb706b49a077d1ea9f7', 1, 'Public'],
        'isdatecastablewithcustomformat' => ['Concerns/HasAttributes', '1e955132fe76443877f22cfab108181d43d41bebcadabac2bae0ba80a8947d32', 1, 'Protected'],
        'preventssilentlydiscardingattributes' => ['Model', '2659d0adf0188f524a3daa33ce28af91a2ba5b3ff1ebc45c2dcb61a2913f1919', 0, 'Public', true],
        'getfillable' => ['Concerns/GuardsAttributes', '8656a42d9b3a8c68061e683290a0f13912003342e39413a3d772f8fcebc235bb', 0, 'Public'],
        'getguarded' => ['Concerns/GuardsAttributes', 'b366c348ddd111eaf22b81d8ec3882501f3a6dd01cdde226a3320ec722b66ac6', 0, 'Public'],
        'isfillable' => ['Concerns/GuardsAttributes', 'f320f604d061072320c6732aaab0626f3d4d219a13220e1c4aa30ac2752cc298', 1, 'Public'],
        'isguarded' => ['Concerns/GuardsAttributes', 'a20a9c4af65eed508f79fea249fddf5253c45684d82f32188592600f962eedc1', 1, 'Public'],
        'isguardablecolumn' => ['Concerns/GuardsAttributes', 'fa7378ef09af2c2111451cb07212445dba1b0009eb22c7a950de20446ead81d3', 1, 'Protected'],
        'totallyguarded' => ['Concerns/GuardsAttributes', 'f5d66008e91b426ec3290cf4db790967b067dee851e60d4ec62045169e2e1b71', 0, 'Public'],
        'fillablefromarray' => ['Concerns/GuardsAttributes', 'f98f8595608cdb486e392a70c0e49f125cf0e9979823c849d56c2014ca52d413', 1, 'Protected'],
        'getdateformat' => ['Concerns/HasAttributes', 'c9446d1c79c1bbd58edcfb206904a76a353dfe66c049ce30844b1ae1a1af985c', 0, 'Public'],
        'isdatecastable' => ['Concerns/HasAttributes', 'ac1bf6f610ae007e51a6f4f4de37bd6f968835075261174d9a0af837b1bbe44a', 1, 'Protected'],
        'originalisequivalent' => ['Concerns/HasAttributes', '5e46b41ef61bc27945c9a98fa1adeef41d48190b856dc8330d2a5f6804b22955', 1, 'Public'],
        'firecustommodelevent' => ['Concerns/HasEvents', '663bbd1f5916a8f22070150452f35a703bb6785b07a3f2fdea33a4c28536d389', 2, 'Protected'],
        'filtermodeleventresults' => ['Concerns/HasEvents', '30f19b7aeb946c1300de5bc43d097db17a446f7313561908fa5c24fc91dc0c03', 1, 'Protected'],
        'gettouchedrelations' => ['Concerns/HasRelationships', 'eb0a370b7811854ec897502d785db490b1c5f1014f4cbe4484f5c80836cb5466', 0, 'Public'],
        'setcreatedat' => ['Concerns/HasTimestamps', '97b9b2f6d5de00f67ccfbdcb50c1b2532fb468a52075548c786e20e420e4b86a', 1, 'Public'],
        'setupdatedat' => ['Concerns/HasTimestamps', 'f2dc35dd1006e2ede09fc55f6baef2fe734c25794312e7811d914863f07b8acf', 1, 'Public'],
        'freshtimestamp' => ['Concerns/HasTimestamps', '819f5d7c18b9c52d0275b93a8bb321e99522eca7ebec7ddbd3cbfb45da3dae29', 0, 'Public'],
        'getcreatedatcolumn' => ['Concerns/HasTimestamps', '136bfe655307b1320ac1a87ec08a22a8e39efc3f19162388ea19e304e894e0e6', 0, 'Public'],
        'getupdatedatcolumn' => ['Concerns/HasTimestamps', 'a64575e3a7c3aae19a8acccb15fe27efa1779596479379c1db4fc5caf827c569', 0, 'Public'],
        'isignoringtimestamps' => ['Concerns/HasTimestamps', '48235976bab2d0fcb08de8805cdc290e487a05e97ae41f44f94c2fa7587c131b', 1, 'Public', true],
        'withoutrecursion' => ['Concerns/PreventsCircularRecursion', '8c48458fa6a8d35a1052a513619dcdf7235c57d618582bea24235f3079692c8e', 2, 'Protected'],
        'hassetmutator' => ['Concerns/HasAttributes', '7f0031d5a1ad70677086612273a8a8c8b67aa77fda8cdcdbf443212eb9d7c3d8', 1, 'Public'],
        'hasattributesetmutator' => ['Concerns/HasAttributes', '0ccbade61863542135cdcad40dd1f2d9a95cf2383536c577edbea4d52b17f6a0', 1, 'Public'],
        'isdateattribute' => ['Concerns/HasAttributes', 'b237e18d9cb68fe6a025a6d14917544c173b48365a145db0b7d6e52e0d0bdfd1', 1, 'Protected'],
        'getdates' => ['Concerns/HasAttributes', '1821efe34ada4ee9553823f6383e4fbf85f71de786d783941d2d917a9d9d0ca4', 0, 'Public'],
        'isjsoncastable' => ['Concerns/HasAttributes', '834af6ca93038afabd92299a0f18406375925f243c8c882062a50d3d27b73e15', 1, 'Protected'],
        'isdirty' => ['Concerns/HasAttributes', 'b3b672ce4a950511cebb24047b4d81f7100d8df4f2059a598ebb4eaeaca4e0bf', 1, 'Public'],
        'haschanges' => ['Concerns/HasAttributes', 'eb39e30e9af616027cf7ce2c97cebe7750e0c1c094524aa2bbb19c98dad2c95a', 2, 'Protected'],
        'touchowners' => ['Concerns/HasRelationships', 'a72337ff401174a5bd778fbb89c3edce62dc7bccf34ca452608d6b35f94f96b7', 0, 'Public'],
        'fill' => ['Model', '6c4000a9d44ec5d4a65f9f5fb22d74e62f00c22debb58332dd661e936f9497c8', 1, 'Public'],
        'save' => ['Model', '0848a706f6249fa18d772b0652fb477016eb175f57ee2e0a9ff095c9d9ecb9bd', 1, 'Public'],
        'finishsave' => ['Model', 'aaae2c6bee43b0d54ee76ea005cf101764903dbc407ad8cd8fde9a8444c55ba7', 1, 'Protected'],
        'performupdate' => ['Model', '034cc77424b5af5103f1f00e5c08af54115bf4eaa5e9e0e391a8455f272f22da', 1, 'Protected'],
        'setkeysforsavequery' => ['Model', '2efd9717d69148ec39c80a07594c27fbf83e661aff937c7b90644e9f9e47ad49', 1, 'Protected'],
        'getkeyforsavequery' => ['Model', 'bee27311a3544a3ed7ede3fb2c9a18d34dee936a7a9b36b36c6dbf7700302d9d', 0, 'Protected'],
        'performinsert' => ['Model', 'fc9cda4f7d8ddc92c0be8d7eca3647fd5a4efa7918dcc0837ad9156f8b04f810', 1, 'Protected'],
        'setattribute' => ['Concerns/HasAttributes', '5323a8cd7d2470d3ddf04b25b47921e08d5e8fcc6cc6c38cccc29715b4695ed3', 2, 'Public'],
        'getoriginal' => ['Concerns/HasAttributes', '9f88d21aa5cd6d8e2753ae8ef7752204d6c1e84a6301343c3799696a9857a619', 2, 'Public'],
        'syncchanges' => ['Concerns/HasAttributes', '30187782cea6d085a1d40716aa531418f484c88f0cebb8ccbcb998e054a53d90', 0, 'Public'],
        'getdirty' => ['Concerns/HasAttributes', 'bb8c68f2a7e7045d1a489fdd983d9702cc43f5486a0fd2a3a9e576b02b3d3f5c', 0, 'Public'],
        'getdirtyforupdate' => ['Concerns/HasAttributes', 'a968b440dd44902c3a5bf82790945d4309da9d2ac5b0825e15486121ec70bbe6', 0, 'Protected'],
        'firemodelevent' => ['Concerns/HasEvents', '6c72670f61d6550595c75a0620832f5aeb25ded9d2ad485844375fe44fd96a2c', 2, 'Protected'],
        'updatetimestamps' => ['Concerns/HasTimestamps', '810004b018ac95a6576532fab2a1d3f067ca586b7264f02d39851366a586420b', 0, 'Public'],
        'usestimestamps' => ['Concerns/HasTimestamps', 'd9800246e94741f6a4e44108f8cb259210c720ead2ed856ded3038c5a2bf3d36', 0, 'Public'],
    ];

    public function __construct(private readonly string $root = '.') {}

    public function proves(Codebase $codebase, string $class): bool
    {
        $reflection = new ModelReflection($codebase, new PhpSource($this->root));
        if ($reflection->default($class, 'builder') !== 'Illuminate\\Database\\Eloquent\\Builder') { return false; }
        foreach ([$class, ...$codebase->getClassAncestors($class)] as $ancestor) {
            // ALL_ANCESTORS also returns implemented interfaces and used traits.
            $metadata = $codebase->getClassLike($ancestor);
            if ($metadata === null || $metadata->hasIncompleteHierarchy()) { return false; }
            foreach ($metadata->attributes as $attribute) {
                if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') === 0) { return false; }
            }
        }
        return $this->methods($codebase, $class, self::METHODS);
    }

    public function update(Codebase $codebase, string $class): bool { return $this->methods($codebase, $class, self::UPDATE_METHODS); }

    /** Unrelated attribute reads must not invoke an unverified receiver-aware cast before refresh. */
    public function reads(Codebase $codebase, string $class, array $properties): bool
    {
        $reflection = new ModelReflection($codebase, new PhpSource($this->root));
        $casts = $reflection->casts($class);
        $incrementing = $reflection->default($class, 'incrementing', true);
        if (! is_array($casts) || ! is_bool($incrementing)) { return false; }
        if ($incrementing) {
            $key = $reflection->default($class, 'primaryKey', 'id');
            $type = $reflection->default($class, 'keyType', 'int');
            if (! is_string($key) || ! is_string($type) || $key === '' || $type === '') { return false; }
            $casts = array_merge([$key => $type], $casts);
        }
        $json = false;
        foreach ($properties as $property) {
            $physical = $codebase->getDeclaringProperty($class, '$'.$property);
            if ($physical !== null) {
                if ($physical->readVisibility !== Visibility::Public || $physical->hooks !== []) { return false; }
                continue;
            }
            // Uncast virtual reads also dispatch date and relationship helpers outside this audited chain.
            if (! array_key_exists($property, $casts)) { return false; }
            $cast = $casts[$property];
            if (! is_string($cast)) { return false; }
            $cast = strtolower($cast);
            if (in_array($cast, ['int', 'integer', 'bool', 'boolean', 'string'], true)) { continue; }
            if (preg_match('/^decimal:(?:0|[1-9][0-9]?)$/D', $cast) === 1 && (int) substr($cast, 8) <= 30) { continue; }
            if (in_array($cast, ['array', 'json', 'json:unicode'], true)) { $json = true; continue; }
            return false;
        }
        return ! $json || $this->methods($codebase, $class, self::JSON_READ_METHODS);
    }

    private function methods(Codebase $codebase, string $class, array $methods): bool
    {
        foreach ($methods as $name => $definition) {
            [$part, $hash, $arity, $visibility] = $definition;
            $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
            if ($method === null || ! self::metadata($method, $name, $arity, $visibility === 'Public' ? Visibility::Public : Visibility::Protected, $definition[4] ?? false)
                || ! in_array(strtolower($method->identifier->class ?? ''), [strtolower(ModelReflection::MODEL), strtolower('Illuminate\\Database\\Eloquent\\'.str_replace('/', '\\', $part))], true)
                || ! $this->source($method, '/laravel/framework/src/Illuminate/Database/Eloquent/'.$part.'.php', $hash)) { return false; }
        }
        return true;
    }

    public function assertion(Codebase $codebase, string $name): bool
    {
        $method = $codebase->getMethod('Testo\\Assert', $name);
        if (! isset(self::ASSERTIONS[$name]) || $method === null || strcasecmp($method->identifier->class ?? '', 'Testo\\Assert') !== 0
            || ! self::metadata($method, $name, $name === 'same' ? 3 : 2, Visibility::Public, true)
            || $method->returnType === null || (string) $method->returnType->type !== 'void'
            || ! $this->source($method, '/testo/assert/Assert.php', self::ASSERTIONS[$name])) { return false; }
        $assertions = $method->assertions['$actual'] ?? $method->assertions['actual'] ?? [];
        if (count($method->assertions) !== 1 || count($assertions) !== 2 || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== []
            || count($method->templates) !== ($name === 'same' ? 1 : 0)) { return false; }
        foreach ($assertions as $assertion) {
            if (! $assertion instanceof \Mago\Sdk\Analyzer\Assertion\TypeAssertion) { return false; }
            if ($name === 'same') {
                $atoms = $assertion->type->atomicTypes;
                $atom = count($atoms) === 1 ? $atoms[0] : null;
                if ($assertion->kind !== \Mago\Sdk\Analyzer\Assertion\TypeAssertionKind::IsIdentical
                    || ! $atom instanceof \Mago\Sdk\Analyzer\Type\GenericParameterType || $atom->name !== 'ExpectedType'
                    || ($atom->intersections ?? []) !== [] || (string) $atom->constraint !== 'mixed'
                    || strcasecmp($atom->definingEntity->name, 'Testo\\Assert') !== 0 || strcasecmp($atom->definingEntity->member ?? '', 'same') !== 0) { return false; }
            } elseif ($assertion->kind !== \Mago\Sdk\Analyzer\Assertion\TypeAssertionKind::IsType || $assertion->type->getLiteralBool() !== ($name === 'true')) { return false; }
        }
        return $name !== 'same' || $method->templates[0]->name === 'ExpectedType' && (string) $method->templates[0]->constraint === 'mixed'
            && $method->templates[0]->default === null;
    }

    private static function metadata(FunctionLikeMetadata $method, string $name, int $arity, Visibility $visibility, bool $static): bool
    {
        return $method->kind === FunctionLikeKind::Method && strcasecmp($method->originalName, $name) === 0
            && $method->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method && strcasecmp($method->identifier->name, $name) === 0
            && $method->visibility === $visibility && $method->static === $static && ! $method->abstract
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE) && count($method->parameters) === $arity
            && $method->location->file !== null && $method->nameLocation !== null
            && ! array_filter($method->parameters, static fn ($parameter): bool => $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC));
    }

    private function source(FunctionLikeMetadata $method, string $suffix, string $hash): bool
    {
        $file = RefreshedModelProperties::path($method->location->file ?? '');
        if (! str_ends_with($file, PHP_OS_FAMILY === 'Windows' ? strtolower($suffix) : $suffix)
            || RefreshedModelProperties::path($method->nameLocation?->file ?? '') !== $file) { return false; }
        $disk = preg_match('~^(?:/|[a-z]:/)~i', $file) === 1 ? $file : $this->root.'/'.$file;
        $size = @filesize($disk);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($disk) : false;
        if ($contents === false || $method->location->span->start < 0 || $method->location->span->end > strlen($contents)
            || $method->nameLocation->span->start < $method->location->span->start || $method->nameLocation->span->end > $method->location->span->end
            || strcasecmp(substr($contents, $method->nameLocation->span->start, $method->nameLocation->span->length()), $method->originalName) !== 0) { return false; }
        $current = hash('sha256', $contents);
        $binding = $this->bindings[$disk] ?? null;
        if ($binding === null || $binding['hash'] !== $current) {
            $expected = self::SOURCE_BINDINGS[$suffix] ?? null;
            $actual = self::bindingFingerprint($contents);
            if (count($this->bindings) >= 32) { array_shift($this->bindings); }
            $binding = $this->bindings[$disk] = ['hash' => $current, 'valid' => $expected !== null && $actual !== null && hash_equals($expected, $actual)];
        }
        if (! $binding['valid']) { return false; }
        return hash_equals($hash, self::fingerprint(substr($contents, $method->location->span->start, $method->location->span->length())));
    }

    /** Preserve native namespaces, import aliases and file directives independently of method tokens. */
    public static function bindingFingerprint(string $source): ?string
    {
        try { $nodes = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? []; }
        catch (\PhpParser\Error) { return null; }
        $namespace = null;
        $directives = $imports = $declarations = [];
        foreach ($nodes as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Declare_) {
                if ($node->stmts !== null) { return null; }
                foreach ($node->declares as $directive) {
                    $value = PhpSource::value($directive->value);
                    if ($directive->key->name !== 'strict_types' || ! is_int($value)) { return null; }
                    $directives[$directive->key->name] = $value;
                }
            } elseif ($node instanceof \PhpParser\Node\Stmt\Namespace_ && $namespace === null && $node->name !== null) { $namespace = $node; }
            else { return null; }
        }
        if ($namespace === null) { return null; }
        foreach ($namespace->stmts as $statement) {
            if ($statement instanceof \PhpParser\Node\Stmt\ClassLike) {
                if ($statement->name === null) { return null; }
                $declarations[] = [$statement->getType(), strtolower($statement->name->name)];
            }
            if (! $statement instanceof \PhpParser\Node\Stmt\Use_ && ! $statement instanceof \PhpParser\Node\Stmt\GroupUse) { continue; }
            foreach ($statement->uses as $use) {
                $kind = $use->type === \PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                $name = ($statement instanceof \PhpParser\Node\Stmt\GroupUse ? $statement->prefix->toString().'\\' : '').$use->name->toString();
                $alias = $use->getAlias()->name;
                if ($kind !== \PhpParser\Node\Stmt\Use_::TYPE_CONSTANT) { $alias = strtolower($alias); $name = strtolower($name); }
                $key = $kind.':'.$alias;
                if (isset($imports[$key])) { return null; }
                $imports[$key] = $name;
            }
        }
        ksort($directives);
        ksort($imports);
        sort($declarations);
        return hash('sha256', json_encode([strtolower($namespace->name->toString()), $directives, $imports, $declarations], JSON_THROW_ON_ERROR));
    }

    /** Method tokens preserve executable syntax; bindingFingerprint anchors their surrounding names. */
    public static function fingerprint(string $source): string
    {
        $parts = [];
        foreach (token_get_all('<?php '.$source) as $token) {
            if (! is_array($token)) { $parts[] = $token; }
            elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $parts[] = $token[1]; }
        }
        return hash('sha256', implode('', $parts));
    }
}
