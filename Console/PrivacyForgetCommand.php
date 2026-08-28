<?php

declare(strict_types=1);

namespace Storm\Ledger\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Ledger\Exception\ForgetIncomplete;
use Storm\Ledger\Exception\InvalidMasterKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The forgetting verb of crypto-shredding: destroy a subject's cipher key, then run every
 * volunteering projection's `ForgetsSubject` hook, per home in one transaction. The history itself
 * stays untouched; every encrypted personal field of the subject's events renders its declared
 * fallback from now on. The destruction leaves a tombstone, the proof of forgetting; re-running is
 * a green no-op that says so, and re-runs every hook, idempotent by contract. This command and its
 * HTTP twin both render the one `SubjectForgetter` assembly.
 *
 * The report is honest about scope, because a forget that overstates itself is a compliance lie. It
 * names the projections it touched, then the read-model projections that did not volunteer, whose
 * catch-up is reset + rebuild and refolds onto fallbacks, then the standing residuals:
 *
 * - Rows written before their class was marked keep their cleartext bytes in the store;
 * - Snapshots taken before the marking may hold cleartext state;
 * - Backups keep the key until their own rotation.
 *
 * `--dry-run` reports the subject's key state and both hook halves, touching nothing.
 *
 * The cipher-key store is bound only in an app that declares at least one `#[Personal]` class; an
 * app with none refuses with a non-zero exit before touching anything, since a clean 0 here would
 * read as an erasure that never applied to it.
 *
 * Examples:
 *
 * ```bash
 * # Forget a subject (idempotent; exit 0 whether it forgot now or already had)
 * bin/console storm:privacy:forget 9f1c…
 * ```
 *
 * ```bash
 * # See what would happen without touching anything
 * bin/console storm:privacy:forget 9f1c… --dry-run
 * ```
 */
#[AsCommand(
    name: 'storm:privacy:forget',
    description: 'Destroy a subject\'s cipher key (crypto-shredding) and run the volunteering read-model hooks; tombstone kept as proof. Idempotent.',
)]
final class PrivacyForgetCommand extends Command
{
    public function __construct(
        private readonly SubjectForgetter $forgetter,
        private readonly ?CipherKeyStore $keys = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('subject', InputArgument::REQUIRED, 'The subject\'s opaque id — the value of the #[Personal] subject payload key.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the subject\'s key state and both hook halves, touching nothing.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation; required in a non-interactive session, since the erase cannot be undone.');
    }

    /**
     * {@inheritDoc}
     *
     * @throws ForgetIncomplete when a volunteer's hook failed; the key IS destroyed, re-run to complete
     * @throws Exception on a DBAL failure reading or tombstoning the key row
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // an exit 0 here would read as "erasure honored" to whoever runs this against a regulator's
        // deadline; declaring nothing #[Personal] must fail LOUD, never a quiet no-op dressed as success
        if ($this->keys === null) {
            $io->error('This app declares no #[Personal] class: crypto-shredding does not apply to it, so there is no cipher key to destroy.');

            return Command::FAILURE;
        }

        $subject = (string) $input->getArgument('subject');

        if (trim($subject) === '') {
            $io->error('The subject id must be a non-empty string.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('dry-run')) {
            return $this->report($io, $this->keys, $subject);
        }

        if (! (bool) $input->getOption('force')) {
            // The state line reads `destroyed_at` alone, never the material: unwrapping here would make
            // the CONFIRMATION fail on a rotated master key while the erase behind it would have
            // succeeded, planting the dry-run's own defect on the destructive path.
            $standing = $this->keys->isDestroyed($subject)
                ? 'it already carries a tombstone, so this run re-runs the hooks and changes no key'
                : 'its key material is overwritten, not archived, and only a database restore brings it back';

            if (! $input->isInteractive()) {
                $io->error(sprintf(
                    'Forgetting [%s] cannot be undone: %s. The session is non-interactive: pass --force to confirm, or --dry-run to see what a run would do.',
                    $subject,
                    $standing,
                ));

                return Command::INVALID;
            }

            // The typo is the case worth the prompt, not the deliberate erase: an id that was never
            // issued still takes a tombstone, and `issue()` refuses that id from then on, so a
            // mistyped subject is condemned rather than merely missed.
            if (! $io->confirm(sprintf('Forget subject [%s]? This cannot be undone; %s.', $subject, $standing), false)) {
                $io->warning('Aborted — no key was destroyed and no hook ran.');

                return Command::SUCCESS;
            }
        }

        try {
            $outcome = $this->forgetter->forget($subject);
        } catch (ForgetIncomplete $e) {
            // the failure path stays as honest about scope as the success path: render what had
            // already run before rethrowing the recovery instruction
            if ($e->outcome !== null) {
                $io->warning(sprintf(
                    'Partial state before the failure — key destroyed: %s; hooks committed: %s; never covered, no hook: %s.',
                    $e->outcome->keyDestroyed ? 'yes, this run' : 'already tombstoned',
                    $e->outcome->touched === [] ? 'none' : implode(', ', $e->outcome->touched),
                    $e->outcome->untouched === [] ? 'none' : implode(', ', $e->outcome->untouched),
                ));
            }

            throw $e;
        }

        $io->success($outcome->keyDestroyed
            ? sprintf('Subject [%s] forgotten — key material destroyed, tombstone kept as the proof.', $subject)
            : sprintf('Subject [%s] was already forgotten — the tombstone stands; the hooks re-ran, idempotent.', $subject));

        $io->text(sprintf(
            'Read-model hooks: %s',
            $outcome->touched === [] ? 'none registered' : implode(', ', $outcome->touched),
        ));

        if ($outcome->untouched !== []) {
            $io->text(sprintf(
                'NOT touched (no ForgetsSubject hook — reset + rebuild refolds them onto fallbacks): %s',
                implode(', ', $outcome->untouched),
            ));
        }

        $io->text([
            'Scope of this forget, stated rather than assumed:',
            ' * every ENCRYPTED personal field of this subject now renders its declared fallback at read;',
            ' * rows written BEFORE their class was marked #[Personal] were stored in clear: the codec renders',
            '   their fallbacks too from now on, but the cleartext bytes remain in the store (erase the stream,',
            '   or rewrite, if those must go) — and a snapshot taken before the marking may hold cleartext state;',
            ' * backups keep the key until their own rotation: the residual window is the backup retention.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * The `--dry-run` report: key state and both hook halves. Never touches the store.
     *
     * `$keys` travels as a parameter, not `$this->keys` read again: the null case already returned
     * before `execute()` ever calls here, and this signature is what says so to a reader of this
     * method alone.
     *
     * @throws Exception on a DBAL failure reading the key row
     */
    private function report(SymfonyStyle $io, CipherKeyStore $keys, string $subject): int
    {
        if ($keys->isDestroyed($subject)) {
            $io->text(sprintf('Subject [%s] is ALREADY forgotten — a run would re-run the hooks (idempotent) and report the standing tombstone.', $subject));
        } else {
            try {
                $io->text($keys->keyFor($subject) !== null
                    ? sprintf('Subject [%s] has an ACTIVE cipher key — a run would destroy its material (tombstone kept) and every encrypted personal field would render its declared fallback at read.', $subject)
                    : sprintf('Subject [%s] was never issued a cipher key — a run would still record a tombstone, the proof of forgetting, and refuse any later key issue for this id.', $subject));
            } catch (InvalidMasterKey) {
                // A rotated or malformed master key is the state where an operator most needs this
                // answer, and the arm that erases never reads it: destroy() nulls the column. Aborting
                // here would report the erase as impossible when it is not.
                $io->text(sprintf('Subject [%s] HAS stored key material that the current master key cannot unwrap — a run would still destroy it, since the erase nulls the column and never reads the master key.', $subject));
            }
        }

        $preview = $this->forgetter->preview();

        $io->text(sprintf(
            'Hooks that would run: %s',
            $preview['volunteers'] === [] ? 'none registered' : implode(', ', $preview['volunteers']),
        ));

        if ($preview['untouched'] !== []) {
            $io->text(sprintf('Would NOT be touched (no ForgetsSubject hook): %s', implode(', ', $preview['untouched'])));
        }

        return Command::SUCCESS;
    }
}
