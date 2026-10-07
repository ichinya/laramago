<?php

declare(strict_types=1);

namespace StrictFloatFixtures;

file_put_contents(__DIR__.'/strict-declaration-body-executed', 'Analyzed strict declarations must never execute.');
function consume(float $value): void {}
