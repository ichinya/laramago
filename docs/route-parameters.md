# Literal route parameter validation

The analyzer reports `ichinya/laramago/laramago-duplicate-route-parameter` when a literal URI in an explicit native route declaration repeats a conventional parameter name. For example, `$router->get('/items/{item}/parts/{item}')` warns, while `/items/{item}/parts/{part}` does not.

Supported receivers are exactly `Illuminate\Routing\Router` for `get`, `post`, `put`, `patch`, `delete`, `options`, `any`, `match`, and `addRoute`, and exactly `Illuminate\Routing\Route` for `setUri`. Positional and named `uri` arguments are supported. Optional markers and binding fields are normalized for comparison: `{item:slug}` and `{item:id?}` use the same parameter name. Names are case sensitive.

This is a declaration-level warning based on the URI at that call, not a simulation of the final route collection. Analysis never boots Laravel, evaluates application PHP, or queries a database. Only the targeted call expression is parsed; there is no filesystem route registry scan.

Dynamic expressions, argument unpacking, subclasses, overridden methods, and facade static declarations are deferred. Only conventional ASCII placeholder and binding-field names are checked. Group prefixes, domains, later route mutations, and provider registrations are not reconstructed. Named-route existence and middleware alias/class validity are not checked: container/provider registrations can supply them dynamically.

The implementation follows Laravel's `Illuminate\Routing\RouteUri::parse()` normalization and Symfony Routing's duplicate-variable prohibition during route compilation. The real-Mago test uses synthetic declarations with an execution trap, rather than requiring or bootstrapping a framework installation.
