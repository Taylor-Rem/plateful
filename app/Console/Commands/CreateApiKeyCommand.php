<?php

namespace App\Console\Commands;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-key:create
    {name : A label for the key, e.g. "Claude" or "Kitchen tablet"}
    {--platform : Mint a platform key: super-admin reach across every restaurant}
    {--restaurant= : Subdomain of the restaurant the key is scoped to}
    {--scopes=* : Scopes to grant (default: every restaurant scope, or * for --platform)}
    {--user= : Email of the person minting it, recorded as the creator}
    {--expires= : When the key stops working, e.g. "2027-01-01" or "+90 days"}
    {--rate-limit= : Requests per minute this key may make (default: the platform ceiling)}')]
#[Description('Mint an API key for the operator API and the MCP server. The key is printed once.')]
class CreateApiKeyCommand extends Command
{
    public function handle(): int
    {
        $platform = (bool) $this->option('platform');
        $subdomain = (string) $this->option('restaurant');

        if ($platform === ($subdomain !== '')) {
            $this->error('Pass exactly one of --platform or --restaurant=<subdomain>.');

            return self::INVALID;
        }

        $restaurant = null;

        if (! $platform) {
            $restaurant = Restaurant::query()->where('subdomain', $subdomain)->first();

            if ($restaurant === null) {
                $this->error("No restaurant with subdomain [{$subdomain}].");

                return self::FAILURE;
            }
        }

        $scopes = $this->resolveScopes($platform);

        if ($scopes === null) {
            return self::INVALID;
        }

        $creator = null;

        if ($this->option('user')) {
            $creator = User::query()->where('email', $this->option('user'))->first();

            if ($creator === null) {
                $this->error("No user with email [{$this->option('user')}].");

                return self::FAILURE;
            }
        }

        $expiresAt = $this->option('expires') ? CarbonImmutable::parse((string) $this->option('expires')) : null;

        $rateLimit = $this->option('rate-limit') !== null ? (int) $this->option('rate-limit') : null;

        if ($rateLimit !== null && ($rateLimit < 1 || $rateLimit > ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE)) {
            $this->error('--rate-limit must be between 1 and '.ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE.'.');

            return self::INVALID;
        }

        ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint(
            name: (string) $this->argument('name'),
            scopes: $scopes,
            restaurant: $restaurant,
            createdBy: $creator,
            expiresAt: $expiresAt,
            rateLimitPerMinute: $rateLimit,
        );

        $this->info(sprintf(
            '%s key [%s] created (id=%d, scopes: %s%s, %d requests/min).',
            $platform ? 'Platform' : 'Restaurant',
            $key->name,
            $key->id,
            implode(', ', (array) $key->scopes),
            $expiresAt ? ', expires '.$expiresAt->toDateString() : '',
            $key->rateLimitPerMinute(),
        ));
        $this->newLine();
        $this->line('Copy it now; it is not stored and cannot be shown again:');
        $this->newLine();
        $this->line('  '.$plain);
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array<int, ApiKeyScope>|null
     */
    protected function resolveScopes(bool $platform): ?array
    {
        $requested = array_values(array_filter((array) $this->option('scopes')));

        if ($requested === []) {
            return $platform ? [ApiKeyScope::All] : ApiKeyScope::restaurantScopes();
        }

        $scopes = [];

        foreach ($requested as $value) {
            $scope = ApiKeyScope::tryFrom((string) $value);

            if ($scope === null || (! $platform && $scope->isPlatformOnly())) {
                $this->error("Unknown or disallowed scope [{$value}]. Valid: ".implode(', ', array_map(
                    fn (ApiKeyScope $s) => $s->value,
                    $platform ? ApiKeyScope::cases() : ApiKeyScope::restaurantScopes(),
                )));

                return null;
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }
}
