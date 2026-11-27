<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Qiick\Outbound\Console\Commands\PruneWebhookPayloadsCommand;
use Qiick\Outbound\Console\Commands\WorkWebhookDeliveriesCommand;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Contracts\LegacyWebhookProfileContract;
use Qiick\Outbound\Contracts\MetadataPolicyContract;
use Qiick\Outbound\Contracts\WebhookEndpointNoticeRecipientsContract;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\EndpointDisablePolicy;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Http\EgressProxy;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Listeners\NotifyWebhookEndpointOwnerListener;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Retry\SystemRandomizer;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver;
use Qiick\Outbound\Webhooks\EndpointCreatorRecipients;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

/**
 * Auto-discovered Laravel service provider for qiick/laravel-outbound.
 *
 * @api
 */
final class OutboundServiceProvider extends ServiceProvider
{
    /**
     * The type of every `outbound.*` key, fixed here rather than read from
     * the defaults: an uncast `env()` default changes type with the
     * environment ("false" becomes a boolean). A test keeps it in step with
     * config/outbound.php.
     *
     * @var array<string, 'int'|'bool'|'string'|'?string'|'map'>
     */
    private const CONFIG_TYPES = [
        'outbound.migrations.load' => 'bool',
        'outbound.ssrf.allow_private_hosts' => 'bool',
        'outbound.ssrf.https_only' => 'bool',
        'outbound.http.timeout_seconds' => 'int',
        'outbound.http.connect_timeout_seconds' => 'int',
        'outbound.http.max_timeout_seconds' => 'int',
        'outbound.http.max_connect_timeout_seconds' => 'int',
        'outbound.http.total_deadline_seconds' => 'int',
        'outbound.http.max_response_bytes' => 'int',
        'outbound.http.max_redirects' => 'int',
        'outbound.http.egress_proxy' => '?string',
        'outbound.webhooks.replay_tolerance_seconds' => 'int',
        'outbound.webhooks.max_payload_bytes' => 'int',
        'outbound.webhooks.max_signatures' => 'int',
        'outbound.webhooks.max_secrets' => 'int',
        'outbound.webhooks.secret_rotation_grace_seconds' => 'int',
        'outbound.webhooks.retry_preset' => 'string',
        'outbound.webhooks.retry_jitter_seconds' => 'int',
        'outbound.webhooks.timeout_seconds' => 'int',
        'outbound.webhooks.respect_retry_after' => 'bool',
        'outbound.webhooks.retry_after_cap_seconds' => 'int',
        'outbound.webhooks.default_max_concurrency' => 'int',
        'outbound.webhooks.payload_retention_days' => 'int',
        'outbound.webhooks.delivery_retention_days' => 'int',
        'outbound.webhooks.auto_disable.enabled' => 'bool',
        'outbound.webhooks.auto_disable.no_success_days' => 'int',
        'outbound.webhooks.auto_disable.min_consecutive_failures' => 'int',
        'outbound.webhooks.auto_disable.notify_owner' => 'bool',
        'outbound.webhooks.auto_disable.endpoint_url' => '?string',
        'outbound.webhooks.allow_raw_legacy_secret' => 'bool',
        'outbound.webhooks.legacy_profiles' => 'map',
        'outbound.webhooks.scope_column' => '?string',
        'outbound.webhooks.users_table' => 'string',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/outbound.php', 'outbound');

        $this->app->singleton(HostResolver::class, DnsHostResolver::class);

        $this->app->singleton(RemoteUrlGuard::class, function (Application $app): RemoteUrlGuard {
            $resolver = $app->make(HostResolver::class);
            $config = $app->make(ConfigRepository::class);

            return new RemoteUrlGuard(
                hostResolver: $resolver,
                allowPrivateHosts: self::configBool($config, 'outbound.ssrf.allow_private_hosts'),
                httpsOnly: self::configBool($config, 'outbound.ssrf.https_only'),
                metadataPolicy: $app->bound(MetadataPolicyContract::class) ? $app->make(MetadataPolicyContract::class) : null,
            );
        });

        $this->app->singleton(OutboundHttpLimits::class, function (Application $app): OutboundHttpLimits {
            $config = $app->make(ConfigRepository::class);

            return new OutboundHttpLimits(
                maxTimeoutSeconds: self::configInt($config, 'outbound.http.max_timeout_seconds'),
                maxConnectTimeoutSeconds: self::configInt($config, 'outbound.http.max_connect_timeout_seconds'),
                totalDeadlineSeconds: self::configInt($config, 'outbound.http.total_deadline_seconds'),
            );
        });

        // Private Factory — never the shared Http facade factory, so
        // Http::globalOptions(['handler' => ...]) cannot replace our curl wrap.
        $this->app->singleton(PinnedHttpClient::class, function (Application $app): PinnedHttpClient {
            $egressProxy = $app->make(ConfigRepository::class)->get('outbound.http.egress_proxy');

            return new PinnedHttpClient(
                new HttpFactory,
                $app->make(RemoteUrlGuard::class),
                $app->make(OutboundHttpLimits::class),
                // Only null or '' (an unset env variable) mean no proxy; any
                // other value, a boolean or an array included, is refused.
                egressProxy: $egressProxy === null || $egressProxy === '' ? null : EgressProxy::fromUrl(is_string($egressProxy) ? $egressProxy : ''),
                events: $app->make(EventDispatcher::class),
            );
        });

        // A product binds its own to choose who hears of a disabled endpoint.
        $this->app->bindIf(WebhookEndpointNoticeRecipientsContract::class, EndpointCreatorRecipients::class);

        $this->app->singleton(SeenWebhookIdStore::class, function (Application $app): SeenWebhookIdStore {
            // ArrayStore is atomic but process-local — allow only in the testing env.
            return new CacheSeenWebhookIdStore(
                $app->make(CacheRepository::class),
                allowArrayStore: $app->environment('testing'),
            );
        });

        $this->app->singleton(StandardWebhookSigner::class, function (Application $app): StandardWebhookSigner {
            $config = $app->make(ConfigRepository::class);

            return new StandardWebhookSigner(
                maxPayloadBytes: self::configInt($config, 'outbound.webhooks.max_payload_bytes'),
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets'),
                events: $app->make(EventDispatcher::class),
            );
        });

        $this->app->singleton(StandardWebhookVerifier::class, function (Application $app): StandardWebhookVerifier {
            $config = $app->make(ConfigRepository::class);

            return new StandardWebhookVerifier(
                replayToleranceSeconds: self::configInt($config, 'outbound.webhooks.replay_tolerance_seconds'),
                maxPayloadBytes: self::configInt($config, 'outbound.webhooks.max_payload_bytes'),
                maxSignatures: self::configInt($config, 'outbound.webhooks.max_signatures'),
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets'),
                seenIds: $app->make(SeenWebhookIdStore::class),
            );
        });

        $this->app->singleton(WebhookSecretResolver::class, function (Application $app): WebhookSecretResolver {
            return new DatabaseWebhookSecretResolver(
                allowRawLegacySecret: self::configBool($app->make(ConfigRepository::class), 'outbound.webhooks.allow_raw_legacy_secret'),
            );
        });

        $this->app->singleton(DatabaseDeliveryStore::class, function (Application $app): DatabaseDeliveryStore {
            $scopeColumn = $app->make(ConfigRepository::class)->get('outbound.webhooks.scope_column');

            if ($scopeColumn !== null && ! is_string($scopeColumn)) {
                throw new InvalidArgumentException('outbound.webhooks.scope_column must be a string.');
            }

            return new DatabaseDeliveryStore(
                scopeColumn: $scopeColumn !== null && $scopeColumn !== '' ? $scopeColumn : null,
                payloadRetentionDays: self::configInt($app->make(ConfigRepository::class), 'outbound.webhooks.payload_retention_days'),
                deliveryRetentionDays: self::configInt($app->make(ConfigRepository::class), 'outbound.webhooks.delivery_retention_days'),
            );
        });

        $this->app->singleton(RetryPolicy::class, function (Application $app): RetryPolicy {
            $config = $app->make(ConfigRepository::class);

            return new RetryPolicy(
                new SystemRandomizer,
                jitterSeconds: self::configInt($config, 'outbound.webhooks.retry_jitter_seconds'),
                respectRetryAfter: self::configBool($config, 'outbound.webhooks.respect_retry_after'),
                retryAfterCapSeconds: self::configInt($config, 'outbound.webhooks.retry_after_cap_seconds'),
            );
        });

        $this->app->singleton(EndpointDisablePolicy::class, function (Application $app): EndpointDisablePolicy {
            $config = $app->make(ConfigRepository::class);

            return new EndpointDisablePolicy(
                isEnabled: self::configBool($config, 'outbound.webhooks.auto_disable.enabled'),
                noSuccessDays: self::configInt($config, 'outbound.webhooks.auto_disable.no_success_days'),
                minConsecutiveFailures: self::configInt($config, 'outbound.webhooks.auto_disable.min_consecutive_failures'),
            );
        });

        $this->app->singleton(WebhookDeliveryCoordinator::class, function (Application $app): WebhookDeliveryCoordinator {
            $config = $app->make(ConfigRepository::class);

            return new WebhookDeliveryCoordinator(
                $app->make(DatabaseDeliveryStore::class),
                $app->make(WebhookSecretResolver::class),
                $app->make(StandardWebhookSigner::class),
                $app->make(PinnedHttpClient::class),
                $app->make(RetryPolicy::class),
                $app->make(OutboundHttpLimits::class),
                timeoutSeconds: self::configInt($config, 'outbound.webhooks.timeout_seconds'),
                connectTimeoutSeconds: self::configInt($config, 'outbound.http.connect_timeout_seconds'),
                defaultRetryPreset: self::defaultRetryPreset($config),
                defaultMaxConcurrency: self::configInt($config, 'outbound.webhooks.default_max_concurrency'),
                maxResponseBytes: self::configInt($config, 'outbound.http.max_response_bytes'),
                exceptionHandler: $app->make(ExceptionHandler::class),
                disablePolicy: $app->make(EndpointDisablePolicy::class),
                events: $app->make(EventDispatcher::class),
                legacyProfiles: self::legacyProfiles($app, $config),
            );
        });

        $this->app->singleton(SecretRotator::class, function (Application $app): SecretRotator {
            $config = $app->make(ConfigRepository::class);

            return new SecretRotator(
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets'),
                defaultGraceSeconds: self::configInt($config, 'outbound.webhooks.secret_rotation_grace_seconds'),
            );
        });
    }

    /**
     * The webhook tables are mandatory, so the package loads its own
     * migrations unless `outbound.migrations.load` is off; a product that
     * turns it off publishes them instead (standards review R1).
     *
     * Every `outbound.*` value is read once here, so a value of the wrong
     * type stops the boot naming its key (fleet convention F1) instead of
     * failing at the first delivery or verification.
     *
     * @throws WebhookException when `outbound.webhooks.max_secrets` is outside
     *                          1–4: the fixed limit is refused at boot, not at
     *                          the first rotation
     * @throws InvalidArgumentException when a value has the wrong type or range
     */
    public function boot(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        $this->app->make(SecretRotator::class);
        // Refuses auto-disable thresholds below 1 and a payload retention
        // shorter than the retry schedule at boot too.
        $this->app->make(EndpointDisablePolicy::class);
        $this->app->make(DatabaseDeliveryStore::class);
        // Refuses an egress proxy that is not an http(s) proxy URL at boot.
        $this->app->make(PinnedHttpClient::class);
        // The other services are built on first use, after a test or a
        // product has changed the config or a binding; their values are
        // checked here against CONFIG_TYPES.
        self::assertConfigTypes($config);
        self::defaultRetryPreset($config);
        $this->app->make(EventDispatcher::class)->listen(WebhookEndpointDisabledEvent::class, NotifyWebhookEndpointOwnerListener::class);

        $migrationsPath = __DIR__.'/../../database/migrations';

        if (self::configBool($config, 'outbound.migrations.load')) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/outbound.php' => config_path('outbound.php'),
            ], 'outbound-config');

            $this->publishesMigrations([$migrationsPath => database_path('migrations')], 'outbound-migrations');

            $this->commands([WorkWebhookDeliveriesCommand::class, PruneWebhookPayloadsCommand::class]);
        }
    }

    /**
     * `outbound.webhooks.legacy_profiles`, each binding resolved.
     *
     * @return array<string, LegacyWebhookProfileContract>
     *
     * @throws InvalidArgumentException when an entry is not a name and a
     *                                  binding of a LegacyWebhookProfileContract
     */
    private static function legacyProfiles(Application $app, ConfigRepository $config): array
    {
        $profiles = [];

        self::assertNamedBindings($config, 'outbound.webhooks.legacy_profiles');

        foreach ((array) self::configValue($config, 'outbound.webhooks.legacy_profiles') as $name => $binding) {
            $profile = is_string($name) && is_string($binding) ? $app->make($binding) : null;

            if (! is_string($name) || ! $profile instanceof LegacyWebhookProfileContract) {
                throw new InvalidArgumentException('outbound.webhooks.legacy_profiles must map a name to a LegacyWebhookProfileContract binding.');
            }

            $profiles[$name] = $profile;
        }

        return $profiles;
    }

    /**
     * Checks every configured value against CONFIG_TYPES, and every section
     * (`outbound.webhooks`, …) against an array, so a section replaced by a
     * scalar is not read as "every key missing".
     *
     * @throws InvalidArgumentException naming the first key of the wrong type
     */
    private static function assertConfigTypes(ConfigRepository $config): void
    {
        foreach (self::CONFIG_TYPES as $key => $type) {
            for ($section = dirname(str_replace('.', '/', $key)); $section !== 'outbound'; $section = dirname($section)) {
                $sectionKey = str_replace('/', '.', $section);

                if ($config->has($sectionKey) && ! is_array($config->get($sectionKey))) {
                    throw new InvalidArgumentException("{$sectionKey} must be an array.");
                }
            }

            match ($type) {
                'int' => self::configInt($config, $key),
                'bool' => self::configBool($config, $key),
                'string' => self::configString($config, $key),
                '?string' => self::configValue($config, $key) === null ? null : self::configString($config, $key),
                'map' => self::assertNamedBindings($config, $key),
            };
        }
    }

    /**
     * @throws InvalidArgumentException when the value is not a map of names to binding names
     */
    private static function assertNamedBindings(ConfigRepository $config, string $key): void
    {
        $bindingsByName = self::configValue($config, $key);

        if (! is_array($bindingsByName) || array_filter($bindingsByName, is_string(...)) !== $bindingsByName || array_filter(array_keys($bindingsByName), is_string(...)) !== array_keys($bindingsByName)) {
            throw new InvalidArgumentException("{$key} must map a name to a LegacyWebhookProfileContract binding.");
        }
    }

    /**
     * @throws InvalidArgumentException when the value is not a preset name
     */
    private static function defaultRetryPreset(ConfigRepository $config): WebhookRetryPresetEnum
    {
        return WebhookRetryPresetEnum::tryFrom(self::configString($config, 'outbound.webhooks.retry_preset'))
            ?? throw new InvalidArgumentException('outbound.webhooks.retry_preset must be standard or short.');
    }

    /**
     * @throws InvalidArgumentException when the value is not an integer
     */
    private static function configInt(ConfigRepository $config, string $key): int
    {
        $value = self::configValue($config, $key);

        return is_int($value) ? $value : throw new InvalidArgumentException("{$key} must be an integer.");
    }

    /**
     * @throws InvalidArgumentException when the value is not a boolean
     */
    private static function configBool(ConfigRepository $config, string $key): bool
    {
        $value = self::configValue($config, $key);

        return is_bool($value) ? $value : throw new InvalidArgumentException("{$key} must be true or false.");
    }

    /**
     * @throws InvalidArgumentException when the value is not a string
     */
    private static function configString(ConfigRepository $config, string $key): string
    {
        $value = self::configValue($config, $key);

        return is_string($value) ? $value : throw new InvalidArgumentException("{$key} must be a string.");
    }

    /**
     * The configured value, or the package's own default from
     * config/outbound.php when the key is missing: Laravel merges only the
     * top level of a package config, so a config published before a key
     * existed lacks it. A value present but of the wrong type, null
     * included, stops the boot instead of silently becoming the default.
     */
    private static function configValue(ConfigRepository $config, string $key): mixed
    {
        return $config->has($key) ? $config->get($key) : data_get(['outbound' => self::packageDefaults()], $key);
    }

    /**
     * config/outbound.php as shipped, the reference for missing keys and types.
     *
     * @return array<mixed>
     */
    private static function packageDefaults(): array
    {
        $defaults = require __DIR__.'/../../config/outbound.php';

        return is_array($defaults) ? $defaults : [];
    }
}
