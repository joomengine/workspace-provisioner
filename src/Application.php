<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Embeddable PHP facade. The embedding service authenticates caller identity before invoking it. */
final readonly class Application
{
    public Journal $journal;
    public Engine $engine;
    public Runtime $runtime;
    public Host $host;
    public Vault $vault;
    public Store $store;

    public function __construct(public Config $config, string $sourceRoot)
    {
        $state = $config->data['state'];
        $this->store = $state['driver'] === 'file' ? new FileStore($state['path']) : new PgStore(
            new \PDO($state['dsn'], $state['username'], rtrim(Files::readPrivate($state['password_file']), "\r\n")));
        $this->journal = new Journal($this->store, $config);
        $this->vault = new Vault($config->data['vault']['directory'], $config->data['vault']['key_file']);
        $transport = new Incus($config->data['incus']);
        $this->runtime = new IncusRuntime($config, $transport, $sourceRoot);
        $this->host = new Host($config, $transport);
        $this->engine = new Engine($config, $this->journal, $this->runtime, $this->vault);
    }

    public function submit(string $authenticatedCaller, array $request): array
    {
        return $this->journal->submit($authenticatedCaller, new Request($request));
    }

    public function credentials(string $caller, string $workspace): array
    {
        $w = $this->journal->workspace($workspace);
        $this->config->authorize($caller, $w['tenant'], 'credentials');
        if ($w['status'] !== 'ready') { throw new Fault('not_ready', 'Credentials can only be retrieved for a ready workspace.'); }
        return $this->vault->reveal($workspace);
    }
}
