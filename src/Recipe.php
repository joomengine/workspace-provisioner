<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

final readonly class Recipe
{
    public array $document;
    public string $hash;

    public function __construct(array $document, array $vmExecutables = [])
    {
        Validate::object($document, ['version', 'id', 'steps']);
        Validate::integer($document['version'], 1, 1);
        Validate::name($document['id']);
        $seen = [];
        $containerStarted = false;
        foreach (Validate::list($document['steps'], 0, 100) as $step) {
            Validate::object($step, ['id', 'target', 'argv', 'timeout', 'repeat', 'check'], ['stdin_secret', 'depends_on']);
            $id = Validate::name($step['id']);
            if (isset($seen[$id]) || !in_array($step['target'], ['vm', 'joomla'], true)
                || !in_array($step['repeat'], ['safe', 'once'], true)) {
                throw new Fault('invalid_recipe', 'Duplicate step or unsupported target/repeat policy.');
            }
            foreach (Validate::list($step['depends_on'] ?? []) as $dependency) {
                if (!isset($seen[Validate::name($dependency)])) {
                    throw new Fault('invalid_recipe', 'Dependencies must refer to earlier steps.');
                }
            }
            $seen[$id] = true;
            Validate::integer($step['timeout'], 1, 3600);
            if (isset($step['stdin_secret'])) {
                Validate::name($step['stdin_secret']);
            }
            Validate::object($step['check'], ['argv'], ['stdout']);
            if (isset($step['check']['stdout']) && (!is_string($step['check']['stdout']) || strlen($step['check']['stdout']) > 8192)) {
                throw new Fault('invalid_recipe', 'Invalid postcondition output.');
            }
            foreach ([$step['argv'], $step['check']['argv']] as $argv) {
                Validate::argv($argv);
                if ($step['target'] === 'vm') {
                    if (!in_array($argv[0], $vmExecutables, true) || $containerStarted) {
                        throw new Fault('invalid_recipe', 'VM steps must precede application steps and use approved executables.');
                    }
                } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/D', $argv[0])) {
                    throw new Fault('invalid_recipe', 'A Joomla CLI command name, not a PHP/shell executable, is required.');
                }
            }
            $containerStarted = $containerStarted || $step['target'] === 'joomla';
        }
        $this->document = $document;
        $this->hash = Json::hash($document);
    }

    public static function standard(): self
    {
        return new self(['version' => 1, 'id' => 'standard', 'steps' => []]);
    }

    public function steps(string $target): array
    {
        return array_values(array_filter($this->document['steps'], static fn (array $s): bool => $s['target'] === $target));
    }
}
