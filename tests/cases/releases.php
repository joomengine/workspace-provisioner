<?php

declare(strict_types=1);

use JoomEngine\Workspace\Release;

test('release versions advance automatically and reject unsafe identifiers', function (): void {
    same('0.1.0', Release::next(null, ['feat: initial implementation']));
    same('1.2.4', Release::next('1.2.3', ['fix: retry safely', 'docs: explain usage']));
    same('1.3.0', Release::next('1.2.3', ['feat(cli): add command']));
    same('2.0.0', Release::next('1.2.3', ['feat!: change protocol']));
    same('2.0.0', Release::next('1.2.3', ["fix: protocol\n\nBREAKING CHANGE: schema changes"]));
    rejects(fn () => Release::next('1.2.3', []), 'no_changes');
    foreach (['latest', '../1.0.0', '01.2.3', 'v1.0.0', '1.2.3;id'] as $version) {
        rejects(fn () => Release::version($version), 'invalid_version');
    }
    same(true, Release::manifest('0.1.0-rc.1', str_repeat('a', 40), 123)['prerelease']);
    same(false, Release::manifest('0.1.0', str_repeat('a', 40), 123)['prerelease']);
});
