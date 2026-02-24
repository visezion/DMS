<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class AgentBuildService
{
    public function build(string $version, string $runtime = 'win-x64', bool $selfContained = true): array
    {
        $safeVersion = $this->sanitizeToken($version, 'build');
        $safeRuntime = $this->sanitizeToken($runtime, 'win-x64');

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());
        $agentRoot = $repoRoot.DIRECTORY_SEPARATOR.'agent';
        $outputRoot = storage_path('app'.DIRECTORY_SEPARATOR.'agent-releases'.DIRECTORY_SEPARATOR.'builds');

        if (! is_dir($agentRoot)) {
            throw new \RuntimeException('Agent repository folder not found at: '.$agentRoot);
        }

        if (! is_dir($outputRoot)) {
            mkdir($outputRoot, 0775, true);
        }

        $script = base_path('scripts'.DIRECTORY_SEPARATOR.'build-agent.ps1');
        if (! is_file($script)) {
            throw new \RuntimeException('Build script missing: '.$script);
        }

        $process = new Process([
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-AgentRoot', $agentRoot,
            '-OutputRoot', $outputRoot,
            '-Version', $safeVersion,
            '-Runtime', $safeRuntime,
            '-SelfContained', $selfContained ? 'true' : 'false',
        ]);

        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Agent build failed:\n".$process->getErrorOutput()."\n".$process->getOutput());
        }

        $output = trim($process->getOutput());
        preg_match('/Artifact:\s*(.+\.zip)/i', $output, $matches);
        $zipPath = $matches[1] ?? null;

        if (! $zipPath) {
            $candidates = glob($outputRoot.DIRECTORY_SEPARATOR.'dms-agent-'.$safeVersion.'-'.$safeRuntime.'-*.zip') ?: [];
            rsort($candidates);
            $zipPath = $candidates[0] ?? null;
        }

        if (! $zipPath || ! is_file($zipPath)) {
            throw new \RuntimeException('Build completed but ZIP artifact missing in '.$outputRoot);
        }

        return [
            'zip_name' => basename($zipPath),
            'zip_full_path' => $zipPath,
            'size_bytes' => filesize($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
            'log' => $output,
        ];
    }

    private function sanitizeToken(string $value, string $fallback): string
    {
        $sanitized = preg_replace('/[^0-9A-Za-z._-]+/', '-', trim($value)) ?? '';
        $sanitized = trim($sanitized, '-');
        return $sanitized !== '' ? $sanitized : $fallback;
    }
}
