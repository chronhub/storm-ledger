<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Console\PrivacyForgetCommand;
use Storm\Ledger\Crypto\SubjectForgetter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PrivacyForgetCommandTest extends TestCase
{
    #[Test]
    public function refuses_loud_when_no_personal_data_is_declared(): void
    {
        // an exit 0 here would read as "erasure honored" to whoever runs this against a regulator's
        // deadline; the app declares no #[Personal] class, so crypto-shredding does not apply to it,
        // and that must fail loud before the subject argument is even looked at
        $forgetter = new SubjectForgetter;

        $tester = new CommandTester(new PrivacyForgetCommand($forgetter));
        $code = $tester->execute(['subject' => 'irrelevant']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('declares no #[Personal] class', $tester->getDisplay());
    }

    #[Test]
    public function refuses_an_empty_or_whitespace_subject(): void
    {
        $keys = $this->createStub(CipherKeyStore::class);
        $forgetter = new SubjectForgetter($keys);

        $tester = new CommandTester(new PrivacyForgetCommand($forgetter, $keys));
        $code = $tester->execute(['subject' => '   ']);

        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('The subject id must be a non-empty string', $tester->getDisplay());
    }

    #[Test]
    public function declares_arguments_and_options(): void
    {
        $keys = $this->createStub(CipherKeyStore::class);
        $forgetter = new SubjectForgetter($keys);

        $command = new PrivacyForgetCommand($forgetter, $keys);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('subject'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('force'));
    }
}
