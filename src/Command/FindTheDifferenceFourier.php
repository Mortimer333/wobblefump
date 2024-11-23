<?php

declare(strict_types=1);

namespace Mortimer333\Wobblefump\Command;

use Brokencube\FFT\FFT;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('wobblefump:diff:fourier', 'Transform binary difference between two files using Fourier')]
final class FindTheDifferenceFourier extends Command
{
    private SymfonyStyle $output;
    public const MODE_FILES = 'F';
    public const MODE_ORIGINAL_URL = 'O';
    public const MODE_NEW_URL = 'N';
    public const MODE_BOTH_URL = 'B';
    public const PRECISION_DEFAULT = 128 * 16;
    public const CHUNK_DEFAULT = 1024 * 1024;
    public const MAX_CURL_RETRIES = 3;

    protected function configure(): void
    {
        $this
            ->addOption(
                'test-run',
                't',
                InputOption::VALUE_NONE,
                'Do a test-run (it will cap operations to the 10 chunks)',
            )
            ->addOption(
                'precision',
                'p',
                InputOption::VALUE_REQUIRED,
                "Precision of comparison (int) should be - size of chunks (1 is per one byte; 100 is per 100 bytes).\n"
                . ' Precision will be rounded down to chunk size and it must be a power of 2.',
                self::PRECISION_DEFAULT,
            )
            ->addOption(
                'chunk',
                'c',
                InputOption::VALUE_REQUIRED,
                'The size of chunks (int) to be retrieved from the files. In terms of memory remember to multiply this'
                . ' value twice as it will be per each file.',
                self::CHUNK_DEFAULT,
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output path for the result',
                './result.csv',
            )
            ->addOption(
                'diff-file',
                'D',
                InputOption::VALUE_REQUIRED,
                'Output path for the difference output. If provided, the diff file will not be removed automatically',
                false,
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    "How program should handle the file paths. \n" .
                    "Modes: \n%s - only original is URL, \n%s - only new is URL, \n%s - both are URL, " .
                    "\n%s - none\n",
                    self::MODE_ORIGINAL_URL,
                    self::MODE_NEW_URL,
                    self::MODE_BOTH_URL,
                    self::MODE_FILES,
                ),
                self::MODE_FILES,
            )
            ->addArgument(
                'original',
                InputArgument::REQUIRED,
                'Path to the original file',
            )
            ->addArgument(
                'new',
                InputArgument::REQUIRED,
                'Path to the new file',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = new SymfonyStyle($input, $output);
        $microtime = microtime(true);
        $startingMemoryUsage = memory_get_peak_usage(true);

        try {
            $this->output('[MEMORY] Starting peak memory usage ' . $this->toMiB($startingMemoryUsage) . 'MB');
            $this->output('Starting Find Diff script...');
            [$isTest, $mode, $original, $new, $precision, $chunk, $diffOutput, $output] = $this->getInputs($input);
            [$mode, $original, $new, $precision, $chunk, $diffOutput, $output] =
                $this->validateInputs($mode, $original, $new, $precision, $chunk, $diffOutput, $output);
            if ($isTest) {
                $this->output('Script will operate in Test mode - it will only try to load up to 10 chunks');
            }

            [
                $tmp,
                $originalHandle,
                $newHandle,
                $originalSize,
                $newSize,
                $resizedChunk,
            ] = $this->prepareForDiff($mode, $original, $new, $chunk, $diffOutput);

            $diffGenerator = $this->getDiff(
                $original,
                $new,
                $originalHandle,
                $newHandle,
                $originalSize,
                $newSize,
                $resizedChunk,
                $precision,
                $isTest,
            );

            $chunksAmount = (int) ceil(min($newSize, $originalSize) / $chunk);

            $this->output('Loading and comparing files...');

            $currentIteration = 0;
            $progressBar = $this->tryToCreateProgressBar($chunksAmount);
            foreach ($diffGenerator as $diff) {
                fwrite($tmp, $diff);
                $this->debug('Load: ' . ++$currentIteration . '/' . $chunksAmount);
                $progressBar?->advance();
            }
            $this->finishProgressBar($progressBar);

            fseek($tmp, 0);

            $this->tryToCloseAndUnlockFile($originalHandle);
            $this->tryToCloseAndUnlockFile($newHandle);

            $diffFileSize = $this->streamGetFileSize($tmp);
            $this->output('Diff file has ' . $this->toMiB($diffFileSize) . ' MB');

            $amountOfOperations = (int) ceil($diffFileSize/$precision);
            $this->output('Calculating Fourier Transform on the results...');
            $progressBar = $this->tryToCreateProgressBar($amountOfOperations);

            $currentIteration = 0;
            $result = $this->openResultFile($output);
            foreach ($this->fourierTransformOnFileGenerator($tmp, $precision) as $data) {
                foreach ($data as $item) {
                    fwrite($result, $item . PHP_EOL);
                }
                $this->debug('Calculated: ' . ++$currentIteration . '/' . $amountOfOperations);
                $progressBar?->advance();
            }
            $this->finishProgressBar($progressBar);

            fclose($tmp);
            $this->tryToCloseAndUnlockFile($result);
            $this->output(sprintf('Command finished successfully and the result was saved into %s', realpath($output)));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ('prod' === $_ENV['APP_ENV']) {
                $this->output->error($e->getMessage());
            } else {
                $this->output->error($e->getMessage() . ' => ' . $e->getFile() . ' => ' . $e->getLine());
            }

            return self::FAILURE;
        } finally {
            $end = memory_get_peak_usage(true);
            $this->output('Command took ' . (microtime(true) - $microtime) . ' seconds to complete');
            $this->output(
                '[MEMORY] If memory usage didn\'t change drastically (like 1MB or 2MB) it will not detect the change:',
            );
            $this->output('[MEMORY] End peak memory usage ' . $this->toMiB($end) . 'MB');
            $this->output(
                '[MEMORY] Actual peak memory usage for the command: ' . $this->toMiB($end - $startingMemoryUsage) . 'MB',
            );
        }
    }

    private function finishProgressBar(?ProgressBar $progressBar): void
    {
        if ($progressBar) {
            $progressBar->finish();
            $this->output('');
        }
    }

    /**
     * @return array{
     *     0: resource,
     *     1: resource|false,
     *     2: resource|false,
     *     3: int,
     *     4: int,
     *     5: int<1, max>,
     * }
     */
    private function prepareForDiff(
        string $mode,
        string $original,
        string $new,
        int $chunk,
        string|false $diffOutput,
    ): array
    {
        $tmp = is_string($diffOutput) ? fopen($diffOutput, 'r+') : tmpfile();
        if (!$tmp) {
            throw new \Exception('Cannot create diff output file');
        }

        $originalIsUrl = self::MODE_BOTH_URL === $mode || self::MODE_ORIGINAL_URL === $mode;
        $originalSize = $this->getSize($original, $originalIsUrl);

        $newIsUrl = self::MODE_BOTH_URL === $mode || self::MODE_NEW_URL === $mode;
        $newSize = $this->getSize($new, $newIsUrl);

        $this->debug('Size of the original file ' . $this->toMiB($originalSize) . ' MB');
        $this->debug('Size of the new file ' . $this->toMiB($newSize) . ' MB');

        // Keeping handles outside the read method, so we don't have to open them every time we want to read
        $originalHandle = $originalIsUrl ? false : $this->openFileInBinaryModeAndLock($original);
        $newHandle = $newIsUrl ? false : $this->openFileInBinaryModeAndLock($new);

        /** @var int<1, max> $chunk */
        $chunk = min($chunk, $originalSize, $newSize);

        return [$tmp, $originalHandle, $newHandle, $originalSize, $newSize, $chunk];
    }

    /**
     * @param resource|false $originalHandle
     * @param resource|false $newHandle
     * @param int<1, max>    $chunk
     *
     * @throws \Exception
     */
    private function getDiff(
        string $original,
        string $new,
               $originalHandle,
               $newHandle,
        int    $originalSize,
        int    $newSize,
        int    $chunk = self::CHUNK_DEFAULT,
        int    $precision = self::PRECISION_DEFAULT,
        bool   $isTest = false,
    ): \Generator {
        $read = 0;
        $loopCounter = 0;

        while ($read < $originalSize && $read < $newSize) {
            /** @var int<1, max> $currentChunk */
            $currentChunk = min($chunk, $originalSize - $read, $newSize - $read);
            $originalChunk = $this->read($original, $read, $currentChunk, $originalHandle);
            $newChunk = $this->read($new, $read, $currentChunk, $newHandle);

            yield $this->compare($originalChunk, $newChunk, $precision);

            $read += $currentChunk;

            if ($isTest && ++$loopCounter > 9) {
                $this->debug('Stop difference search after ten loops due to Test mode');
                break;
            }
        }
    }

    private function compare(string $original, string $new, int $precision): string
    {
        $differences = '';
        $cursor = 0;
        $orgLen = strlen($original);
        $newLen = strlen($new);
        while ($cursor < $orgLen && $cursor < $newLen) {
            $nextStep = min($precision, $newLen - $cursor, $orgLen - $cursor);
            $differences .= substr($original, $cursor, $nextStep) ^ substr($new, $cursor, $nextStep);
            $cursor += $nextStep;
        }

        return $differences;
    }

    private function tryToCreateProgressBar(int $max = 0): ?ProgressBar
    {
        if ($this->output->isDebug()) {
            return null;
        }

        $progressBar = $this->output->createProgressBar($max);
        $starting = memory_get_peak_usage(true);
        $progressBar->setFormat(
            ' %current%/%max% [%bar%] %percent:3s%% %elapsed:16s%/%estimated:-16s% '
            . $this->toMiB($starting) . ' MiB/%memory:6s%',
        );
        $progressBar->start();

        return $progressBar;
    }

    private function fftMagnitude(array $data): array
    {
        return FFT::magnitude($data);
    }

    /**
     * @param int<1, max> $chunkSize
     * @param resource    $handle
     */
    private function fourierTransformOnFileGenerator($handle, int $chunkSize): \Generator
    {
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            $data = [];
            for ($i = 0; $i < $chunkSize; ++$i) {
                $data[] = isset($chunk[$i]) ? ord($chunk[$i]) : 0;
            }
            unset($chunk);

            yield $this->fftMagnitude($data);
        }
    }

    private function streamGetFileSize($stream): int
    {
        $stats = fstat($stream);

        return $stats['size'] ?? throw new \Exception("Couldn't read stream size");
    }

    /**
     * @return resource
     * @throws \Exception
     */
    private function openResultFile(string $path)
    {
        $result = fopen($path, 'w');
        if (!$result || !flock($result, LOCK_EX)) {
            throw new \Exception('Couldn\'t open and lock the file to save the result');
        }

        return $result;
    }

    /**
     * @param mixed $handle
     */
    private function tryToCloseAndUnlockFile($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @return resource
     *
     * @throws \Exception
     */
    private function openFileInBinaryModeAndLock(string $path)
    {
        $handle = fopen($path, 'rb');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new \Exception(sprintf('Cannot open %s', $path));
        }

        return $handle;
    }

    /**
     * @param int<1, max>    $chunk
     * @param resource|false $handle
     *
     * @throws \Exception
     */
    private function read(string $path, int $start, int $chunk, $handle): string
    {
        if (!$handle) {
            return $this->loadRemoteFileChunk($path, $start, $start + $chunk);
        }

        fseek($handle, $start);
        $result = fread($handle, $chunk);
        if (false === $result) {
            throw new \Exception(sprintf('Couldn\'t read the file %s', $result));
        }

        return $result;
    }

    /**
     * @throws \Exception
     */
    private function loadRemoteFileChunk(string $path, int $from, int $to, int $tries = 0): string
    {
        try {
            $handle = curl_init($path);
            if (!$handle) {
                throw new \Exception(sprintf('Couldn\'t create cURL handle for %s', $path));
            }

            curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_HTTPHEADER, [
                sprintf('Range: bytes=%s-%s', $from, $to - 1),
            ]);

            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($handle, CURLOPT_TIMEOUT, 2);
            /** @var string|false $result */
            $result = curl_exec($handle);
            if (false === $result) {
                throw new \Exception(sprintf('Invalid result for: %s', $path));
            }

            $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if (200 !== $code && 206 !== $code) {
                throw new \Exception(sprintf('Invalid response: %s', $code));
            }
            curl_close($handle);

            return $result;
        } catch (\Exception $e) {
            if ($tries <= self::MAX_CURL_RETRIES) {
                return $this->loadRemoteFileChunk($path, $from, $to, $tries + 1);
            }

            throw $e;
        }
    }

    private function toMiB(int $size): float
    {
        return round($size / 1024 / 1024, 4);
    }

    private function getSize(string $path, bool $isUrl): int
    {
        if ($isUrl) {
            return $this->getSizeOfRemoteFile($path);
        }

        $size = filesize($path);
        if (false === $size) {
            throw new \Exception(sprintf("Couldn't read %s file size", $path));
        }

        return $size;
    }

    /**
     * @return array{
     *     0: bool,
     *     1: string,
     *     2: string,
     *     3: string,
     *     4: int,
     *     5: int,
     *     6: string|string,
     *     7: string,
     * }
     */
    private function getInputs(InputInterface $input): array
    {
        $isTest = (bool) $input->getOption('test-run');
        $mode = (string) $input->getOption('mode');
        $original = (string) $input->getArgument('original');
        $new = (string) $input->getArgument('new');
        $precision = (int) $input->getOption('precision');
        $chunk = (int) $input->getOption('chunk');
        $diffOutput = $input->getOption('diff-file');
        $output = (string) $input->getOption('output');

        return [$isTest, $mode, $original, $new, $precision, $chunk, $diffOutput, $output];
    }

    /**
     * @return array{
     * 0: string,
     * 1: string,
     * 2: string,
     * 3: int<1, max>,
     * 4: int<1, max>,
     * 5: string|false,
     * 6: string,
     * }
     *
     * @throws \Exception
     */
    private function validateInputs(
        string $mode,
        string $original,
        string $new,
        int $precision,
        int $chunk,
        string|false $diffOutput,
        string $output,
    ): array {
        $correctModes = [self::MODE_FILES, self::MODE_ORIGINAL_URL, self::MODE_NEW_URL, self::MODE_BOTH_URL];
        $this->assert(!in_array($mode, $correctModes), 'Invalid mode given');
        $this->assert(empty($original), 'Path to original file cannot be empty');
        $this->assert(empty($new), 'Path to new file cannot be empty');

        if (self::MODE_NEW_URL !== $mode && self::MODE_BOTH_URL !== $mode) {
            $this->assert(!file_exists($new) || !is_readable($new), 'Path to new file is invalid or cannot be read');
        } else {
            $this->assert(!$this->ping($new), 'URL of the new version is not resolving correctly.');
        }

        if (self::MODE_ORIGINAL_URL !== $mode && self::MODE_BOTH_URL !== $mode) {
            $this->assert(
                !file_exists($original) || !is_readable($original),
                'Path to original file is invalid or cannot be read',
            );
        } else {
            $this->assert(!$this->ping($new), 'URL of the original version is not resolving correctly.');
        }

        $this->assert($precision < 1, 'Precision cannot be lower then 1');
        /** @var int<1, max> $precision */
        $this->assert(($precision & ($precision - 1)) !== 0, 'Precision must be a power of 2');
        $this->assert($chunk < 1, 'Chunk cannot be lower then 1');
        /** @var int<1, max> $chunk */
        if ($precision > $chunk) {
            $precision = $chunk;
        }

        if (is_string($diffOutput)) {
            $diffOutputDir = dirname($diffOutput);
            $this->assert(
                !$diffOutputDir || !is_writable($diffOutputDir),
                sprintf('Path to output diff file is not writeable or its directory doesn\'t exist: %s', $diffOutputDir)
            );
        }

        $outputDir = dirname($output);
        $this->assert(
            !$outputDir || !is_writable($outputDir),
            sprintf('Path to output file is not writeable or its directory doesn\'t exist: %s', $output)
        );

        return [$mode, $original, $new, $precision, $chunk, $diffOutput, $output];
    }

    /**
     * @param array<string, array<string>> $headers
     */
    private function mapCurlHeaders(array &$headers): \Closure
    {
        return function (\CurlHandle $curl, string $header) use (&$headers): int {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }

            $name = strtolower(trim($header[0]));
            $headers[$name][] = trim($header[1]);

            return $len;
        };
    }

    private function getSizeOfRemoteFile(string $url): int
    {
        $handle = curl_init($url);
        if (!$handle) {
            return 0;
        }

        $headers = [];
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_HEADER, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, $this->mapCurlHeaders($headers));
        curl_exec($handle);
        curl_close($handle);

        $headers['content-length'] ??= [];
        // Retrieve the last available header - it is our file to be downloaded after redirects
        $contentLength = (int) ($headers['content-length'][array_key_last($headers['content-length'])] ?? 0);

        return $contentLength;
    }

    /**
     * @throws \Exception
     */
    private function ping(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $handle = curl_init($url);
        if (!$handle) {
            return false;
        }

        $headers = [];
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, $this->mapCurlHeaders($headers));
        curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if (
            !isset($headers['accept-ranges'])
            && 'bytes' !== ($headers['accept-ranges'][array_key_last($headers['accept-ranges'])] ?? '')
        ) {
            throw new \Exception(sprintf('Server of %s does not accept Range Requests', $url));
        }

        $this->debug('Ping for ' . $url . ' returned ' . $code);

        return 200 === $code;
    }

    /**
     * @throws \Exception
     */
    private function assert(bool $condition, string $description): void
    {
        if (!$condition) {
            return;
        }

        throw new \Exception($description);
    }

    private function output(string $message): void
    {
        $this->output->writeln($message);
    }

    private function debug(string $message): void
    {
        if (!$this->output->isDebug()) {
            return;
        }

        $this->output->writeln($message);
    }
}
