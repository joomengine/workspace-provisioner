<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

interface Runtime
{
    public function ensure(array $workspace, callable $observe): void;
    public function prepare(array $workspace, array $credentials): void;
    public function initialize(array $workspace): void;
    public function start(array $workspace): void;
    public function verify(array $workspace): array;
    public function handover(array $workspace): void;
    public function access(array $workspace, bool $enabled): void;
    public function suspend(array $workspace): void;
    public function delete(array $workspace): void;
    public function replaceKeys(array $workspace, array $keys): void;
    public function recipeCheck(array $workspace, array $step): bool;
    public function recipeRun(array $workspace, array $step, string $stdin): void;
    public function backup(array $workspace, string $operation): array;
}
