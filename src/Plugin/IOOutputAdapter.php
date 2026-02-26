<?php

namespace CStore\Plugin;

use Composer\IO\IOInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bridges Composer's IOInterface to Symfony's OutputInterface.
 *
 * The existing PackageDownloader and VendorLinker classes depend on
 * Symfony's OutputInterface (used by CLI commands). Inside a Composer
 * plugin, we only have IOInterface. This adapter lets us reuse
 * those classes without modifying their constructors.
 */
class IOOutputAdapter implements OutputInterface
{
    private OutputFormatterInterface $formatter;
    private int $verbosity;
    private bool $decorated;

    public function __construct(private IOInterface $io)
    {
        $this->formatter = new OutputFormatter();
        $this->decorated = $io->isDecorated();
        $this->verbosity = $this->detectInitialVerbosity($io);
    }

    public function write($messages, bool $newline = false, int $options = 0): void
    {
        if (!$this->shouldWrite($options)) {
            return;
        }

        $verbosity = $this->toComposerVerbosity($this->verbosityFromOptions($options));
        if (is_iterable($messages)) {
            foreach ($messages as $message) {
                $this->io->write($message, $newline, $verbosity);
            }
        } else {
            $this->io->write($messages, $newline, $verbosity);
        }
    }

    public function writeln($messages, int $options = 0): void
    {
        if (!$this->shouldWrite($options)) {
            return;
        }

        $verbosity = $this->toComposerVerbosity($this->verbosityFromOptions($options));
        if (is_iterable($messages)) {
            foreach ($messages as $message) {
                $this->io->write($message, true, $verbosity);
            }
        } else {
            $this->io->write($messages, true, $verbosity);
        }
    }

    public function setVerbosity(int $level): void
    {
        $this->verbosity = $level;
    }

    public function getVerbosity(): int
    {
        return $this->verbosity;
    }

    public function isQuiet(): bool { return $this->verbosity <= self::VERBOSITY_QUIET; }
    public function isVerbose(): bool { return $this->verbosity >= self::VERBOSITY_VERBOSE; }
    public function isVeryVerbose(): bool { return $this->verbosity >= self::VERBOSITY_VERY_VERBOSE; }
    public function isDebug(): bool { return $this->verbosity >= self::VERBOSITY_DEBUG; }

    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }
    public function isDecorated(): bool { return $this->decorated; }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $this->formatter = $formatter;
    }
    public function getFormatter(): OutputFormatterInterface { return $this->formatter; }

    private function verbosityFromOptions(int $options): int
    {
        $levels = [
            self::VERBOSITY_DEBUG,
            self::VERBOSITY_VERY_VERBOSE,
            self::VERBOSITY_VERBOSE,
            self::VERBOSITY_NORMAL,
            self::VERBOSITY_QUIET,
        ];

        if (\defined(OutputInterface::class . '::VERBOSITY_SILENT')) {
            /** @var int $silent */
            $silent = \constant(OutputInterface::class . '::VERBOSITY_SILENT');
            $levels[] = $silent;
        }

        foreach ($levels as $level) {
            if (($options & $level) === $level) {
                return $level;
            }
        }

        return self::VERBOSITY_NORMAL;
    }

    private function shouldWrite(int $options): bool
    {
        $requiredVerbosity = $this->verbosityFromOptions($options);
        return $requiredVerbosity <= $this->getVerbosity();
    }

    private function detectInitialVerbosity(IOInterface $io): int
    {
        if ($io->isDebug()) return self::VERBOSITY_DEBUG;
        if ($io->isVeryVerbose()) return self::VERBOSITY_VERY_VERBOSE;
        if ($io->isVerbose()) return self::VERBOSITY_VERBOSE;
        return self::VERBOSITY_NORMAL;
    }

    private function toComposerVerbosity(int $verbosity): int
    {
        return match ($verbosity) {
            self::VERBOSITY_QUIET => IOInterface::QUIET,
            self::VERBOSITY_VERBOSE => IOInterface::VERBOSE,
            self::VERBOSITY_VERY_VERBOSE => IOInterface::VERY_VERBOSE,
            self::VERBOSITY_DEBUG => IOInterface::DEBUG,
            default => IOInterface::NORMAL,
        };
    }
}
