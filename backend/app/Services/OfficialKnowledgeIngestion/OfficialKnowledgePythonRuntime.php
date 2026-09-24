<?php

namespace App\Services\OfficialKnowledgeIngestion;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class OfficialKnowledgePythonRuntime
{
    /** @return array{executable: string, python_version: string, pypdf_version: string, cryptography_version: string} */
    public function preflight(): array
    {
        $executable = config('maintenance.official_knowledge.python_executable');
        if (! is_string($executable) || $executable === '') {
            throw new RuntimeException('Official extraction runtime is not configured.');
        }
        if (! str_starts_with($executable, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Official extraction runtime executable must be an absolute path.');
        }
        if (! is_file($executable)) {
            throw new RuntimeException('Official extraction runtime executable is not a regular file.');
        }
        if (! is_executable($executable)) {
            throw new RuntimeException('Official extraction runtime executable is not executable.');
        }

        $script = <<<'PYTHON'
import json
import sys

runtime_contract = {
    "python": [sys.version_info.major, sys.version_info.minor, sys.version_info.micro],
    "pypdf": None,
    "cryptography": None,
}
try:
    import pypdf
    runtime_contract["pypdf"] = pypdf.__version__
except Exception:
    pass
try:
    import cryptography
    runtime_contract["cryptography"] = cryptography.__version__
except Exception:
    pass
print(json.dumps(runtime_contract))
PYTHON;

        $process = new Process([$executable, '-I', '-c', $script], base_path());
        $process->setTimeout((float) config('maintenance.official_knowledge.preflight_timeout_seconds', 10));
        $this->run($process, 'preflight', $executable);

        try {
            $identity = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Official extraction runtime preflight returned invalid identity JSON.', previous: $exception);
        }
        $python = $identity['python'] ?? null;
        if (! is_array($python) || count($python) < 3 || ! array_is_list($python)) {
            throw new RuntimeException('Official extraction runtime preflight returned an invalid Python version.');
        }
        $requiredMajor = (int) config('maintenance.official_knowledge.python_major', 3);
        $requiredMinor = (int) config('maintenance.official_knowledge.python_minor', 11);
        if ((int) $python[0] !== $requiredMajor || (int) $python[1] !== $requiredMinor) {
            throw new RuntimeException("Official extraction runtime requires Python {$requiredMajor}.{$requiredMinor}.x.");
        }
        $requiredPypdf = (string) config('maintenance.official_knowledge.pypdf_version', '6.14.2');
        if (! is_string($identity['pypdf'] ?? null)) {
            throw new RuntimeException('Official extraction runtime cannot import pypdf.');
        }
        if (! hash_equals($requiredPypdf, $identity['pypdf'])) {
            throw new RuntimeException("Official extraction runtime requires pypdf {$requiredPypdf} exactly.");
        }
        $requiredCryptography = (string) config('maintenance.official_knowledge.cryptography_version', '50.0.1');
        if (! is_string($identity['cryptography'] ?? null)) {
            throw new RuntimeException('Official extraction runtime cannot import the PDF cryptography provider.');
        }
        if (! hash_equals($requiredCryptography, $identity['cryptography'])) {
            throw new RuntimeException("Official extraction runtime requires cryptography {$requiredCryptography} exactly.");
        }

        return [
            'executable' => $executable,
            'python_version' => implode('.', array_map('intval', array_slice($python, 0, 3))),
            'pypdf_version' => $identity['pypdf'],
            'cryptography_version' => $identity['cryptography'],
        ];
    }

    /** @param list<int> $pages */
    public function extract(string $script, string $pdfPath, array $pages): string
    {
        $runtime = $this->preflight();
        $process = new Process([
            $runtime['executable'], '-I', '-c', $script, $pdfPath,
            json_encode($pages, JSON_THROW_ON_ERROR),
        ], base_path());
        $process->setTimeout((float) config('maintenance.official_knowledge.extraction_timeout_seconds', 120));
        $this->run($process, 'page extraction', $runtime['executable']);

        return $process->getOutput();
    }

    private function run(Process $process, string $operation, string $executable): void
    {
        $started = microtime(true);
        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $timedOut = true;
            throw new RuntimeException(
                "Official extraction runtime {$operation} failed: ".$this->diagnostics($process, $executable, $started, $timedOut),
                previous: $exception,
            );
        } catch (ProcessSignaledException $exception) {
            throw new RuntimeException(
                "Official extraction runtime {$operation} failed: ".$this->diagnostics($process, $executable, $started, false),
                previous: $exception,
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Official extraction runtime {$operation} failed: ".$this->diagnostics($process, $executable, $started, $timedOut),
            );
        }
    }

    private function diagnostics(Process $process, string $executable, float $started, bool $timedOut): string
    {
        $signaled = $process->hasBeenSignaled();
        $fields = [
            'executable' => basename($executable),
            'exit_code' => $process->getExitCode() ?? 'null',
            'exit_text' => $process->getExitCodeText() ?? 'unknown',
            'stdout_bytes' => strlen($process->getOutput()),
            'stderr_bytes' => strlen($process->getErrorOutput()),
            'signaled' => $signaled ? 'yes' : 'no',
            'term_signal' => $signaled ? ($process->getTermSignal() ?? 'unknown') : 'none',
            'timed_out' => $timedOut ? 'yes' : 'no',
            'elapsed_seconds' => number_format(microtime(true) - $started, 6, '.', ''),
        ];

        return implode(' ', array_map(
            static fn (string $key, string|int $value): string => "{$key}={$value}",
            array_keys($fields),
            array_values($fields),
        ));
    }
}
