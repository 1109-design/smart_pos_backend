<?php

namespace App\Services\Zimra;

class FiscalisationGuard
{
    /**
     * Decide whether the current runtime may send state-changing requests to
     * ZIMRA (submit receipts, open/close fiscal days, register devices, issue
     * certificates).
     *
     * The decision relies ONLY on runtime/machine signals — never on values
     * stored in the database — because production databases are routinely
     * cloned to local machines for development. A cloned database carries the
     * real device certificates, TINs and the ZimraConfiguration `environment`
     * row, so the only trustworthy "am I production?" signals are APP_ENV and
     * the host identity, which travel with the server, not the data.
     */
    public function allows(): bool
    {
        return $this->evaluate()['allowed'];
    }

    public function blockReason(): ?string
    {
        return $this->evaluate()['reason'];
    }

    public function assert(): void
    {
        $result = $this->evaluate();

        if (! $result['allowed']) {
            throw new FiscalisationBlockedException((string) $result['reason']);
        }
    }

    /**
     * Resolve the real runtime context and decide.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function evaluate(): array
    {
        return $this->decide(
            (bool) config('zimra.fiscalisation.enabled', true),
            (string) app()->environment(),
            (array) config('zimra.fiscalisation.allowed_environments', ['production']),
            (array) config('zimra.fiscalisation.allowed_hosts', []),
            $this->machineIdentifiers(),
        );
    }

    /**
     * Pure decision function — no framework or IO access, so it is trivially
     * unit-testable and contains no hidden state.
     *
     * @param  array<int, string>  $allowedEnvironments
     * @param  array<int, string>  $allowedHosts
     * @param  array<int, string>  $machineIdentifiers
     * @return array{allowed: bool, reason: string|null}
     */
    public function decide(
        bool $enabled,
        string $environment,
        array $allowedEnvironments,
        array $allowedHosts,
        array $machineIdentifiers
    ): array {
        if (! $enabled) {
            return $this->deny('ZIMRA fiscalisation is disabled (ZIMRA_FISCALISATION_ENABLED=false).');
        }

        $allowedEnvironments = $this->normalise($allowedEnvironments);

        if (! in_array(strtolower(trim($environment)), $allowedEnvironments, true)) {
            return $this->deny(sprintf(
                "ZIMRA fiscalisation blocked: APP_ENV '%s' is not permitted (allowed: %s). This protects live ZIMRA devices when a production database is cloned for local development.",
                $environment,
                implode(', ', $allowedEnvironments) ?: '(none)'
            ));
        }

        $allowedHosts = $this->normalise($allowedHosts);

        if (! empty($allowedHosts)) {
            $identifiers = $this->normalise($machineIdentifiers);

            if (empty(array_intersect($allowedHosts, $identifiers))) {
                return $this->deny(sprintf(
                    'ZIMRA fiscalisation blocked: this host [%s] is not in the allowed host list [%s]. Set ZIMRA_FISCALISATION_ALLOWED_HOSTS on the production server only.',
                    implode(', ', $identifiers) ?: 'unknown',
                    implode(', ', $allowedHosts)
                ));
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array{allowed: false, reason: string}
     */
    protected function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    /**
     * Lower-case, trim and drop empty values so comparisons are case/space safe.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    protected function normalise(array $values): array
    {
        $normalised = array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $values
        );

        return array_values(array_unique(array_filter(
            $normalised,
            static fn (string $value): bool => $value !== ''
        )));
    }

    /**
     * Machine-level identifiers that uniquely describe the server. These do NOT
     * travel with a database dump, which is exactly why they are trustworthy.
     *
     * @return array<int, string>
     */
    protected function machineIdentifiers(): array
    {
        $hostname = gethostname() ?: null;

        $identifiers = array_merge([
            $hostname,
            php_uname('n') ?: null,
            $hostname ? gethostbyname($hostname) : null,
            $_SERVER['SERVER_ADDR'] ?? null,
        ], $this->localIpAddresses());

        return array_values(array_filter($identifiers));
    }

    /**
     * Every IPv4 address bound to this machine's network interfaces.
     *
     * Enumerating the interfaces directly is the only reliable way to match a
     * LAN IP (e.g. 10.10.0.29) in ALL runtime contexts — crucially the queue
     * worker (CLI), where $_SERVER['SERVER_ADDR'] is absent and
     * gethostbyname(gethostname()) usually returns 127.0.0.1 instead of the
     * real interface address. A UDP "connect" (no packets sent) is used as a
     * fallback to discover the primary outbound interface IP.
     *
     * @return array<int, string>
     */
    protected function localIpAddresses(): array
    {
        $addresses = [];

        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();

            if (is_array($interfaces)) {
                foreach ($interfaces as $interface) {
                    foreach ($interface['unicast'] ?? [] as $unicast) {
                        // family 2 = AF_INET (IPv4).
                        if (($unicast['family'] ?? null) === 2 && ! empty($unicast['address'])) {
                            $addresses[] = $unicast['address'];
                        }
                    }
                }
            }
        }

        $socket = @stream_socket_client('udp://10.255.255.255:53', $errno, $errstr, 1);

        if ($socket !== false) {
            $name = @stream_socket_get_name($socket, false);
            fclose($socket);

            if (is_string($name) && str_contains($name, ':')) {
                $addresses[] = substr($name, 0, strrpos($name, ':'));
            }
        }

        return $addresses;
    }
}
