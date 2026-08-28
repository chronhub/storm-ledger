<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Ledger package wiring: the schema installer, storm:install, autowires the DBAL Connection and is
 * autoconfigured as a console command.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\Ledger\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/config/',
            dirname(__DIR__).'/Exception/',
            dirname(__DIR__).'/Tests/',
        ]);

    // storm:install creates each schema on ITS side, per-projection homing: `projections` rides
    // the read-model store alias when the Projector wiring declares it; nullOnInvalid keeps the
    // package standalone, where no Projector means a single connection, byte-identical.
    // The safe-head note is about the RUNTIME, not the schema this command proves, so its absence
    // must never fail a container: nullOnInvalid keeps the installer wired wherever Chronicler's own
    // services are not, and the note simply goes unsaid.
    // $privacyKeys stays explicitly null here, never left to autowire: RegisterPersonalDataPass is
    // the ONLY place that arms it, a Reference bound before autowiring runs, and only where the
    // compiled #[Personal] map is non-empty. An explicit null is what autowiring cannot override,
    // so an app with none never reaches DbalCipherKeyStore, hence never its master-key parameter.
    $services->set(\Storm\Ledger\Console\StormInstallCommand::class)
        ->arg('$readModels', service('storm.read_model_store_connection')->nullOnInvalid())
        ->arg('$safeHead', service(\Storm\Chronicler\SafeHead\SafeHeadPrecondition::class)->nullOnInvalid())
        ->arg('$privacyKeys', null);

    // The cipher-key store behind crypto-shredding. The master key is a PARAMETER the bundle sets
    // from config, default %env(STORM_PRIVACY_MASTER_KEY)%. Nothing autowires this store: every
    // consumer's argument is explicitly null below, so the env is only ever reached through the
    // one Reference RegisterPersonalDataPass hands out, and only where a #[Personal] class exists.
    // #[AsAlias] binds the Contracts port for that one wiring, not for ambient autowiring.
    $services->set(\Storm\Ledger\Crypto\DbalCipherKeyStore::class)
        ->arg('$masterKey', '%storm.privacy.master_key%');

    // The forget's one assembly, shared by the console verb and the HTTP twin. The projector wiring
    // is optional BY CONTRACT: a standalone Ledger or a query-only app has no registry and no
    // lanes, and the forget degrades to the key destruction with an empty report. $keys is armed
    // the same way as $privacyKeys above, and for the same reason.
    $services->set(\Storm\Ledger\Crypto\SubjectForgetter::class)
        ->arg('$keys', null)
        ->arg('$registry', service(\Storm\Projector\Registry\ProjectionRegistry::class)->nullOnInvalid())
        ->arg('$lanes', service(\Storm\Projector\Run\ProjectionLanes::class)->nullOnInvalid());

    // The console verb's own $keys, read directly for the confirmation's key-state line and the
    // dry-run report; armed the same way and for the same reason as SubjectForgetter's above.
    $services->set(\Storm\Ledger\Console\PrivacyForgetCommand::class)
        ->arg('$keys', null);
};
