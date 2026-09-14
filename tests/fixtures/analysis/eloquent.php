<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Comparison;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use stdClass;

final class ComparisonModel extends Model {}

/** @return Builder<ComparisonModel> */
function explicitQuery(): Builder
{
    return ComparisonModel::query()->where('name', 'Example');
}

/** @return Builder<ComparisonModel> */
function implicitQuery(): Builder
{
    return ComparisonModel::where('name', 'Example');
}

function misspelledMethod(): void
{
    ComparisonModel::wherre('name', 'Example');
}

function missingArgument(): void
{
    ComparisonModel::where();
}

function invalidArgument(): void
{
    ComparisonModel::where(new stdClass);
}
