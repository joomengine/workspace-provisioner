<?php

declare(strict_types=1);

use JoomEngine\Workspace\Config;

test('host topology rejects overlapping pools and cross-host trusted ingress', function (): void {
    $data = fixtureConfig('/tmp')->data;
    $data['hosts']['second'] = $data['hosts']['lab'];
    $data['hosts']['second']['remote'] = 'second';
    rejects(fn () => new Config($data), 'invalid_config');
    $data['hosts']['second']['bridge_address'] = '10.94.0.1/24';
    $data['hosts']['second']['addresses'] = ['10.94.0.10'];
    new Config($data);
    $bad = $data;
    $bad['hosts']['lab']['ingress'][] = '10.94.0.0/24';
    rejects(fn () => new Config($bad), 'invalid_config');
    $bad = $data;
    $bad['hosts']['second']['remote'] = $data['hosts']['lab']['remote'];
    $bad['hosts']['second']['project'] = 'other-project';
    rejects(fn () => new Config($bad), 'invalid_config');
});
