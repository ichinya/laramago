<?php /**
 * Get / set the specified configuration value.
 *
 * If an array is passed as the key, we will assume you want to set an array of values.
 *
 * @param  array<string, mixed>|string|null  $key
 * @param  mixed  $default
 * @return ($key is null ? \Illuminate\Config\Repository : ($key is string ? mixed : null))
 */
function config($key = \null, $default = \null)
{
    if (\is_null($key)) {
        return \app('config');
    }
    if (\is_array($key)) {
        return \app('config')->set($key);
    }
    return \app('config')->get($key, $default);
}
