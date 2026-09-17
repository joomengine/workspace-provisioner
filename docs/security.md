# Security model and operating boundaries

Treat customer PHP, uploaded extensions, Composer dependencies and interactive shells as untrusted code. One Incus virtual machine per workspace is the primary tenant boundary. Customer SSH/SFTP terminates in the development container. Container restrictions reduce exposure inside the VM; they are not proof that the VM or hypervisor cannot be compromised.

## Trusted management

The CLI and PHP facade are administrator/service tools. Run them on a trusted management system with a protected Incus client configuration, operation database and encryption key. They must not be publicly exposed with unauthenticated caller labels. Restrict Incus management to authenticated clients on approved management networks. Never mount its socket or keys in a workload, share its client configuration with customers, or connect public PR jobs to a privileged persistent runner.

Use named inventory/catalog entries, strict request validation, resource ownership checks and recorded operation IDs. Host preparation is explicit and installation-UUID confirmed. It does not format storage or adopt existing resources. Workers must fail closed when configuration changes or ownership/policy checks do not match. Do not bypass those errors by changing ownership markers on arbitrary resources.

## VM and network boundary

Keep hypervisors and guest kernels patched. Disable customer access to VM and physical-host accounts. Enforce memory, disk, I/O, virtual CPU and NIC constraints through Incus, not only through the customer-controlled guest. The current networking design combines direct NIC ACLs, port isolation and source address protections. A bridge-wide ACL alone is not the tenant boundary.

Block other customer networks, management services, metadata endpoints and outbound SMTP. Include publicly addressed administrative systems in the operator's denied destinations. IPv6 is denied by the selected policy rather than left as an uncontrolled alternate route. Only approved test/gateway ingress reaches web and SSH endpoints. The database has no published port. Shared physical-host networking and firewall interactions require actual lab tests, not just inspection of generated JSON.

Containers have no privileged mode, device passthrough or Docker/Incus sockets. The Joomla directory is shared only within its own VM. The development shell has no mount of the VM's system files. Writable directories, read-only roots, process limits and explicitly reduced capabilities are part of workload definitions. SSH uses public keys, unique host keys, protected authorized-key configuration, no passwords and no root login. A nonstandard SSH port is not an access-control mechanism. Interactive shell users can implement network tunnels themselves; external policy must still block unauthorized destinations.

## Initialization and secrets

Run confidential initialization before enabling customer ingress. VM recipe helpers are approved executable digests; application recipes run inside the JCB container as the workload user. After handover, website CLI and files must never be run with VM-root or host-root authority. Customer-controlled symlinks or scripts are not management configuration.

Credential files and authenticated encrypted state are scoped to one workspace. Ordinary operation output contains references and status, not administrator passwords. One-time credential export writes a new owner-only file. File-backed Compose secrets are protected files, not an encrypted vault. No infrastructure-wide registry, DNS, firewall, payment or database-management credential belongs in the guest. Temporary Composer auth is confined to its initial one-off container.

Private recipes remain outside checkout/build context. Their resulting branding and installed application code may be visible to the customer. Do not print recipe contents or rely on automatic log masking to make an otherwise unsafe log public. Diagnostics intentionally suppress arbitrary application command output. CI fixtures must be synthetic and use only public images; do not use public integration jobs for private recipes or registry credentials.

## Failure, updates and recovery

Persist image digests before infrastructure creation and retain them across retries. Do not install from a moving tag every time a container starts. Updating the provisioner package must not replace an active worker mid-operation. Drain workers and preserve the operation store and key before changing package versions. Infrastructure changes need tested recovery procedures.

Suspension must stop existing access, not just hide a dashboard button. The provisioner stops the VM and checks its state. Failure to confirm a stop remains visible. Resume revalidates before access is opened. Deletion is scoped to recorded owned resources; a failed cleanup is not reported as successful. Do not call a successful mock test evidence that a live session was terminated.

Encrypted backup primitives, VM exports and a validated restoration procedure are distinct deliverables. Until the live lifecycle and real restore tests pass, do not treat the presence of archive utilities as a recoverability guarantee. Maintain offline/protected key backups and apply an explicit retention policy; private storage is not a replacement for encryption or restoration tests.

## Reporting and qualification

Never publish real credentials, customer payloads or operational addresses in issues. Submit a minimal synthetic reproduction and identify the affected commit/version. There is no claim of an independent security certification. Release acceptance requires the evidence described in [qualification](qualification.md), and a stable package is not published merely because its source archive builds.
