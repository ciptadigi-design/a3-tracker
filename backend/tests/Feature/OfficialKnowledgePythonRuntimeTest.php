<?php

namespace Tests\Feature;

use App\Services\OfficialKnowledgeIngestion\LocalPypdfTextAcquirer;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgePythonRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class OfficialKnowledgePythonRuntimeTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_dependency_manifest_contains_only_the_hash_pinned_extraction_dependencies(): void
    {
        $this->assertSame(
            "pypdf==6.14.2 \\\n    --hash=sha256:3f07891af76dc002657e04993ab9b4de81de29f9013b9761d0b7968bff12e946\ncryptography==50.0.1 \\\n    --hash=sha256:51afcfceb15597cf2635068e4ac9a56b2abde622edde17f37d85fd7b5306497a \\\n    --hash=sha256:e2ca8fd1b6b4b82a1c4cb02841d0837e3c12336c2e24b520ab8ab3b969733d8f\ncffi==2.1.1 \\\n    --hash=sha256:3311ed60d36f83378794e1009ac6258bafbf81f7888b4caa7b35a521e3f95813 \\\n    --hash=sha256:34e261f78cb6ceaaa36f42f2613f4380d94d9c759a9c73c769ee6e0247364632\npycparser==3.0 \\\n    --hash=sha256:b727414169a36b7d524c1c3e31839a521725078d7b2ff038656844266160a992\n",
            file_get_contents(base_path('runtime/official-knowledge/requirements.txt')),
        );
    }

    public function test_valid_absolute_pinned_runtime_is_accepted(): void
    {
        $runtime = $this->configuredRuntime('valid');

        $identity = $runtime->preflight();

        $this->assertSame('3.11.14', $identity['python_version']);
        $this->assertSame('6.14.2', $identity['pypdf_version']);
        $this->assertSame('50.0.1', $identity['cryptography_version']);
    }

    public function test_relative_executable_is_rejected_without_path_discovery(): void
    {
        config(['maintenance.official_knowledge.python_executable' => 'python3']);

        $this->expectExceptionMessage('must be an absolute path');
        app(OfficialKnowledgePythonRuntime::class)->preflight();
    }

    public function test_missing_executable_is_rejected(): void
    {
        config(['maintenance.official_knowledge.python_executable' => '/missing/private/python']);

        $this->expectExceptionMessage('not a regular file');
        app(OfficialKnowledgePythonRuntime::class)->preflight();
    }

    public function test_non_executable_file_is_rejected(): void
    {
        $file = $this->temporaryDirectory().'/python';
        file_put_contents($file, '#!/bin/sh');
        chmod($file, 0600);
        config(['maintenance.official_knowledge.python_executable' => $file]);

        $this->expectExceptionMessage('is not executable');
        app(OfficialKnowledgePythonRuntime::class)->preflight();
    }

    public function test_directory_masquerading_as_executable_is_rejected(): void
    {
        $directory = $this->temporaryDirectory().'/python';
        mkdir($directory, 0700);
        config(['maintenance.official_knowledge.python_executable' => $directory]);

        $this->expectExceptionMessage('not a regular file');
        app(OfficialKnowledgePythonRuntime::class)->preflight();
    }

    public function test_runtime_without_pypdf_is_rejected(): void
    {
        $runtime = $this->configuredRuntime('missing_pypdf');

        $this->expectExceptionMessage('cannot import pypdf');
        $runtime->preflight();
    }

    public function test_wrong_pypdf_version_is_rejected(): void
    {
        $runtime = $this->configuredRuntime('wrong_pypdf');

        $this->expectExceptionMessage('requires pypdf 6.14.2 exactly');
        $runtime->preflight();
    }

    public function test_wrong_python_version_is_rejected(): void
    {
        $runtime = $this->configuredRuntime('wrong_python');

        $this->expectExceptionMessage('requires Python 3.11.x');
        $runtime->preflight();
    }

    public function test_interpreter_start_failure_retains_exit_metadata(): void
    {
        $runtime = $this->configuredRuntime('preflight_exit');

        try {
            $runtime->preflight();
            $this->fail('Preflight unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exit_code=9', $exception->getMessage());
            $this->assertStringContainsString('stderr_bytes=0', $exception->getMessage());
        }
    }

    public function test_runtime_without_pdf_cryptography_provider_is_rejected(): void
    {
        $runtime = $this->configuredRuntime('missing_cryptography');

        $this->expectExceptionMessage('cannot import the PDF cryptography provider');
        $runtime->preflight();
    }

    public function test_wrong_cryptography_version_is_rejected(): void
    {
        $runtime = $this->configuredRuntime('wrong_cryptography');

        $this->expectExceptionMessage('requires cryptography 50.0.1 exactly');
        $runtime->preflight();
    }

    public function test_empty_stderr_failure_retains_exit_metadata(): void
    {
        $acquirer = new LocalPypdfTextAcquirer($this->configuredRuntime('extract_exit'));

        try {
            $acquirer->acquire(__FILE__, [1]);
            $this->fail('Extraction unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exit_code=23', $exception->getMessage());
            $this->assertStringContainsString('stderr_bytes=0', $exception->getMessage());
            $this->assertStringContainsString('timed_out=no', $exception->getMessage());
        }
    }

    public function test_signal_termination_retains_signal_metadata(): void
    {
        $acquirer = new LocalPypdfTextAcquirer($this->configuredRuntime('signal'));

        try {
            $acquirer->acquire(__FILE__, [1]);
            $this->fail('Extraction unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('signaled=yes', $exception->getMessage());
            $this->assertStringContainsString('term_signal=15', $exception->getMessage());
        }
    }

    public function test_timeout_has_sanitized_timeout_diagnostic(): void
    {
        $acquirer = new LocalPypdfTextAcquirer($this->configuredRuntime('timeout'));
        config(['maintenance.official_knowledge.extraction_timeout_seconds' => 0.1]);

        try {
            $acquirer->acquire(__FILE__, [1]);
            $this->fail('Extraction unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('timed_out=yes', $exception->getMessage());
            $this->assertStringContainsString('stdout_bytes=0', $exception->getMessage());
            $this->assertStringNotContainsString(__FILE__, $exception->getMessage());
        }
    }

    public function test_explicit_runtime_is_independent_of_ambient_path_and_deterministic(): void
    {
        $originalPath = getenv('PATH');
        putenv('PATH=/path/without/python');
        try {
            $acquirer = new LocalPypdfTextAcquirer($this->configuredRuntime('valid'));
            $first = $acquirer->acquire(__FILE__, [1]);
            $second = $acquirer->acquire(__FILE__, [1]);
        } finally {
            putenv($originalPath === false ? 'PATH' : "PATH={$originalPath}");
        }

        $this->assertSame([1 => 'stable fixture page text'], $first);
        $this->assertSame($first, $second);
    }

    public function test_preflight_command_reports_only_sanitized_identity(): void
    {
        $this->configuredRuntime('valid');

        $this->artisan('maintenance:v2-extractor-runtime-preflight')
            ->expectsOutput('CONFIGURED_EXECUTABLE_ABSOLUTE=PASS')
            ->expectsOutputToContain('EXECUTABLE_IDENTITY=private-python')
            ->expectsOutput('PYTHON_VERSION=3.11.14')
            ->expectsOutput('PYPDF_VERSION=6.14.2')
            ->expectsOutput('CRYPTOGRAPHY_VERSION=50.0.1')
            ->expectsOutput('OFFICIAL_EXTRACTION_RUNTIME_PREFLIGHT=PASS')
            ->assertSuccessful();
    }

    private function configuredRuntime(string $mode): OfficialKnowledgePythonRuntime
    {
        $executable = $this->fakeExecutable($mode);
        config([
            'maintenance.official_knowledge.python_executable' => $executable,
            'maintenance.official_knowledge.python_major' => 3,
            'maintenance.official_knowledge.python_minor' => 11,
            'maintenance.official_knowledge.pypdf_version' => '6.14.2',
            'maintenance.official_knowledge.cryptography_version' => '50.0.1',
            'maintenance.official_knowledge.preflight_timeout_seconds' => 2,
            'maintenance.official_knowledge.extraction_timeout_seconds' => 2,
        ]);

        return app(OfficialKnowledgePythonRuntime::class);
    }

    private function fakeExecutable(string $mode): string
    {
        $path = $this->temporaryDirectory().'/private-python';
        $script = <<<'SH'
#!/bin/sh
mode="__MODE__"
case "$3" in
  *runtime_contract*)
    case "$mode" in
      missing_pypdf) printf '%s\n' '{"python":[3,11,14],"pypdf":null,"cryptography":"50.0.1"}'; exit 0 ;;
      wrong_pypdf) printf '%s\n' '{"python":[3,11,14],"pypdf":"6.14.1","cryptography":"50.0.1"}'; exit 0 ;;
      wrong_python) printf '%s\n' '{"python":[3,12,0],"pypdf":"6.14.2","cryptography":"50.0.1"}'; exit 0 ;;
      missing_cryptography) printf '%s\n' '{"python":[3,11,14],"pypdf":"6.14.2","cryptography":null}'; exit 0 ;;
      wrong_cryptography) printf '%s\n' '{"python":[3,11,14],"pypdf":"6.14.2","cryptography":"49.0.0"}'; exit 0 ;;
      preflight_exit) exit 9 ;;
      *) printf '%s\n' '{"python":[3,11,14],"pypdf":"6.14.2","cryptography":"50.0.1"}'; exit 0 ;;
    esac
    ;;
esac
case "$mode" in
  extract_exit) exit 23 ;;
  signal) kill -TERM $$ ;;
  timeout) /bin/sleep 2; exit 0 ;;
  *) printf '%s\n' '{"1":"stable fixture page text"}'; exit 0 ;;
esac
SH;
        file_put_contents($path, str_replace('__MODE__', $mode, $script));
        chmod($path, 0700);

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/official-python-runtime-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
