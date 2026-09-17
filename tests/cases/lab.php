<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/LabPlan.php';

use JoomEngine\Workspace\Testing\LabPlan;

test('real VM harness requires an explicit disposable installation and scoped commands', function (): void {
    $cfg = fixtureConfig('/tmp');
    $plan = ['version' => 1, 'operator' => '/operator.json', 'caller' => 'operator', 'tenant' => 'test',
        'catalog' => 'standard', 'profile' => array_key_first($cfg->data['profiles']),
        'confirm_installation' => $cfg->data['installation_id'], 'disposable' => true, 'instances' => 2];
    new LabPlan($plan, $cfg);
    $bad = $plan; $bad['disposable'] = false;
    rejects(fn () => new LabPlan($bad, $cfg), 'lab_not_authorized');
    $bad = $plan; $bad['application_checks'] = [['argv' => ['/bin/sh'], 'verify_argv' => ['list']]];
    rejects(fn () => new LabPlan($bad, $cfg), 'invalid_lab');
    $bad = $plan; $bad['unknown'] = true;
    rejects(fn () => new LabPlan($bad, $cfg), 'invalid_fields');
});
