# Laravel LSP adaptation queue

This tracks the 197 individually numbered research items. Queued entries are proposals, not promises of supported behavior. Each item receives independent review; existing coverage and SDK limitations must be evidenced. Offline analysis and native contract priority apply throughout.

Baseline: a67d3ee; previous package validation passed 3393 checks. No application bootstrap or database is required.

| Item | Scope | Status |
| --- | --- | --- |
| 1 | Model table mapping | already-covered; Verified ModelReflection::table and tests/properties.php. |
| 2 | Migration column types and nullability | already-covered; Verified SchemaIndex and migration locals, literals and contracts tests. |
| 3 | Native property and PHPDoc priority | already-covered; Verified EloquentPropertyProvider native and PHPDoc priority with properties tests. |
| 4 | Built-in attribute casts | already-covered; Verified AttributeTypes built-in casts and casts, advanced-casts, cast-contracts, carbon-properties tests. |
| 5 | Typed accessors and mutators | already-covered; Verified typed legacy and Attribute accessors in EloquentPropertyProvider and properties tests. |
| 6 | Separate attribute read and write types | already-covered; Verified independent read/write contracts in AttributeTypes, CustomCastTypes and focused tests. |
| 7 | Relation properties and typed collections | already-covered; Verified relation properties, nullable models and typed collections with five focused suites. |
| 8 | Legacy and attributed local scopes | already-covered; Verified legacy and attributed scope resolution; 43 focused scenarios passed. |
| 9 | Scope parameters and simple fluent bodies | already-covered; Verified scope signatures and bounded fluent body inference; native and disabled regressions passed. |
| 10 | Custom builders and collections | already-covered; Verified custom builder and collection resolution with eight focused suites. |
| 11 | Fillable name validation | implemented; Explicit exact-model field catalogs validate literal fillable names; native dispatch and disabled regressions passed. |
| 12 | Guarded name validation | implemented; Guarded names reuse model catalogs with case-insensitive and wildcard semantics; focused and disabled checks passed. |
| 13 | Hidden name validation | implemented; Hidden names use complete field and pre-serialization key catalogs; focused native/custom/disabled cases passed. |
| 14 | Visible name validation | implemented; Visible names reuse complete serialization catalogs with native empty-list and case semantics; focused tests passed. |
| 15 | Appended accessor name validation | implemented; Literal appends entries checked against an independent explicit complete appendable-key catalog; native serialization guards and real-Mago enabled/disabled regressions pass. |
| 16 | Model attribute declarations for field lists | implemented; Native Fillable/Guarded/Hidden/Visible/Appends attributes use exact catalogs and installed lifecycle guards; effective Guarded precedence, custom overrides and older-framework regressions pass. |
| 17 | Physical columns versus computed accessors | already-covered; Verified physical-column selection versus accessors, raw pluck keys and SQL alias boundaries; three suites passed. |
| 18 | Literal mass-assignment keys | implemented; Native forceFill literal keys use complete exact-model catalogs with native/PHPDoc priority; 190 key and 135 write-contract checks pass. Ordinary fill/create remain semantically deferred. |
| 19 | Mass-assignment value types | implemented; Native forceFill checks disjoint structured values against independent explicit write contracts. 135 real-Mago case/mode checks pass; fill/create, scalar coercions and schema-derived input types remain deferred. |
| 20 | Model update versus builder update contracts | already-covered; Native Model instance update returns bool; Builder update returns int; static Model update retains invalid-static-method-access. Enabled/disabled real-Mago audit preserves custom overrides and arity errors; input validation remains deferred. |
| 21 | Literal query column references | deferred; Query signatures and bounded positive projections pass focused tests. Missing SQL columns require effective query-state proof or an independent query-context completeness contract; migration/model fields cannot account for scopes, joins and aliases. |
| 22 | Both operands of whereColumn | implemented; whereColumn/orWhereColumn forward installed scalar/named/array signatures and preserve Builder<Model>; 139 predicate regressions pass. Missing SQL-column diagnostics remain deferred without effective-query proof. |
| 23 | Search versus creation attribute arrays | deferred; Native search attributes versus creation/update values remain distinct; 77 create scenarios pass. Semantic key diagnostics require effective SQL context plus safe ordinary mass-assignment contracts, deferred in items 21 and 18-19. |
| 24 | Suggestions for proven field typos | implemented; Already-proven missing fillable/guarded/forceFill fields receive a unique bounded catalog-backed typo suggestion; ambiguous/distant candidates defer. Real-Mago enabled/custom/disabled scenarios pass; no automatic edits or SQL-name inference. |
| 25 | Additional relation-name call sites | implemented; Additional non-morph existence methods and loadMissing reuse native relation-name checks; three focused suites passed. |
| 26 | Nested relation paths | already-covered; Verified nested relation traversal, callback typing and complete-name checks with focused suites. |
| 27 | Mixed eager-load declaration arrays | implemented; Mixed eager-load arrays honor effective keys and Laravel numeric-string semantics; dynamic/negative uncertainty defers. |
| 28 | Eager-load colon projection syntax | implemented; Eager-loading methods including withWhereHas accept colon projections; pure existence queries preserve full names. Real-Mago tests cover builder/static forwarding and native fallback. |
| 29 | Related model projection columns | deferred; Missing projection columns require complete effective-query metadata or an explicit query-source contract; schema alone cannot exclude scopes, joins, from changes, and aliases. No diagnostic added. |
| 30 | Eager-loading callback parameter types | implemented; Direct Builder::with literal-path callbacks receive concrete Relation types under native broad contracts. Real-Mago/native comparisons pass; nested callback arrays remain SDK-deferred. |
| 31 | Morph callbacks with explicit model classes | implemented; Four native Morph existence callbacks refine explicit fully qualified model targets under per-class runtime identity assertions; 45 morph and native trait callback regressions pass. Unknown morph maps/imports and custom dispatch defer. |
| 32 | Relation aggregate references | implemented; Seven native Builder with-aggregate methods validate relation references under existing complete catalogs; four real-Mago modes pass. Model/Collection load aggregates defer pending standard collection selection proof; no SQL column/result-property claims. |
| 33 | Relation aggregate alias syntax | implemented; Native three-token aggregate alias extraction precedes relation-path filtering, retaining relation checks with alias punctuation; 58 scenarios in four real-Mago modes pass. No SQL alias validity or aggregate-property inference. |
| 34 | Query-local aggregate result properties | deferred; Query-local aggregate properties need synchronous source/receiver provenance and alias-aware mutation invalidation; generic markers alone survive select resets incorrectly. Existing 50 query-chain and 60 projection scenarios preserve unknown-property errors. |
| 35 | Literal configuration result types | already-covered; Verified literal config and native Config::get types with configuration and configuration-index suites. |
| 36 | Additional configuration call sites | already-covered; Native config and Config::get literal results verified in configuration/framework-contract tests. Arbitrary Repository instances cannot inherit the application index without producer provenance. |
| 37 | Complete configuration key diagnostics | implemented; Complete runtime assertions plus source-complete parents enable native helper/facade key diagnostics. Custom app/repository dispatch, bindings, dynamic branches and native errors retain priority. |
| 38 | Configuration getMany key validation | implemented; Native Config getMany validates literal list/default keys under existing complete catalogs; numeric semantics, custom dispatch, duplicate/reference/negative/overflow deferrals and native errors pass real-Mago checks. |
| 39 | Typed configuration getters | implemented; Six native typed Config getters reuse complete-key diagnostics while retaining native results; exact repository provenance, resolved class identities and helper bodies guard custom chains. Integrated config/getMany regressions pass. |
| 40 | Config injection attribute references | implemented; Exact native Config injection attributes validate literal keys under complete runtime/source catalogs; native resolver/container/repository provenance and contextual-handler deferrals pass 23 real-Mago scenarios. |
| 41 | Configuration push and prepend targets | deferred; Native writer inputs already checked: twelve real-Mago scenarios preserve native diagnostics. Missing push/prepend targets are valid initialization; value diagnostics need independently proven pre-call repository contents. |
| 42 | Configuration declaration locations | deferred; Configuration typing passes 29 scenarios; SDK1.48.1 exposes no literal-to-declaration/document-link provider. Editor navigation needs a source-span/target-location extension point; no unused provenance or artificial valid-key diagnostics added. |
| 43 | Explicit environment name catalogs | implemented; Composer-only exact environment name catalog has explicit completeness and tri-state membership; malformed metadata defers. Thirteen catalog cases pass; no .env reads, values, diagnostics or Auth/config inference. |
| 44 | Environment helper key references | implemented; Native env helper literal keys are checked against explicit complete permitted-name catalogs; five real-Mago modes pass. Custom helpers/forwarding/Env origins defer, no values read or fallback-error claims; caches reset per analysis generation. |
| 45 | Env get key references | implemented; Direct native Env get literal references reuse the permitted-name catalog and literal spans; three real-Mago modes pass. Custom origins/subclasses/dynamic calls defer; native types and fallbacks retained, no environment values read. |
| 46 | Environment template interpolation references | deferred; Template interpolation needs a registered non-PHP source target; item47 real-Mago lifecycle probe rejects template spans as unknown files. Existing PHP env/catalog tests pass; no unused parser or mislocated diagnostics added. |
| 47 | Duplicate environment template declarations | deferred; Real-Mago lifecycle Issue at a synthetic .env.example key fails with unknown-file protocol error. Duplicate-template review warnings need non-PHP source registration or a separate checker; values remain unread. |
| 48 | Vite environment name references | deferred; Vite import.meta.env references require JS/TS source registration and frontend semantics absent from current PHP analyzer SDK. Existing PHP env/catalog checks pass; no value reads or mislocated frontend diagnostics added. |
| 49 | Existing complete named-route contracts | already-covered; Verified complete named-route catalogs; 19 real-Mago scenarios and source analysis passed. |
| 50 | Route and to_route helpers | implemented; Native route/to_route literal names use shared complete catalogs, helper/method provenance and service-binding guards. Seven real-Mago modes verify native/custom contracts and literal spans. |
| 51 | Named-route facade calls | implemented; Native URL/Redirect route references use complete catalogs. Shared facade proof preserves concrete methods across full ancestry; eleven facade modes and Config regressions pass. |
| 52 | Signed and temporary signed routes | implemented; Signed and temporary signed route names use complete catalogs on native facades/exact receivers; forwarding-chain signature guards, changed defaults and twelve real-Mago modes preserve custom/native errors. |
| 53 | Response route redirects | implemented; Native Response facade/factory and zero-argument response helper route redirects use complete catalogs with forwarding-chain and binding guards; 209 real-Mago cases pass, preserving custom/native behavior and literal spans. |
| 54 | RedirectToRoute attribute references | implemented; Native class-level RedirectToRoute on direct FormRequest subclasses validates literal route names under a complete catalog. Custom handlers, competing attributes, changed URL generation and configured services defer. Seventeen real-Mago cases, integration compatibility and source analysis passed. |
| 55 | Route-name predicate semantics | already-covered; Native bool contracts cover route-name predicates; absent names and wildcard patterns are valid boolean tests, so missing-route warnings would be incorrect. Installed bodies reviewed; existing route/facade suites pass. |
| 56 | Static literal route registration catalogs | implemented; Explicit named-routes.files extracts a strict native literal registration subset under completeness assertions; unsupported syntax or unsafe/unreadable paths disables the entire catalog. Catalog and real-Mago consumer tests, manual compatibility and source analysis passed. |
| 57 | Conflicting active route names | deferred; Native route collections replace equal method/domain/URI registrations; names-only catalogs cannot prove distinct surviving routes. Requires ordered active identities, final names and replacement proof. Existing route contract suite: 34 checks passed. |
| 58 | Required named-route parameters | deferred; Names-only catalogs cannot prove required URL parameters: mutable URL defaults, domain placeholders and binding-field mapping change satisfaction. Isolated native probe confirms missing-to-valid transition after defaults; 20 existing real-Mago route checks passed. |
| 59 | Domain optional and default route parameters | deferred; Native URL generation needs ordered domain/URI metadata and effective generator defaults. Route declaration defaults do not fill URL placeholders; domain parameters remain required for relative output. Eight native probes and 20 existing real-Mago declaration checks passed. |
| 60 | Route declaration diagnostic locations | deferred; Existing route diagnostics have source locations. Declaration provenance requires effective registrations and indexed files; editor navigation additionally requires document-link/definition SDK hooks. LSP action locations are not registration provenance. Twenty existing route checks passed. |
| 61 | Controller class existence in string actions | implemented; tests/controller-action-classes.php; absolute literal actions only |
| 62 | Controller method existence in string actions | implemented; tests/controller-action-classes.php; standard dispatch and binding guards |
| 63 | Controller action visibility | implemented; Controller action visibility follows native dispatch scope; 36 real-Mago scenarios passed. |
| 64 | Array controller action references | implemented; Literal controller action arrays resolve PHP names and preserve native diagnostics; 58 real-Mago scenarios passed. |
| 65 | Invokable controller actions | implemented; Absolute invokable strings require actual invoke declarations; namespace uncertainty defers; 77 scenarios passed. |
| 66 | Controller route group context | implemented; Safe controller pair arrays and absolute Controller@method actions retain diagnostics inside namespace/controller/prefix, dynamic and nested route groups. Relative and method-only group resolution remains deferred. Real-Mago controller suite and source analysis passed. |
| 67 | Additional route registration methods | implemented; Native fallback controller actions and view/redirect/permanentRedirect URI declarations now use existing checks with correct argument positions. Resource expansion and registrar chains defer. Combined group/fallback coverage and shared fixture consumers passed 220 checks; source analysis clean. |
| 68 | Controller parameter route and DI contracts | deferred; Controller parameter validation requires finalized route/domain/default/binding/middleware and DI contracts. Native scalar dispatch is positional while model binding uses names; unlisted container bindings do not prove failure. Five framework-only probes and existing controller/route suites passed. |
| 69 | Static middleware alias catalog | implemented; Metadata-only alias catalog reads explicitly selected literal maps and bounded typed native withMiddleware alias declarations without execution. Unknown and complete empty states differ; exact target spelling is preserved. Forty-one catalog checks and Mago source analysis passed; absence is not a pipeline error. |
| 70 | Middleware groups and legacy kernel catalog | implemented; Metadata-only group catalog reads selected literal maps or direct legacy Kernel group declarations, preserving order, duplicates and raw nested references. Activation and completeness are explicit independent contracts; legacy aliases and modern callback extraction defer. Thirty-one metadata checks and source analysis passed. |
| 71 | Middleware name references | deferred; Complete alias/group catalogs do not close callable or container resolution. Five native framework probes accept unlisted names and even nonexistent class targets through valid bindings. Missing-name diagnostics require a separate complete resolution contract; existing container suite passed. |
| 72 | Middleware parameter string parsing | deferred; Parameter parsing needs a resolved consumer and dispatch contract. Seventeen native probes confirm callable-before-parse, exact group precedence and different empty/zero suffix behavior for direct versus grouped middleware. Raw catalogs remain unchanged; no unused parser added. |
| 73 | Middleware class-string references | already-covered; Native Mago already checks middleware ::class references through imports, arrays and parameter concatenation; enabled/native proof produced the same four precise class diagnostics. Additional raw-string checks remain deferred because missing class names can be valid container keys. |
| 74 | Middleware handle contracts | implemented; Checks proven missing dispatch only for the first directly constructed plain object in an immediate audited native Pipeline chain. Strings, container state, later pipes and custom bodies defer. Seventeen real-Mago modes cover native/default/doc changes, function shadows, line endings and custom vendor paths; source clean. |
| 75 | Middleware attributes and arrays | already-covered; Native Mago preserves actual Middleware/WithoutMiddleware attribute targets and argument contracts, ordinary constructor and inherited HasMiddleware array PHPDoc. Thirteen exact native/enabled diagnostics matched. Additional raw-name resolution remains deferred. |
| 76 | Middleware parameter arity | implemented; Checks minimum required arity only for proven first direct-object native Pipeline dispatch, which supplies two arguments and prefers callable __invoke. Required-after-optional, defaults, variadics and valid extra arguments are covered. Nine arity and seventeen shared dispatch modes passed; raw string arity defers. |
| 77 | Middleware group cycles | queued |
| 78 | withoutMiddleware semantics | queued |
| 79 | Static Gate define catalog | queued |
| 80 | Explicit policy mapping catalog | queued |
| 81 | Declarative model-policy attributes | queued |
| 82 | Policy class references | queued |
| 83 | Policy method availability | queued |
| 84 | Gate ability name references | queued |
| 85 | Gate ability arrays | queued |
| 86 | Route can and Authorize attributes | queued |
| 87 | Policy model argument compatibility | queued |
| 88 | Additional policy arguments | queued |
| 89 | Class-level policy abilities | queued |
| 90 | Policy discovery resolver boundaries | queued |
| 91 | Existing explicit container binding types | already-covered; Verified explicit container bindings, aliases and contract guards; focused tests and source analysis passed. |
| 92 | Existing framework aliases and facade roots | already-covered; Verified literal framework aliases and facade roots; focused enabled/disabled suites passed. |
| 93 | Additional literal binding registrations | implemented; Literal unconditional scoped registrations reuse binding inference; direct/named, duplicate/runtime deferral and core-service replacement regressions pass. Conditional registration APIs remain deferred. |
| 94 | Container alias chains and uncertainty | queued |
| 95 | Container injection attributes | queued |
| 96 | Container alias cycles | queued |
| 97 | Binding interface compatibility | queued |
| 98 | Explicit factory required arguments | implemented; Literal container factories accept zero to two untyped required by-value parameters supplied by Laravel. Real-Mago checks retain return/body and uncertain-signature boundaries. |
| 99 | Complete service ID catalog references | queued |
| 100 | Contextual binding resolution | queued |
| 101 | Existing complete view helper contracts | already-covered; Verified complete view helper catalogs; seven reference-catalog modes and source analysis passed. |
| 102 | View facade and factory references | queued |
| 103 | Response view references | queued |
| 104 | Route view references | queued |
| 105 | MailMessage view and markdown references | queued |
| 106 | Declarative mail content references | queued |
| 107 | assertViewIs references | queued |
| 108 | Pagination view references | queued |
| 109 | View first fallback lists | queued |
| 110 | Conditional and iterative view rendering | queued |
| 111 | Package view namespaces | queued |
| 112 | View suggestions and declaration locations | queued |
| 113 | Existing literal translation types | already-covered; Verified literal translation string refinement with translation-strings and reference-catalogs suites. |
| 114 | Existing explicit-locale reference contracts | already-covered; Verified explicit locale/fallback reference catalogs; reference-catalogs and translation-strings suites passed. |
| 115 | Translator and Lang call sites | queued |
| 116 | trans_choice references | queued |
| 117 | JSON phrase translation references | queued |
| 118 | Package translation namespaces | queued |
| 119 | Translation replacement placeholders | queued |
| 120 | Suspicious replacement names | queued |
| 121 | Translation string leaves versus groups | queued |
| 122 | Cross-locale placeholder consistency | queued |
| 123 | Pluralization syntax contracts | queued |
| 124 | Translation fallback provenance | queued |
| 125 | Static filesystem disk catalog | implemented; tests/configuration-index.php; source-only completeness |
| 126 | Storage disk references | implemented; Guarded native Storage::disk diagnostics; 27 real-Mago scenarios passed. |
| 127 | Storage injection attribute references | implemented; Native Storage injection attribute diagnostics with provenance and custom-handler guards; focused suites passed. |
| 128 | Storage fake and forget semantics | queued |
| 129 | Unknown default disk configuration | queued |
| 130 | Concrete filesystem adapter types | queued |
| 131 | Explicit public asset catalogs | queued |
| 132 | Asset literal references | queued |
| 133 | Mix manifest parsing | queued |
| 134 | Mix manifest key references | queued |
| 135 | Malformed Mix manifest diagnostics | queued |
| 136 | Static path helper resolution | queued |
| 137 | Path existence in required-file contexts | queued |
| 138 | Vite manifest adaptation | queued |
| 139 | Explicit Inertia page catalog | implemented; tests/inertia-page-catalog.php; symlink creation test skipped on host |
| 140 | Inertia render page references | implemented; tests/inertia-page-references.php; opt-in complete catalog |
| 141 | Inertia helper and route references | queued |
| 142 | Optional Inertia integration calls | queued |
| 143 | Ambiguous Inertia page names | queued |
| 144 | Simple Vue defineProps names | queued |
| 145 | Required Inertia props contracts | queued |
| 146 | PHP and frontend prop type contracts | queued |
| 147 | Inertia assertion page contracts | queued |
| 148 | Static Blade class component catalog | queued |
| 149 | Static Blade anonymous component catalog | queued |
| 150 | Explicit Blade component aliases | queued |
| 151 | Blade component tag resolution | queued |
| 152 | Required Blade constructor props | queued |
| 153 | Literal Blade props declarations | queued |
| 154 | Blade HTML attributes versus PHP bindings | queued |
| 155 | Blade prop argument types | queued |
| 156 | Blade attribute bag semantics | queued |
| 157 | Blade view and translation references | queued |
| 158 | Static custom Blade directive catalogs | queued |
| 159 | Complete Blade directive reference checks | queued |
| 160 | Blade diagnostic source mapping | queued |
| 161 | Explicit Livewire component catalog | queued |
| 162 | Conventional Livewire class and view mapping | queued |
| 163 | PHP Livewire component references | queued |
| 164 | Blade Livewire component references | queued |
| 165 | Volt route component references | queued |
| 166 | Explicit Livewire mount contracts | queued |
| 167 | Livewire event and listener contracts | queued |
| 168 | Existing validated field shapes | already-covered; Verified validated field shapes, nested rules and dynamic/custom contract deferrals with focused suites. |
| 169 | Additional validation declaration contexts | queued |
| 170 | Versioned built-in validation rule catalog | queued |
| 171 | Validation rule name diagnostics | queued |
| 172 | Validation rule parameter contracts | queued |
| 173 | Validation regex delimiter parsing | queued |
| 174 | Validation rule object contracts | queued |
| 175 | Literal validation table and column references | queued |
| 176 | Cross-field validation reference hints | queued |
| 177 | Static Pest uses and path declarations | queued |
| 178 | Pest trait and file mapping | queued |
| 179 | Static Pest expectation extensions | queued |
| 180 | Pest closure this typing | queued |
| 181 | Pest expectation method forwarding | queued |
| 182 | Conflicting test context diagnostics | queued |
| 183 | Shared static metadata indexes | queued |
| 184 | Shared Laravel reference call registry | queued |
| 185 | Shared import and class identity resolution | queued |
| 186 | Shared literal string argument handling | queued |
| 187 | Precise literal element diagnostic spans | queued |
| 188 | Cross-platform and custom vendor paths | already-covered; Installer and real-worker path tests pass on Windows, including spaces and custom vendor configuration. Linux/macOS execution and combined generated custom-vendor installation remain unverified. |
| 189 | Index dependency invalidation | already-covered; Fresh analyzer runs reread changed migrations, configuration and source; properties/configuration regressions pass. Same-worker incremental/watch invalidation is not claimed. |
| 190 | Lazy integration index loading | implemented; Configuration/storage/Inertia diagnostic catalogs load on demand and reset per analysis generation; eager warning scans retained. Behavioral lazy lookup and four focused suites pass. |
| 191 | Metadata provenance locations | queued |
| 192 | Explicit metadata confidence states | queued |
| 193 | Upstream scenario regression adaptation | queued |
| 194 | Version-aware Laravel API capabilities | queued |
| 195 | Static metadata export | queued |
| 196 | Diagnostic name fixes and navigation | queued |
| 197 | Long-running index watch mode | queued |
