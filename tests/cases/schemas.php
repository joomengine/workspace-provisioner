<?php

declare(strict_types=1);

use JoomEngine\Workspace\{Json, Recipe, Schema};

test('versioned schemas describe every runtime action without accepting extra fields', function (): void {
    foreach (Schema::TYPES as $type) {
        $schema = Schema::document($type);
        same($schema, Json::decode(Json::encode($schema)));
        same('#/$defs/' . $type, $schema['$ref']);
        same(false, $schema['$defs']['operator']['additionalProperties']);
        same(false, $schema['$defs']['step']['additionalProperties']);
        foreach ($schema['$defs']['request']['oneOf'] as $request) {
            same(false, $request['additionalProperties']);
            $action = $request['properties']['action']['const'];
            same($action === 'restore', isset($request['properties']['replace_existing']));
        }
    }
    rejects(fn () => Schema::document('anything'), 'invalid_schema');
    foreach (glob(dirname(__DIR__, 2) . '/examples/recipe.*.json') as $file) {
        new Recipe(Json::decode(file_get_contents($file)));
    }
});
